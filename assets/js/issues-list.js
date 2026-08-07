/**
 * ClearA11y Issues Explorer.
 */
(function() {
	'use strict';

	const Query = window.ClearA11yIssueQuery;
	const API_URL = cleara11yData.apiUrl;
	const NONCE = cleara11yData.nonce;
	let query = Query.parse(window.location.href);
	let requestController = null;
	let detailController = null;
	let lastResult = null;
	let openingControl = null;
	let openingOccurrenceId = null;
	let activeOccurrence = null;
	let activeDetailTab = 'overview';
	let detailCloseTimer = null;
	let searchTimer = null;

	const el = {};
	const byId = id => document.getElementById(id);
	const escapeHtml = value => {
		const node = document.createElement('span');
		node.textContent = value === null || value === undefined ? '' : String(value);
		return node.innerHTML;
	};
	const escapeAttribute = value => escapeHtml(value).replace(/"/g, '&quot;').replace(/'/g, '&#039;');
	const formatDate = value => value ? new Date(value.replace(' ', 'T') + (value.includes('Z') ? '' : 'Z')).toLocaleString() : '';

	function init() {
		[
			'explorer-title', 'explorer-summary', 'breadcrumb-current', 'snapshot-banner',
			'filter-status', 'filter-severity', 'include-exceptions', 'include-exceptions-control', 'search-issues', 'group-by',
			'sort', 'clear-filters', 'issues-container', 'results-region',
			'results-announcer', 'pagination', 'prev-page', 'next-page', 'page-info',
			'detail-panel', 'detail-content'
		].forEach(id => { el[id] = byId('cleara11y-' + id); });

		bindControls();
		setupEntityFilters();
		syncControls();
		load();
		window.addEventListener('popstate', () => {
			const previousQuery = query;
			const nextQuery = Query.parse(window.location.href);
			query = nextQuery;
			syncControls();
			if (lastResult && sameResultQuery(previousQuery, nextQuery)) {
				renderContext(lastResult);
				if (query.occurrenceId) loadDetail(query.occurrenceId);
				else closeDetail(Boolean(previousQuery.occurrenceId));
				return;
			}
			load({restoreFocus: previousQuery.occurrenceId && !query.occurrenceId});
		});
		document.addEventListener('cleara11y:exception-saved', handleExceptionSaved);
	}

	function bindControls() {
		el['filter-status'].addEventListener('change', event => update({findingType: event.target.value}));
		el['filter-severity'].addEventListener('change', event => update({severity: event.target.value}));
		el['include-exceptions'].addEventListener('change', event => update({
			includeExceptions: event.target.checked,
			status: event.target.checked ? 'all' : 'active'
		}));
		el['group-by'].addEventListener('change', event => update({groupBy: event.target.value}));
		el.sort.addEventListener('change', event => update({sort: event.target.value}));
		el['search-issues'].addEventListener('input', event => {
			window.clearTimeout(searchTimer);
			searchTimer = window.setTimeout(() => update({search: event.target.value.trim()}), 350);
		});
		el['clear-filters'].addEventListener('click', () => {
			query = {
				status: 'active', findingType: '', includeExceptions: false, severity: '', ruleId: '', pageId: undefined,
				scanId: undefined, search: '', groupBy: 'page', sort: 'severity',
				occurrenceId: undefined, resultsPage: 1
			};
			commit();
				clearEntityFilterInputs();
		});
		el['prev-page'].addEventListener('click', () => update({resultsPage: Math.max(1, query.resultsPage - 1)}));
		el['next-page'].addEventListener('click', () => update({resultsPage: query.resultsPage + 1}));
		el['issues-container'].addEventListener('click', handleResultsClick);
		el['detail-content'].addEventListener('click', handleDetailClick);
		el['detail-content'].addEventListener('keydown', handleDetailKeydown);
		document.addEventListener('keydown', event => {
			if (
				event.key === 'Escape'
				&& el['detail-panel'].classList.contains('is-open')
				&& el['detail-panel'].contains(document.activeElement)
				&& !document.getElementById('cleara11y-wizard-modal')
			) {
				event.preventDefault();
				requestCloseDetail();
			}
		});
	}

	function update(changes, replace) {
		const hadOpenDetail = Boolean(query.occurrenceId);
		query = Object.assign({}, query, changes);
		if (!Object.prototype.hasOwnProperty.call(changes, 'resultsPage')) query.resultsPage = 1;
		if (!Object.prototype.hasOwnProperty.call(changes, 'occurrenceId')) query.occurrenceId = undefined;
		if (query.scanId) {
			query.status = 'all';
			query.includeExceptions = true;
		}
		if (hadOpenDetail && !query.occurrenceId) closeDetail(false);
		commit(replace);
	}

	function commit(replace) {
		const url = Query.toUrl(query, window.location.href);
		const state = Object.assign({}, window.history.state || {}, {
			cleara11yExplorer: true,
			scrollY: window.scrollY
		});
		window.history[replace ? 'replaceState' : 'pushState'](state, '', url);
		syncControls();
		load();
	}

	function openOccurrence(id, control) {
		openingControl = control || openingControl;
		openingOccurrenceId = String(id);
		const replace = Boolean(query.occurrenceId);
		query = Object.assign({}, query, {occurrenceId: Number(id)});
		const url = Query.toUrl(query, window.location.href);
		window.history[replace ? 'replaceState' : 'pushState']({
			cleara11yExplorer: true,
			scrollY: window.scrollY
		}, '', url);
		renderContext(lastResult);
		syncSelectedRow();
		loadDetail(id);
	}

	function sameResultQuery(first, second) {
		return ['status', 'findingType', 'includeExceptions', 'severity', 'ruleId', 'pageId', 'scanId', 'search', 'groupBy', 'sort', 'resultsPage']
			.every(key => String(first[key] ?? '') === String(second[key] ?? ''));
	}

	function syncControls() {
		el['filter-status'].value = query.findingType;
		el['include-exceptions'].checked = Boolean(query.includeExceptions);
		el['include-exceptions-control'].hidden = Boolean(query.scanId);
		el['filter-severity'].value = query.severity;
		el['search-issues'].value = query.search;
		el['group-by'].value = query.groupBy;
		el.sort.value = query.sort;

		// Sync entity filters from URL parameters
		syncEntityFilters();
	}

	async function syncEntityFilters() {
		const entityTypes = [
			{ type: 'rule', queryKey: 'ruleId', inputId: 'cleara11y-rule-filter' },
			{ type: 'page', queryKey: 'pageId', inputId: 'cleara11y-page-filter' },
			{ type: 'scan', queryKey: 'scanId', inputId: 'cleara11y-scan-filter' }
		];

		for (const entityType of entityTypes) {
			const value = query[entityType.queryKey];
			const input = document.getElementById(entityType.inputId);
			if (!input) continue;

			if (!value) {
				// No filter set - clear input and selectedId
				input.value = '';
				delete input.dataset.selectedId;
				continue;
			}

			try {
				// Fetch the options to find the label for this ID
				const response = await fetch(API_URL + 'issues/filter-options?' + new URLSearchParams({
					type: entityType.type,
					search: '' // Get all options to find the match
				}), {
					headers: {'X-WP-Nonce': NONCE}
				});

				if (!response.ok) continue;

				const options = (await response.json()).options || [];
				const matchedOption = options.find(opt => String(opt.id) === String(value));

				if (matchedOption) {
					input.value = matchedOption.label;
					input.dataset.selectedId = value; // Store the ID for reference
				}
			} catch (error) {
				console.error('Failed to sync entity filter:', entityType.type, error);
			}
		}
	}

	function clearEntityFilterInputs() {
		const entityTypes = ['rule', 'page', 'scan'];
		entityTypes.forEach(type => {
			const inputId = type === 'rule' ? 'cleara11y-rule-filter' :
				type === 'page' ? 'cleara11y-page-filter' : 'cleara11y-scan-filter';
			const input = document.getElementById(inputId);
			if (input) {
				input.value = '';
				delete input.dataset.selectedId;
			}
		});
	}

	async function load(options) {
		options = options || {};
		if (requestController) requestController.abort();
		requestController = new AbortController();
		setLoading();
		try {
			const response = await fetch(API_URL + 'issues/occurrences?' + Query.apiParams(query, cleara11yData.perPage), {
				headers: {'X-WP-Nonce': NONCE},
				signal: requestController.signal
			});
			if (!response.ok) throw new Error(await responseMessage(response));
			lastResult = await response.json();
			renderContext(lastResult);
			renderResults(lastResult);
			if (query.occurrenceId) {
				await loadDetail(query.occurrenceId);
			} else {
				closeDetail(Boolean(options.restoreFocus));
			}
		} catch (error) {
			if (error.name !== 'AbortError') renderError(error.message);
		}
	}

	function setLoading() {
		el['issues-container'].setAttribute('aria-busy', 'true');
		el['issues-container'].innerHTML = `
			<div class="cleara11y-loading-state" role="status">
				<span class="spinner is-active" aria-hidden="true"></span>
				<span>${escapeHtml(cleara11yData.strings.loadingOccurrences)}</span>
			</div>`;
	}

	function renderContext(data) {
		const context = data.context;
		const summary = data.summary;
		let title = cleara11yData.strings.activeTitle;
		if (context.scan) {
			title = context.scan.label;
			if (query.severity) title = capitalize(query.severity) + ' issues from ' + context.scan.label;
		} else if (context.rule && context.page) {
			title = context.rule.label + ' issues on ' + context.page.label;
		} else if (context.rule) {
			title = context.rule.label + ' issues';
		} else if (context.page) {
			title = 'Issues on ' + context.page.label;
		} else if (query.severity) {
			title = capitalize(query.severity) + ' accessibility issues';
		} else if (query.findingType === 'violation') {
			title = query.includeExceptions ? 'Confirmed issues including exceptions' : 'Confirmed active issues';
		} else if (query.findingType === 'review') {
			title = query.includeExceptions ? 'Unconfirmed issues including exceptions' : 'Unconfirmed active issues';
		} else if (query.status === 'exception') {
			title = 'Accessibility exceptions';
		} else if (query.includeExceptions) {
			title = 'Accessibility issues including exceptions';
		}
		el['explorer-title'].textContent = title;
		el['breadcrumb-current'].textContent = query.occurrenceId ? title + ' / Occurrence' : title;
		el['explorer-summary'].textContent = `${summary.total_occurrences} matching ${plural(summary.total_occurrences, 'occurrence')}` +
			` on ${summary.affected_pages} ${plural(summary.affected_pages, 'page')}.`;
		renderSnapshot(context.scan);
	}

	function renderSnapshot(scan) {
		if (!scan) {
			el['snapshot-banner'].hidden = true;
			return;
		}
		let label = 'Latest scan snapshot';
		if (scan.is_historical) label = 'Historical snapshot';
		if (scan.is_provisional) label = 'Scan in progress — results may still change';
		el['snapshot-banner'].innerHTML = `<strong>${escapeHtml(label)}</strong>` +
			(scan.date ? ` <span>Captured ${escapeHtml(formatDate(scan.date))}.</span>` : '') +
			' <span>This view shows failures recorded by this scan, not the site’s current workflow state.</span>';
		el['snapshot-banner'].hidden = false;
	}

	function renderResults(data) {
		const items = data.items || [];
		el['issues-container'].removeAttribute('aria-busy');
		if (!items.length) {
			const filtered = Boolean(query.search || query.severity || query.findingType || query.ruleId || query.pageId || query.status === 'exception');
			const text = query.scanId
				? cleara11yData.strings.noScanIssues
				: (filtered ? cleara11yData.strings.noFilteredIssues : cleara11yData.strings.noIssues);
			el['issues-container'].innerHTML = `<div class="cleara11y-empty-state"><h3>${escapeHtml(text)}</h3>` +
				(filtered ? '<button type="button" class="button" data-clear-results>Clear filters</button>' : '') + '</div>';
		} else if (query.groupBy === 'none') {
			el['issues-container'].innerHTML = `<div class="cleara11y-result-list">${items.map(item => renderRow(item, 'none')).join('')}</div>`;
		} else {
			const groups = new Map();
			items.forEach(item => {
				const key = query.groupBy === 'page' ? String(item.page.id) : item.rule.id;
				if (!groups.has(key)) groups.set(key, []);
				groups.get(key).push(item);
			});
			el['issues-container'].innerHTML = Array.from(groups.values()).map(renderGroup).join('');
		}
		const pagination = data.pagination;
		el.pagination.hidden = pagination.total_pages <= 1;
		el['prev-page'].disabled = pagination.page <= 1;
		el['next-page'].disabled = pagination.page >= pagination.total_pages;
		el['page-info'].textContent = `Page ${pagination.page} of ${pagination.total_pages}`;
		el['results-announcer'].textContent = `${data.summary.total_occurrences} issue occurrences found.`;
		syncSelectedRow();
	}

	function renderGroup(items) {
		const first = items[0];
		const pageGroup = query.groupBy === 'page';
		const label = pageGroup ? first.page.title : first.rule.title;
		const id = 'cleara11y-group-' + (pageGroup ? first.page.id : safeId(first.rule.id));
		const metadata = pageGroup
			? pathname(first.page.url)
			: first.rule.id;
		const actions = pageGroup
			? `<a class="button button-small" href="${escapeHtml(first.page.url)}" target="_blank" rel="noopener noreferrer">View</a>` +
				(first.page.edit_url ? `<a class="button button-small" href="${escapeHtml(first.page.edit_url)}">Edit</a>` : '')
			: `<a class="button button-small" href="${escapeHtml(first.rule.reference_url)}">Issue reference</a>`;
		return `<section class="cleara11y-result-group">
			<header class="cleara11y-group-header">
				<div class="cleara11y-group-header__identity">
					<h3><button type="button" class="cleara11y-group-toggle" aria-expanded="true" aria-controls="${id}">${escapeHtml(label)}</button></h3>
					${pageGroup
						? `<span class="cleara11y-group-header__path">${escapeHtml(metadata)}</span>`
						: `<code class="cleara11y-group-header__rule-id">${escapeHtml(metadata)}</code>`}
				</div>
				<div class="cleara11y-group-header__actions">${actions}</div>
			</header>
			<div id="${id}" class="cleara11y-result-list">${items.map(item => renderRow(item, query.groupBy)).join('')}</div>
		</section>`;
	}

	function renderRow(item, grouping) {
		const pagePrimary = grouping === 'rule';
		const primaryTitle = pagePrimary ? item.page.title : item.rule.title;
		const accessibleLabel = `View details for ${item.rule.title} on ${item.page.title}`;
		const pagePath = pathname(item.page.url);
		const pageMetadata = grouping === 'none'
			? `<p class="cleara11y-result-row__meta"><strong>${escapeHtml(item.page.title)}</strong>${truncatedValue(pagePath, 'Page path')}</p>`
			: (pagePrimary ? `<p class="cleara11y-result-row__meta">${truncatedValue(pagePath, 'Page path')}</p>` : '');
		const stateBadges = (item.finding_type === 'review'
			? '<span class="cleara11y-status-text is-unconfirmed">Unconfirmed</span>'
			: '') + (item.status === 'exception'
			? '<span class="cleara11y-status-text is-exception">Exception</span>'
			: '');
		const identifier = item.selector || `Occurrence #${item.id}`;
		const identifierLabel = item.selector ? 'Affected element selector' : 'Occurrence identifier';
		return `<article class="cleara11y-result-row severity-${escapeHtml(item.severity)}" data-occurrence-row="${item.id}">
			<div class="cleara11y-result-row__main">
				<span class="screen-reader-text">${escapeHtml(capitalize(item.severity))} severity.</span>
				<div class="cleara11y-result-row__heading">
					<h4><button type="button" class="button-link cleara11y-view-occurrence" data-occurrence-id="${item.id}"
						aria-label="${escapeAttribute(accessibleLabel)}" aria-controls="cleara11y-detail-panel" aria-expanded="false">${escapeHtml(primaryTitle)}</button></h4>
					${stateBadges}
				</div>
				${pageMetadata}
				<code class="cleara11y-selector cleara11y-truncated-value" tabindex="0" aria-label="${escapeAttribute(identifierLabel + ': ' + identifier)}" data-tooltip="${escapeAttribute(identifier)}"><span class="cleara11y-truncated-value__text">${escapeHtml(identifier)}</span></code>
			</div>
		</article>`;
	}

	function truncatedValue(value, label) {
		return `<span class="cleara11y-truncated-value" tabindex="0" aria-label="${escapeAttribute(label + ': ' + value)}" data-tooltip="${escapeAttribute(value)}"><span class="cleara11y-truncated-value__text">${escapeHtml(value)}</span></span>`;
	}

	function handleResultsClick(event) {
		const clear = event.target.closest('[data-clear-results]');
		if (clear) {
			el['clear-filters'].click();
			return;
		}
		const toggle = event.target.closest('.cleara11y-group-toggle');
		if (toggle) {
			const expanded = toggle.getAttribute('aria-expanded') === 'true';
			toggle.setAttribute('aria-expanded', String(!expanded));
			byId(toggle.getAttribute('aria-controls')).hidden = expanded;
			return;
		}
		const button = event.target.closest('.cleara11y-view-occurrence');
		if (button) {
			openOccurrence(Number(button.dataset.occurrenceId), button);
			return;
		}
		const row = event.target.closest('[data-occurrence-row]');
		const interactive = row ? event.target.closest('a, button, input, select, textarea, code, [tabindex]') : null;
		const rowInteractive = interactive && row.contains(interactive);
		const selectedText = window.getSelection ? window.getSelection().toString() : '';
		if (row && !rowInteractive && !selectedText) {
			const control = row.querySelector('.cleara11y-view-occurrence');
			openOccurrence(Number(row.dataset.occurrenceRow), control);
		}
	}

	function syncSelectedRow() {
		document.querySelectorAll('[data-occurrence-row]').forEach(row => {
			const selected = String(row.dataset.occurrenceRow) === String(query.occurrenceId || '');
			row.classList.toggle('is-selected', selected);
			row.querySelector('.cleara11y-view-occurrence')?.setAttribute('aria-expanded', String(selected));
		});
	}

	async function loadDetail(id) {
		if (detailController) detailController.abort();
		detailController = new AbortController();
		window.clearTimeout(detailCloseTimer);
		if (!el['detail-panel'].classList.contains('is-open')) activeDetailTab = 'overview';
		el['detail-panel'].hidden = false;
		window.requestAnimationFrame(() => el['detail-panel'].classList.add('is-open'));
		el['detail-content'].innerHTML = '<div class="cleara11y-loading-state" role="status">Loading occurrence details…</div>';
		activeOccurrence = null;
		try {
			const response = await fetch(API_URL + 'issues/occurrences/' + id, {
				headers: {'X-WP-Nonce': NONCE},
				signal: detailController.signal
			});
			if (!response.ok) throw new Error(await responseMessage(response));
			activeOccurrence = (await response.json()).occurrence;
			renderDetail(activeOccurrence);
			byId('cleara11y-detail-title')?.focus();
		} catch (error) {
			if (error.name !== 'AbortError') {
				el['detail-content'].innerHTML = `<div class="cleara11y-error-state"><h2 id="cleara11y-detail-title" tabindex="-1">Occurrence unavailable</h2>
					<p>${escapeHtml(error.message)}</p><button class="button" data-close-detail>Return to results</button></div>`;
			}
		}
	}

	function renderDetail(item) {
		const evidence = parseEvidence(item.node_evidence);
		el['detail-content'].innerHTML = `
			<div class="cleara11y-detail__header">
				<div class="cleara11y-detail__toolbar">
					<button type="button" class="button-link" data-close-detail>← Close details</button>
					<button type="button" class="button" data-copy-occurrence>Copy link</button>
				</div>
				<header>
					<h2 id="cleara11y-detail-title" tabindex="-1">${escapeHtml(item.rule.title)}</h2>
					<p><code>${escapeHtml(item.rule.id)}</code>
						<span class="cleara11y-badge severity-${escapeHtml(item.severity)}">${escapeHtml(capitalize(item.severity))}</span>
						<span class="cleara11y-status-text">${item.finding_type === 'review' ? 'Unconfirmed' : 'Confirmed'}</span>
						${item.status === 'exception' ? '<span class="cleara11y-status-text is-exception">Current exception</span>' : ''}</p>
					${item.finding_type === 'review' ? '<p class="cleara11y-review-note"><strong>Unconfirmed — manual review required.</strong> The scanner found evidence of a possible failure but could not classify it with full certainty.</p>' : ''}
				</header>
				<div class="cleara11y-detail__tabs" role="tablist" aria-label="Issue detail sections">
					<button type="button" role="tab" id="cleara11y-detail-tab-overview" aria-controls="cleara11y-detail-panel-overview" data-detail-tab="overview">Overview</button>
					<button type="button" role="tab" id="cleara11y-detail-tab-evidence" aria-controls="cleara11y-detail-panel-evidence" data-detail-tab="evidence">Evidence</button>
					<button type="button" role="tab" id="cleara11y-detail-tab-guidance" aria-controls="cleara11y-detail-panel-guidance" data-detail-tab="guidance">Guidance</button>
					<button type="button" role="tab" id="cleara11y-detail-tab-exception" aria-controls="cleara11y-detail-panel-exception" data-detail-tab="exception">Exception</button>
				</div>
			</div>
			<div role="tabpanel" id="cleara11y-detail-panel-overview" aria-labelledby="cleara11y-detail-tab-overview" data-detail-panel="overview">
				<section><h3>Location</h3>
					<p><strong>${escapeHtml(item.page.title)}</strong><br><span class="cleara11y-break-word">${escapeHtml(item.page.url)}</span></p>
					<p class="cleara11y-detail__actions">
						<a class="button" href="${escapeHtml(item.page.url)}" target="_blank" rel="noopener noreferrer">Open page</a>
						${item.inspect_url ? `<a class="button" href="${escapeHtml(item.inspect_url)}" target="_blank" rel="noopener noreferrer">Inspect current page</a>` : ''}
					</p>
					${detailValue('CSS selector', item.selector, true)}
					${detailValue('XPath', item.xpath, true)}
				</section>
				<section><h3>Observation</h3>
					<dl><dt>Occurrence ID</dt><dd>${item.id}</dd>
						<dt>Scan</dt><dd><a data-scan-explorer-url href="${escapeHtml(explorerUrl({scanId: item.scan.id, groupBy: 'rule'}))}">${escapeHtml(item.scan.name)}</a> (${escapeHtml(item.scan.status)})</dd>
						<dt>Captured</dt><dd>${escapeHtml(formatDate(item.scan.scanned_at) || 'Unavailable')}</dd></dl>
				</section>
			</div>
			<div role="tabpanel" id="cleara11y-detail-panel-evidence" aria-labelledby="cleara11y-detail-tab-evidence" data-detail-panel="evidence" hidden>
				<section><h3>Scan evidence</h3>
					<p class="cleara11y-evidence-note">${item.finding_type === 'review' ? 'This evidence was flagged for review by the scanning engine.' : 'This section contains deterministic evidence captured during the scan.'}</p>
					${detailValue('Failure', item.message)}
					${detailValue('Affected HTML', item.html, true, 'pre')}
					${detailValue('Accessible name', item.accessible_name)}
					${detailValue('Relevant text', item.inner_text_snippet)}
					${evidence ? detailValue('Additional captured evidence', evidence, true, 'pre') : '<p class="description">No additional node evidence was captured.</p>'}
				</section>
			</div>
			<div role="tabpanel" id="cleara11y-detail-panel-guidance" aria-labelledby="cleara11y-detail-tab-guidance" data-detail-panel="guidance" hidden>
				<section><h3>Remediation reference</h3>
					<p>${escapeHtml(item.help_text || 'No additional remediation guidance was captured.')}</p>
					<p class="description">Guidance is contextual and should be verified against the component and codebase.</p>
					${item.rule.wcag_criterion ? `<p><strong>WCAG:</strong> ${escapeHtml(item.rule.wcag_criterion)}</p>` : ''}
					${item.rule.help_url ? `<p><a href="${escapeHtml(item.rule.help_url)}" target="_blank" rel="noopener noreferrer">Learn more about this rule</a></p>` : ''}
				</section>
			</div>
			<div role="tabpanel" id="cleara11y-detail-panel-exception" aria-labelledby="cleara11y-detail-tab-exception" data-detail-panel="exception" hidden>
				<section><h3>Exception workflow</h3>
					${item.status === 'exception'
						? '<p>This observation currently matches an active exception.</p>'
						: `<div class="cleara11y-detail__actions">
							<button type="button" class="button" data-structured-exception data-occurrence-id="${item.id}">Create exception…</button>
							<button type="button" class="button" data-temporary-exception data-occurrence-id="${item.id}">Snooze until next scan</button>
						</div>`}
				</section>
			</div>`;
		activateDetailTab(activeDetailTab);
	}

	function detailValue(label, value, code, tag) {
		if (!value) return '';
		const element = tag || (code ? 'code' : 'p');
		const scrollable = element === 'code' || element === 'pre'
			? ` tabindex="0" aria-label="${escapeHtml(label)}"`
			: '';
		return `<div class="cleara11y-detail-value"><h4>${escapeHtml(label)}</h4><${element}${scrollable}>${escapeHtml(value)}</${element}></div>`;
	}

	function explorerUrl(overrides) {
		const url = new URL(window.location.href);
		url.search = '';
		url.searchParams.set('page', 'cleara11y-issues');
		Object.entries(overrides).forEach(([key, value]) => url.searchParams.set(key, value));
		return url.toString();
	}

	function closeDetail(restoreFocus) {
		if (detailController) detailController.abort();
		activeOccurrence = null;
		el['detail-panel'].classList.remove('is-open');
		syncSelectedRow();
		window.clearTimeout(detailCloseTimer);
		const finishClose = () => {
			if (el['detail-panel'].classList.contains('is-open')) return;
			el['detail-panel'].hidden = true;
			el['detail-content'].innerHTML = '';
		};
		if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) finishClose();
		else detailCloseTimer = window.setTimeout(finishClose, 200);
		if (!restoreFocus) return;
		const currentControl = openingOccurrenceId
			? document.querySelector(`.cleara11y-view-occurrence[data-occurrence-id="${openingOccurrenceId}"]`)
			: null;
		if (currentControl) currentControl.focus();
		else if (openingControl && document.contains(openingControl)) openingControl.focus();
	}

	function handleDetailClick(event) {
		const tab = event.target.closest('[data-detail-tab]');
		if (tab) {
			activateDetailTab(tab.dataset.detailTab, true);
			return;
		}
		if (event.target.closest('[data-close-detail]')) {
			requestCloseDetail();
			return;
		}
		if (event.target.closest('[data-copy-occurrence]')) {
			copyCurrentUrl();
			return;
		}
		const quick = event.target.closest('[data-temporary-exception]');
		if (quick) createTemporaryException(Number(quick.dataset.occurrenceId), quick);
		const structured = event.target.closest('[data-structured-exception]');
		if (structured) openStructuredException(Number(structured.dataset.occurrenceId));
	}

	function handleDetailKeydown(event) {
		const tab = event.target.closest('[role="tab"]');
		if (!tab || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
		const tabs = Array.from(el['detail-content'].querySelectorAll('[role="tab"]'));
		const current = tabs.indexOf(tab);
		let next = current;
		if (event.key === 'ArrowLeft') next = (current - 1 + tabs.length) % tabs.length;
		if (event.key === 'ArrowRight') next = (current + 1) % tabs.length;
		if (event.key === 'Home') next = 0;
		if (event.key === 'End') next = tabs.length - 1;
		event.preventDefault();
		activateDetailTab(tabs[next].dataset.detailTab, true);
	}

	function activateDetailTab(tabName, moveFocus = false) {
		const tabs = Array.from(el['detail-content'].querySelectorAll('[data-detail-tab]'));
		const panels = Array.from(el['detail-content'].querySelectorAll('[data-detail-panel]'));
		if (!tabs.length || !panels.some(panel => panel.dataset.detailPanel === tabName)) return;
		activeDetailTab = tabName;
		tabs.forEach(tab => {
			const selected = tab.dataset.detailTab === tabName;
			tab.setAttribute('aria-selected', String(selected));
			tab.tabIndex = selected ? 0 : -1;
			if (selected && moveFocus) tab.focus();
		});
		panels.forEach(panel => { panel.hidden = panel.dataset.detailPanel !== tabName; });
		el['detail-panel'].scrollTop = 0;
	}

	function requestCloseDetail() {
		if (window.history.state && window.history.state.cleara11yExplorer) {
			window.history.back();
			return;
		}
		query = Object.assign({}, query, {occurrenceId: undefined});
		const url = Query.toUrl(query, window.location.href);
		window.history.replaceState(window.history.state, '', url);
		renderContext(lastResult);
		closeDetail(true);
	}

	function clearOccurrenceAndReload() {
		query = Object.assign({}, query, {occurrenceId: undefined});
		const url = Query.toUrl(query, window.location.href);
		window.history.replaceState({cleara11yExplorer: true, scrollY: window.scrollY}, '', url);
		renderContext(lastResult);
		closeDetail(false);
		el['results-region'].focus();
		load();
	}

	function handleExceptionSaved(event) {
		const violationId = Number(event.detail?.violationId || 0);
		if (!violationId || violationId !== Number(query.occurrenceId || 0)) return;
		clearOccurrenceAndReload();
	}

	async function createTemporaryException(id, button) {
		button.disabled = true;
		try {
			const response = await fetch(API_URL + 'exceptions/snooze', {
				method: 'POST',
				headers: {'X-WP-Nonce': NONCE, 'Content-Type': 'application/json'},
				body: JSON.stringify({violation_id: id})
			});
			if (!response.ok) throw new Error(await responseMessage(response));
			clearOccurrenceAndReload();
		} catch (error) {
			window.alert(error.message);
			button.disabled = false;
		}
	}

	function openStructuredException(id) {
		const item = Number(activeOccurrence?.id) === Number(id)
			? activeOccurrence
			: lastResult?.items?.find(candidate => candidate.id === id);
		if (!item || !window.cleara11yWizard) {
			window.alert('The exception wizard is unavailable. Open the Exceptions screen to create an exception.');
			return;
		}
		window.cleara11yWizard.open({
			violation_id: id,
			target_type: item.can_anchor_exception ? 'rule_on_element' : 'rule',
			rule_ids: [item.rule.id],
			element_match: {css_selector: item.selector || ''},
			scope: {scope_type: 'page', url: item.page.url},
			context: {
				page_id: item.page.id,
				page_title: item.page.title,
				post_type: item.page.post_type || '',
				rule_title: item.rule.title || item.rule.id,
				occurrence_fallback: !item.can_anchor_exception
			},
			duration: {duration_type: 'permanent'},
			note: item.message || ''
		});
	}

	function setupEntityFilters() {
		document.querySelectorAll('.cleara11y-entity-filter').forEach(container => {
			const type = container.dataset.filterType;
			const input = container.querySelector('input');
			const list = container.querySelector('[role="listbox"]');
			let timer;
			input.addEventListener('focus', () => loadOptions(type, input, list));
			input.addEventListener('input', () => {
				// Check if browser's X button was used to clear the input
				if (input.dataset.selectedId && input.value === '') {
					const key = type === 'page' ? 'pageId' : type === 'scan' ? 'scanId' : 'ruleId';
					delete input.dataset.selectedId;
					update({[key]: ''});
					// Refresh dropdown to show all options
					loadOptions(type, input, list);
					return;
				}
				window.clearTimeout(timer);
				timer = window.setTimeout(() => loadOptions(type, input, list), 250);
			});
			input.addEventListener('keydown', event => {
				if (event.key === 'ArrowDown' && !list.hidden) {
					event.preventDefault();
					list.querySelector('button')?.focus();
				} else if (event.key === 'Escape') {
					hideOptions(input, list);
				}
			});
			list.addEventListener('keydown', event => {
				const buttons = Array.from(list.querySelectorAll('button'));
				const index = buttons.indexOf(document.activeElement);
				if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
					event.preventDefault();
					const next = event.key === 'ArrowDown' ? Math.min(buttons.length - 1, index + 1) : Math.max(0, index - 1);
					buttons[next]?.focus();
				} else if (event.key === 'Escape') {
					hideOptions(input, list);
					input.focus();
				}
			});
			list.addEventListener('click', event => {
				const option = event.target.closest('button[data-option-id]');
				if (!option) return;
				const key = type === 'page' ? 'pageId' : type === 'scan' ? 'scanId' : 'ruleId';
				const value = type === 'rule' ? option.dataset.optionId : Number(option.dataset.optionId);
				input.value = option.textContent.trim();
				hideOptions(input, list);
				const changes = {[key]: value};
				if (type === 'rule') changes.groupBy = 'page';
				if (type === 'page' || type === 'scan') changes.groupBy = 'rule';
				update(changes);
			});
			document.addEventListener('click', event => {
				if (!container.contains(event.target)) hideOptions(input, list);
			});
		});
	}

	async function loadOptions(type, input, list) {
		const response = await fetch(API_URL + 'issues/filter-options?' + new URLSearchParams({type, search: input.value}), {
			headers: {'X-WP-Nonce': NONCE}
		});
		if (!response.ok) return;
		const options = (await response.json()).options || [];

		// Get the currently selected value for this type
		const queryKey = type === 'page' ? 'pageId' : type === 'scan' ? 'scanId' : 'ruleId';
		const selectedValue = query[queryKey];

		list.innerHTML = options.length
			? options.map(option => {
				const isSelected = String(option.id) === String(selectedValue);
				const selectedAttr = isSelected ? ' aria-selected="true"' : '';
				const selectedClass = isSelected ? ' class="selected"' : '';
				return `<li role="option"${selectedAttr}${selectedClass}><button type="button" data-option-id="${escapeHtml(option.id)}">${escapeHtml(option.label)}</button></li>`;
			}).join('')
			: '<li class="description">No matches</li>';
		list.hidden = false;
		input.setAttribute('aria-expanded', 'true');
	}

	function hideOptions(input, list) {
		list.hidden = true;
		input.setAttribute('aria-expanded', 'false');
	}

	function renderError(message) {
		el['issues-container'].removeAttribute('aria-busy');
		el['issues-container'].innerHTML = `<div class="cleara11y-error-state" role="alert">
			<h3>${escapeHtml(cleara11yData.strings.error)}</h3><p>${escapeHtml(message)}</p>
			<button type="button" class="button" data-retry-results>Retry</button></div>`;
		el['issues-container'].querySelector('[data-retry-results]').addEventListener('click', () => load());
	}

	async function copyCurrentUrl() {
		try {
			await navigator.clipboard.writeText(window.location.href);
			el['results-announcer'].textContent = 'Link copied.';
		} catch (error) {
			window.prompt('Copy this link:', window.location.href);
		}
	}

	async function responseMessage(response) {
		try {
			const body = await response.json();
			return body.message || body.code || `Request failed (${response.status})`;
		} catch (error) {
			return `Request failed (${response.status})`;
		}
	}

	function parseEvidence(value) {
		if (!value) return '';
		try {
			return JSON.stringify(JSON.parse(value), null, 2);
		} catch (error) {
			return String(value);
		}
	}

	function pathname(url) {
		try {
			const parsed = new URL(url);
			return parsed.pathname + parsed.search;
		} catch (error) {
			return url;
		}
	}

	const capitalize = value => value ? value.charAt(0).toUpperCase() + value.slice(1) : '';
	const plural = (count, noun) => count === 1 ? noun : noun + 's';
	const safeId = value => String(value).replace(/[^a-zA-Z0-9_-]/g, '-');

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
	else init();
})();
