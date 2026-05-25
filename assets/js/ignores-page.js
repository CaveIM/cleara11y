/**
 * ClearA11y Ignores Management Page
 */
(function($) {
	'use strict';

	// Rule titles mapping for human-readable display
	const ruleTitles = {
		// WCAG 2.1 Level A
		'color-contrast': 'Links must have discernible text',
		'image-alt': 'Images must have alternate text',
		'label': 'Form field must have a label',
		'button-name': 'Buttons must have discernible text',
		'link-name': 'Links must have discernible text',
		'list': 'Lists must be properly structured',
		'listitem': 'List items must be in list containers',
		// WCAG 2.1 Level AA
		'aria-roles': 'ARIA roles must be valid',
		'aria-allowed-attr': 'ARIA attributes must be valid for the role',
		'aria-required-attr': 'Required ARIA attributes must be present',
		'aria-required-children': 'Elements with ARIA roles must have required children',
		'aria-valid-attr-value': 'ARIA attribute values must be valid',
		'aria-valid-attr': 'ARIA attributes must be valid',
		'aria-unsupported-elements': 'ARIA must not be used on unsupported elements',
		'duplicate-id': 'Elements must have unique id attributes',
		'heading-order': 'Headings must be in logical order',
		'empty-heading': 'Headings must not be empty',
		'landmark-one-main': 'Page must have one main landmark',
		'landmark-unique': 'Landmarks must have unique labels',
		region: 'Page must have landmark regions',
		// Form and input rules
		'select-name': 'Form select must have a label',
		'textbox-label': 'Text input must have a label',
		'textarea-label': 'Textarea must have a label',
		'checkbox-label': 'Checkbox must have a label',
		'radio-label': 'Radio button must have a label',
		// Table rules
		'table-duplicate-name': 'Tables must not have duplicate names',
		'th-has-data-cells': 'Table headers must have data cells',
		'td-headers-attr': 'Table cells must use headers attribute correctly',
		// Language and text rules
		'has-lang': 'Page must have valid language attribute',
		'valid-lang': 'Language attribute must have valid value',
		// Media rules
		'video-caption': 'Videos must have captions',
		'audio-description': 'Audio content must have description',
		// Focus rules
		'focus-order-semantics': 'Focus must follow logical order',
		'tabindex': 'tabindex attribute must be used correctly',
		// Frame rules
		'title-unique': 'Frames must have unique titles',
		'frame-title': 'Frames must have title attribute',
		// Other rules
		'bypass': 'Page must have skip navigation link',
		'document-title': 'Page must have title',
		'meta-viewport': 'Viewport meta tag must be set correctly',
		'html-has-lang': 'HTML element must have lang attribute',
		'page-has-heading-one': 'Page must have at least one h1',
		'scope-valid': 'Scope attribute must be used correctly',
		// Default fallback
		'unknown': 'Unknown accessibility rule'
	};

	// State
	const state = {
		currentTab: 'active',
		currentStatus: 'active',
		currentPage: 1,
		perPage: 20,
		totalPages: 1,
		hideSystemGenerated: false,
		loading: false
	};

	// DOM Elements
	let $tbody, $pagination, $tabContent;

	// Initialize
	$(document).ready(function() {
		initDOM();
		initTabs();
		initFilters();
		loadRules();

		// Event listeners for row actions
		$(document).on('click', '.cleara11y-view-ignore', viewIgnore);
		$(document).on('click', '.cleara11y-edit-ignore', editIgnore);
		$(document).on('click', '.cleara11y-disable-ignore', disableIgnore);
		$(document).on('click', '.cleara11y-enable-ignore', enableIgnore);
		$(document).on('click', '.cleara11y-delete-ignore', deleteIgnore);
		$(document).on('click', '.cleara11y-modal-close', closeModals);
		$('#cleara11y-create-ignore').on('click', openCreateWizard);
	});

	function initDOM() {
		$tbody = $('#cleara11y-ignores-table-body');
		$pagination = $('#cleara11y-ignores-pagination');
		$tabContent = $('.cleara11y-tab-content');
	}

	function initTabs() {
		$('.nav-tab[data-tab]').on('click', function(e) {
			e.preventDefault();
			const $tab = $(this);
			const tabName = $tab.data('tab');

			// Update active tab
			$('.nav-tab').removeClass('nav-tab-active');
			$tab.addClass('nav-tab-active');

			// Show corresponding panel
			$('.tab-panel').removeClass('active').hide();
			if (tabName === 'audit') {
				$('#tab-audit').addClass('active').show();
				loadAuditLog();
			} else {
				$('#tab-rules').addClass('active').show();
				state.currentStatus = tabName;
				state.currentPage = 1;
				loadRules();
			}
		});
	}

	function initFilters() {
		$('#cleara11y-hide-system-ignores').on('change', function() {
			state.hideSystemGenerated = $(this).is(':checked');
			state.currentPage = 1;
			loadRules();
		});

		$('#cleara11y-refresh-ignores').on('click', function() {
			loadRules();
		});

		$('#cleara11y-refresh-audit').on('click', function() {
			loadAuditLog();
		});
	}

	function loadRules() {
		if (state.loading) return;
		state.loading = true;

		showLoading();

		const params = {
			status: state.currentStatus,
			page: state.currentPage,
			per_page: state.perPage
		};

		if (state.hideSystemGenerated) {
			params.system_generated = false;
		}

		$.ajax({
			url: cleara11yIgnores.apiUrl,
			data: params,
			method: 'GET',
			beforeSend: function(xhr) {
				xhr.setRequestHeader('X-WP-Nonce', cleara11yIgnores.nonce);
			},
			success: function(response) {
				renderRules(response.data);
				updateCounts(response.counts);
				updatePagination(response.total, response.page, response.per_page, response.total_pages);
			},
			error: function() {
				showError(cleara11yIgnores.strings.error);
			},
			complete: function() {
				state.loading = false;
			}
		});
	}

	function renderRules(rules) {
		if (!rules || rules.length === 0) {
			showEmptyState();
			return;
		}

		let html = '';
		rules.forEach(function(rule) {
			html += '<tr>';

			// Ignore Rule column (30%) - Shows rule titles and IDs
			html += '<td style="width: 30%;">';
			// Show readable titles
			html += '<div style="margin-bottom: 5px; font-size: 13px; color: #646970;">';
			html += '<strong>Rule:</strong> ' + esc_html(rule.rule_ids.map(function(id) {
				return ruleTitles[id] || id;
			}).join(', '));
			html += '</div>';
			// Show rule IDs
			html += '<div style="font-size: 11px; color: #646970;">';
			html += '<strong>rule-id:</strong> ' + esc_html(rule.rule_ids.join(', '));
			html += '</div>';
			// Show note if available
			if (rule.note) {
				html += '<div class="cleara11y-note">' + esc_html(rule.note) + '</div>';
			}
			html += '</td>';

			// Target column (15%) - Shows target type and selector
			html += '<td style="width: 15%;">';
			html += '<div>' + esc_html(rule.target_type) + '</div>';
			// Show selector if available
			if (rule.element_match && rule.element_match.css_selector) {
				html += '<div style="font-size: 11px; color: #646970; margin-top: 3px; word-break: break-all;">';
				html += esc_html(rule.element_match.css_selector);
				html += '</div>';
			}
			html += '</td>';

			// Scope column (15%) - Shows scope type and details
			html += '<td style="width: 15%;">';
			html += '<div class="cleara11y-rule-scope">' + esc_html(getScopeLabel(rule.scope)) + '</div>';
			html += '</td>';

			// Duration column (10%)
			html += '<td style="width: 10%;">';
			html += '<span class="cleara11y-rule-duration cleara11y-duration-' + rule.duration.duration_type + '">';
			html += esc_html(getDurationLabel(rule.duration));
			html += '</span>';
			html += '</td>';

			// Reason column (10%)
			html += '<td style="width: 10%;">';
			if (rule.reason_category) {
				html += '<span class="cleara11y-reason-category">' + esc_html(rule.reason_category) + '</span>';
			}
			html += '</td>';

			// Created By column (10%)
			html += '<td style="width: 10%;">';
			html += esc_html(rule.created_by_name || 'System');
			html += '<br>';
			html += '<small>' + formatDate(rule.created_at) + '</small>';
			html += '</td>';

			// Actions column (10%)
			html += '<td style="width: 10%;">';
			html += '<div class="cleara11y-row-actions">';
			html += '<button type="button" class="button button-small cleara11y-view-ignore" data-id="' + rule.id + '">';
			html += 'View';
			html += '</button>';

			if (rule.status === 'active') {
				html += '<button type="button" class="button button-small cleara11y-disable-ignore" data-id="' + rule.id + '">';
				html += 'Disable';
				html += '</button>';
			} else {
				html += '<button type="button" class="button button-small cleara11y-enable-ignore" data-id="' + rule.id + '">';
				html += 'Enable';
				html += '</button>';
			}

			html += '<button type="button" class="button button-small cleara11y-delete-ignore" data-id="' + rule.id + '">';
			html += 'Delete';
			html += '</button>';
			html += '</div>';
			html += '</td>';

			html += '</tr>';
		});

		$tbody.html(html);
	}

	function loadAuditLog() {
		$.ajax({
			url: cleara11yIgnores.apiUrl + '/audit/all',
			method: 'GET',
			beforeSend: function(xhr) {
				xhr.setRequestHeader('X-WP-Nonce', cleara11yIgnores.nonce);
			},
			success: function(response) {
				renderAuditLog(response.data);
			},
			error: function() {
				showError(cleara11yIgnores.strings.error);
			}
		});
	}

	function renderAuditLog(logEntries) {
		const $auditBody = $('#cleara11y-audit-table-body');

		if (!logEntries || logEntries.length === 0) {
			$auditBody.html('<tr><td colspan="5" style="text-align: center; padding: 40px;">No audit log entries found.</td></tr>');
			return;
		}

		let html = '';
		logEntries.forEach(function(entry) {
			html += '<tr>';
			html += '<td>' + esc_html(entry.event_label) + '</td>';
			html += '<td>';
			if (entry.ignore_rule_id) {
				html += '<span class="cleara11y-ignore-rule-id">ID: ' + esc_html(entry.ignore_rule_id.substring(0, 8)) + '...</span>';
			} else {
				html += '-';
			}
			html += '</td>';
			html += '<td>' + esc_html(entry.actor_name || 'System') + '</td>';
			html += '<td>' + formatDate(entry.timestamp) + '</td>';
			html += '<td>';
			if (entry.metadata) {
				html += '<code>' + JSON.stringify(entry.metadata).substring(0, 50) + '...</code>';
			}
			html += '</td>';
			html += '</tr>';
		});

		$auditBody.html(html);
	}

	function updateCounts(counts) {
		$('#cleara11y-active-count').text('(' + counts.active + ')');
		$('#cleara11y-expired-count').text('(' + counts.expired + ')');
		$('#cleara11y-disabled-count').text('(' + counts.disabled + ')');
	}

	function updatePagination(total, page, perPage, totalPages) {
		state.totalPages = totalPages;
		state.currentPage = page;

		if (total > perPage) {
			$pagination.show();
			$('#cleara11y-ignores-displaying-num').text(
				('Showing %1$s of %2$s items').replace('%1$s', (page - 1) * perPage + 1).replace('%2$s', Math.min(page * perPage, total))
			);
			$('#cleara11y-ignores-current-page').val(page);
			$('#cleara11y-ignores-total-pages').text(totalPages);

			// Update button states
			$('#cleara11y-ignores-first-page, #cleara11y-ignores-prev-page').prop('disabled', page === 1);
			$('#cleara11y-ignores-next-page, #cleara11y-ignores-last-page').prop('disabled', page === totalPages);
		} else {
			$pagination.hide();
		}
	}

	function showLoading() {
		$tbody.html('<tr><td colspan="7" style="text-align: center; padding: 40px;"><span class="spinner is-active"></span>Loading...</td></tr>');
	}

	function showEmptyState() {
		let emptyTemplate = $('#cleara11y-empty-state-template').html();
		let message = '';

		switch (state.currentStatus) {
			case 'active':
				message = 'No active ignore rules found.';
				break;
			case 'expired':
				message = 'No expired ignore rules found.';
				break;
			case 'disabled':
				message = 'No disabled ignore rules found.';
				break;
		}

		emptyTemplate = emptyTemplate.replace('data-empty-message', message);
		$tbody.html(emptyTemplate);
	}

	function showError(message) {
		$tbody.html('<tr><td colspan="7" style="text-align: center; padding: 40px; color: #d63638;">' + esc_html(message) + '</td></tr>');
	}

	function viewIgnore(e) {
		e.preventDefault();
		const ruleId = $(this).data('id');

		$.ajax({
			url: cleara11yIgnores.apiUrl + '/' + ruleId,
			method: 'GET',
			beforeSend: function(xhr) {
				xhr.setRequestHeader('X-WP-Nonce', cleara11yIgnores.nonce);
			},
			success: function(rule) {
				showRuleDetailModal(rule);
			},
			error: function() {
				alert(cleara11yIgnores.strings.error);
			}
		});
	}

	function showRuleDetailModal(rule) {
		let html = '<div class="cleara11y-detail-section">';
		html += '<h3>Rule Details</h3>';
		html += '<div class="cleara11y-detail-row"><span class="cleara11y-detail-label">Label:</span><span class="cleara11y-detail-value">' + esc_html(rule.label) + '</span></div>';
		html += '<div class="cleara11y-detail-row"><span class="cleara11y-detail-label">Target Type:</span><span class="cleara11y-detail-value">' + esc_html(rule.target_type) + '</span></div>';
		html += '<div class="cleara11y-detail-row"><span class="cleara11y-detail-label">Scope:</span><span class="cleara11y-detail-value">' + esc_html(getScopeLabel(rule.scope)) + '</span></div>';
		html += '<div class="cleara11y-detail-row"><span class="cleara11y-detail-label">Duration:</span><span class="cleara11y-detail-value">' + esc_html(getDurationLabel(rule.duration)) + '</span></div>';
		if (rule.reason_category) {
			html += '<div class="cleara11y-detail-row"><span class="cleara11y-detail-label">Reason:</span><span class="cleara11y-detail-value">' + esc_html(rule.reason_category) + '</span></div>';
		}
		if (rule.note) {
			html += '<div class="cleara11y-detail-row"><span class="cleara11y-detail-label">Note:</span><span class="cleara11y-detail-value">' + esc_html(rule.note) + '</span></div>';
		}
		html += '<div class="cleara11y-detail-row"><span class="cleara11y-detail-label">Matched Violations:</span><span class="cleara11y-detail-value">' + rule.match_count + '</span></div>';
		html += '</div>';

		$('#cleara11y-ignore-detail-body').html(html);
		$('#cleara11y-ignore-detail-modal').show();
	}

	function closeModals() {
		$('.cleara11y-modal-backdrop').parent().hide();
	}

	function disableIgnore(e) {
		e.preventDefault();
		const ruleId = $(this).data('id');

		if (!confirm(cleara11yIgnores.strings.confirmDisable)) {
			return;
		}

		$.ajax({
			url: cleara11yIgnores.apiUrl + '/' + ruleId + '/disable',
			method: 'POST',
			beforeSend: function(xhr) {
				xhr.setRequestHeader('X-WP-Nonce', cleara11yIgnores.nonce);
			},
			success: function() {
				loadRules();
			},
			error: function() {
				alert(cleara11yIgnores.strings.error);
			}
		});
	}

	function enableIgnore(e) {
		e.preventDefault();
		const ruleId = $(this).data('id');

		$.ajax({
			url: cleara11yIgnores.apiUrl + '/' + ruleId + '/enable',
			method: 'POST',
			beforeSend: function(xhr) {
				xhr.setRequestHeader('X-WP-Nonce', cleara11yIgnores.nonce);
			},
			success: function() {
				loadRules();
			},
			error: function() {
				alert(cleara11yIgnores.strings.error);
			}
		});
	}

	function deleteIgnore(e) {
		e.preventDefault();
		const ruleId = $(this).data('id');

		if (!confirm(cleara11yIgnores.strings.confirmDelete)) {
			return;
		}

		$.ajax({
			url: cleara11yIgnores.apiUrl + '/' + ruleId,
			method: 'DELETE',
			beforeSend: function(xhr) {
				xhr.setRequestHeader('X-WP-Nonce', cleara11yIgnores.nonce);
			},
			success: function() {
				closeModals();
				loadRules();
			},
			error: function() {
				alert(cleara11yIgnores.strings.error);
			}
		});
	}

	function editIgnore() {
		closeModals();
		// TODO: Open edit wizard
		alert('Edit functionality coming soon.');
	}

	// Wizard state
	const wizardState = {
		currentStep: 1,
		totalSteps: 5,
		data: {
			target_type: '',
			rule_ids: [],
			element_match: {},
			scope: { scope_type: '' },
			duration: { duration_type: '' },
			reason_category: '',
			note: ''
		},
		impactPreview: null
	};

	// Helper function to get localized string
	function str(key) {
		return cleara11yIgnores.strings[key] || key;
	}

	function openCreateWizard() {
		resetWizard();
		renderWizardModal();
		showWizardStep(1);
	}

	function resetWizard() {
		wizardState.currentStep = 1;
		wizardState.data = {
			target_type: '',
			rule_ids: [],
			element_match: {},
			scope: { scope_type: '' },
			duration: { duration_type: '' },
			reason_category: '',
			note: ''
		};
		wizardState.impactPreview = null;
	}

	function renderWizardModal() {
		const wizardHtml = `
			<div id="cleara11y-wizard-modal" style="display: none;">
				<div class="cleara11y-modal-backdrop"></div>
				<div class="cleara11y-modal-content cleara11y-wizard-content">
					<div class="cleara11y-modal-header">
						<h2>${esc_html(str('createWizardTitle'))}</h2>
						<button type="button" class="cleara11y-modal-close">
							<span class="dashicons dashicons-no-alt"></span>
						</button>
					</div>

					<!-- Progress Bar -->
					<div class="cleara11y-wizard-progress">
						<div class="cleara11y-progress-bar">
							<div class="cleara11y-progress-fill" id="cleara11y-progress-fill"></div>
						</div>
						<div class="cleara11y-progress-steps">
							<span class="cleara11y-progress-step active" data-step="1">1</span>
							<span class="cleara11y-progress-step" data-step="2">2</span>
							<span class="cleara11y-progress-step" data-step="3">3</span>
							<span class="cleara11y-progress-step" data-step="4">4</span>
							<span class="cleara11y-progress-step" data-step="5">5</span>
						</div>
						<div class="cleara11y-progress-labels">
							<span data-step="1">${esc_html(str('target'))}</span>
							<span data-step="2">${esc_html(str('scope'))}</span>
							<span data-step="3">${esc_html(str('duration'))}</span>
							<span data-step="4">${esc_html(str('reason'))}</span>
							<span data-step="5">${esc_html(str('step5Title'))}</span>
						</div>
					</div>

					<!-- Wizard Steps -->
					<div class="cleara11y-modal-body cleara11y-wizard-body">
						${getStepContent(1)}
						${getStepContent(2)}
						${getStepContent(3)}
						${getStepContent(4)}
						${getStepContent(5)}
					</div>

					<!-- Wizard Footer -->
					<div class="cleara11y-modal-footer cleara11y-wizard-footer">
						<button type="button" class="button button-secondary cleara11y-modal-close" id="cleara11y-wizard-cancel">
							${esc_html(str('cancel'))}
						</button>
						<button type="button" class="button button-primary" id="cleara11y-wizard-next" disabled>
							${esc_html(str('next'))}
						</button>
						<button type="button" class="button button-primary" id="cleara11y-wizard-create" style="display: none;">
							${esc_html(str('createRule'))}
						</button>
					</div>
				</div>
			</div>
		`;

		// Remove existing wizard if any
		$('#cleara11y-wizard-modal').remove();

		// Add wizard to page
		$('body').append(wizardHtml);

		// Bind wizard events
		bindWizardEvents();

		// Show wizard
		$('#cleara11y-wizard-modal').show();
	}

	function getStepContent(step) {
		switch(step) {
			case 1:
				return `
					<div class="cleara11y-wizard-step" data-step="1">
						<h3>${esc_html(str('step1Title'))}</h3>
						<p class="description">${esc_html(str('step1Desc'))}</p>

						<div class="cleara11y-target-options">
							<label class="cleara11y-radio-card">
								<input type="radio" name="target_type" value="rule">
								<div class="cleara11y-radio-card-content">
									<strong>${esc_html(str('ruleOnly'))}</strong>
									<p>${esc_html(str('ruleOnlyDesc'))}</p>
								</div>
							</label>

							<label class="cleara11y-radio-card">
								<input type="radio" name="target_type" value="element">
								<div class="cleara11y-radio-card-content">
									<strong>${esc_html(str('elementOnly'))}</strong>
									<p>${esc_html(str('elementOnlyDesc'))}</p>
								</div>
							</label>

							<label class="cleara11y-radio-card">
								<input type="radio" name="target_type" value="rule_on_element">
								<div class="cleara11y-radio-card-content">
									<strong>${esc_html(str('ruleOnElement'))}</strong>
									<p>${esc_html(str('ruleOnElementDesc'))}</p>
								</div>
							</label>
						</div>

						<div id="cleara11y-rule-ids-section" style="display: none; margin-top: 20px;">
							<label for="cleara11y-rule-ids"><strong>Accessibility Rules:</strong></label>
							<input type="text" id="cleara11y-rule-ids" class="regular-text" placeholder="e.g., color-contrast, image-alt">
							<p class="description">Enter rule IDs separated by commas. Leave empty to match all rules.</p>
						</div>

						<div id="cleara11y-element-section" style="display: none; margin-top: 20px;">
							<label><strong>Element Matching:</strong></label>
							<div style="margin: 10px 0;">
								<label>
									<input type="radio" name="element_match_type" value="css_selector">
									CSS Selector
								</label>
								<input type="text" id="cleara11y-css-selector" class="regular-text" placeholder=".my-button, #header" style="margin-left: 10px;">
							</div>
							<div style="margin: 10px 0;">
								<label>
									<input type="radio" name="element_match_type" value="fingerprint">
									Element Fingerprint (more stable)
								</label>
								<input type="text" id="cleara11y-element-fingerprint" class="regular-text" placeholder="SHA-256 hash" style="margin-left: 10px;">
							</div>
							<p class="description">Choose how to identify the element. CSS selectors are simpler but may break if the page structure changes.</p>
						</div>
					</div>
				`;

			case 2:
				return `
					<div class="cleara11y-wizard-step" data-step="2" style="display: none;">
						<h3>${esc_html(str('step2Title'))}</h3>
						<p class="description">${esc_html(str('step2Desc'))}</p>

						<div class="cleara11y-scope-options">
							<label class="cleara11y-radio-card">
								<input type="radio" name="scope_type" value="page">
								<div class="cleara11y-radio-card-content">
									<strong>${esc_html(str('singlePage'))}</strong>
									<p>${esc_html(str('singlePageDesc'))}</p>
								</div>
							</label>

							<label class="cleara11y-radio-card">
								<input type="radio" name="scope_type" value="site">
								<div class="cleara11y-radio-card-content">
									<strong>${esc_html(str('entireSite'))}</strong>
									<p>${esc_html(str('entireSiteDesc'))}</p>
								</div>
							</label>

							<label class="cleara11y-radio-card">
								<input type="radio" name="scope_type" value="content_type">
								<div class="cleara11y-radio-card-content">
									<strong>${esc_html(str('contentTypes'))}</strong>
									<p>${esc_html(str('contentTypesDesc'))}</p>
								</div>
							</label>

							<label class="cleara11y-radio-card">
								<input type="radio" name="scope_type" value="url_pattern">
								<div class="cleara11y-radio-card-content">
									<strong>${esc_html(str('urlPattern'))}</strong>
									<p>${esc_html(str('urlPatternDesc'))}</p>
								</div>
							</label>
						</div>

						<div id="cleara11y-scope-page-section" style="display: none; margin-top: 20px;">
							<label for="cleara11y-scope-url"><strong>Page URL:</strong></label>
							<input type="text" id="cleara11y-scope-url" class="large-text" placeholder="https://example.com/page">
						</div>

						<div id="cleara11y-scope-content-type-section" style="display: none; margin-top: 20px;">
							<label><strong>Post Types:</strong></label>
							<div style="margin: 10px 0;">
								<label><input type="checkbox" name="post_types" value="page"> Pages</label>
								<label style="margin-left: 15px;"><input type="checkbox" name="post_types" value="post"> Posts</label>
							</div>
						</div>

						<div id="cleara11y-scope-url-pattern-section" style="display: none; margin-top: 20px;">
							<label for="cleara11y-scope-patterns"><strong>URL Patterns:</strong></label>
							<input type="text" id="cleara11y-scope-patterns" class="large-text" placeholder="*/blog/*, */products/*">
							<p class="description">Enter patterns separated by commas. Use * as a wildcard.</p>
						</div>
					</div>
				`;

			case 3:
				return `
					<div class="cleara11y-wizard-step" data-step="3" style="display: none;">
						<h3>${esc_html(str('step3Title'))}</h3>
						<p class="description">${esc_html(str('step3Desc'))}</p>

						<div class="cleara11y-duration-options">
							<label class="cleara11y-radio-card">
								<input type="radio" name="duration_type" value="until_next_scan">
								<div class="cleara11y-radio-card-content">
									<strong>${esc_html(str('untilNextScan'))}</strong>
									<p>${esc_html(str('untilNextScanDesc'))}</p>
								</div>
							</label>

							<label class="cleara11y-radio-card">
								<input type="radio" name="duration_type" value="permanent">
								<div class="cleara11y-radio-card-content">
									<strong>${esc_html(str('permanent'))}</strong>
									<p>${esc_html(str('permanentDesc'))}</p>
								</div>
							</label>

							<label class="cleara11y-radio-card">
								<input type="radio" name="duration_type" value="until_date">
								<div class="cleara11y-radio-card-content">
									<strong>${esc_html(str('untilDate'))}</strong>
									<p>${esc_html(str('untilDateDesc'))}</p>
								</div>
							</label>

							<label class="cleara11y-radio-card">
								<input type="radio" name="duration_type" value="until_content_changes">
								<div class="cleara11y-radio-card-content">
									<strong>${esc_html(str('untilContentChanges'))}</strong>
									<p>${esc_html(str('untilContentChangesDesc'))}</p>
								</div>
							</label>
						</div>

						<div id="cleara11y-duration-date-section" style="display: none; margin-top: 20px;">
							<label for="cleara11y-expires-at"><strong>Expiration Date:</strong></label>
							<input type="datetime-local" id="cleara11y-expires-at" class="regular-text">
						</div>
					</div>
				`;

			case 4:
				return `
					<div class="cleara11y-wizard-step" data-step="4" style="display: none;">
						<h3>${esc_html(str('step4Title'))}</h3>
						<p class="description">${esc_html(str('step4Desc'))}</p>

						<div style="margin: 20px 0;">
							<label for="cleara11y-reason-category"><strong>Reason Category:</strong> <span class="required">*</span></label>
							<select id="cleara11y-reason-category" class="regular-text" style="width: 100%;">
								<option value="">Select a reason...</option>
								<option value="false_positive">False Positive</option>
								<option value="accepted_risk">Accepted Risk</option>
								<option value="third_party_code">Third-Party Code</option>
								<option value="planned_fix">Planned Fix</option>
								<option value="design_limitation">Design Limitation</option>
								<option value="other">Other</option>
							</select>
						</div>

						<div style="margin: 20px 0;">
								<label for="cleara11y-note"><strong>Additional Notes:</strong></label>
							<textarea id="cleara11y-note" rows="4" class="large-text" placeholder="Provide more context about this ignore decision..."></textarea>
						</div>
					</div>
				`;

			case 5:
				return `
					<div class="cleara11y-wizard-step" data-step="5" style="display: none;">
						<h3>${esc_html(str('step5Title'))}</h3>
						<p class="description">${esc_html(str('step5Desc'))}</p>

						<div id="cleara11y-review-content">
							<div class="cleara11y-review-section">
								<h4>${esc_html(str('target'))}</h4>
								<div id="cleara11y-review-target"></div>
							</div>

							<div class="cleara11y-review-section">
								<h4>${esc_html(str('scope'))}</h4>
								<div id="cleara11y-review-scope"></div>
							</div>

							<div class="cleara11y-review-section">
								<h4>${esc_html(str('duration'))}</h4>
								<div id="cleara11y-review-duration"></div>
							</div>

							<div class="cleara11y-review-section">
								<h4>${esc_html(str('reason'))}</h4>
								<div id="cleara11y-review-reason"></div>
							</div>
						</div>

						<div id="cleara11y-impact-preview-section" style="margin-top: 30px; padding: 20px; background: #f0f6fc; border-left: 4px solid #0073aa;">
							<h4>${esc_html(str('impactPreview'))}</h4>
							<p class="description">${esc_html(str('impactPreviewDesc'))}</p>
							<div id="cleara11y-impact-preview-content">
								<span class="spinner is-active"></span>
								${esc_html(str('calculatingImpact'))}
							</div>
						</div>
					</div>
				`;
		}
	}

	function bindWizardEvents() {
		// Close button
		$('#cleara11y-wizard-modal .cleara11y-modal-close, #cleara11y-wizard-cancel').on('click', function() {
			closeWizard();
		});

		// Next button
		$('#cleara11y-wizard-next').on('click', function() {
			if (validateCurrentStep()) {
				saveCurrentStep();
				if (wizardState.currentStep < wizardState.totalSteps) {
					showWizardStep(wizardState.currentStep + 1);
				}
			}
		});

		// Create button
		$('#cleara11y-wizard-create').on('click', function() {
			createIgnoreRule();
		});

		// Step 1: Target type changes
		$('input[name="target_type"]').on('change', function() {
			const value = $(this).val();
			$('#cleara11y-rule-ids-section').toggle(
				value === 'rule' || value === 'rule_on_element'
			);
			$('#cleara11y-element-section').toggle(
				value === 'element' || value === 'rule_on_element'
			);
			updateNextButtonState();
		});

		// Step 1: Rule IDs input
		$('#cleara11y-rule-ids').on('input', updateNextButtonState);

		// Step 1: Element match changes
		$('input[name="element_match_type"]').on('change', function() {
			$('#cleara11y-css-selector').toggle($(this).val() === 'css_selector');
			$('#cleara11y-element-fingerprint').toggle($(this).val() === 'fingerprint');
			updateNextButtonState();
		});
		$('#cleara11y-css-selector, #cleara11y-element-fingerprint').on('input', updateNextButtonState);

		// Step 2: Scope type changes
		$('input[name="scope_type"]').on('change', function() {
			const value = $(this).val();
			$('#cleara11y-scope-page-section').toggle(value === 'page');
			$('#cleara11y-scope-content-type-section').toggle(value === 'content_type');
			$('#cleara11y-scope-url-pattern-section').toggle(value === 'url_pattern');
			updateNextButtonState();
		});
		$('#cleara11y-scope-url, #cleara11y-scope-patterns').on('input', updateNextButtonState);
		$('input[name="post_types"]').on('change', updateNextButtonState);

		// Step 3: Duration type changes
		$('input[name="duration_type"]').on('change', function() {
			$('#cleara11y-duration-date-section').toggle($(this).val() === 'until_date');
			updateNextButtonState();
		});
		$('#cleara11y-expires-at').on('input', updateNextButtonState);

		// Step 4: Reason changes
		$('#cleara11y-reason-category').on('change', updateNextButtonState);
		$('#cleara11y-note').on('input', updateNextButtonState);
	}

	function showWizardStep(step) {
		wizardState.currentStep = step;

		// Update progress bar
		const progress = ((step - 1) / (wizardState.totalSteps - 1)) * 100;
		$('#cleara11y-progress-fill').css('width', progress + '%');

		// Update step indicators
		$('.cleara11y-progress-step').removeClass('active completed').each(function() {
			const stepNum = $(this).data('step');
			if (stepNum < step) {
				$(this).addClass('completed');
			} else if (stepNum === step) {
				$(this).addClass('active');
			}
		});

		// Show/hide step content
		$('.cleara11y-wizard-step').hide();
		$('.cleara11y-wizard-step[data-step="' + step + '"]').show();

		// Update buttons
		if (step === wizardState.totalSteps) {
			$('#cleara11y-wizard-next').hide();
			$('#cleara11y-wizard-create').show();
			loadImpactPreview();
		} else {
			$('#cleara11y-wizard-next').show();
			$('#cleara11y-wizard-create').hide();
			updateNextButtonState();
		}
	}

	function updateNextButtonState() {
		let valid = validateCurrentStep();
		$('#cleara11y-wizard-next').prop('disabled', !valid);
	}

	function validateCurrentStep() {
		const step = wizardState.currentStep;

		switch(step) {
			case 1:
				const targetType = $('input[name="target_type"]:checked').val();
				if (!targetType) return false;

				if (targetType === 'rule' || targetType === 'rule_on_element') {
					const ruleIds = $('#cleara11y-rule-ids').val().trim();
					if (!ruleIds) return false;
				}

				if (targetType === 'element' || targetType === 'rule_on_element') {
					const matchType = $('input[name="element_match_type"]:checked').val();
					if (!matchType) return false;
					if (matchType === 'css_selector' && !$('#cleara11y-css-selector').val().trim()) return false;
					if (matchType === 'fingerprint' && !$('#cleara11y-element-fingerprint').val().trim()) return false;
				}
				return true;

			case 2:
				const scopeType = $('input[name="scope_type"]:checked').val();
				if (!scopeType) return false;

				if (scopeType === 'page' && !$('#cleara11y-scope-url').val().trim()) return false;
				if (scopeType === 'content_type' && !$('input[name="post_types"]:checked').length) return false;
				if (scopeType === 'url_pattern' && !$('#cleara11y-scope-patterns').val().trim()) return false;
				return true;

			case 3:
				const durationType = $('input[name="duration_type"]:checked').val();
				if (!durationType) return false;
				if (durationType === 'until_date' && !$('#cleara11y-expires-at').val()) return false;
				return true;

			case 4:
				return $('#cleara11y-reason-category').val() !== '';

			default:
				return true;
		}
	}

	function saveCurrentStep() {
		const step = wizardState.currentStep;

		switch(step) {
			case 1:
				wizardState.data.target_type = $('input[name="target_type"]:checked').val();
				wizardState.data.rule_ids = $('#cleara11y-rule-ids').val().split(',').map(s => s.trim()).filter(s => s);

				const matchType = $('input[name="element_match_type"]:checked').val();
				if (matchType === 'css_selector') {
					wizardState.data.element_match = {
						css_selector: $('#cleara11y-css-selector').val().trim()
					};
				} else if (matchType === 'fingerprint') {
					wizardState.data.element_match = {
						element_fingerprint: $('#cleara11y-element-fingerprint').val().trim()
					};
				}
				break;

			case 2:
				const scopeType = $('input[name="scope_type"]:checked').val();
				wizardState.data.scope = { scope_type: scopeType };

				if (scopeType === 'page') {
					wizardState.data.scope.url = $('#cleara11y-scope-url').val().trim();
				} else if (scopeType === 'content_type') {
					wizardState.data.scope.post_types = $('input[name="post_types"]:checked').map(function() {
						return $(this).val();
					}).get();
				} else if (scopeType === 'url_pattern') {
					wizardState.data.scope.patterns = $('#cleara11y-scope-patterns').val().split(',').map(s => s.trim());
				}
				break;

			case 3:
				const durationType = $('input[name="duration_type"]:checked').val();
				wizardState.data.duration = { duration_type: durationType };

				if (durationType === 'until_date') {
					wizardState.data.duration.expires_at = $('#cleara11y-expires-at').val();
				}
				break;

			case 4:
				wizardState.data.reason_category = $('#cleara11y-reason-category').val();
				wizardState.data.note = $('#cleara11y-note').val().trim();

				// Update review section
				updateReviewContent();
				break;
		}
	}

	function updateReviewContent() {
		// Target
		let targetHtml = '<p><strong>Type:</strong> ' + esc_html(wizardState.data.target_type) + '</p>';
		if (wizardState.data.rule_ids.length) {
			targetHtml += '<p><strong>Rules:</strong> ' + esc_html(wizardState.data.rule_ids.join(', ')) + '</p>';
		}
		if (wizardState.data.element_match.css_selector) {
			targetHtml += '<p><strong>CSS Selector:</strong> <code>' + esc_html(wizardState.data.element_match.css_selector) + '</code></p>';
		} else if (wizardState.data.element_match.element_fingerprint) {
			targetHtml += '<p><strong>Element Fingerprint:</strong> <code>' + esc_html(wizardState.data.element_match.element_fingerprint.substring(0, 16)) + '...</code></p>';
		}
		$('#cleara11y-review-target').html(targetHtml);

		// Scope
		let scopeHtml = '<p><strong>Type:</strong> ' + esc_html(wizardState.data.scope.scope_type) + '</p>';
		if (wizardState.data.scope.url) {
			scopeHtml += '<p><strong>URL:</strong> ' + esc_html(wizardState.data.scope.url) + '</p>';
		} else if (wizardState.data.scope.post_types) {
			scopeHtml += '<p><strong>Post Types:</strong> ' + esc_html(wizardState.data.scope.post_types.join(', ')) + '</p>';
		} else if (wizardState.data.scope.patterns) {
			scopeHtml += '<p><strong>Patterns:</strong> ' + esc_html(wizardState.data.scope.patterns.join(', ')) + '</p>';
		}
		$('#cleara11y-review-scope').html(scopeHtml);

		// Duration
		let durationHtml = '<p><strong>Type:</strong> ' + esc_html(wizardState.data.duration.duration_type) + '</p>';
		if (wizardState.data.duration.expires_at) {
			durationHtml += '<p><strong>Expires:</strong> ' + esc_html(wizardState.data.duration.expires_at) + '</p>';
		}
		$('#cleara11y-review-duration').html(durationHtml);

		// Reason
		let reasonHtml = '<p><strong>Category:</strong> ' + esc_html(wizardState.data.reason_category) + '</p>';
		if (wizardState.data.note) {
			reasonHtml += '<p><strong>Note:</strong> ' + esc_html(wizardState.data.note) + '</p>';
		}
		$('#cleara11y-review-reason').html(reasonHtml);
	}

	function loadImpactPreview() {
		$('#cleara11y-impact-preview-content').html('<span class="spinner is-active"></span> ' + esc_html(str('calculatingImpact')));

		$.ajax({
			url: cleara11yIgnores.apiUrl + '/preview',
			method: 'POST',
			data: JSON.stringify(wizardState.data),
			contentType: 'application/json',
			beforeSend: function(xhr) {
				xhr.setRequestHeader('X-WP-Nonce', cleara11yIgnores.nonce);
			},
			success: function(response) {
				wizardState.impactPreview = response.data;
				renderImpactPreview(response.data);
				$('#cleara11y-wizard-create').prop('disabled', false);
			},
			error: function() {
				$('#cleara11y-impact-preview-content').html('<p style="color: #d63638;">' + esc_html(str('failedToCalculate')) + '</p>');
				$('#cleara11y-wizard-create').prop('disabled', false);
			}
		});
	}

	function renderImpactPreview(impact) {
		let html = '';

		if (impact.issues > 0) {
			html += '<div class="cleara11y-impact-item">';
			html += '<span class="cleara11y-impact-count">' + impact.issues + '</span> ';
			html += esc_html(str('issuesIgnored'));
			html += '</div>';
		} else {
			html += '<div class="cleara11y-impact-item">';
			html += esc_html(str('noIssuesMatch'));
			html += '</div>';
		}

		if (impact.pages > 0) {
			html += '<div class="cleara11y-impact-item">';
			html += '<span class="cleara11y-impact-count">' + impact.pages + '</span> ';
			html += esc_html(str('pagesAffected'));
			html += '</div>';
		}

		if (impact.issues > 10) {
			html += '<div class="cleara11y-impact-warning">';
			html += '<strong>' + esc_html(str('impactWarning')) + '</strong> ';
			html += esc_html(str('impactWarningDesc'));
			html += '</div>';
		}

		$('#cleara11y-impact-preview-content').html(html);
	}

	function createIgnoreRule() {
		const $createBtn = $('#cleara11y-wizard-create');
		$createBtn.prop('disabled', true).text(esc_html(str('creating')));

		$.ajax({
			url: cleara11yIgnores.apiUrl,
			method: 'POST',
			data: JSON.stringify(wizardState.data),
			contentType: 'application/json',
			beforeSend: function(xhr) {
				xhr.setRequestHeader('X-WP-Nonce', cleara11yIgnores.nonce);
			},
			success: function(response) {
				closeWizard();
				loadRules();
				$('#cleara11y-create-ignore').after(
					'<div class="notice notice-success is-dismissible" style="margin: 20px 0;">' +
					'<p>' + esc_html(str('createSuccess')) + '</p>' +
					'</div>'
				);
				setTimeout(function() {
					$('.notice.is-dismissible').fadeOut(function() {
						$(this).remove();
					});
				}, 3000);
			},
			error: function(xhr) {
				const error = xhr.responseJSON?.message || esc_html(str('createFailed'));
				$('#cleara11y-impact-preview-section').after(
					'<div class="notice notice-error" style="margin: 20px 0;"><p>' + esc_html(error) + '</p></div>'
				);
				$createBtn.prop('disabled', false).text(esc_html(str('createRule')));
			}
		});
	}

	function closeWizard() {
		$('#cleara11y-wizard-modal').remove();
	}

	// Helper functions
	function getScopeLabel(scope) {
		if (!scope) return '';
		switch (scope.scope_type) {
			case 'page':
				return 'Page: ' + scope.url;
			case 'site':
				return 'Entire site';
			case 'content_type':
				return 'Content types: ' + (scope.post_types || []).join(', ');
			case 'url_pattern':
				return 'URL pattern: ' + (scope.patterns || []).join(', ');
			default:
				return '';
		}
	}

	function getDurationLabel(duration) {
		if (!duration) return '';
		switch (duration.duration_type) {
			case 'until_next_scan':
				return 'Until next scan';
			case 'permanent':
				return 'Permanent';
			case 'until_date':
				return 'Until: ' + (duration.expires_at || '');
			case 'until_content_changes':
				return 'Until content changes';
			default:
				return '';
		}
	}

	function formatDate(dateString) {
		if (!dateString) return '';
		const date = new Date(dateString);
		return date.toLocaleString();
	}

	function esc_html(text) {
		if (!text) return '';
		return $('<div/>').text(text).html();
	}

	// Pagination handlers
	$('#cleara11y-ignores-first-page').on('click', function() {
		if (state.currentPage > 1) {
			state.currentPage = 1;
			loadRules();
		}
	});

	$('#cleara11y-ignores-prev-page').on('click', function() {
		if (state.currentPage > 1) {
			state.currentPage--;
			loadRules();
		}
	});

	$('#cleara11y-ignores-next-page').on('click', function() {
		if (state.currentPage < state.totalPages) {
			state.currentPage++;
			loadRules();
		}
	});

	$('#cleara11y-ignores-last-page').on('click', function() {
		if (state.currentPage < state.totalPages) {
			state.currentPage = state.totalPages;
			loadRules();
		}
	});


		// Expose wizard functions globally for use from other pages (e.g., Issues List)
		window.cleara11yWizard = {
			state: wizardState,
			open: openCreateWizard,
			close: closeWizard,
			reset: resetWizard
		};

		// Also expose to window for direct access (backward compatibility)
		window.wizardState = wizardState;
		window.openCreateWizard = openCreateWizard;
		window.resetWizard = resetWizard;
		window.closeWizard = closeWizard;
})(jQuery);
