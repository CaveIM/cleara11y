/**
 * Issue Types Page JavaScript
 *
 * Handles the issue types page functionality including:
 * - Loading and displaying issue types grouped by rule
 * - Filtering by severity and search
 * - Viewing pages with specific issue types
 */

document.addEventListener('DOMContentLoaded', function() {
	const IssueTypesApp = {
		apiUrl: cleara11yData.apiUrl,
		nonce: cleara11yData.nonce,
		strings: cleara11yData.strings,

		state: {
			currentSeverity: '',
			searchTerm: '',
			issueTypes: [],
			counts: {},
		},

		init() {
			this.cacheElements();
			this.bindEvents();
			this.loadData();
		},

		cacheElements() {
			this.elements = {
				severityFilter: document.getElementById('cleara11y-severity-filter'),
				searchInput: document.getElementById('cleara11y-issue-search'),
				issueTypesList: document.getElementById('cleara11y-issue-types-list'),
				statsGrid: document.getElementById('cleara11y-stats-grid'),
				pagesModal: document.getElementById('cleara11y-pages-modal'),
			};
		},

		bindEvents() {
			// Severity filter
			if (this.elements.severityFilter) {
				this.elements.severityFilter.addEventListener('change', (e) => {
					this.state.currentSeverity = e.target.value;
					this.loadIssueTypes();
				});
			}

			// Search (debounced)
			let searchTimeout;
			if (this.elements.searchInput) {
				this.elements.searchInput.addEventListener('input', (e) => {
					clearTimeout(searchTimeout);
					searchTimeout = setTimeout(() => {
						this.state.searchTerm = e.target.value;
						this.loadIssueTypes();
					}, 300);
				});
			}

			// Modal close buttons
			document.querySelectorAll('.cleara11y-modal-close').forEach(btn => {
				btn.addEventListener('click', () => this.closeModals());
			});

			// Close modals on backdrop click
			document.querySelectorAll('.cleara11y-modal').forEach(modal => {
				modal.addEventListener('click', (e) => {
					if (e.target === modal) {
						this.closeModals();
					}
				});
			});
		},

		async loadData() {
			await this.loadIssueTypes();
		},

		async loadIssueTypes() {
			try {
				const params = new URLSearchParams();

				if (this.state.currentSeverity) {
					params.append('severity', this.state.currentSeverity);
				}

				if (this.state.searchTerm) {
					params.append('search', this.state.searchTerm);
				}

				const response = await fetch(`${this.apiUrl}issue-types?${params}`, {
					headers: {
						'X-WP-Nonce': this.nonce,
					},
				});

				if (!response.ok) throw new Error('Failed to load issue types');

				const data = await response.json();
				this.state.issueTypes = data.issue_types || [];
				this.state.counts = data.counts || {};

				this.renderIssueTypes();
				this.renderStats(data.counts);
			} catch (error) {
				console.error('Error loading issue types:', error);
				this.renderError();
			}
		},

		renderIssueTypes() {
			if (this.state.issueTypes.length === 0) {
				this.elements.issueTypesList.innerHTML = `
					<div class="cleara11y-empty-state">
						<span class="dashicons dashicons-search"></span>
						<p>${this.strings.noIssues}</p>
					</div>
				`;
				return;
			}

			const html = this.state.issueTypes.map(issueType => {
				return `
					<div class="cleara11y-issue-type-item" data-rule-id="${issueType.rule_id}">
						<div class="cleara11y-issue-severity ${issueType.severity}">${issueType.severity}</div>
						<div class="cleara11y-issue-count">
							<div class="number">${issueType.issue_count}</div>
							<div class="label">${issueType.issue_count === 1 ? 'issue' : 'issues'}</div>
						</div>
						<div class="cleara11y-issue-info">
							<h3>${this.escapeHtml(issueType.message || issueType.rule_id)}</h3>
							<div class="rule-id">${this.escapeHtml(issueType.rule_id)}</div>
							<div class="message">${this.escapeHtml(issueType.message || '')}</div>
							<div class="meta">
								<span>Found on <strong>${issueType.page_count}</strong> ${issueType.page_count === 1 ? 'page' : 'pages'}</span>
							</div>
						</div>
						<div class="cleara11y-issue-actions">
							<button class="button view-pages" data-rule-id="${issueType.rule_id}">
								View Pages
							</button>
						</div>
					</div>
				`;
			}).join('');

			this.elements.issueTypesList.innerHTML = html;

			// Bind action button events
			this.bindActionButtons();
		},

		bindActionButtons() {
			// View pages buttons
			this.elements.issueTypesList.querySelectorAll('.view-pages').forEach(btn => {
				btn.addEventListener('click', (e) => {
					const ruleId = e.target.dataset.ruleId;
					this.viewIssuePages(ruleId);
				});
			});

		},

		async viewIssuePages(ruleId) {
			const modal = this.elements.pagesModal;
			const modalTitle = document.getElementById('cleara11y-modal-title');
			const modalBody = document.getElementById('cleara11y-modal-body');

			modalTitle.textContent = `Pages with ${ruleId} issues`;
			modalBody.innerHTML = `
				<div class="cleara11y-loading">
					<span class="spinner is-active"></span>
					Loading pages...
				</div>
			`;
			modal.style.display = 'flex';

			try {
				const response = await fetch(`${this.apiUrl}issue-types/${ruleId}/pages`, {
					headers: {
						'X-WP-Nonce': this.nonce,
					},
				});

				if (!response.ok) throw new Error('Failed to load pages');

				const data = await response.json();

				if (data.pages.length === 0) {
					modalBody.innerHTML = `
						<div class="cleara11y-empty-state">
							<p>No pages found with this issue.</p>
						</div>
					`;
					return;
				}

				const html = `
					<ul class="cleara11y-pages-list">
						${data.pages.map(page => `
							<li class="cleara11y-page-item">
								<div class="cleara11y-page-info">
									<div class="cleara11y-page-title">${this.escapeHtml(page.post_title || '(Untitled)')}</div>
									<div class="cleara11y-page-url">
										<a href="${page.post_url}" target="_blank" rel="noopener">${this.escapeHtml(page.post_url)}</a>
									</div>
								</div>
								<div class="cleara11y-page-issues">
									<strong>${page.active_count}</strong> ${page.active_count === 1 ? 'issue' : 'issues'}
								</div>
							</li>
						`).join('')}
					</ul>
					${data.total_pages > 1 ? `
						<div class="cleara11y-pagination">
							Page ${data.page} of ${data.total_pages}
						</div>
					` : ''}
				`;

				modalBody.innerHTML = html;
			} catch (error) {
				console.error('Error loading pages:', error);
				modalBody.innerHTML = `
					<div class="cleara11y-error">
						Error loading pages. Please try again.
					</div>
				`;
			}
		},

		closeModals() {
			document.querySelectorAll('.cleara11y-modal').forEach(modal => {
				modal.style.display = 'none';
			});
		},

		renderStats(counts) {
			this.elements.statsGrid.innerHTML = `
				<div class="cleara11y-stat-card">
					<div class="stat-label">Total Issues</div>
					<div class="stat-value">${counts.all || 0}</div>
				</div>
			`;
		},

		renderError() {
			this.elements.issueTypesList.innerHTML = `
				<div class="cleara11y-error">
					<p>${this.strings.error}</p>
				</div>
			`;
		},

		escapeHtml(text) {
			const div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		},
	};

	// Initialize the app
	IssueTypesApp.init();
});
