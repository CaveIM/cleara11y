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
		dismissed: 'active',
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

			document.getElementById('cleara11y-filter-dismissed').addEventListener('change', (e) => {
				currentFilters.dismissed = e.target.value;
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
				document.getElementById('cleara11y-filter-dismissed').value = 'active';
				document.getElementById('cleara11y-search-issues').value = '';
				currentFilters = {
					severity: '',
					dismissed: 'active',
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

				if (currentFilters.dismissed) {
					params.append('dismissed', currentFilters.dismissed);
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
				const isDismissed = !!issue.dismissed;
				const severityColors = {
					critical: '#d63638',
					moderate: '#f56e28',
					minor: '#ffb900'
				};

				return `
					<div class="cleara11y-issue-row" data-issue-id="${issue.id}" style="padding: 15px; border-bottom: 1px solid #c3c4c7; ${isDismissed ? 'opacity: 0.6;' : ''}">
						<div style="display: flex; justify-content: space-between; align-items: start; gap: 20px;">
							<div style="flex: 1;">
								<div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
									<h4 style="margin: 0; font-size: 14px;">
										${this.escapeHtml(issue.rule_id || 'Unknown Issue')}
									</h4>
									<span class="cleara11y-issue-badge" style="background: ${severityColors[issue.severity]}; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: 600;">
										${issue.severity}
									</span>
									${isDismissed ? '<span class="dashicons dashicons-hidden" style="color: #646970;" title="Dismissed"></span>' : ''}
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
								${isDismissed && issue.dismissal_comment ? `
									<div style="margin-top: 8px; padding: 8px; background: #f0f0f1; border-left: 3px solid #646970; border-radius: 3px;">
										<span style="font-size: 11px; color: #646970;">
											<strong>Dismissed:</strong> ${issue.dismissed_at ? new Date(issue.dismissed_at).toLocaleDateString() : ''}
											${issue.dismissal_comment ? `<br><em>"${this.escapeHtml(issue.dismissal_comment)}"</em>` : ''}
										</span>
									</div>
								` : ''}
							</div>
							<div class="cleara11y-issue-actions" style="display: flex; gap: 8px; align-items: center;">
								<button type="button" class="button button-small cleara11y-view-issue" data-issue-id="${issue.id}">
									<span class="dashicons dashicons-visibility" style="margin-top: 3px;"></span>
									View
								</button>
								${isDismissed ? `
									<button type="button" class="button button-small cleara11y-undismiss-issue" data-issue-id="${issue.id}">
										<span class="dashicons dashicons-undo" style="margin-top: 3px;"></span>
										Undo
									</button>
								` : `
									<button type="button" class="button button-small cleara11y-dismiss-issue" data-issue-id="${issue.id}">
										<span class="dashicons dashicons-hidden" style="margin-top: 3px;"></span>
										Dismiss
									</button>
								`}
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

			// Dismiss buttons
			document.querySelectorAll('.cleara11y-dismiss-issue').forEach(btn => {
				btn.addEventListener('click', (e) => {
					const issueId = parseInt(e.currentTarget.dataset.issueId);
					this.dismissIssue(issueId);
				});
			});

			// Undismiss buttons
			document.querySelectorAll('.cleara11y-undismiss-issue').forEach(btn => {
				btn.addEventListener('click', (e) => {
					const issueId = parseInt(e.currentTarget.dataset.issueId);
					this.undismissIssue(issueId);
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
		 * Dismiss an issue
		 */
		async dismissIssue(issueId) {
			const comment = prompt('Enter a comment (optional):');
			if (comment === null) return; // User cancelled

			try {
				const response = await fetch(API_URL + 'issues/' + issueId + '/dismiss', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': NONCE
					},
					body: JSON.stringify({ comment: comment || '' })
				});

				if (!response.ok) {
					throw new Error('Failed to dismiss issue');
				}

				// Reload issues
				this.loadIssues();
				this.loadStats();

			} catch (error) {
				console.error('[ClearA11y Issues List] Error dismissing issue:', error);
				alert('Error dismissing issue: ' + error.message);
			}
		},

		/**
		 * Undismiss an issue
		 */
		async undismissIssue(issueId) {
			try {
				const response = await fetch(API_URL + 'issues/' + issueId + '/undismiss', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': NONCE
					}
				});

				if (!response.ok) {
					throw new Error('Failed to undismiss issue');
				}

				// Reload issues
				this.loadIssues();
				this.loadStats();

			} catch (error) {
				console.error('[ClearA11y Issues List] Error undismissing issue:', error);
				alert('Error undismissing issue: ' + error.message);
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
