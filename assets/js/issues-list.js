/**
 * ClearA11y Issues List JavaScript
 *
 * Handles the issues list page functionality.
 *
 * @package ClearA11y
 */

(function() {
	'use strict';

	const API_URL = cleara11yData.apiUrl;
	const NONCE = cleara11yData.nonce;

	// State
	let currentPage = 1;
	let totalPages = 1;
	let currentFilters = {
		severity: '',
		status: 'active',
		search: ''
	};

	/**
	 * Issues List Module
	 */
	const IssuesList = {
		/**
		 * Initialize
		 */
		init() {
			this.setupEventListeners();
			this.loadStats();
			this.loadIssues();
		},

		/**
		 * Setup event listeners
		 */
		setupEventListeners() {
			// Filter changes
			document.getElementById('cleara11y-filter-severity').addEventListener('change', (e) => {
				currentFilters.severity = e.target.value;
				currentPage = 1;
				this.loadIssues();
			});

			document.getElementById('cleara11y-filter-status').addEventListener('change', (e) => {
				currentFilters.status = e.target.value;
				currentPage = 1;
				this.loadIssues();
			});

			// Search input (debounced)
			let searchTimeout;
			document.getElementById('cleara11y-search-issues').addEventListener('input', (e) => {
				clearTimeout(searchTimeout);
				searchTimeout = setTimeout(() => {
					currentFilters.search = e.target.value.trim();
					currentPage = 1;
					this.loadIssues();
				}, 300);
			});

			// Reset filters
			document.getElementById('cleara11y-reset-filters').addEventListener('click', () => {
				document.getElementById('cleara11y-filter-severity').value = '';
				document.getElementById('cleara11y-filter-status').value = 'active';
				document.getElementById('cleara11y-search-issues').value = '';
				currentFilters = {
					severity: '',
					status: 'active',
					search: ''
				};
				currentPage = 1;
				this.loadIssues();
			});

			// Pagination
			document.getElementById('cleara11y-prev-page').addEventListener('click', () => {
				if (currentPage > 1) {
					currentPage--;
					this.loadIssues();
				}
			});

			document.getElementById('cleara11y-next-page').addEventListener('click', () => {
				if (currentPage < totalPages) {
					currentPage++;
					this.loadIssues();
				}
			});

			// Modal close handlers
			this.setupModalHandlers();
		},

		/**
		 * Setup modal handlers
		 */
		setupModalHandlers() {
			const modal = document.getElementById('cleara11y-issue-modal');

			// Close buttons
			const closeButtons = modal.querySelectorAll('.cleara11y-modal-close, .cleara11y-modal-close-btn');
			closeButtons.forEach(btn => {
				btn.addEventListener('click', () => {
					modal.style.display = 'none';
				});
			});

			// Close on backdrop click
			modal.addEventListener('click', (e) => {
				if (e.target === modal) {
					modal.style.display = 'none';
				}
			});
		},

		/**
		 * Load issues statistics
		 */
		async loadStats() {
			try {
				const response = await fetch(API_URL + 'issues/stats', {
					headers: { 'X-WP-Nonce': NONCE }
				});

				if (!response.ok) {
					throw new Error('Failed to load stats');
				}

				const stats = await response.json();

				// Update stats display
				document.getElementById('cleara11y-total-issues').textContent = stats.active || 0;
				document.getElementById('cleara11y-critical-issues').textContent = stats.critical || 0;
				document.getElementById('cleara11y-moderate-issues').textContent = stats.moderate || 0;
				document.getElementById('cleara11y-minor-issues').textContent = stats.minor || 0;

				// Update exception count if element exists
				const ignoredEl = document.getElementById('cleara11y-ignored-issues');
				if (ignoredEl) {
					ignoredEl.textContent = stats.ignored || 0;
				}

			} catch (error) {
				console.error('[ClearA11y Issues List] Error loading stats:', error);
			}
		},

		/**
		 * Load issues
		 */
		async loadIssues() {
			const container = document.querySelector('.cleara11y-issues-container');

			// Show loading
			container.innerHTML = `
				<div class="cleara11y-loading-spinner" style="text-align: center; padding: 40px;">
					<span class="spinner is-active" style="float: none; margin: 0;"></span>
					<p style="margin-top: 15px;">${cleara11yData.strings.loading}</p>
				</div>
			`;

			try {
				// Build query params
				const params = new URLSearchParams({
					page: currentPage,
					per_page: 20
				});

				if (currentFilters.severity) {
					params.append('severity', currentFilters.severity);
				}

				if (currentFilters.status) {
					params.append('status', currentFilters.status);
				}

				if (currentFilters.search) {
					params.append('search', currentFilters.search);
				}

				const response = await fetch(API_URL + 'issues/list?' + params.toString(), {
					headers: { 'X-WP-Nonce': NONCE }
				});

				if (!response.ok) {
					throw new Error('Failed to load issues');
				}

				const data = await response.json();

				totalPages = data.total_pages || 1;
				currentPage = data.page || 1;

				this.renderIssues(data.data || []);
				this.updatePagination();

			} catch (error) {
				console.error('[ClearA11y Issues List] Error loading issues:', error);
				container.innerHTML = `
					<div class="notice notice-error" style="padding: 15px;">
						<p>${cleara11yData.strings.error}: ${this.escapeHtml(error.message)}</p>
					</div>
				`;
			}
		},

		/**
		 * Render issues list
		 */
		renderIssues(issues) {
			const container = document.querySelector('.cleara11y-issues-container');

			if (issues.length === 0) {
				container.innerHTML = `
					<div class="notice notice-info" style="padding: 15px;">
						<p>${cleara11yData.strings.noIssues}</p>
					</div>
				`;
				return;
			}

			// Group issues by severity
			const critical = issues.filter(i => i.severity === 'critical');
			const moderate = issues.filter(i => i.severity === 'moderate');
			const minor = issues.filter(i => i.severity === 'minor');

			let html = '';

			const renderIssue = (issue) => {
				const severityColors = {
					critical: '#d63638',
					moderate: '#f56e28',
					minor: '#ffb900'
				};

				return `
					<div class="cleara11y-issue-row" data-issue-id="${issue.id}" style="padding: 15px; border-bottom: 1px solid #c3c4c7;">
						<div style="display: flex; justify-content: space-between; align-items: start; gap: 20px;">
							<div style="flex: 1;">
								<div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
									<h4 style="margin: 0; font-size: 14px;">
										${this.escapeHtml(issue.rule_id || 'Unknown Issue')}
									</h4>
									<span class="cleara11y-issue-badge" style="background: ${severityColors[issue.severity]}; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: 600;">
										${issue.severity}
									</span>
								</div>
								<p style="margin: 0 0 8px 0; font-size: 13px; color: #646970;">
									${this.escapeHtml(issue.message || issue.description || '')}
								</p>
								<div style="font-size: 12px; color: #646970;">
									<strong>Page:</strong>
									<a href="${this.escapeHtml(issue.post_url)}" target="_blank" style="text-decoration: none;">
										${this.escapeHtml(issue.post_title || 'N/A')}
									</a>
								</div>
								${issue.selector ? `
									<div style="font-size: 12px; color: #646970; margin-top: 4px;">
										<strong>Selector:</strong> <code style="background: #f0f0f1; padding: 2px 6px; border-radius: 3px;">${this.escapeHtml(issue.selector)}</code>
									</div>
								` : ''}
							</div>
							<div class="cleara11y-issue-actions" style="display: flex; gap: 8px; align-items: center;">
								<button type="button" class="button button-small cleara11y-ignore-wizard" data-issue-id="${issue.id}" data-rule-id="${this.escapeHtml(issue.rule_id || '')}" data-selector="${this.escapeHtml(issue.selector || '')}" data-message="${this.escapeHtml(issue.message || '')}">
									<span class="dashicons dashicons-admin-tools" style="margin-top: 3px;"></span>
									Mark as Exception…
								</button>
								<button type="button" class="button button-small cleara11y-quick-ignore" data-issue-id="${issue.id}" data-selector="${this.escapeHtml(issue.selector || '')}" data-rule-id="${this.escapeHtml(issue.rule_id || '')}" title="Temporary exception - exclude from active issue counts until next scan">
									<span class="dashicons dashicons-dismiss" style="margin-top: 3px;"></span>
									Temporary Exception
								</button>
							</div>
						</div>
					</div>
				`;
			};

			// Render by severity
			if (critical.length > 0) {
				html += `<div style="background: #fff; border: 1px solid #c3c4c7; border-top: 3px solid #d63638; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
					<div style="padding: 12px 15px; background: #f6f7f7; border-bottom: 1px solid #c3c4c7;">
						<h3 style="margin: 0; font-size: 14px; color: #d63638;">Critical Issues (${critical.length})</h3>
					</div>
					${critical.map(renderIssue).join('')}
				</div>`;
			}

			if (moderate.length > 0) {
				html += `<div style="background: #fff; border: 1px solid #c3c4c7; border-top: 3px solid #f56e28; box-shadow: 0 1px 1px rgba(0,0,0,.04); margin-top: 20px;">
					<div style="padding: 12px 15px; background: #f6f7f7; border-bottom: 1px solid #c3c4c7;">
						<h3 style="margin: 0; font-size: 14px; color: #f56e28;">Moderate Issues (${moderate.length})</h3>
					</div>
					${moderate.map(renderIssue).join('')}
				</div>`;
			}

			if (minor.length > 0) {
				html += `<div style="background: #fff; border: 1px solid #c3c4c7; border-top: 3px solid #ffb900; box-shadow: 0 1px 1px rgba(0,0,0,.04); margin-top: 20px;">
					<div style="padding: 12px 15px; background: #f6f7f7; border-bottom: 1px solid #c3c4c7;">
						<h3 style="margin: 0; font-size: 14px; color: #dba617;">Minor Issues (${minor.length})</h3>
					</div>
					${minor.map(renderIssue).join('')}
				</div>`;
			}

			container.innerHTML = html;

			// Attach event handlers
			this.attachIssueHandlers();
		},

		/**
		 * Attach event handlers for issue actions
		 */
		attachIssueHandlers() {
			// View details buttons
			document.querySelectorAll('.cleara11y-view-issue').forEach(btn => {
				btn.addEventListener('click', (e) => {
					const issueId = e.currentTarget.dataset.issueId;
					this.viewIssueDetails(parseInt(issueId));
				});
			});

			// Temporary Exception buttons
			document.querySelectorAll('.cleara11y-quick-ignore').forEach(btn => {
				btn.addEventListener('click', (e) => {
					const issueId = parseInt(e.currentTarget.dataset.issueId);
					this.quickIgnoreIssue(issueId);
				});
			});

			// Exception Wizard buttons
			document.querySelectorAll('.cleara11y-ignore-wizard').forEach(btn => {
				btn.addEventListener('click', (e) => {
					const issueId = parseInt(e.currentTarget.dataset.issueId);
					const ruleId = e.currentTarget.dataset.ruleId || '';
					const selector = e.currentTarget.dataset.selector || '';
					const message = e.currentTarget.dataset.message || '';
					this.openIgnoreWizard(issueId, ruleId, selector, message);
				});
			});
		},

		/**
		 * View issue details in modal
		 */
		async viewIssueDetails(issueId) {
			// Find the issue element to get current data
			const issueElement = document.querySelector(`[data-issue-id="${issueId}"]`);
			if (!issueElement) return;

			// Get basic info from the element
			const ruleId = issueElement.querySelector('h4')?.textContent || '';
			const message = issueElement.querySelector('p')?.textContent || '';
			const severity = issueElement.querySelector('.cleara11y-issue-badge')?.textContent || '';

			const modal = document.getElementById('cleara11y-issue-modal');
			const content = modal.querySelector('.cleara11y-issue-detail-content');

			content.innerHTML = `
				<div style="padding: 10px;">
					<h3 style="margin: 0 0 15px 0;">${this.escapeHtml(ruleId)}</h3>
					<span class="cleara11y-issue-badge" style="background: ${severity === 'critical' ? '#d63638' : severity === 'moderate' ? '#f56e28' : '#ffb900'}; color: #fff; padding: 4px 10px; border-radius: 3px; font-size: 12px;">
						${severity}
					</span>
					<p style="margin: 15px 0;">${this.escapeHtml(message)}</p>
					<p style="color: #646970; font-size: 13px;">
						<strong>To view full details and manage this issue, please go to the page's scan results.</strong>
					</p>
				</div>
			`;

			modal.style.display = 'block';
		},

			/**
			 * Open exception wizard with pre-filled data from an issue
			 */

			openIgnoreWizard(issueId, ruleId, selector, message) {
				// Wait for DOM to be ready and ignores page script to load
				if (typeof jQuery === 'undefined' || typeof openCreateWizard !== 'function') {
					console.error('[ClearA11y Issues List] Wizard not available. Make sure ignores-page.js is loaded.');
					alert('The exception wizard is not available. Please try refreshing the page or go to the Exceptions page directly.');
					return;
				}

				// Access the wizard state from ignores-page.js
				if (typeof wizardState === 'undefined') {
					console.error('[ClearA11y Issues List] Wizard state not available');
					return;
				}

				// Pre-fill the wizard state with issue data
				wizardState.currentStep = 1;
				wizardState.data.target_type = 'rule_on_element';
				wizardState.data.rule_ids = ruleId ? [ruleId] : [];
				wizardState.data.element_match = {
					css_selector: selector || ''
				};
				wizardState.data.scope = { scope_type: 'page' };
				wizardState.data.duration = { duration_type: 'until_next_scan' };
				wizardState.data.reason_category = '';
				wizardState.data.note = message || '';

				console.log('[ClearA11y Issues List] Opening wizard with pre-filled data:', wizardState.data);

				// Open the wizard using the function from ignores-page.js
				try {
					openCreateWizard();

					// Pre-fill the form fields
					setTimeout(() => {
						// Set target type
						jQuery('input[name="target_type"][value="rule_on_element"]').prop('checked', true).trigger('change');

						// Fill rule IDs
						jQuery('#cleara11y-rule-ids').val(ruleId);

						// Set element match type to CSS selector
						jQuery('input[name="element_match_type"][value="css_selector"]').prop('checked', true).trigger('change');

						// Fill CSS selector
						jQuery('#cleara11y-css-selector').val(selector);

						// Set scope to page (default)
						jQuery('input[name="scope_type"][value="page"]').prop('checked', true).trigger('change');

						// Set duration to until next scan (default)
						jQuery('input[name="duration_type"][value="until_next_scan"]').prop('checked', true).trigger('change');

						// Enable next button
						jQuery('#cleara11y-wizard-next').prop('disabled', false);
					}, 100);
				} catch (error) {
					console.error('[ClearA11y Issues List] Error opening wizard:', error);
					alert('Error opening exception wizard. Please try again.');
				}
			},

		/**
		 * Create a temporary exception for an issue.
		 */
		async quickIgnoreIssue(issueId) {
			try {
				const response = await fetch(API_URL + 'ignores/quick', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': typeof cleara11yIgnores !== 'undefined' ? cleara11yIgnores.nonce : NONCE
					},
					body: JSON.stringify({ violation_id: issueId })
				});

				if (!response.ok) {
					throw new Error('Failed to create temporary exception');
				}

				const data = await response.json();

				// Show success toast with undo button
				this.showToast(data.message || 'Issue marked as a temporary exception until next scan', data.id, 'quick-ignore');

				// Reload issues
				this.loadIssues();
				this.loadStats();

			} catch (error) {
				console.error('[ClearA11y Issues List] Error creating temporary exception:', error);
				alert('Error creating temporary exception: ' + error.message);
			}
		},

		/**
		 * Show toast notification with optional undo button
		 */
		showToast(message, undoId = null, undoType = 'quick-ignore') {
			// Remove existing toasts
			const existingToast = document.querySelector('.cleara11y-toast');
			if (existingToast) {
				existingToast.remove();
			}

			// Create toast element
			const toast = document.createElement('div');
			toast.className = 'cleara11y-toast';
			toast.innerHTML = `
				<div class="cleara11y-toast-content">
					<span class="cleara11y-toast-message">${message}</span>
					${undoId ? `
						<button type="button" class="button button-small cleara11y-toast-undo" data-undo-id="${undoId}" data-undo-type="${undoType}">
							<span class="dashicons dashicons-undo" style="margin-top: 3px;"></span>
							Undo
						</button>
					` : ''}
					<button type="button" class="cleara11y-toast-close" aria-label="Close">
						<span class="dashicons dashicons-no-alt"></span>
					</button>
				</div>
			`;

			// Add to page
			document.body.appendChild(toast);

			// Animate in
			requestAnimationFrame(() => {
				toast.classList.add('cleara11y-toast-visible');
			});

			// Auto-hide after 5 seconds if no undo button
			if (!undoId) {
				setTimeout(() => {
					this.hideToast(toast);
				}, 5000);
			}

			// Attach event listeners
			toast.querySelector('.cleara11y-toast-close')?.addEventListener('click', () => {
				this.hideToast(toast);
			});

			if (undoId) {
				toast.querySelector('.cleara11y-toast-undo')?.addEventListener('click', (e) => {
					this.handleUndo(undoId, undoType);
				});
			}
		},

		/**
		 * Hide toast notification
		 */
		hideToast(toast = null) {
			const toastEl = toast || document.querySelector('.cleara11y-toast');
			if (toastEl) {
				toastEl.classList.remove('cleara11y-toast-visible');
				setTimeout(() => {
					toastEl.remove();
				}, 300);
			}
		},

		/**
		 * Handle undo action from toast
		 */
		async handleUndo(undoId, undoType) {
			try {
				if (undoType === 'quick-ignore') {
					// Undo temporary exception
					const response = await fetch(`${API_URL}ignores/${undoId}/undo`, {
						method: 'POST',
						headers: {
							'X-WP-Nonce': typeof cleara11yIgnores !== 'undefined' ? cleara11yIgnores.nonce : NONCE
						}
					});

					if (!response.ok) {
						throw new Error('Failed to undo temporary exception');
					}

					this.hideToast();
					this.showToast('Temporary exception removed');
					this.loadIssues();
					this.loadStats();

				} else {
					console.warn('Unknown undo type:', undoType);
				}
			} catch (error) {
				console.error('[ClearA11y Issues List] Error handling undo:', error);
				alert('Error undoing action: ' + error.message);
			}
		},

		/**
		 * Update pagination controls
		 */
		updatePagination() {
			const pagination = document.querySelector('.cleara11y-pagination');
			const pageInfo = document.getElementById('cleara11y-page-info');
			const prevBtn = document.getElementById('cleara11y-prev-page');
			const nextBtn = document.getElementById('cleara11y-next-page');

			pageInfo.textContent = `Page ${currentPage} of ${totalPages}`;

			prevBtn.disabled = (currentPage <= 1);
			nextBtn.disabled = (currentPage >= totalPages);

			if (totalPages > 1) {
				pagination.style.display = 'flex';
			} else {
				pagination.style.display = 'none';
			}
		},

		/**
		 * Escape HTML
		 */
		escapeHtml(text) {
			const div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		}
	};

	// Initialize on DOM ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', () => IssuesList.init());
	} else {
		IssuesList.init();
	}
})();
