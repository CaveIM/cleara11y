/**
 * ClearA11y Pages List JavaScript
 *
 * Handles the pages list page functionality including:
 * - Loading and displaying pages with accessibility scores
 * - Filtering by post type, status, severity
 * - Sorting by various criteria
 * - Viewing page issues in modal
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
		post_type: 'page',
		status: 'all',
		severity: '',
		search: '',
		orderby: 'scanned_date',
		order: 'desc'
	};

	/**
	 * Pages List Module
	 */
	const PagesList = {
		/**
		 * Initialize
		 */
		init() {
			this.setupEventListeners();
			this.loadStats();
			this.loadPages();
		},

		/**
		 * Setup event listeners
		 */
		setupEventListeners() {
			// Filter changes
			document.getElementById('cleara11y-filter-post-type').addEventListener('change', (e) => {
				currentFilters.post_type = e.target.value;
				currentPage = 1;
				this.loadPages();
				this.loadStats();
			});

			document.getElementById('cleara11y-filter-status').addEventListener('change', (e) => {
				currentFilters.status = e.target.value;
				currentPage = 1;
				this.loadPages();
			});

			document.getElementById('cleara11y-filter-severity').addEventListener('change', (e) => {
				currentFilters.severity = e.target.value;
				currentPage = 1;
				this.loadPages();
			});

			document.getElementById('cleara11y-sort-by').addEventListener('change', (e) => {
				const sortMap = {
					'scanned_date': 'scanned_date',
					'score': 'score',
					'issues': 'issues',
					'title': 'title'
				};
				currentFilters.orderby = sortMap[e.target.value] || 'scanned_date';
				currentFilters.order = (e.target.value === 'score') ? 'asc' : 'desc';
				currentPage = 1;
				this.loadPages();
			});

			// Search input (debounced)
			let searchTimeout;
			document.getElementById('cleara11y-search-pages').addEventListener('input', (e) => {
				clearTimeout(searchTimeout);
				searchTimeout = setTimeout(() => {
					currentFilters.search = e.target.value.trim();
					currentPage = 1;
					this.loadPages();
				}, 300);
			});

			// Reset filters
			document.getElementById('cleara11y-reset-filters').addEventListener('click', () => {
				document.getElementById('cleara11y-filter-post-type').value = 'page';
				document.getElementById('cleara11y-filter-status').value = 'all';
				document.getElementById('cleara11y-filter-severity').value = '';
				document.getElementById('cleara11y-sort-by').value = 'scanned_date';
				document.getElementById('cleara11y-search-pages').value = '';
				currentFilters = {
					post_type: 'page',
					status: 'all',
					severity: '',
					search: '',
					orderby: 'scanned_date',
					order: 'desc'
				};
				currentPage = 1;
				this.loadPages();
				this.loadStats();
			});

			// Pagination
			document.getElementById('cleara11y-prev-page').addEventListener('click', () => {
				if (currentPage > 1) {
					currentPage--;
					this.loadPages();
				}
			});

			document.getElementById('cleara11y-next-page').addEventListener('click', () => {
				if (currentPage < totalPages) {
					currentPage++;
					this.loadPages();
				}
			});

			// Modal close handlers
			this.setupModalHandlers();
		},

		/**
		 * Setup modal handlers
		 */
		setupModalHandlers() {
			const modal = document.getElementById('cleara11y-page-issues-modal');

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
		 * Load pages statistics
		 */
		async loadStats() {
			try {
				const params = new URLSearchParams({
					post_type: currentFilters.post_type,
					status: 'all',
					per_page: 1 // Just to get counts
				});

				const response = await fetch(API_URL + 'pages/list?' + params.toString(), {
					headers: { 'X-WP-Nonce': NONCE }
				});

				if (!response.ok) {
					throw new Error('Failed to load stats');
				}

				const data = await response.json();

				// Calculate stats
				const scanned = data.data?.filter(p => p.scan_status === 'completed').length || 0;
				const total = data.total || 0;
				const unscanned = total - scanned;

				// Get all pages to calculate average score
				const allPagesResponse = await fetch(API_URL + 'pages/list?post_type=' + currentFilters.post_type + '&status=scanned&per_page=1000', {
					headers: { 'X-WP-Nonce': NONCE }
				});

				let avgScore = '-';
				if (allPagesResponse.ok) {
					const allPagesData = await allPagesResponse.json();
					const scores = allPagesData.data?.map(p => p.score).filter(s => s !== null) || [];
					if (scores.length > 0) {
						const sum = scores.reduce((a, b) => a + b, 0);
						avgScore = Math.round(sum / scores.length);
					}
				}

				// Update stats display
				document.getElementById('cleara11y-total-pages').textContent = total || '-';
				document.getElementById('cleara11y-scanned-pages').textContent = scanned || '-';
				document.getElementById('cleara11y-unscanned-pages').textContent = unscanned || '-';
				document.getElementById('cleara11y-avg-score').textContent = avgScore;

			} catch (error) {
				console.error('[ClearA11y Pages List] Error loading stats:', error);
			}
		},

		/**
		 * Load pages
		 */
		async loadPages() {
			const container = document.querySelector('.cleara11y-pages-container');

			// Show loading
			container.innerHTML = `
				<div class="cleara11y-loading-spinner" style="text-align: center; padding: 40px;">
					<span class="spinner is-active" style="float: none; margin: 0;"></span>
					<p style="margin-top: 15px;">${cleara11yData.strings.loading || 'Loading...'}</p>
				</div>
			`;

			try {
				// Build query params
				const params = new URLSearchParams({
					post_type: currentFilters.post_type,
					status: currentFilters.status,
					orderby: currentFilters.orderby,
					order: currentFilters.order,
					page: currentPage,
					per_page: 20
				});

				if (currentFilters.severity) {
					params.append('severity', currentFilters.severity);
				}

				if (currentFilters.search) {
					params.append('search', currentFilters.search);
				}

				const response = await fetch(API_URL + 'pages/list?' + params.toString(), {
					headers: { 'X-WP-Nonce': NONCE }
				});

				if (!response.ok) {
					throw new Error('Failed to load pages');
				}

				const data = await response.json();

				totalPages = data.total_pages || 1;
				currentPage = data.page || 1;

				this.renderPages(data.data || []);
				this.updatePagination();

			} catch (error) {
				console.error('[ClearA11y Pages List] Error loading pages:', error);
				container.innerHTML = `
					<div class="notice notice-error" style="padding: 15px;">
						<p>${cleara11yData.strings.error || 'Error'}: ${this.escapeHtml(error.message)}</p>
					</div>
				`;
			}
		},

		/**
		 * Render pages list
		 */
		renderPages(pages) {
			const container = document.querySelector('.cleara11y-pages-container');

			if (pages.length === 0) {
				container.innerHTML = `
					<div class="notice notice-info" style="padding: 15px;">
						<p>${cleara11yData.strings.noIssues || 'No pages found.'}</p>
					</div>
				`;
				return;
			}

			let html = '<div style="background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 1px 1px rgba(0,0,0,.04);">';

			html += '<table class="wp-list-table widefat fixed striped">';
			html += '<thead><tr>';
			html += '<th style="width: 50px;">Score</th>';
			html += '<th>Page</th>';
			html += '<th style="width: 150px;">Issues</th>';
			html += '<th style="width: 120px;">Status</th>';
			html += '<th style="width: 100px;">Actions</th>';
			html += '</tr></thead>';
			html += '<tbody>';

			pages.forEach(page => {
				const scoreColor = this.getScoreColor(page.score);
				const statusBadge = this.getStatusBadge(page.scan_status);

				html += `
					<tr>
						<td>
							<div class="cleara11y-score-badge" style="background: ${scoreColor}; color: #fff; padding: 5px 10px; border-radius: 3px; font-weight: 600; text-align: center;">
								${page.score ?? '-'}
							</div>
						</td>
						<td>
							<div>
								<strong><a href="${this.escapeHtml(page.post_url)}" target="_blank" style="text-decoration: none;">${this.escapeHtml(page.post_title || '(Untitled)')}</a></strong>
								${page.scan_status === 'completed' && page.scanned_at ? `
									<div style="font-size: 12px; color: #646970; margin-top: 4px;">
										Scanned: ${new Date(page.scanned_at).toLocaleDateString()}
									</div>
								` : ''}
								${page.error_message ? `
									<div style="font-size: 12px; color: #d63638; margin-top: 4px;">
										${this.escapeHtml(page.error_message)}
									</div>
								` : ''}
							</div>
						</td>
						<td>
							<div style="font-size: 13px;">
								${page.issues.total > 0 ? `
									<div style="display: flex; gap: 10px;">
										<span style="color: #d63638;"><strong>${page.issues.critical}</strong> critical</span>
										<span style="color: #f56e28;"><strong>${page.issues.moderate}</strong> moderate</span>
										<span style="color: #ffb900;"><strong>${page.issues.minor}</strong> minor</span>
									</div>
								` : '<span style="color: #00a32a;">No issues</span>'}
							</div>
						</td>
						<td>${statusBadge}</td>
						<td>
							${page.issues.total > 0 ? `
								<button type="button" class="button button-small cleara11y-view-issues" data-post-id="${page.post_id}" data-post-title="${this.escapeHtml(page.post_title || '')}">
									View Issues
								</button>
							` : ''}
						</td>
					</tr>
				`;
			});

			html += '</tbody></table></div>';
			container.innerHTML = html;

			// Attach event handlers
			this.attachActionHandlers();
		},

		/**
		 * Attach event handlers for actions
		 */
		attachActionHandlers() {
			// View issues buttons
			document.querySelectorAll('.cleara11y-view-issues').forEach(btn => {
				btn.addEventListener('click', (e) => {
					const postId = e.currentTarget.dataset.postId;
					const postTitle = e.currentTarget.dataset.postTitle;
					this.viewPageIssues(postId, postTitle);
				});
			});
		},

		/**
		 * View page issues in modal
		 */
		async viewPageIssues(postId, postTitle) {
			const modal = document.getElementById('cleara11y-page-issues-modal');
			const content = modal.querySelector('.cleara11y-page-issues-content');

			content.innerHTML = `
				<div style="text-align: center; padding: 40px;">
					<span class="spinner is-active" style="float: none; margin: 0;"></span>
					<p style="margin-top: 15px;">Loading issues...</p>
				</div>
			`;

			modal.querySelector('.cleara11y-modal-title').textContent = `Issues: ${postTitle}`;
			modal.style.display = 'block';

			try {
				const response = await fetch(API_URL + `posts/${postId}/issues`, {
					headers: { 'X-WP-Nonce': NONCE }
				});

				if (!response.ok) {
					throw new Error('Failed to load issues');
				}

				const data = await response.json();
				const issues = data.issues || [];

				if (issues.length === 0) {
					content.innerHTML = `
						<div class="notice notice-info" style="padding: 15px;">
							<p>No accessibility issues found on this page.</p>
						</div>
					`;
					return;
				}

				// Group by severity
				const critical = issues.filter(i => i.severity === 'critical');
				const moderate = issues.filter(i => i.severity === 'moderate');
				const minor = issues.filter(i => i.severity === 'minor');

				let html = '';

				const renderIssue = (issue) => {
					return `
						<div style="padding: 12px; border-left: 3px solid #c3c4c7; background: #f6f7f7; margin-bottom: 10px;">
							<div style="font-weight: 600; margin-bottom: 5px;">${this.escapeHtml(issue.rule_id || 'Unknown Issue')}</div>
							<div style="font-size: 13px; margin-bottom: 5px;">${this.escapeHtml(issue.message || issue.description || '')}</div>
							${issue.selector ? `
								<div style="font-size: 12px; color: #646970;">
									<code style="background: #fff; padding: 2px 6px; border-radius: 3px;">${this.escapeHtml(issue.selector)}</code>
								</div>
							` : ''}
						</div>
					`;
				};

				if (critical.length > 0) {
					html += `<h4 style="color: #d63638; margin-top: 0;">Critical Issues (${critical.length})</h4>`;
					html += critical.map(renderIssue).join('');
				}

				if (moderate.length > 0) {
					html += `<h4 style="color: #f56e28; margin-top: 20px;">Moderate Issues (${moderate.length})</h4>`;
					html += moderate.map(renderIssue).join('');
				}

				if (minor.length > 0) {
					html += `<h4 style="color: #dba617; margin-top: 20px;">Minor Issues (${minor.length})</h4>`;
					html += minor.map(renderIssue).join('');
				}

				content.innerHTML = html;

			} catch (error) {
				console.error('[ClearA11y Pages List] Error loading issues:', error);
				content.innerHTML = `
					<div class="notice notice-error" style="padding: 15px;">
						<p>Error loading issues: ${this.escapeHtml(error.message)}</p>
					</div>
				`;
			}
		},

		/**
		 * Get score color based on value
		 */
		getScoreColor(score) {
			if (score === null || score === undefined) return '#646970';
			if (score >= 90) return '#00a32a';
			if (score >= 70) return '#ffb900';
			if (score >= 50) return '#f56e28';
			return '#d63638';
		},

		/**
		 * Get status badge HTML
		 */
		getStatusBadge(status) {
			const badges = {
				'completed': '<span style="display: inline-block; padding: 4px 8px; background: #e7f7ed; color: #00a32a; border-radius: 3px; font-size: 12px; font-weight: 600;">Scanned</span>',
				'unscanned': '<span style="display: inline-block; padding: 4px 8px; background: #f0f0f1; color: #646970; border-radius: 3px; font-size: 12px;">Unscanned</span>',
				'failed': '<span style="display: inline-block; padding: 4px 8px; background: #f6f7f7; color: #d63638; border-radius: 3px; font-size: 12px; font-weight: 600;">Failed</span>',
			};
			return badges[status] || badges['unscanned'];
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
		document.addEventListener('DOMContentLoaded', () => PagesList.init());
	} else {
		PagesList.init();
	}
})();
