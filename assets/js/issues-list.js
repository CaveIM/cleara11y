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
	let searchTimer = null;

	const el = {};
	const byId = id => document.getElementById(id);
	const escapeHtml = value => {
		const node = document.createElement('span');
		node.textContent = value === null || value === undefined ? '' : String(value);
		return node.innerHTML;
	};
	const formatDate = value => value ? new Date(value.replace(' ', 'T') + (value.includes('Z') ? '' : 'Z')).toLocaleString() : '';

	function init() {
		[
			'explorer-title', 'explorer-summary', 'breadcrumb-current', 'snapshot-banner',
			'filter-status', 'filter-severity', 'search-issues', 'group-by',
			'sort', 'clear-filters', 'issues-container', 'results-region',
			'results-announcer', 'pagination', 'prev-page', 'next-page', 'page-info',
			'detail-panel', 'detail-content'
		].forEach(id => { el[id] = byId('cleara11y-' + id); });

		bindControls();
		setupEntityFilters();
		syncControls();
		load();
		window.addEventListener('popstate', () => {
			const previousOccurrence = query.occurrenceId;
			query = Query.parse(window.location.href);
			syncControls();
			load({restoreFocus: previousOccurrence && !query.occurrenceId});
		});
	}

	function bindControls() {
		el['filter-status'].addEventListener('change', event => update({status: event.target.value}));
		el['filter-severity'].addEventListener('change', event => update({severity: event.target.value}));
		el['group-by'].addEventListener('change', event => update({groupBy: event.target.value}));
		el.sort.addEventListener('change', event => update({sort: event.target.value}));
		el['search-issues'].addEventListener('input', event => {
			window.clearTimeout(searchTimer);
			searchTimer = window.setTimeout(() => update({search: event.target.value.trim()}), 350);
		});
		el['clear-filters'].addEventListener('click', () => {
			query = {
				status: 'active', severity: '', ruleId: '', pageId: undefined,
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
	}

	function update(changes, replace) {
		query = Object.assign({}, query, changes);
		if (!Object.prototype.hasOwnProperty.call(changes, 'resultsPage')) query.resultsPage = 1;
		if (!Object.prototype.hasOwnProperty.call(changes, 'occurrenceId')) query.occurrenceId = undefined;
		if (query.scanId) query.status = 'all';
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

	function syncControls() {
		el['filter-status'].value = query.status;
		el['filter-status'].disabled = Boolean(query.scanId);
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
			if (!value) continue;

			const input = document.getElementById(entityType.inputId);
			if (!input) continue;

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
			const response = await fetch(API_URL + 'issues/occurrences?' + Query.apiParams(query), {
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
		} else if (query.status === 'ignored') {
			title = 'Accessibility exceptions';
		} else if (query.status === 'all') {
			title = 'All accessibility issues';
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
			const filtered = Boolean(query.search || query.severity || query.ruleId || query.pageId || query.status === 'ignored');
			const text = query.scanId
				? cleara11yData.strings.noScanIssues
				: (filtered ? cleara11yData.strings.noFilteredIssues : cleara11yData.strings.noIssues);
			el['issues-container'].innerHTML = `<div class="cleara11y-empty-state"><h3>${escapeHtml(text)}</h3>` +
				(filtered ? '<button type="button" class="button" data-clear-results>Clear filters</button>' : '') + '</div>';
		} else if (query.groupBy === 'none') {
			el['issues-container'].innerHTML = `<div class="cleara11y-result-list">${items.map(renderRow).join('')}</div>`;
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
	}

	function renderGroup(items) {
		const first = items[0];
		const label = query.groupBy === 'page' ? first.page.title : first.rule.title;
		const id = 'cleara11y-group-' + (query.groupBy === 'page' ? first.page.id : safeId(first.rule.id));
		return `<section class="cleara11y-result-group">
			<h3><button type="button" class="cleara11y-group-toggle" aria-expanded="true" aria-controls="${id}">
				<span>${escapeHtml(label)}</span><span class="count">${items.length} on this page</span>
			</button></h3>
			<div id="${id}" class="cleara11y-result-list">${items.map(renderRow).join('')}</div>
		</section>`;
	}

	function renderRow(item) {
		return `<article class="cleara11y-result-row severity-${escapeHtml(item.severity)}" data-occurrence-row="${item.id}">
			<div class="cleara11y-result-row__main">
				<div class="cleara11y-result-row__heading">
					<h4>${escapeHtml(item.rule.title)}</h4>
					<span class="cleara11y-badge severity-${escapeHtml(item.severity)}">${escapeHtml(capitalize(item.severity))}</span>
					<span class="cleara11y-status-text">${item.status === 'ignored' ? 'Exception' : (item.finding_type === 'review' ? 'Needs review' : 'Confirmed')}</span>
				</div>
				<p>${escapeHtml(item.message || item.help_text)}</p>
				<p class="cleara11y-result-row__meta"><strong>${escapeHtml(item.page.title)}</strong>
					<span>${escapeHtml(pathname(item.page.url))}</span></p>
				${item.selector ? `<code class="cleara11y-selector" tabindex="0" aria-label="Affected element selector">${escapeHtml(item.selector)}</code>` : ''}
			</div>
			<button type="button" class="button cleara11y-view-occurrence" data-occurrence-id="${item.id}">View details</button>
		</article>`;
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
			openingControl = button;
			openingOccurrenceId = button.dataset.occurrenceId;
			update({occurrenceId: Number(button.dataset.occurrenceId)}, false);
		}
	}

	async function loadDetail(id) {
		if (detailController) detailController.abort();
		detailController = new AbortController();
		el['detail-panel'].hidden = false;
		el['detail-content'].innerHTML = '<div class="cleara11y-loading-state" role="status">Loading occurrence details…</div>';
		try {
			const response = await fetch(API_URL + 'issues/occurrences/' + id, {
				headers: {'X-WP-Nonce': NONCE},
				signal: detailController.signal
			});
			if (!response.ok) throw new Error(await responseMessage(response));
			renderDetail((await response.json()).occurrence);
			if (window.matchMedia('(max-width: 782px)').matches) {
				el['detail-panel'].scrollIntoView({block: 'start'});
			}
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
			<div class="cleara11y-detail__toolbar">
				<button type="button" class="button-link" data-close-detail>← Back to results</button>
				<button type="button" class="button" data-copy-occurrence>Copy link</button>
			</div>
			<header>
				<h2 id="cleara11y-detail-title" tabindex="-1">${escapeHtml(item.rule.title)}</h2>
				<p><code>${escapeHtml(item.rule.id)}</code>
					<span class="cleara11y-badge severity-${escapeHtml(item.severity)}">${escapeHtml(capitalize(item.severity))}</span>
					<span class="cleara11y-status-text">${item.status === 'ignored' ? 'Current exception' : (item.finding_type === 'review' ? 'Needs review' : 'Confirmed issue')}</span></p>
				${item.finding_type === 'review' ? '<p class="cleara11y-review-note"><strong>Manual review recommended.</strong> The scanner found evidence of a possible failure but could not classify it with full certainty.</p>' : ''}
			</header>
			<section><h3>Location</h3>
				<p><strong>${escapeHtml(item.page.title)}</strong><br><span class="cleara11y-break-word">${escapeHtml(item.page.url)}</span></p>
				<p class="cleara11y-detail__actions">
					<a class="button" href="${escapeHtml(item.page.url)}" target="_blank" rel="noopener noreferrer">Open page</a>
					${item.inspect_url ? `<a class="button" href="${escapeHtml(item.inspect_url)}" target="_blank" rel="noopener noreferrer">Inspect current page</a>` : ''}
				</p>
				${detailValue('CSS selector', item.selector, true)}
				${detailValue('XPath', item.xpath, true)}
			</section>
			<section><h3>Scan evidence</h3>
				<p class="cleara11y-evidence-note">${item.finding_type === 'review' ? 'This evidence was flagged for review by the scanning engine.' : 'This section contains deterministic evidence captured during the scan.'}</p>
				${detailValue('Failure', item.message)}
				${detailValue('Affected HTML', item.html, true, 'pre')}
				${detailValue('Accessible name', item.accessible_name)}
				${detailValue('Relevant text', item.inner_text_snippet)}
				${evidence ? detailValue('Additional captured evidence', evidence, true, 'pre') : '<p class="description">No additional node evidence was captured.</p>'}
			</section>
			<section><h3>Remediation reference</h3>
				<p>${escapeHtml(item.help_text || 'No additional remediation guidance was captured.')}</p>
				<p class="description">Guidance is contextual and should be verified against the component and codebase.</p>
				${item.rule.wcag_criterion ? `<p><strong>WCAG:</strong> ${escapeHtml(item.rule.wcag_criterion)}</p>` : ''}
				${item.rule.help_url ? `<p><a href="${escapeHtml(item.rule.help_url)}" target="_blank" rel="noopener noreferrer">Learn more about this rule</a></p>` : ''}
			</section>
			<section><h3>Observation</h3>
				<dl><dt>Occurrence ID</dt><dd>${item.id}</dd>
					<dt>Scan</dt><dd><a data-scan-explorer-url href="${escapeHtml(explorerUrl({scanId: item.scan.id, groupBy: 'rule'}))}">${escapeHtml(item.scan.name)}</a> (${escapeHtml(item.scan.status)})</dd>
					<dt>Captured</dt><dd>${escapeHtml(formatDate(item.scan.scanned_at) || 'Unavailable')}</dd></dl>
			</section>
			<section><h3>Exception workflow</h3>
				${item.status === 'ignored'
					? '<p>This observation currently matches an active exception.</p>'
					: `<div class="cleara11y-detail__actions">
						<button type="button" class="button" data-structured-exception data-occurrence-id="${item.id}">Create exception…</button>
						<button type="button" class="button" data-temporary-exception data-occurrence-id="${item.id}">Temporary exception</button>
					</div>`}
			</section>`;
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
		el['detail-panel'].hidden = true;
		el['detail-content'].innerHTML = '';
		if (!restoreFocus) return;
		const currentControl = openingOccurrenceId
			? document.querySelector(`.cleara11y-view-occurrence[data-occurrence-id="${openingOccurrenceId}"]`)
			: null;
		if (currentControl) currentControl.focus();
		else if (openingControl && document.contains(openingControl)) openingControl.focus();
	}

	function handleDetailClick(event) {
		if (event.target.closest('[data-close-detail]')) {
			if (window.history.state && window.history.state.cleara11yExplorer) window.history.back();
			else update({occurrenceId: undefined}, true);
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

	async function createTemporaryException(id, button) {
		button.disabled = true;
		try {
			const response = await fetch(API_URL + 'ignores/quick', {
				method: 'POST',
				headers: {'X-WP-Nonce': NONCE, 'Content-Type': 'application/json'},
				body: JSON.stringify({violation_id: id})
			});
			if (!response.ok) throw new Error(await responseMessage(response));
			await load();
		} catch (error) {
			window.alert(error.message);
			button.disabled = false;
		}
	}

	function openStructuredException(id) {
		const item = lastResult?.items?.find(candidate => candidate.id === id);
		if (!item || !window.cleara11yWizard) {
			window.alert('The exception wizard is unavailable. Open the Exceptions screen to create an exception.');
			return;
		}
		const state = window.cleara11yWizard.state;
		state.data.target_type = 'rule_on_element';
		state.data.rule_ids = [item.rule.id];
		state.data.element_match = {css_selector: item.selector || ''};
		state.data.scope = {scope_type: 'page', url: item.page.url};
		state.data.duration = {duration_type: 'permanent'};
		state.data.note = item.message || '';
		window.cleara11yWizard.open();
	}

	function setupEntityFilters() {
		document.querySelectorAll('.cleara11y-entity-filter').forEach(container => {
			const type = container.dataset.filterType;
			const input = container.querySelector('input');
			const list = container.querySelector('[role="listbox"]');
			let timer;
			input.addEventListener('focus', () => loadOptions(type, input, list));
			input.addEventListener('input', () => {
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
