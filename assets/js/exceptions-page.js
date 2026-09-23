/**
 * ClearA11y Exceptions Management Page
 */
(function($) {
	'use strict';

	// Rule titles mapping for human-readable display
	const ruleTitles = {
		// WCAG 2.1 Level A
		'color-contrast': 'Elements must meet minimum color contrast ratio thresholds',
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
	let modalReturnFocus = null;
	let rulesRequest = null;

	// Initialize
	$(document).ready(function() {
		initDOM();
		initTabs();
		initFilters();
		$('#cleara11y-exceptions-current-page').on('change', function() {
			state.currentPage = Math.max(1, Math.min(state.totalPages, parseInt(this.value, 10) || 1));
			loadRules();
		});
		$('#cleara11y-exception-detail-modal').on('keydown', function(event) {
			if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); closeModals(); }
			if (event.key === 'Tab') {
				const controls = $(this).find('button:visible, a[href]:visible, input:visible');
				const first = controls[0], last = controls[controls.length - 1];
				if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
				else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
			}
		});
		if ($tbody.length) {
			loadRules();
		}

		// Event listeners for row actions
		$(document).on('click', '.cleara11y-view-exception', viewException);
		$(document).on('click', '.cleara11y-edit-exception', editException);
		$(document).on('click', '.cleara11y-disable-exception', disableException);
		$(document).on('click', '.cleara11y-enable-exception', enableException);
		$(document).on('click', '.cleara11y-revoke-exception', revokeException);
		$(document).on('click', '.cleara11y-modal-close', closeModals);
		$(document).on('click', '[data-cleara11y-create-exception]', function(e) {
			e.preventDefault();
			const button = this;
			const canAnchor = button.dataset.canAnchor === '1';
			openCreateWizard({
				violation_id: Number(button.dataset.occurrenceId || 0),
				target_type: canAnchor ? 'rule_on_element' : 'rule',
				rule_ids: [button.dataset.ruleId],
				element_match: {css_selector: button.dataset.selector || ''},
				scope: {scope_type: 'page', url: button.dataset.pageUrl || ''},
				context: {
					page_id: Number(button.dataset.pageId || 0),
					page_title: button.dataset.pageTitle || '',
					post_type: button.dataset.postType || '',
					rule_title: button.dataset.ruleTitle || '',
					occurrence_fallback: !canAnchor,
					occurrence_fallback_reason: button.dataset.anchorStatus || 'unavailable'
				},
				duration: {duration_type: 'permanent'},
				note: button.dataset.message || ''
			});
		});
	});

	function initDOM() {
		$tbody = $('#cleara11y-exceptions-table-body');
		$pagination = $('#cleara11y-exceptions-pagination');
		$tabContent = $('.cleara11y-tab-content');
	}

	function initTabs() {
		$('.nav-tab[data-tab]').on('click', function(e) {
			e.preventDefault();
			const $tab = $(this);
			const tabName = $tab.data('tab');

			// Update active tab
			$('.cleara11y-exceptions-wrap .nav-tab').removeClass('nav-tab-active').removeAttr('aria-current');
			$tab.addClass('nav-tab-active').attr('aria-current', 'page');

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
		$('#cleara11y-hide-system-exceptions').on('change', function() {
			state.hideSystemGenerated = $(this).is(':checked');
			state.currentPage = 1;
			loadRules();
		});

		$('#cleara11y-refresh-exceptions').on('click', function() {
			loadRules();
		});

		$('#cleara11y-refresh-audit').on('click', function() {
			loadAuditLog();
		});
	}

	function loadRules() {
		if (rulesRequest) rulesRequest.abort();
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

		rulesRequest = $.ajax({
			url: cleara11yExceptions.apiUrl,
			data: params,
			method: 'GET',
			beforeSend: function(xhr) {
				xhr.setRequestHeader('X-WP-Nonce', cleara11yExceptions.nonce);
			},
			success: function(response) {
				renderRules(response.data);
				updateCounts(response.counts);
				updatePagination(response.total, response.page, response.per_page, response.total_pages);
			},
			error: function(xhr, status) {
				if (status === 'abort') return;
				showError(cleara11yExceptions.strings.error);
			},
			complete: function() {
				state.loading = false;
			}
		});
	}

	function targetLabel(rule) {
		return str({rule: 'ruleOnly', element: 'elementOnly', rule_on_element: 'ruleOnElement'}[rule.target_type] || 'target');
	}

	function ruleTitle(rule) {
		return rule.target_type === 'element' ? str('elementOnly') : (rule.rule_ids || []).map(id => ruleTitles[id] || id).join(', ');
	}

	function readable(value) {
		return String(value || '').replace(/_/g, ' ').replace(/^./, letter => letter.toUpperCase());
	}

	function renderRules(rules) {
		if (!rules || !rules.length) { showEmptyState(); return; }
		$tbody.html(rules.map(rule => '<tr><td><button type="button" class="button-link cleara11y-view-exception cleara11y-exception-title" data-id="' + esc_html(rule.id) + '">' + esc_html(ruleTitle(rule)) + '</button>' +
			'<div class="cleara11y-exception-meta">' + esc_html(rule.system_generated ? str('management0') : readable(rule.reason_category)) + '</div></td>' +
			'<td>' + esc_html(targetLabel(rule)) + (rule.element_match?.css_selector ? '<code class="cleara11y-exception-selector">' + esc_html(rule.element_match.css_selector) + '</code>' : '') + '</td>' +
			'<td>' + esc_html(getScopeLabel(rule.scope)) + '</td><td>' + esc_html(getDurationLabel(rule.duration)) + '</td>' +
			'<td>' + esc_html(rule.created_by_name || str('management1')) + '<div class="cleara11y-exception-meta">' + esc_html(formatDate(rule.created_at)) + '</div></td></tr>').join(''));
	}

	function loadAuditLog() {
		$.ajax({
			url: cleara11yExceptions.apiUrl + '/audit/all',
			method: 'GET',
			beforeSend: function(xhr) {
				xhr.setRequestHeader('X-WP-Nonce', cleara11yExceptions.nonce);
			},
			success: function(response) {
				renderAuditLog(response.data);
			},
			error: function() {
				$('#cleara11y-audit-table-body').html('<tr><td colspan="5" role="alert">' + esc_html(cleara11yExceptions.strings.error) + '</td></tr>');
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
			if (entry.exception_rule_id) {
				html += '<button type="button" class="button-link cleara11y-view-exception" data-id="' + esc_html(entry.exception_rule_id) + '">' + esc_html(entry.exception_rule_id.substring(0, 8)) + '</button>';
			} else {
				html += '-';
			}
			html += '</td>';
			html += '<td>' + esc_html(entry.actor_name || str('management1')) + '</td>';
			html += '<td>' + formatDate(entry.timestamp) + '</td>';
			html += '<td>';
			if (entry.metadata && Object.keys(entry.metadata).length) {
				html += '<details><summary>View details</summary><pre>' + esc_html(JSON.stringify(entry.metadata, null, 2)) + '</pre></details>';
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
		$('#cleara11y-revoked-count').text('(' + counts.revoked + ')');
	}

	function updatePagination(total, page, perPage, totalPages) {
		$('#cleara11y-exceptions-summary').text(total + ' ' + state.currentStatus + ' exceptions' + (state.hideSystemGenerated ? ' · Quick snoozes hidden' : ''));
		state.totalPages = totalPages;
		state.currentPage = page;

		if (total > perPage) {
			$pagination.show();
			$('#cleara11y-exceptions-displaying-num').text(
				'Showing ' + ((page - 1) * perPage + 1) + '–' + Math.min(page * perPage, total) + ' of ' + total
			);
			$('#cleara11y-exceptions-current-page').val(page);
			$('#cleara11y-exceptions-total-pages').text(totalPages);

			// Update button states
			$('#cleara11y-exceptions-first-page, #cleara11y-exceptions-prev-page').prop('disabled', page === 1);
			$('#cleara11y-exceptions-next-page, #cleara11y-exceptions-last-page').prop('disabled', page === totalPages);
		} else {
			$pagination.hide();
		}
	}

	function showLoading() {
		$tbody.html('<tr><td colspan="5" style="text-align: center; padding: 40px;"><span class="spinner is-active"></span>Loading...</td></tr>');
	}

	function showEmptyState() {
		$tbody.html('<tr><td colspan="5" class="cleara11y-exceptions-empty"><strong>' + esc_html('No ' + state.currentStatus + ' exceptions.') + '</strong><p>' + esc_html(state.hideSystemGenerated ? str('management16') : str('management17')) + '</p></td></tr>');
	}

	function showError(message) {
		$tbody.html('<tr><td colspan="5" style="text-align: center; padding: 40px; color: #d63638;">' + esc_html(message) + '</td></tr>');
	}

	function viewException(e) {
		e.preventDefault();
		const ruleId = $(this).data('id');
		modalReturnFocus = this;

		$.ajax({
			url: cleara11yExceptions.apiUrl + '/' + ruleId,
			method: 'GET',
			beforeSend: function(xhr) {
				xhr.setRequestHeader('X-WP-Nonce', cleara11yExceptions.nonce);
			},
			success: function(rule) {
				showRuleDetailModal(rule);
			},
			error: function() {
				alert(cleara11yExceptions.strings.error);
			}
		});
	}

	function showRuleDetailModal(rule) {
		const row = (label, value) => '<div class="cleara11y-detail-row"><span class="cleara11y-detail-label">' + esc_html(label) + '</span><span class="cleara11y-detail-value">' + esc_html(String(value ?? '')) + '</span></div>';
		let html = '<h3>' + esc_html(ruleTitle(rule)) + '</h3><div class="cleara11y-detail-section">';
		html += row(str('management4'), readable(rule.status)) + row(str('management2'), targetLabel(rule)) + row(str('management3'), getScopeLabel(rule.scope)) + row('Duration', getDurationLabel(rule.duration));
		html += '</div><section class="cleara11y-detail-section"><h3>Review decision</h3>' + row(str('management6'), readable(rule.reason_category)) + row(str('management7'), rule.note || str('management8')) + row(str('management9'), (rule.created_by_name || str('management1')) + ' · ' + formatDate(rule.created_at)) + '</section>';
		html += '<section class="cleara11y-detail-section"><h3>Finding context</h3>' + row(str('management11'), (rule.rule_ids || []).join(', ') || str('management12'));
		if (rule.element_match?.css_selector) html += '<pre><code>' + esc_html(rule.element_match.css_selector) + '</code></pre>';
		html += row(str('management13'), rule.match_count || 0) + '</section>';
		html += '<section class="cleara11y-detail-section"><h3>History</h3><ol class="cleara11y-exception-history">';
		(rule.audit_log || []).forEach(entry => {
			html += '<li><strong>' + esc_html(entry.event_label || readable(entry.event_type)) + '</strong><div class="cleara11y-exception-meta">' + esc_html((entry.actor_name || str('management1')) + ' · ' + formatDate(entry.timestamp)) + '</div></li>';
		});
		html += '</ol></section>';
		$('#cleara11y-exception-detail-body').html(html);
		$('#cleara11y-exception-detail-title').text(str('management15'));
		$('#cleara11y-edit-exception').data('id', rule.id).toggle(!rule.system_generated && rule.status !== 'revoked');
		const $footer = $('#cleara11y-exception-detail-modal .cleara11y-modal-footer');
		$footer.find('[data-management-action]').remove();
		if (rule.status === 'active' || rule.status === 'disabled') {
			const action = rule.status === 'active' ? 'disable' : 'enable';
			$footer.append('<button type="button" data-management-action class="button cleara11y-' + action + '-exception" data-id="' + esc_html(rule.id) + '">' + readable(action) + '</button>');
		}
		if (rule.status !== 'revoked') $footer.append('<button type="button" data-management-action class="button cleara11y-revoke-exception" data-id="' + esc_html(rule.id) + '">Revoke</button>');
		$('#cleara11y-exception-detail-modal').show();
		$('#wpbody-content > .cleara11y-exceptions-wrap').prop('inert', true);
		$('#cleara11y-exception-detail-modal .cleara11y-modal-close').first().trigger('focus');
	}

	function closeModals() {
		$('.cleara11y-modal-backdrop').parent().hide();
		$('.cleara11y-exceptions-wrap').prop('inert', false);
		if (modalReturnFocus && document.contains(modalReturnFocus)) {
			modalReturnFocus.focus();
		}
	}

	function disableException(e) {
		e.preventDefault();
		const ruleId = $(this).data('id');

		if (!confirm(cleara11yExceptions.strings.confirmDisable)) {
			return;
		}

		$.ajax({
			url: cleara11yExceptions.apiUrl + '/' + ruleId + '/disable',
			method: 'POST',
			beforeSend: function(xhr) {
				xhr.setRequestHeader('X-WP-Nonce', cleara11yExceptions.nonce);
			},
			success: function() {
				closeModals();
				loadRules();
			},
			error: function() {
				alert(cleara11yExceptions.strings.error);
			}
		});
	}

	function enableException(e) {
		e.preventDefault();
		const ruleId = $(this).data('id');

		$.ajax({
			url: cleara11yExceptions.apiUrl + '/' + ruleId + '/enable',
			method: 'POST',
			beforeSend: function(xhr) {
				xhr.setRequestHeader('X-WP-Nonce', cleara11yExceptions.nonce);
			},
			success: function() {
				closeModals();
				loadRules();
			},
			error: function() {
				alert(cleara11yExceptions.strings.error);
			}
		});
	}

	function revokeException(e) {
		e.preventDefault();
		const ruleId = $(this).data('id');

		if (!confirm(cleara11yExceptions.strings.confirmDelete)) {
			return;
		}

		$.ajax({
			url: cleara11yExceptions.apiUrl + '/' + ruleId,
			method: 'DELETE',
			beforeSend: function(xhr) {
				xhr.setRequestHeader('X-WP-Nonce', cleara11yExceptions.nonce);
			},
			success: function() {
				closeModals();
				loadRules();
			},
			error: function() {
				alert(cleara11yExceptions.strings.error);
			}
		});
	}

	function editException(event) {
		const ruleId = $(event.currentTarget).data('id');
		closeModals();
		$.ajax({
			url: cleara11yExceptions.apiUrl + '/' + encodeURIComponent(ruleId),
			method: 'GET',
			beforeSend: function(xhr) {
				xhr.setRequestHeader('X-WP-Nonce', cleara11yExceptions.nonce);
			},
			success: function(rule) {
				openCreateWizard(rule, ruleId);
			},
			error: function(xhr) {
				alert(xhr.responseJSON?.message || cleara11yExceptions.strings.error);
			}
		});
	}

	// Wizard state
	const wizardState = {
		currentStep: 1,
		totalSteps: 5,
		editingId: null,
		selectedPageUrl: '',
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
		return cleara11yExceptions.strings[key] || key;
	}

	let wizardReturnFocus = null;

	function openCreateWizard(initialData = {}, editingId = null) {
		if (!editingId && !Number(initialData?.violation_id || 0)) {
			return;
		}
		wizardReturnFocus = document.activeElement;
		resetWizard();
		wizardState.editingId = editingId;
		wizardState.data = $.extend(true, {}, wizardState.data, initialData || {});
		wizardState.selectedPageUrl = wizardState.data.scope?.url || '';
		if (!wizardState.data.target_type) {
			wizardState.data.target_type = 'rule';
		}
		renderWizardModal();
		hydrateWizard();
		showWizardStep(1, false);
		const $target = $('#cleara11y-wizard-modal input[name="target_type"]:checked');
		if (editingId || !$target.length) {
			$('#cleara11y-wizard-modal .cleara11y-wizard-step[data-step="1"] h3').trigger('focus');
		} else {
			$target.trigger('focus');
		}
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
		wizardState.editingId = null;
	}

	function hydrateWizard() {
		const data = wizardState.data;
		const contextPostType = data.context?.post_type || '';
		const occurrenceSpecific = Boolean(data.violation_id) && !data.context?.occurrence_fallback;
		if (wizardState.editingId) {
			$('input[name="target_type"]').prop('disabled', true);
			$('#cleara11y-edit-target-notice').prop('hidden', false);
		} else {
			$('input[name="target_type"][value="element"], input[name="target_type"][value="rule_on_element"]')
				.prop('disabled', !occurrenceSpecific);
		}
		if (data.context?.occurrence_fallback) {
			const fallbackMessage = data.context.occurrence_fallback_reason === 'ambiguous'
				? 'This finding shares its element identity with multiple elements in the scan. This exception will apply to the selected rule on this page.'
				: 'This older finding does not have stable element identity. This exception will apply to the selected rule on this page.';
			$('#cleara11y-occurrence-fallback-notice p').text(fallbackMessage);
			$('#cleara11y-occurrence-fallback-notice').prop('hidden', false);
		}
		if (
			contextPostType
			&& !$('input[name="post_types"]').filter(function() {
				return this.value === contextPostType;
			}).length
		) {
			const $label = $('<label>');
			const $input = $('<input>', {
				type: 'checkbox',
				name: 'post_types',
				value: contextPostType
			});
			$label.append($input, ' ' + contextPostType);
			$('#cleara11y-scope-content-type-section > div').append($label);
		}
		if (data.target_type) {
			$('input[name="target_type"][value="' + data.target_type + '"]').prop('checked', true).trigger('change');
		}
		renderFindingContext();
		if (data.scope?.scope_type) {
			$('input[name="scope_type"][value="' + data.scope.scope_type + '"]').prop('checked', true).trigger('change');
			renderSelectedPage();
			$('#cleara11y-scope-patterns').val((data.scope.patterns || []).join(', '));
			(data.scope.post_types || []).forEach(type => {
				$('input[name="post_types"][value="' + type + '"]').prop('checked', true);
			});
		}
		if (!wizardState.editingId && data.violation_id) {
			$('#cleara11y-page-search').prop('hidden', true).prop('disabled', true);
			$('#cleara11y-selected-page [data-remove-page]').remove();
			$('#cleara11y-scope-page-section > label').text('Detected page:');
		}
		if (data.duration?.duration_type) {
			$('input[name="duration_type"][value="' + data.duration.duration_type + '"]').prop('checked', true).trigger('change');
			$('#cleara11y-expires-at').val((data.duration.expires_at || '').replace(' ', 'T').slice(0, 16));
		}
		$('#cleara11y-reason-category').val(data.reason_category || '');
		$('#cleara11y-note').val(data.note || '');
		updateNextButtonState();
	}

	function renderWizardModal() {
		const stepLabels = [
			str('target'),
			str('scope'),
			str('duration'),
			str('reason'),
			str('step5Title')
		];
		const wizardHtml = `
			<div id="cleara11y-wizard-modal" style="display: none;">
				<div class="cleara11y-modal-backdrop"></div>
				<div class="cleara11y-modal-content cleara11y-wizard-content" role="dialog" aria-modal="true" aria-labelledby="cleara11y-wizard-title">
					<div class="cleara11y-modal-header">
						<h2 id="cleara11y-wizard-title">${esc_html(wizardState.editingId ? 'Edit reviewed exception' : str('createWizardTitle'))}</h2>
						<button type="button" class="cleara11y-modal-close cleara11y-modal-close--icon" aria-label="${esc_html(str('cancel'))}">
							<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
						</button>
					</div>

					<div class="cleara11y-wizard-progress" role="group" aria-label="Exception setup progress">
						<p class="cleara11y-wizard-step-count" aria-live="polite">
							Step <span id="cleara11y-current-step">1</span> of ${wizardState.totalSteps}:
							<strong id="cleara11y-current-step-label">${esc_html(stepLabels[0])}</strong>
						</p>
						<ol class="cleara11y-progress-steps">
							${stepLabels.map(function(label, index) {
								const step = index + 1;
								return `<li class="cleara11y-progress-step${step === 1 ? ' active' : ''}" data-step="${step}"${step === 1 ? ' aria-current="step"' : ''}><span aria-hidden="true">${step}.</span> ${esc_html(label)}</li>`;
							}).join('')}
						</ol>
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
						<button type="button" class="button" id="cleara11y-wizard-back" hidden>Back</button>
						<div class="cleara11y-wizard-footer__actions">
							<button type="button" class="button button-primary" id="cleara11y-wizard-next" disabled>
								${esc_html(str('next'))}
							</button>
							<button type="button" class="button button-primary" id="cleara11y-wizard-create" hidden>
								${esc_html(wizardState.editingId ? 'Save Exception' : str('createRule'))}
							</button>
						</div>
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
						<h3 tabindex="-1">${esc_html(str('step1Title'))}</h3>
						<p class="description">${esc_html(str('step1Desc'))}</p>
						<div class="cleara11y-finding-context" aria-label="Detected finding">
							<h4>Detected finding</h4>
							<dl>
								<dt>Rule</dt><dd><span id="cleara11y-finding-rule-title"></span> <code id="cleara11y-finding-rule-id"></code></dd>
								<dt>Page</dt><dd id="cleara11y-finding-page"></dd>
								<dt>Element</dt><dd><code id="cleara11y-finding-selector"></code></dd>
							</dl>
						</div>

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
						<div id="cleara11y-occurrence-fallback-notice" class="notice notice-warning inline" hidden>
							<p>This older finding does not have stable element identity. This exception will apply to the selected rule on this page.</p>
						</div>
						<div id="cleara11y-edit-target-notice" class="notice notice-info inline" hidden>
							<p>The target of an existing exception cannot be changed. You can still update its scope, duration, reason, and note.</p>
						</div>
					</div>
				`;

			case 2:
				return `
					<div class="cleara11y-wizard-step" data-step="2" style="display: none;">
						<h3 tabindex="-1">${esc_html(str('step2Title'))}</h3>
						<p class="description">${esc_html(str('step2Desc'))}</p>

						<div class="cleara11y-scope-options">
							<div class="cleara11y-scope-option">
								<label class="cleara11y-radio-card">
									<input type="radio" name="scope_type" value="page" aria-controls="cleara11y-scope-page-section">
									<div class="cleara11y-radio-card-content">
										<strong>${esc_html(str('singlePage'))}</strong>
										<p>${esc_html(str('singlePageDesc'))}</p>
									</div>
								</label>
								<div id="cleara11y-scope-page-section" class="cleara11y-wizard-reveal-section" style="display: none;">
									<label for="cleara11y-page-search"><strong>Page or content:</strong></label>
									<div class="cleara11y-picker">
										<input type="search" id="cleara11y-page-search" role="combobox" aria-autocomplete="list"
											aria-controls="cleara11y-page-options" aria-expanded="false" autocomplete="off"
											placeholder="Search scanned pages, posts, or other content">
										<ul id="cleara11y-page-options" class="cleara11y-picker__options" role="listbox" hidden></ul>
									</div>
									<div id="cleara11y-selected-page" class="cleara11y-picker__selected" aria-live="polite"></div>
									<p class="description">The page URL is filled from the selected scanned content.</p>
								</div>
							</div>

							<label class="cleara11y-radio-card">
								<input type="radio" name="scope_type" value="site">
								<div class="cleara11y-radio-card-content">
									<strong>${esc_html(str('entireSite'))}</strong>
									<p>${esc_html(str('entireSiteDesc'))}</p>
								</div>
							</label>

							<div class="cleara11y-scope-option">
								<label class="cleara11y-radio-card">
									<input type="radio" name="scope_type" value="content_type" aria-controls="cleara11y-scope-content-type-section">
									<div class="cleara11y-radio-card-content">
										<strong>${esc_html(str('contentTypes'))}</strong>
										<p>${esc_html(str('contentTypesDesc'))}</p>
									</div>
								</label>
								<div id="cleara11y-scope-content-type-section" class="cleara11y-wizard-reveal-section" style="display: none;">
									<label><strong>Post Types:</strong></label>
									<div class="cleara11y-wizard-checkboxes">
										<label><input type="checkbox" name="post_types" value="page"> Pages</label>
										<label><input type="checkbox" name="post_types" value="post"> Posts</label>
									</div>
								</div>
							</div>

							<div class="cleara11y-scope-option">
								<label class="cleara11y-radio-card">
									<input type="radio" name="scope_type" value="url_pattern" aria-controls="cleara11y-scope-url-pattern-section">
									<div class="cleara11y-radio-card-content">
										<strong>${esc_html(str('urlPattern'))}</strong>
										<p>${esc_html(str('urlPatternDesc'))}</p>
									</div>
								</label>
								<div id="cleara11y-scope-url-pattern-section" class="cleara11y-wizard-reveal-section" style="display: none;">
									<label for="cleara11y-scope-patterns"><strong>URL Patterns:</strong></label>
									<input type="text" id="cleara11y-scope-patterns" class="large-text" placeholder="*/blog/*, */products/*">
									<p class="description">Enter patterns separated by commas. Use * as a wildcard.</p>
								</div>
							</div>
						</div>

					</div>
				`;

			case 3:
				return `
					<div class="cleara11y-wizard-step" data-step="3" style="display: none;">
						<h3 tabindex="-1">${esc_html(str('step3Title'))}</h3>
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

						<div id="cleara11y-duration-date-section" class="cleara11y-wizard-reveal-section" style="display: none;">
							<label for="cleara11y-expires-at"><strong>Expiration Date:</strong></label>
							<input type="datetime-local" id="cleara11y-expires-at" class="regular-text">
						</div>
					</div>
				`;

			case 4:
				return `
					<div class="cleara11y-wizard-step" data-step="4" style="display: none;">
						<h3 tabindex="-1">${esc_html(str('step4Title'))}</h3>
						<p class="description">${esc_html(str('step4Desc'))}</p>

						<div class="cleara11y-wizard-form-row">
							<label for="cleara11y-reason-category"><strong>Reason Category:</strong> <span class="required">*</span></label>
							<select id="cleara11y-reason-category" class="regular-text">
								<option value="">Select a reason...</option>
								<option value="false_positive">False Positive</option>
								<option value="not_applicable">Not Applicable</option>
								<option value="acceptable_in_context">Acceptable in Context</option>
								<option value="accepted_risk">Accepted Risk</option>
								<option value="third_party_code">Third-Party Code</option>
								<option value="tracked_elsewhere">Tracked Elsewhere</option>
								<option value="planned_fix">Planned Fix</option>
								<option value="design_limitation">Design Limitation</option>
								<option value="other">Other</option>
							</select>
						</div>

						<div class="cleara11y-wizard-form-row">
								<label for="cleara11y-note"><strong>Notes: <span class="required">*</span></strong></label>
							<textarea id="cleara11y-note" required rows="4" class="large-text" placeholder="Provide more context about this review decision..."></textarea>
						</div>
					</div>
				`;

			case 5:
				return `
					<div class="cleara11y-wizard-step" data-step="5" style="display: none;">
						<h3 tabindex="-1">${esc_html(str('step5Title'))}</h3>
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

						<div id="cleara11y-impact-preview-section" class="cleara11y-impact-preview">
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
		$('#cleara11y-wizard-modal .cleara11y-modal-close').on('click', function() {
			closeWizard();
		});
		$('#cleara11y-wizard-modal').on('keydown', function(event) {
			if (event.key === 'Escape') {
				event.preventDefault();
				event.stopPropagation();
				closeWizard();
			} else if (event.key === 'Tab') {
				trapWizardFocus(event);
			}
		});
		$('#cleara11y-wizard-modal').on('click', function(event) {
			if (!$(event.target).closest('.cleara11y-picker').length) {
				hideAllWizardOptions();
			}
		});
		$('#cleara11y-wizard-back').on('click', function() {
			if (wizardState.currentStep > 1) {
				showWizardStep(wizardState.currentStep - 1, true);
			}
		});

		// Next button
		$('#cleara11y-wizard-next').on('click', function() {
			if (validateCurrentStep()) {
				saveCurrentStep();
				if (wizardState.currentStep < wizardState.totalSteps) {
					showWizardStep(wizardState.currentStep + 1, true);
				}
			}
		});

		// Create button
		$('#cleara11y-wizard-create').on('click', function() {
			createExceptionRule();
		});

		// Step 1: Target type changes
		$('input[name="target_type"]').on('change', function() {
			updateNextButtonState();
		});

		setupWizardPicker('page', $('#cleara11y-page-search'), $('#cleara11y-page-options'));
		$('#cleara11y-selected-page').on('click', '[data-remove-page]', function() {
			wizardState.data.scope.url = '';
			wizardState.selectedPageUrl = '';
			wizardState.data.context = $.extend({}, wizardState.data.context, {
				page_id: 0,
				page_title: '',
				post_type: ''
			});
			renderSelectedPage();
			updateNextButtonState();
			$('#cleara11y-page-search').trigger('focus');
		});

		// Step 2: Scope type changes
		$('input[name="scope_type"]').on('change', function() {
			const value = $(this).val();
			const animate = wizardState.currentStep === 2
				&& !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
			$('.cleara11y-scope-option > .cleara11y-wizard-reveal-section').each(function() {
				const $section = $(this);
				const selected = $section.parent().find('input[name="scope_type"]').val() === value;
				$section.stop(true, true);
				if (selected && animate) {
					$section.slideDown(160);
				} else {
					$section.toggle(selected);
				}
			});
			if (
				value === 'content_type'
				&& wizardState.data.context?.post_type
				&& !$('input[name="post_types"]:checked').length
			) {
				$('input[name="post_types"][value="' + wizardState.data.context.post_type + '"]').prop('checked', true);
			}
			updateNextButtonState();
		});
		$('#cleara11y-scope-patterns').on('input', updateNextButtonState);
		$('input[name="post_types"]').on('change', updateNextButtonState);

		// Step 3: Duration type changes
		$('input[name="duration_type"]').on('change', function() {
			$('#cleara11y-duration-date-section').toggle($(this).val() === 'until_date');
			updateNextButtonState();
		});
		$('#cleara11y-expires-at').on('input', updateNextButtonState);

		// Step 4: Reason changes
		$('#cleara11y-reason-category').on('change', updateNextButtonState);
		$('#cleara11y-note').on('input change', updateNextButtonState);
	}

	function setupWizardPicker(type, $input, $list) {
		let timer;
		let request = null;
		let suppressNextFocusLoad = false;
		$input.on('focus', function() {
			if (suppressNextFocusLoad) {
				suppressNextFocusLoad = false;
				return;
			}
			loadWizardOptions(type, $input, $list);
		});
		$input.on('input', function() {
			window.clearTimeout(timer);
			timer = window.setTimeout(function() {
				loadWizardOptions(type, $input, $list);
			}, 250);
		});
		$input.on('keydown', function(event) {
			if (event.key === 'ArrowDown' && !$list.prop('hidden')) {
				event.preventDefault();
				$list.find('button').first().trigger('focus');
			} else if (event.key === 'Escape') {
				event.preventDefault();
				event.stopPropagation();
				hideWizardOptions($input, $list);
			}
		});
		$list.on('keydown', function(event) {
			const buttons = $list.find('button').toArray();
			const index = buttons.indexOf(document.activeElement);
			if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
				event.preventDefault();
				const next = event.key === 'ArrowDown'
					? Math.min(buttons.length - 1, index + 1)
					: Math.max(0, index - 1);
				buttons[next]?.focus();
			} else if (event.key === 'Escape') {
				event.preventDefault();
				event.stopPropagation();
				hideWizardOptions($input, $list);
				$input.trigger('focus');
			}
		});
		$list.on('click', 'button[data-option-id]', function() {
			const option = this.dataset;
			window.clearTimeout(timer);
			if (request) {
				request.abort();
				request = null;
			}
			wizardState.data.scope.url = option.optionUrl || '';
			wizardState.selectedPageUrl = wizardState.data.scope.url;
			wizardState.data.context = $.extend({}, wizardState.data.context, {
				page_id: Number(option.optionId || 0),
				page_title: option.optionLabel || '',
				post_type: option.optionPostType || ''
			});
			renderSelectedPage();
			$input.val('');
			hideWizardOptions($input, $list);
			updateNextButtonState();
			suppressNextFocusLoad = true;
			$input.trigger('focus');
		});

		function loadWizardOptions(type, $input, $list) {
			const endpoint = cleara11yExceptions.apiUrl.replace(/exceptions\/?$/, '')
				+ 'issues/filter-options?'
				+ $.param({type: type, search: $input.val()});
			if (request) {
				request.abort();
			}
			request = $.ajax({
				url: endpoint,
				method: 'GET',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', cleara11yExceptions.nonce);
				},
				success: function(response) {
					const options = response.options || [];
					if (!options.length) {
						$list.html('<li class="description">No matches found.</li>');
					} else {
						$list.empty();
						options.forEach(function(option) {
							const $button = $('<button>', {
								type: 'button',
								'data-option-id': String(option.id),
								'data-option-label': option.label,
								'data-option-url': option.url || '',
								'data-option-post-type': option.post_type || ''
							});
							$button.append($('<span>').text(option.label));
							if (option.post_type) {
								$button.append($('<small>').text(option.post_type));
							}
							$list.append($('<li>', {role: 'option'}).append($button));
						});
					}
					$list.prop('hidden', false);
					$input.attr('aria-expanded', 'true');
					revealWizardOptions($input, $list);
				},
				complete: function() {
					request = null;
				}
			});
		}
	}

	function revealWizardOptions($input, $list) {
		const $body = $input.closest('.cleara11y-wizard-body');
		$body.addClass('has-open-picker');
		const keepListAboveFooter = function() {
			const body = $body.get(0);
			const list = $list.get(0);
			if (!body || !list || $list.prop('hidden')) {
				return;
			}
			const overflow = list.getBoundingClientRect().bottom - body.getBoundingClientRect().bottom;
			if (overflow > 0) {
				body.scrollTop += overflow + 8;
			}
		};
		window.requestAnimationFrame(function() {
			keepListAboveFooter();
		});
		window.setTimeout(keepListAboveFooter, 220);
	}

	function hideWizardOptions($input, $list) {
		$list.prop('hidden', true);
		$input.attr('aria-expanded', 'false');
		$input.closest('.cleara11y-wizard-body').removeClass('has-open-picker');
	}

	function hideAllWizardOptions() {
		$('#cleara11y-wizard-modal .cleara11y-picker').each(function() {
			const $picker = $(this);
			hideWizardOptions($picker.find('[role="combobox"]'), $picker.find('[role="listbox"]'));
		});
	}

	function renderFindingContext() {
		const ruleId = (wizardState.data.rule_ids || [])[0] || '';
		const ruleTitle = wizardState.data.context?.rule_title || ruleTitles[ruleId] || ruleId || 'Unavailable';
		const pageTitle = wizardState.data.context?.page_title || wizardState.data.scope?.url || 'Unavailable';
		const selector = wizardState.data.element_match?.css_selector || 'Unavailable';
		$('#cleara11y-finding-rule-title').text(ruleTitle);
		$('#cleara11y-finding-rule-id').text(ruleId);
		$('#cleara11y-finding-page').text(pageTitle);
		$('#cleara11y-finding-selector').text(selector);
	}

	function renderSelectedPage() {
		const $selected = $('#cleara11y-selected-page').empty();
		const url = wizardState.data.scope?.url || '';
		if (!url) {
			return;
		}
		const label = wizardState.data.context?.page_title || url;
		const $item = $('<span>', {class: 'cleara11y-picker__selection'});
		$item.append($('<span>').text(label));
		if (wizardState.editingId) {
			$item.append($('<button>', {
				type: 'button',
				'data-remove-page': '1',
				'aria-label': 'Remove selected page'
			}).text('×'));
		}
		$selected.append($item);
	}

	function showWizardStep(step, moveFocus = false) {
		hideAllWizardOptions();
		wizardState.currentStep = step;
		const labels = [str('target'), str('scope'), str('duration'), str('reason'), str('step5Title')];
		$('#cleara11y-current-step').text(step);
		$('#cleara11y-current-step-label').text(labels[step - 1]);

		// Update step indicators
		$('.cleara11y-progress-step').removeClass('active completed').removeAttr('aria-current').each(function() {
			const stepNum = $(this).data('step');
			if (stepNum < step) {
				$(this).addClass('completed');
			} else if (stepNum === step) {
				$(this).addClass('active').attr('aria-current', 'step');
			}
		});

		// Show/hide step content
		$('.cleara11y-wizard-step').hide();
		$('.cleara11y-wizard-step[data-step="' + step + '"]').show();
		$('#cleara11y-wizard-back').prop('hidden', step === 1);

		// Update buttons
		if (step === wizardState.totalSteps) {
			$('#cleara11y-wizard-next').prop('hidden', true);
			$('#cleara11y-wizard-create').prop('hidden', false);
			loadImpactPreview();
		} else {
			$('#cleara11y-wizard-next').prop('hidden', false);
			$('#cleara11y-wizard-create').prop('hidden', true);
			updateNextButtonState();
		}

		if (moveFocus) {
			$('.cleara11y-wizard-step[data-step="' + step + '"] h3').first().trigger('focus');
		}
		$('.cleara11y-wizard-body').scrollTop(0);
	}

	function trapWizardFocus(event) {
		const focusable = $('#cleara11y-wizard-modal .cleara11y-wizard-content')
			.find('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')
			.filter(':visible')
			.toArray();
		if (!focusable.length) {
			return;
		}
		const first = focusable[0];
		const last = focusable[focusable.length - 1];
		if (event.shiftKey && document.activeElement === first) {
			event.preventDefault();
			last.focus();
		} else if (!event.shiftKey && document.activeElement === last) {
			event.preventDefault();
			first.focus();
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
				return targetType === 'element' || Boolean((wizardState.data.rule_ids || []).length);

			case 2:
				const scopeType = $('input[name="scope_type"]:checked').val();
				if (!scopeType) return false;

				if (scopeType === 'page' && !wizardState.selectedPageUrl) return false;
				if (scopeType === 'content_type' && !$('input[name="post_types"]:checked').length) return false;
				if (scopeType === 'url_pattern' && !$('#cleara11y-scope-patterns').val().trim()) return false;
				return true;

			case 3:
				const durationType = $('input[name="duration_type"]:checked').val();
				if (!durationType) return false;
				if (durationType === 'until_date' && !$('#cleara11y-expires-at').val()) return false;
				return true;

			case 4:
				return $('#cleara11y-reason-category').val() !== '' && $('#cleara11y-note').val().trim() !== '';

			default:
				return true;
		}
	}

	function saveCurrentStep() {
		const step = wizardState.currentStep;

		switch(step) {
			case 1:
				wizardState.data.target_type = $('input[name="target_type"]:checked').val();
				break;

			case 2:
				const scopeType = $('input[name="scope_type"]:checked').val();
				const selectedPageUrl = wizardState.selectedPageUrl;
				wizardState.data.scope = { scope_type: scopeType };

				if (scopeType === 'page') {
					wizardState.data.scope.url = selectedPageUrl;
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
		const targetLabels = {
			rule: str('ruleOnly'),
			element: str('elementOnly'),
			rule_on_element: str('ruleOnElement')
		};
		let targetHtml = '<p><strong>Type:</strong> ' + esc_html(targetLabels[wizardState.data.target_type] || wizardState.data.target_type) + '</p>';
		if (wizardState.data.rule_ids.length) {
			targetHtml += '<p><strong>Source rule:</strong> ' + esc_html(wizardState.data.rule_ids.join(', ')) + '</p>';
		}
		if (wizardState.data.element_match.css_selector) {
			targetHtml += '<p><strong>CSS Selector:</strong> <code>' + esc_html(wizardState.data.element_match.css_selector) + '</code></p>';
		} else if (wizardState.data.element_match.element_fingerprint) {
			targetHtml += '<p><strong>Element Fingerprint:</strong> <code>' + esc_html(wizardState.data.element_match.element_fingerprint.substring(0, 16)) + '...</code></p>';
		}
		$('#cleara11y-review-target').html(targetHtml);

		// Scope
		const scopeLabels = {
			page: str('singlePage'),
			site: str('entireSite'),
			content_type: str('contentTypes'),
			url_pattern: str('urlPattern')
		};
		let scopeHtml = '<p><strong>Type:</strong> ' + esc_html(scopeLabels[wizardState.data.scope.scope_type] || wizardState.data.scope.scope_type) + '</p>';
		if (wizardState.data.scope.url) {
			scopeHtml += '<p><strong>URL:</strong> ' + esc_html(wizardState.data.scope.url) + '</p>';
		} else if (wizardState.data.scope.post_types) {
			scopeHtml += '<p><strong>Post Types:</strong> ' + esc_html(wizardState.data.scope.post_types.join(', ')) + '</p>';
		} else if (wizardState.data.scope.patterns) {
			scopeHtml += '<p><strong>Patterns:</strong> ' + esc_html(wizardState.data.scope.patterns.join(', ')) + '</p>';
		}
		$('#cleara11y-review-scope').html(scopeHtml);

		// Duration
		const durationLabels = {
			until_next_scan: str('untilNextScan'),
			permanent: str('permanent'),
			until_date: str('untilDate'),
			until_content_changes: str('untilContentChanges')
		};
		let durationHtml = '<p><strong>Type:</strong> ' + esc_html(durationLabels[wizardState.data.duration.duration_type] || wizardState.data.duration.duration_type) + '</p>';
		if (wizardState.data.duration.expires_at) {
			durationHtml += '<p><strong>Expires:</strong> ' + esc_html(wizardState.data.duration.expires_at) + '</p>';
		}
		$('#cleara11y-review-duration').html(durationHtml);

		// Reason
		const reasonLabel = $('#cleara11y-reason-category option:selected').text() || wizardState.data.reason_category;
		let reasonHtml = '<p><strong>Category:</strong> ' + esc_html(reasonLabel) + '</p>';
		if (wizardState.data.note) {
			reasonHtml += '<p><strong>Note:</strong> ' + esc_html(wizardState.data.note) + '</p>';
		}
		$('#cleara11y-review-reason').html(reasonHtml);
	}

	function loadImpactPreview() {
		$('#cleara11y-impact-preview-content').html('<span class="spinner is-active"></span> ' + esc_html(str('calculatingImpact')));

		$.ajax({
			url: cleara11yExceptions.apiUrl + '/preview',
			method: 'POST',
			data: JSON.stringify(wizardState.data),
			contentType: 'application/json',
			beforeSend: function(xhr) {
				xhr.setRequestHeader('X-WP-Nonce', cleara11yExceptions.nonce);
			},
			success: function(response) {
				wizardState.impactPreview = response.data;
				renderImpactPreview(response.data);
				$('#cleara11y-wizard-create').prop('disabled', false);
			},
			error: function() {
				$('#cleara11y-impact-preview-content').html('<p class="cleara11y-impact-error">' + esc_html(str('failedToCalculate')) + '</p>');
				$('#cleara11y-wizard-create').prop('disabled', false);
			}
		});
	}

	function renderImpactPreview(impact) {
		let html = '';

		if (impact.issues > 0) {
			html += '<div class="cleara11y-impact-item">';
			html += '<span class="cleara11y-impact-count">' + impact.issues + '</span> ';
			html += esc_html(str('issuesExcepted'));
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

	function createExceptionRule() {
		const $createBtn = $('#cleara11y-wizard-create');
		const createLabel = wizardState.editingId ? 'Save Exception' : str('createRule');
		$('.cleara11y-wizard-error').remove();
		$createBtn.prop('disabled', true).text(esc_html(wizardState.editingId ? 'Saving...' : str('creating')));

		$.ajax({
			url: cleara11yExceptions.apiUrl + (wizardState.editingId ? '/' + encodeURIComponent(wizardState.editingId) : ''),
			method: wizardState.editingId ? 'PUT' : 'POST',
			data: JSON.stringify(wizardState.data),
			contentType: 'application/json',
			beforeSend: function(xhr) {
				xhr.setRequestHeader('X-WP-Nonce', cleara11yExceptions.nonce);
			},
			success: function(response) {
				const successMessage = wizardState.editingId ? 'Exception updated successfully.' : str('createSuccess');
				const savedViolationId = Number(wizardState.data.violation_id || 0);
				const wasEditing = Boolean(wizardState.editingId);
				closeWizard();
				if (!wasEditing && savedViolationId) {
					document.dispatchEvent(new CustomEvent('cleara11y:exception-saved', {
						detail: {violationId: savedViolationId}
					}));
				}
				if ($tbody.length) {
					loadRules();
				}
				const $notice = $(
					'<div class="notice notice-success is-dismissible" style="margin: 20px 0;">' +
					'<p>' + esc_html(successMessage) + '</p>' +
					'</div>'
				);
				const $createLink = $('#cleara11y-create-exception');
				if ($createLink.length) {
					$createLink.after($notice);
				} else {
					$('.wrap h1').first().after($notice);
				}
				setTimeout(function() {
					$('.notice.is-dismissible').fadeOut(function() {
						$(this).remove();
					});
				}, 3000);
			},
			error: function(xhr) {
				const error = xhr.responseJSON?.message || str('createFailed');
				const $notice = $('<div>', {
					class: 'notice notice-error inline cleara11y-wizard-error',
					role: 'alert',
					tabindex: '-1'
				}).append($('<p>').text(error));
				$('#cleara11y-impact-preview-section').after($notice);
				$createBtn.prop('disabled', false).text(esc_html(createLabel));
				$notice.trigger('focus');
			}
		});
	}

	function closeWizard() {
		$('#cleara11y-wizard-modal').remove();
		if (wizardReturnFocus && document.contains(wizardReturnFocus)) {
			wizardReturnFocus.focus();
		}
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
	$('#cleara11y-exceptions-first-page').on('click', function() {
		if (state.currentPage > 1) {
			state.currentPage = 1;
			loadRules();
		}
	});

	$('#cleara11y-exceptions-prev-page').on('click', function() {
		if (state.currentPage > 1) {
			state.currentPage--;
			loadRules();
		}
	});

	$('#cleara11y-exceptions-next-page').on('click', function() {
		if (state.currentPage < state.totalPages) {
			state.currentPage++;
			loadRules();
		}
	});

	$('#cleara11y-exceptions-last-page').on('click', function() {
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
