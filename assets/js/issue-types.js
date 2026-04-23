/**
 * Issue Types Page JavaScript
 *
 * Handles the issue types page functionality including:
 * - Loading and displaying issue types grouped by rule
 * - Filtering by severity and status
 * - Global ignore/unignore functionality
 * - Viewing pages with specific issue types
 */

document.addEventListener('DOMContentLoaded', function() {
	const IssueTypesApp = {
		apiUrl: cleara11yData.apiUrl,
		nonce: cleara11yData.nonce,
		strings: cleara11yData.strings,

		state: {
			currentStatus: 'active',
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
				filterTabs: document.querySelectorAll('.cleara11y-filter-tab'),
				severityFilter: document.getElementById('cleara11y-severity-filter'),
				searchInput: document.getElementById('cleara11y-issue-search'),
				issueTypesList: document.getElementById('cleara11y-issue-types-list'),
				statsGrid: document.getElementById('cleara11y-stats-grid'),
				countActive: document.getElementById('count-active'),
				countDismissedGlobal: document.getElementById('count-dismissed-global'),
				countAll: document.getElementById('count-all'),
				pagesModal: document.getElementById('cleara11y-pages-modal'),
				ignoreModal: document.getElementById('cleara11y-ignore-modal'),
			};
		},

		bindEvents() {
			// Filter tabs
			this.elements.filterTabs.forEach(tab => {
				tab.addEventListener('click', (e) => {
					this.elements.filterTabs.forEach(t => t.classList.remove('active'));
					e.target.classList.add('active');
					this.state.currentStatus = e.target.dataset.status;
					this.loadIssueTypes();
				});
			});

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

			// Cancel global ignore
			const cancelBtn = document.getElementById('cleara11y-cancel-ignore');
			if (cancelBtn) {
				cancelBtn.addEventListener('click', () => this.closeModals());
			}

			// Confirm global ignore
			const confirmBtn = document.getElementById('cleara11y-confirm-ignore');
			if (confirmBtn) {
				confirmBtn.addEventListener('click', () => this.confirmGlobalIgnore());
			}

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
			await Promise.all([
				this.loadIssueTypes(),
				this.loadStats(),
			]);
		},

		async loadIssueTypes() {
			try {
				const params = new URLSearchParams({
					status: this.state.currentStatus,
				});

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
				this.updateCounts();
			} catch (error) {
				console.error('Error loading issue types:', error);
				this.renderError();
			}
		},

		async loadStats() {
			try {
				const response = await fetch(`${this.apiUrl}issues/stats`, {
					headers: {
						'X-WP-Nonce': this.nonce,
					},
				});

				if (!response.ok) return;

				const stats = await response.json();
				this.renderStats(stats);
			} catch (error) {
				console.error('Error loading stats:', error);
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
				const isGloballyIgnored = issueType.globally_ignored > 0;

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
								${isGloballyIgnored ? '<span class="globally-ignored-badge">Globally Ignored</span>' : ''}
							</div>
						</div>
						<div class="cleara11y-issue-actions">
							<button class="button view-pages" data-rule-id="${issueType.rule_id}">
								View Pages
							</button>
							<button class="button ${isGloballyIgnored ? 'button-secondary' : ''} toggle-global-ignore"
								data-rule-id="${issueType.rule_id}"
								data-ignored="${isGloballyIgnored ? '1' : '0'}">
								${isGloballyIgnored ? 'Unignore' : 'Ignore Globally'}
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

			// Toggle global ignore buttons
			this.elements.issueTypesList.querySelectorAll('.toggle-global-ignore').forEach(btn => {
				btn.addEventListener('click', (e) => {
					const ruleId = e.target.dataset.ruleId;
					const ignored = e.target.dataset.ignored === '1';
					this.showGlobalIgnoreModal(ruleId, ignored);
				});
			});
		},

		updateCounts() {
			if (this.elements.countActive) {
				this.elements.countActive.textContent = this.formatNumber(this.state.counts.active || 0);
			}
			if (this.elements.countDismissedGlobal) {
				this.elements.countDismissedGlobal.textContent = this.formatNumber(this.state.counts['dismissed-global'] || 0);
			}
			if (this.elements.countAll) {
				this.elements.countAll.textContent = this.formatNumber(this.state.counts.all || 0);
			}
		},

		formatNumber(num) {
			if (num >= 1000) {
				return (num / 1000).toFixed(1) + 'k';
			}
			return num.toString();
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

		showGlobalIgnoreModal(ruleId, currentlyIgnored) {
			const modal = this.elements.ignoreModal;
			const message = document.getElementById('cleara11y-ignore-message');
			const confirmBtn = document.getElementById('cleara11y-confirm-ignore');
			const commentInput = document.getElementById('cleara11y-ignore-comment');

			// Find issue type info
			const issueType = this.state.issueTypes.find(it => it.rule_id === ruleId);

			if (currentlyIgnored) {
				message.textContent = `Are you sure you want to restore "${issueType?.message || ruleId}" from global ignore? All instances of this issue will become active again.`;
				confirmBtn.textContent = 'Restore Issue';
			} else {
				message.textContent = `Globally ignore "${issueType?.message || ruleId}"? This will hide all instances of this issue across your site.`;
				confirmBtn.textContent = 'Ignore Globally';
			}

			confirmBtn.dataset.ruleId = ruleId;
			confirmBtn.dataset.ignored = currentlyIgnored ? '0' : '1';

			// Clear previous comment
			commentInput.value = '';

			modal.style.display = 'flex';
		},

		async confirmGlobalIgnore() {
			const confirmBtn = document.getElementById('cleara11y-confirm-ignore');
			const ruleId = confirmBtn.dataset.ruleId;
			const ignored = confirmBtn.dataset.ignored === '1';
			const comment = document.getElementById('cleara11y-ignore-comment').value;

			try {
				const response = await fetch(`${this.apiUrl}issue-types/${ruleId}/ignore-global`, {
					method: 'POST',
					headers: {
						'X-WP-Nonce': this.nonce,
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({
						ignored: !ignored,
						comment: comment,
					}),
				});

				if (!response.ok) throw new Error('Failed to update global ignore');

				await this.loadIssueTypes();
				await this.loadStats();
				this.closeModals();
			} catch (error) {
				console.error('Error updating global ignore:', error);
				alert('Error updating global ignore. Please try again.');
			}
		},

		closeModals() {
			document.querySelectorAll('.cleara11y-modal').forEach(modal => {
				modal.style.display = 'none';
			});
		},

		renderStats(stats) {
			this.elements.statsGrid.innerHTML = `
				<div class="cleara11y-stat-card">
					<div class="stat-label">Critical</div>
					<div class="stat-value" style="color: #f66565;">${stats.critical || 0}</div>
				</div>
				<div class="cleara11y-stat-card">
					<div class="stat-label">Moderate</div>
					<div class="stat-value" style="color: #f5a623;">${stats.moderate || 0}</div>
				</div>
				<div class="cleara11y-stat-card">
					<div class="stat-label">Minor</div>
					<div class="stat-value" style="color: #6dd4b6;">${stats.minor || 0}</div>
				</div>
				<div class="cleara11y-stat-card">
					<div class="stat-label">Total Issues</div>
					<div class="stat-value">${stats.active || 0}</div>
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
