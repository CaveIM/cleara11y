/**
 * ClearA11y Issues Views JavaScript
 *
 * Handles view switching and rendering for the new issue browsing views.
 *
 * @package ClearA11y
 */

(function() {
	'use strict';

	/**
	 * Issues Views Module
	 */
	const IssuesViews = {
		currentView: 'by-issue-type',  // Default view for global Issues page
		currentScanId: null,           // For scan detail pages
		filters: {},
		sort: { by: '', order: 'desc' },
		pagination: { page: 1, perPage: 20, totalPages: 1 },
		viewCache: {},                 // Cache for loaded views
		API_URL: null,                 // Will be set during init
		NONCE: null,                   // Will be set during init
		strings: {},                   // Will be set during init

		/**
		 * Initialize the module
		 */
		init(scanId = null) {
			console.log('[ClearA11y IssuesViews] SCRIPT STARTED!', {
				scanId,
				cleara11yData: typeof cleara11yData !== 'undefined' ? cleara11yData : 'undefined',
				cleara11yScanData: typeof cleara11yScanData !== 'undefined' ? cleara11yScanData : 'undefined'
			});

			// Set API URL and NONCE from appropriate data source
			if (typeof cleara11yScanData !== 'undefined' && cleara11yScanData.apiUrl) {
				this.API_URL = cleara11yScanData.apiUrl;
				this.NONCE = cleara11yScanData.nonce;
				this.strings = cleara11yScanData.strings || {};
				console.log('[ClearA11y IssuesViews] Using cleara11yScanData for API config');
			} else if (typeof cleara11yData !== 'undefined' && cleara11yData.apiUrl) {
				this.API_URL = cleara11yData.apiUrl;
				this.NONCE = cleara11yData.nonce;
				this.strings = cleara11yData.strings || {};
				console.log('[ClearA11y IssuesViews] Using cleara11yData for API config');
			} else {
				console.error('[ClearA11y IssuesViews] CRITICAL ERROR: Neither cleara11yData nor cleara11yScanData found!');
				console.error('[ClearA11y IssuesViews] Available globals:', Object.keys(window).filter(k => k.includes('cleara11y')));
				return;
			}

			console.log('[ClearA11y IssuesViews] API config loaded:', {
				API_URL: this.API_URL,
				NONCE: this.NONCE ? 'present' : 'missing',
				strings: this.strings
			});

			// Check for scan-specific data from PHP
			if (typeof cleara11yScanData !== 'undefined' && cleara11yScanData.scanId) {
				this.currentScanId = cleara11yScanData.scanId;
				// Default to by-page view for scan details
				this.currentView = 'by-page';
				console.log('[ClearA11y IssuesViews] Scan detail page detected, using scan_id:', this.currentScanId);
			} else {
				this.currentScanId = scanId;
				// Default to by-issue-type for global Issues page
				this.currentView = 'by-issue-type';
				console.log('[ClearA11y IssuesViews] Global Issues page, default view:', this.currentView);
			}

			console.log('[ClearA11y IssuesViews] Setting up view switcher...');
			this.setupViewSwitcher();
			console.log('[ClearA11y IssuesViews] Setting up filters...');
			this.setupFilters();
			console.log('[ClearA11y IssuesViews] Loading stats...');
			this.loadStats();
			console.log('[ClearA11y IssuesViews] Loading current view:', this.currentView);
			this.loadCurrentView();
		},

		/**
		 * Setup view switcher tabs
		 */
		setupViewSwitcher() {
			console.log('[ClearA11y IssuesViews] setupViewSwitcher() called');
			const tabs = document.querySelectorAll('.cleara11y-view-tab');
			console.log('[ClearA11y IssuesViews] Found tabs:', tabs.length, tabs);

			if (tabs.length === 0) {
				console.error('[ClearA11y IssuesViews] No tabs found! Check if view switcher HTML exists.');
				return;
			}

			tabs.forEach((tab, index) => {
				console.log(`[ClearA11y IssuesViews] Setting up tab ${index}:`, tab.dataset.view, tab);
				tab.addEventListener('click', (e) => {
					console.log('[ClearA11y IssuesViews] Tab clicked:', tab.dataset.view, e);
					e.preventDefault();
					const view = tab.dataset.view;
					this.switchView(view);
				});
			});

			// Add hover effect
			tabs.forEach(tab => {
				tab.addEventListener('mouseenter', () => {
					if (!tab.classList.contains('active')) {
						tab.style.background = '#f0f0f1';
					}
				});
				tab.addEventListener('mouseleave', () => {
					if (!tab.classList.contains('active')) {
						tab.style.background = 'none';
					}
				});
			});

			console.log('[ClearA11y IssuesViews] View switcher setup complete');
		},

		/**
		 * Setup filters
		 */
		setupFilters() {
			// Show advanced filters when a view is active
			this.showAdvancedFilters();

			// Setup debounced search
			let searchTimeout;
			const searchInput = document.getElementById('cleara11y-search-adv');
			if (searchInput) {
				searchInput.addEventListener('input', (e) => {
					clearTimeout(searchTimeout);
					searchTimeout = setTimeout(() => {
						this.filters.search = e.target.value.trim();
						this.pagination.page = 1;
						this.loadCurrentView();
					}, 300);
				});
			}

			// Setup filter changes
			this.setupFilterChange('cleara11y-filter-severity-adv', 'severity');
			this.setupFilterChange('cleara11y-filter-post-type', 'post_type');
			this.setupFilterChange('cleara11y-filter-wcag', 'wcag');
			this.setupFilterChange('cleara11y-filter-content-type-grouping', 'content_type_grouping');

			// Setup sort
			const sortSelect = document.getElementById('cleara11y-sort-by');
			if (sortSelect) {
				sortSelect.addEventListener('change', (e) => {
					const value = e.target.value;
					if (value) {
						const [by, order] = value.split('-');
						this.sort = { by, order };
						this.loadCurrentView();
					}
				});
			}

			// Setup reset
			const resetBtn = document.getElementById('cleara11y-reset-filters-adv');
			if (resetBtn) {
				resetBtn.addEventListener('click', () => {
					this.resetFilters();
				});
			}
		},

		/**
		 * Setup individual filter change handler
		 */
		setupFilterChange(elementId, filterKey) {
			const element = document.getElementById(elementId);
			if (element) {
				element.addEventListener('change', (e) => {
					this.filters[filterKey] = e.target.value;
					this.pagination.page = 1;
					this.loadCurrentView();
				});
			}
		},

		/**
		 * Switch to a different view
		 */
		switchView(viewName) {
			console.log('[ClearA11y IssuesViews] switchView() called with:', viewName);

			// Update tabs
			const tabs = document.querySelectorAll('.cleara11y-view-tab');
			console.log('[ClearA11y IssuesViews] Updating tabs, found:', tabs.length);
			tabs.forEach(tab => {
				tab.classList.remove('active');
				tab.style.borderBottom = '3px solid transparent';
				tab.style.color = '';
				tab.style.fontWeight = '';
				if (tab.dataset.view === viewName) {
					tab.classList.add('active');
					tab.style.borderBottom = '3px solid #2271b1';
					tab.style.color = '#2271b1';
					tab.style.fontWeight = '600';
					console.log('[ClearA11y IssuesViews] Marked tab as active:', tab);
				}
			});

			this.currentView = viewName;
			this.pagination.page = 1; // Reset to first page
			console.log('[ClearA11y IssuesViews] Current view updated to:', this.currentView);

			// Show/hide appropriate filters
			console.log('[ClearA11y IssuesViews] Showing advanced filters for view:', viewName);
			this.showAdvancedFilters();

			// Load the view
			console.log('[ClearA11y IssuesViews] Loading view:', viewName);
			this.loadCurrentView();
		},

		/**
		 * Show advanced filters for current view
		 */
		showAdvancedFilters() {
			console.log('[ClearA11y IssuesViews] showAdvancedFilters() called for view:', this.currentView);
			const container = document.querySelector('.cleara11y-advanced-filters');
			if (!container) {
				console.log('[ClearA11y IssuesViews] No advanced filters container found');
				return;
			}
			console.log('[ClearA11y IssuesViews] Advanced filters container found, displaying it');

			// Show the container
			container.style.display = 'block';

			// Hide all filters first
			console.log('[ClearA11y IssuesViews] Hiding all filters first');
			document.querySelectorAll('.cleara11y-advanced-filters > div > select, .cleara11y-advanced-filters > div > input').forEach(el => {
				el.style.display = 'none';
			});

			// Show filters based on current view
			console.log('[ClearA11y IssuesViews] Showing filters for view:', this.currentView);
			switch (this.currentView) {
				case 'by-page':
					console.log('[ClearA11y IssuesViews] Showing filters for by-page view');
					this.showFilter('cleara11y-filter-severity-adv');
					this.showFilter('cleara11y-filter-post-type');
					this.showFilter('cleara11y-search-adv');
					console.log('[ClearA11y IssuesViews] Populating sort options for by-page view');
					this.populateSortOptions([
						{ value: 'issues-desc', label: 'Most Issues' },
						{ value: 'issues-asc', label: 'Least Issues' },
						{ value: 'title-asc', label: 'Page Title (A-Z)' },
						{ value: 'title-desc', label: 'Page Title (Z-A)' },
						{ value: 'score-desc', label: 'Lowest Score' },
						{ value: 'score-asc', label: 'Highest Score' },
						{ value: 'scanned_date-desc', label: 'Recently Scanned' },
						{ value: 'scanned_date-asc', label: 'Oldest Scan' },
					]);
					break;

				case 'by-issue-type':
					console.log('[ClearA11y IssuesViews] Showing filters for by-issue-type view');
					this.showFilter('cleara11y-filter-severity-adv');
					this.showFilter('cleara11y-filter-wcag');
					this.showFilter('cleara11y-search-adv');
					console.log('[ClearA11y IssuesViews] Populating sort options for by-issue-type view');
					this.populateSortOptions([
						{ value: 'severity-desc', label: 'Highest Severity' },
						{ value: 'severity-asc', label: 'Lowest Severity' },
						{ value: 'issues-desc', label: 'Most Issues' },
						{ value: 'issues-asc', label: 'Least Issues' },
						{ value: 'pages-desc', label: 'Most Pages' },
						{ value: 'pages-asc', label: 'Least Pages' },
					]);
					break;

				case 'by-severity':
					console.log('[ClearA11y IssuesViews] Showing filters for by-severity view');
					// No additional filters for severity view
					this.populateSortOptions([]);
					break;

				case 'by-content-type':
					console.log('[ClearA11y IssuesViews] Showing filters for by-content-type view');
					this.showFilter('cleara11y-filter-severity-adv');
					this.showFilter('cleara11y-filter-content-type-grouping');
					this.showFilter('cleara11y-search-adv');
					console.log('[ClearA11y IssuesViews] Populating sort options for by-content-type view');
					this.populateSortOptions([
						{ value: 'issues-desc', label: 'Most Issues' },
						{ value: 'issues-asc', label: 'Least Issues' },
						{ value: 'pages-desc', label: 'Most Pages' },
						{ value: 'pages-asc', label: 'Least Pages' },
						{ value: 'name-asc', label: 'Name (A-Z)' },
					]);
					break;

				case 'exceptions':
					console.log('[ClearA11y IssuesViews] Showing filters for exceptions view');
					this.showFilter('cleara11y-filter-severity-adv');
					this.showFilter('cleara11y-search-adv');
					console.log('[ClearA11y IssuesViews] Populating sort options for exceptions view');
					this.populateSortOptions([
						{ value: 'created-desc', label: 'Newest Created' },
						{ value: 'created-asc', label: 'Oldest Created' },
						{ value: 'severity-desc', label: 'Highest Severity' },
						{ value: 'severity-asc', label: 'Lowest Severity' },
						{ value: 'expires-asc', label: 'Expiring Soon' },
					]);
					break;

				default:
					console.warn('[ClearA11y IssuesViews] Unknown view for filter display:', this.currentView);
			}
		},

		/**
		 * Show a specific filter
		 */
		showFilter(elementId) {
			const element = document.getElementById(elementId);
			if (element) {
				element.style.display = 'inline-block';
			}
		},

		/**
		 * Populate sort options
		 */
		populateSortOptions(options) {
			const sortSelect = document.getElementById('cleara11y-sort-by');
			if (!sortSelect) return;

			sortSelect.innerHTML = '<option value="">' + (this.strings.sortBy || 'Sort by...') + '</option>';
			options.forEach(option => {
				const opt = document.createElement('option');
				opt.value = option.value;
				opt.textContent = option.label;
				sortSelect.appendChild(opt);
			});
		},

		/**
		 * Reset all filters
		 */
		resetFilters() {
			this.filters = {};
			this.sort = { by: '', order: 'desc' };
			this.pagination.page = 1;

			// Reset all select elements
			document.querySelectorAll('.cleara11y-advanced-filters select').forEach(select => {
				select.value = '';
			});

			// Reset search input
			const searchInput = document.getElementById('cleara11y-search-adv');
			if (searchInput) {
				searchInput.value = '';
			}

			// Reload view
			this.loadCurrentView();
		},

		/**
		 * Load current view
		 */
		async loadCurrentView() {
			console.log('[ClearA11y IssuesViews] loadCurrentView() called, currentView:', this.currentView);

			const container = document.getElementById('cleara11y-view-content');
			console.log('[ClearA11y IssuesViews] Container found:', container);
			if (!container) {
				console.error('[ClearA11y IssuesViews] Container #cleara11y-view-content not found!');
				return;
			}

			// Show loading
			container.innerHTML = `
				<div class="cleara11y-loading-spinner" style="text-align: center; padding: 40px;">
					<span class="spinner is-active" style="float: none; margin: 0;"></span>
					<p style="margin-top: 15px;">Loading...</p>
				</div>
			`;

			try {
				console.log('[ClearA11y IssuesViews] Switching on view:', this.currentView);
				switch (this.currentView) {
					case 'by-page':
						console.log('[ClearA11y IssuesViews] Rendering by-page view...');
						await this.renderByPage();
						break;
					case 'by-issue-type':
						console.log('[ClearA11y IssuesViews] Rendering by-issue-type view...');
						await this.renderByIssueType();
						break;
					case 'by-severity':
						console.log('[ClearA11y IssuesViews] Rendering by-severity view...');
						await this.renderBySeverity();
						break;
					case 'by-content-type':
						console.log('[ClearA11y IssuesViews] Rendering by-content-type view...');
						await this.renderByContentType();
						break;
					case 'exceptions':
						console.log('[ClearA11y IssuesViews] Rendering exceptions view...');
						await this.renderExceptions();
						break;
					default:
						console.error('[ClearA11y IssuesViews] Unknown view:', this.currentView);
				}
			} catch (error) {
				console.error('[ClearA11y IssuesViews] ERROR loading view:', error);
				console.error('[ClearA11y IssuesViews] Error details:', {
					message: error.message,
					stack: error.stack,
					name: error.name
				});
				container.innerHTML = `
					<div class="cleara11y-error" style="text-align: center; padding: 40px; color: #d63638;">
						<p><strong>Error loading view:</strong> ${error.message}</p>
						<p style="font-size: 12px; color: #646970;">Check browser console for details.</p>
						<button class="button" onclick="location.reload()">Reload Page</button>
					</div>
				`;
			}
		},

		/**
		 * Render By Page view
		 */
		async renderByPage() {
			console.log('[ClearA11y IssuesViews] renderByPage() starting');
			const params = new URLSearchParams({
				page: this.pagination.page,
				per_page: this.pagination.perPage,
				...this.filters,
			});

			if (this.sort.by) {
				params.append('orderby', this.sort.by);
				params.append('order', this.sort.order);
			}

			if (this.currentScanId) {
				params.append('scan_id', this.currentScanId);
			}

			const apiUrl = `${this.API_URL}views/by-page?${params}`;
			console.log('[ClearA11y IssuesViews] renderByPage() calling API:', apiUrl);
			console.log('[ClearA11y IssuesViews] renderByPage() request params:', {
				page: this.pagination.page,
				per_page: this.pagination.perPage,
				filters: this.filters,
				sort: this.sort,
				scan_id: this.currentScanId
			});

			try {
				const response = await fetch(apiUrl, {
					headers: { 'X-WP-Nonce': this.NONCE }
				});

				console.log('[ClearA11y IssuesViews] renderByPage() response status:', response.status, response.statusText);

				if (!response.ok) {
					const errorText = await response.text();
					console.error('[ClearA11y IssuesViews] renderByPage() API error response:', errorText);
					throw new Error('Failed to load pages');
				}

				const data = await response.json();
				console.log('[ClearA11y IssuesViews] renderByPage() data received:', {
					total_pages: data.total_pages,
					total_items: data.total,
					items_count: data.data ? data.data.length : 0
				});
				console.log('[ClearA11y IssuesViews] renderByPage() sample data:', data.data ? data.data.slice(0, 2) : 'no data');

				this.pagination.totalPages = data.total_pages;

				const container = document.getElementById('cleara11y-view-content');
				container.innerHTML = this.renderPagesList(data.data);
				console.log('[ClearA11y IssuesViews] renderByPage() rendered pages list');

				// Attach event handlers
				this.attachPageHandlers();

				// Render pagination if needed
				if (data.total_pages > 1) {
					console.log('[ClearA11y IssuesViews] renderByPage() rendering pagination');
					this.renderPagination();
				} else {
					console.log('[ClearA11y IssuesViews] renderByPage() no pagination needed (total pages:', data.total_pages, ')');
				}
			} catch (error) {
				console.error('[ClearA11y IssuesViews] renderByPage() ERROR:', error);
				console.error('[ClearA11y IssuesViews] renderByPage() Error details:', {
					message: error.message,
					stack: error.stack,
					name: error.name
				});
				throw error;
			}
		},

		/**
		 * Render pages list HTML
		 */
		renderPagesList(pages) {
			if (!pages || pages.length === 0) {
				return `
					<div style="text-align: center; padding: 40px; color: #646970;">
						<p>No pages found.</p>
					</div>
				`;
			}

			return `
				<div class="cleara11y-pages-list" style="display: grid; gap: 15px;">
					${pages.map(page => this.renderPageCard(page)).join('')}
				</div>
			`;
		},

		/**
		 * Render single page card
		 */
		renderPageCard(page) {
			const score = page.score || 0;
			const scoreColor = score >= 95 ? '#00a32a' : score >= 85 ? '#0065cc' : score >= 70 ? '#f56e28' : '#d63638';
			const activeTotal = page.issues.active.total;
			const exceptionsTotal = page.issues.exceptions.total;

			return `
				<div class="cleara11y-view-card" style="background: #fff; border: 1px solid #c3c4c7; padding: 20px; border-radius: 4px; border-left: 4px solid ${scoreColor};">
					<div style="display: flex; justify-content: space-between; align-items: start; gap: 20px;">
						<div style="flex: 1;">
							<div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
								<h3 style="margin: 0; font-size: 16px;">
									<a href="${page.post_url}" target="_blank" style="text-decoration: none; color: #1d2327;">
										${this.escapeHtml(page.post_title)}
									</a>
								</h3>
								${page.post_type ? `<span class="cleara11y-badge" style="background: #f0f0f1; color: #1d2327; padding: 2px 8px; border-radius: 3px; font-size: 11px;">${page.post_type}</span>` : ''}
							</div>

							<div style="display: flex; gap: 20px; flex-wrap: wrap; margin-top: 10px;">
								<div>
									<span style="color: #646970; font-size: 13px;">Score: </span>
									<strong style="color: ${scoreColor}; font-size: 18px;">${score}</strong>
									${page.score_grade ? `<span style="background: ${scoreColor}; color: #fff; padding: 2px 6px; border-radius: 3px; font-size: 11px; margin-left: 5px;">${page.score_grade}</span>` : ''}
								</div>

								${activeTotal > 0 ? `
									<div>
										<span style="color: #646970; font-size: 13px;">Active Issues: </span>
										<strong style="font-size: 18px;">${activeTotal}</strong>
										${page.issues.active.critical > 0 ? `<span style="color: #d63638; margin-left: 8px;">${page.issues.active.critical} critical</span>` : ''}
										${page.issues.active.moderate > 0 ? `<span style="color: #f56e28; margin-left: 8px;">${page.issues.active.moderate} moderate</span>` : ''}
										${page.issues.active.minor > 0 ? `<span style="color: #ffb900; margin-left: 8px;">${page.issues.active.minor} minor</span>` : ''}
									</div>
								` : '<div style="color: #00a32a; font-weight: 600;">✓ No active issues</div>'}

								${exceptionsTotal > 0 ? `
									<div>
										<span style="color: #646970; font-size: 13px;">Exceptions: </span>
										<strong style="font-size: 18px; color: #646970;">${exceptionsTotal}</strong>
									</div>
								` : ''}
							</div>
						</div>

						<div>
							<a href="${page.post_url}" target="_blank" class="button button-small">
								View Page
							</a>
						</div>
					</div>
				</div>
			`;
		},

		/**
		 * Render By Issue Type view
		 */
		async renderByIssueType() {
			console.log('[ClearA11y IssuesViews] renderByIssueType() starting');
			const params = new URLSearchParams({
				page: this.pagination.page,
				per_page: this.pagination.perPage,
				...this.filters,
			});

			if (this.sort.by) {
				params.append('orderby', this.sort.by);
				params.append('order', this.sort.order);
			}

			if (this.currentScanId) {
				params.append('scan_id', this.currentScanId);
			}

			const apiUrl = `${this.API_URL}views/by-issue-type?${params}`;
			console.log('[ClearA11y IssuesViews] renderByIssueType() calling API:', apiUrl);
			console.log('[ClearA11y IssuesViews] renderByIssueType() request params:', {
				page: this.pagination.page,
				per_page: this.pagination.perPage,
				filters: this.filters,
				sort: this.sort,
				scan_id: this.currentScanId
			});

			try {
				const response = await fetch(apiUrl, {
					headers: { 'X-WP-Nonce': this.NONCE }
				});

				console.log('[ClearA11y IssuesViews] renderByIssueType() response status:', response.status, response.statusText);

				if (!response.ok) {
					const errorText = await response.text();
					console.error('[ClearA11y IssuesViews] renderByIssueType() API error response:', errorText);
					throw new Error('Failed to load issue types');
				}

				const data = await response.json();
				console.log('[ClearA11y IssuesViews] renderByIssueType() data received:', {
					total_pages: data.total_pages,
					total_items: data.total,
					items_count: data.data ? data.data.length : 0
				});
				console.log('[ClearA11y IssuesViews] renderByIssueType() sample data:', data.data ? data.data.slice(0, 2) : 'no data');

				this.pagination.totalPages = data.total_pages;

				const container = document.getElementById('cleara11y-view-content');
				container.innerHTML = this.renderIssueTypesList(data.data);
				console.log('[ClearA11y IssuesViews] renderByIssueType() rendered issue types list');

				// Render pagination if needed
				if (data.total_pages > 1) {
					console.log('[ClearA11y IssuesViews] renderByIssueType() rendering pagination');
					this.renderPagination();
				} else {
					console.log('[ClearA11y IssuesViews] renderByIssueType() no pagination needed (total pages:', data.total_pages, ')');
				}
			} catch (error) {
				console.error('[ClearA11y IssuesViews] renderByIssueType() ERROR:', error);
				console.error('[ClearA11y IssuesViews] renderByIssueType() Error details:', {
					message: error.message,
					stack: error.stack,
					name: error.name
				});
				throw error;
			}
		},

		/**
		 * Render issue types list HTML
		 */
		renderIssueTypesList(issueTypes) {
			if (!issueTypes || issueTypes.length === 0) {
				return `
					<div style="text-align: center; padding: 40px; color: #646970;">
						<p>No issue types found.</p>
					</div>
				`;
			}

			return `
				<div class="cleara11y-issue-types-list" style="display: grid; gap: 15px;">
					${issueTypes.map(type => this.renderIssueTypeCard(type)).join('')}
				</div>
			`;
		},

		/**
		 * Render single issue type card
		 */
		renderIssueTypeCard(type) {
			const severityColor = type.severity === 'critical' ? '#d63638' : type.severity === 'moderate' ? '#f56e28' : '#ffb900';
			const activeCount = type.active.issue_count;
			const pageCount = type.active.page_count;

			return `
				<div class="cleara11y-view-card" style="background: #fff; border: 1px solid #c3c4c7; padding: 20px; border-radius: 4px; border-left: 4px solid ${severityColor};">
					<div style="display: flex; justify-content: space-between; align-items: start; gap: 20px;">
						<div style="flex: 1;">
							<div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
								<h3 style="margin: 0; font-size: 16px;">${this.escapeHtml(type.rule_id)}</h3>
								<span class="cleara11y-badge" style="background: ${severityColor}; color: #fff; padding: 4px 8px; border-radius: 3px; font-size: 11px; font-weight: 600;">${type.severity}</span>
								${type.wcag_criterion ? `<span style="color: #646970; font-size: 12px;">${this.escapeHtml(type.wcag_criterion)}</span>` : ''}
							</div>

							<p style="margin: 5px 0 15px 0; color: #1d2327; line-height: 1.5;">${this.escapeHtml(type.message)}</p>

							<div style="display: flex; gap: 20px; flex-wrap: wrap;">
								<div>
									<span style="color: #646970; font-size: 13px;">Active Issues: </span>
									<strong style="font-size: 18px; color: ${severityColor};">${activeCount}</strong>
								</div>
								<div>
									<span style="color: #646970; font-size: 13px;">Pages Affected: </span>
									<strong style="font-size: 18px;">${pageCount}</strong>
								</div>
								${type.exceptions.issue_count > 0 ? `
									<div>
										<span style="color: #646970; font-size: 13px;">Exceptions: </span>
										<strong style="font-size: 18px; color: #646970;">${type.exceptions.issue_count}</strong>
									</div>
								` : ''}
							</div>
						</div>

						${type.help_url ? `
							<a href="${type.help_url}" target="_blank" class="button button-small" style="margin-top: 5px;">
								Learn More
							</a>
						` : ''}
					</div>
				</div>
			`;
		},

		/**
		 * Render By Severity view
		 */
		async renderBySeverity() {
			console.log('[ClearA11y IssuesViews] renderBySeverity() starting');
			const params = new URLSearchParams();
			if (this.currentScanId) {
				params.append('scan_id', this.currentScanId);
			}
			if (this.filters.severity) {
				params.append('severity', this.filters.severity);
			}

			const apiUrl = `${this.API_URL}views/by-severity?${params}`;
			console.log('[ClearA11y IssuesViews] renderBySeverity() calling API:', apiUrl);
			console.log('[ClearA11y IssuesViews] renderBySeverity() request params:', {
				scan_id: this.currentScanId,
				severity: this.filters.severity
			});

			try {
				const response = await fetch(apiUrl, {
					headers: { 'X-WP-Nonce': this.NONCE }
				});

				console.log('[ClearA11y IssuesViews] renderBySeverity() response status:', response.status, response.statusText);

				if (!response.ok) {
					const errorText = await response.text();
					console.error('[ClearA11y IssuesViews] renderBySeverity() API error response:', errorText);
					throw new Error('Failed to load severity data');
				}

				const data = await response.json();
				console.log('[ClearA11y IssuesViews] renderBySeverity() data received:', {
					items_count: data.data ? data.data.length : 0
				});
				console.log('[ClearA11y IssuesViews] renderBySeverity() sample data:', data.data ? data.data.slice(0, 2) : 'no data');

				const container = document.getElementById('cleara11y-view-content');
				container.innerHTML = this.renderSeverityBuckets(data.data);
				console.log('[ClearA11y IssuesViews] renderBySeverity() rendered severity buckets');
			} catch (error) {
				console.error('[ClearA11y IssuesViews] renderBySeverity() ERROR:', error);
				console.error('[ClearA11y IssuesViews] renderBySeverity() Error details:', {
					message: error.message,
					stack: error.stack,
					name: error.name
				});
				throw error;
			}
		},

		/**
		 * Render severity buckets HTML
		 */
		renderSeverityBuckets(buckets) {
			if (!buckets || buckets.length === 0) {
				return `
					<div style="text-align: center; padding: 40px; color: #646970;">
						<p>No issues found.</p>
					</div>
				`;
			}

			return `
				<div class="cleara11y-severity-buckets" style="display: grid; gap: 20px;">
					${buckets.map(bucket => this.renderSeverityBucket(bucket)).join('')}
				</div>
			`;
		},

		/**
		 * Render single severity bucket
		 */
		renderSeverityBucket(bucket) {
			const severityColor = bucket.severity === 'critical' ? '#d63638' : bucket.severity === 'moderate' ? '#f56e28' : '#ffb900';
			const activeTotal = bucket.active.total;
			const exceptionsTotal = bucket.exceptions.total;

			return `
				<div class="cleara11y-severity-bucket" style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; overflow: hidden;">
					<div style="background: ${severityColor}; color: #fff; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center;">
						<h3 style="margin: 0; text-transform: capitalize; font-size: 18px;">${bucket.severity} Issues</h3>
						<span style="font-size: 24px; font-weight: 600;">${activeTotal}</span>
					</div>

					<div style="padding: 20px;">
						<div style="display: flex; gap: 30px; margin-bottom: 20px;">
							<div>
								<span style="color: #646970; font-size: 13px;">Unique Pages: </span>
								<strong style="font-size: 18px;">${bucket.active.unique_pages}</strong>
							</div>
							${exceptionsTotal > 0 ? `
								<div>
									<span style="color: #646970; font-size: 13px;">Exceptions: </span>
									<strong style="font-size: 18px; color: #646970;">${exceptionsTotal}</strong>
								</div>
							` : ''}
						</div>

						${bucket.top_rules && bucket.top_rules.length > 0 ? `
							<h4 style="margin: 0 0 15px 0; font-size: 14px; color: #646970;">Top Rules</h4>
							<div style="display: grid; gap: 10px;">
								${bucket.top_rules.map(rule => `
									<div style="display: flex; justify-content: space-between; align-items: center; padding: 10px; background: #f6f7f7; border-radius: 3px;">
										<span style="font-weight: 600;">${this.escapeHtml(rule.rule_id)}</span>
										<span style="color: ${severityColor};">${rule.issue_count} issues on ${rule.page_count} pages</span>
									</div>
								`).join('')}
							</div>
						` : ''}
					</div>
				</div>
			`;
		},

		/**
		 * Render By Content Type view
		 */
		async renderByContentType() {
			console.log('[ClearA11y IssuesViews] renderByContentType() starting');
			const params = new URLSearchParams({
				page: this.pagination.page,
				per_page: this.pagination.perPage,
				content_type: this.filters.content_type_grouping || 'post_type',
				...this.filters,
			});

			if (this.sort.by) {
				params.append('orderby', this.sort.by);
				params.append('order', this.sort.order);
			}

			if (this.currentScanId) {
				params.append('scan_id', this.currentScanId);
			}

			const apiUrl = `${this.API_URL}views/by-content-type?${params}`;
			console.log('[ClearA11y IssuesViews] renderByContentType() calling API:', apiUrl);
			console.log('[ClearA11y IssuesViews] renderByContentType() request params:', {
				page: this.pagination.page,
				per_page: this.pagination.perPage,
				content_type: this.filters.content_type_grouping || 'post_type',
				filters: this.filters,
				sort: this.sort,
				scan_id: this.currentScanId
			});

			try {
				const response = await fetch(apiUrl, {
					headers: { 'X-WP-Nonce': this.NONCE }
				});

				console.log('[ClearA11y IssuesViews] renderByContentType() response status:', response.status, response.statusText);

				if (!response.ok) {
					const errorText = await response.text();
					console.error('[ClearA11y IssuesViews] renderByContentType() API error response:', errorText);
					throw new Error('Failed to load content types');
				}

				const data = await response.json();
				console.log('[ClearA11y IssuesViews] renderByContentType() data received:', {
					total_pages: data.total_pages,
					total_items: data.total,
					items_count: data.data ? data.data.length : 0
				});
				console.log('[ClearA11y IssuesViews] renderByContentType() sample data:', data.data ? data.data.slice(0, 2) : 'no data');

				this.pagination.totalPages = data.total_pages;

				const container = document.getElementById('cleara11y-view-content');
				container.innerHTML = this.renderContentTypesList(data.data);
				console.log('[ClearA11y IssuesViews] renderByContentType() rendered content types list');

				// Render pagination if needed
				if (data.total_pages > 1) {
					console.log('[ClearA11y IssuesViews] renderByContentType() rendering pagination');
					this.renderPagination();
				} else {
					console.log('[ClearA11y IssuesViews] renderByContentType() no pagination needed (total pages:', data.total_pages, ')');
				}
			} catch (error) {
				console.error('[ClearA11y IssuesViews] renderByContentType() ERROR:', error);
				console.error('[ClearA11y IssuesViews] renderByContentType() Error details:', {
					message: error.message,
					stack: error.stack,
					name: error.name
				});
				throw error;
			}
		},

		/**
		 * Render content types list HTML
		 */
		renderContentTypesList(contentTypes) {
				if (!contentTypes || contentTypes.length === 0) {
					return `
						<div style="text-align: center; padding: 40px; color: #646970;">
							<p>No content types found.</p>
						</div>
					`;
				}

				return `
					<div class="cleara11y-content-types-list" style="display: grid; gap: 15px;">
						${contentTypes.map(type => this.renderContentTypeCard(type)).join('')}
					</div>
				`;
			},

		/**
		 * Render single content type card
		 */
		renderContentTypeCard(type) {
			const activeTotal = type.active.total_issues;
			const score = type.avg_score || 0;
			const scoreColor = score >= 80 ? '#00a32a' : score >= 60 ? '#f56e28' : '#d63638';

			return `
				<div class="cleara11y-view-card" style="background: #fff; border: 1px solid #c3c4c7; padding: 20px; border-radius: 4px;">
					<div style="display: flex; justify-content: space-between; align-items: center; gap: 20px;">
						<div style="flex: 1;">
							<h3 style="margin: 0 0 10px 0; font-size: 18px;">${this.escapeHtml(type.content_label)}</h3>

							<div style="display: flex; gap: 30px; flex-wrap: wrap;">
								<div>
									<span style="color: #646970; font-size: 13px;">Pages: </span>
									<strong style="font-size: 18px;">${type.pages_count}</strong>
								</div>
								<div>
									<span style="color: #646970; font-size: 13px;">Active Issues: </span>
									<strong style="font-size: 18px;">${activeTotal}</strong>
								</div>
								<div>
									<span style="color: #646970; font-size: 13px;">Avg Score: </span>
									<strong style="font-size: 18px; color: ${scoreColor};">${score}</strong>
								</div>
								${type.exceptions.total_issues > 0 ? `
									<div>
										<span style="color: #646970; font-size: 13px;">Exceptions: </span>
										<strong style="font-size: 18px; color: #646970;">${type.exceptions.total_issues}</strong>
									</div>
								` : ''}
							</div>

							${activeTotal > 0 ? `
								<div style="margin-top: 10px; display: flex; gap: 15px; flex-wrap: wrap;">
									${type.active.critical > 0 ? `<span style="color: #d63638;">${type.active.critical} critical</span>` : ''}
									${type.active.moderate > 0 ? `<span style="color: #f56e28;">${type.active.moderate} moderate</span>` : ''}
									${type.active.minor > 0 ? `<span style="color: #ffb900;">${type.active.minor} minor</span>` : ''}
								</div>
							` : '<div style="margin-top: 10px; color: #00a32a; font-weight: 600;">✓ No active issues</div>'}
						</div>
					</div>
				</div>
			`;
		},

		/**
		 * Render Exceptions view
		 */
		async renderExceptions() {
			console.log('[ClearA11y IssuesViews] renderExceptions() starting');
			const params = new URLSearchParams({
				page: this.pagination.page,
				per_page: this.pagination.perPage,
				...this.filters,
			});

			if (this.sort.by) {
				params.append('orderby', this.sort.by);
				params.append('order', this.sort.order);
			}

			if (this.currentScanId) {
				params.append('scan_id', this.currentScanId);
			}

			const apiUrl = `${this.API_URL}views/exceptions?${params}`;
			console.log('[ClearA11y IssuesViews] renderExceptions() calling API:', apiUrl);
			console.log('[ClearA11y IssuesViews] renderExceptions() request params:', {
				page: this.pagination.page,
				per_page: this.pagination.perPage,
				filters: this.filters,
				sort: this.sort,
				scan_id: this.currentScanId
			});

			try {
				const response = await fetch(apiUrl, {
					headers: { 'X-WP-Nonce': this.NONCE }
				});

				console.log('[ClearA11y IssuesViews] renderExceptions() response status:', response.status, response.statusText);

				if (!response.ok) {
					const errorText = await response.text();
					console.error('[ClearA11y IssuesViews] renderExceptions() API error response:', errorText);
					throw new Error('Failed to load exceptions');
				}

				const data = await response.json();
				console.log('[ClearA11y IssuesViews] renderExceptions() data received:', {
					total_pages: data.total_pages,
					total_items: data.total,
					items_count: data.data ? data.data.length : 0
				});
				console.log('[ClearA11y IssuesViews] renderExceptions() sample data:', data.data ? data.data.slice(0, 2) : 'no data');

				this.pagination.totalPages = data.total_pages;

				const container = document.getElementById('cleara11y-view-content');
				container.innerHTML = this.renderExceptionsList(data.data);
				console.log('[ClearA11y IssuesViews] renderExceptions() rendered exceptions list');

				// Render pagination if needed
				if (data.total_pages > 1) {
					console.log('[ClearA11y IssuesViews] renderExceptions() rendering pagination');
					this.renderPagination();
				} else {
					console.log('[ClearA11y IssuesViews] renderExceptions() no pagination needed (total pages:', data.total_pages, ')');
				}
			} catch (error) {
				console.error('[ClearA11y IssuesViews] renderExceptions() ERROR:', error);
				console.error('[ClearA11y IssuesViews] renderExceptions() Error details:', {
					message: error.message,
					stack: error.stack,
					name: error.name
				});
				throw error;
			}
		},

		/**
		 * Render exceptions list HTML
		 */
		renderExceptionsList(exceptions) {
			if (!exceptions || exceptions.length === 0) {
				return `
					<div style="text-align: center; padding: 40px; color: #646970;">
						<p>No exceptions found.</p>
					</div>
				`;
			}

			return `
				<div class="cleara11y-exceptions-list" style="display: grid; gap: 15px;">
					${exceptions.map(exc => this.renderExceptionCard(exc)).join('')}
				</div>
			`;
		},

		/**
		 * Render single exception card
		 */
		renderExceptionCard(exception) {
			const severityColor = exception.severity === 'critical' ? '#d63638' : exception.severity === 'moderate' ? '#f56e28' : '#ffb900';

			return `
				<div class="cleara11y-view-card" style="background: #fff; border: 1px solid #c3c4c7; padding: 20px; border-radius: 4px; border-left: 4px solid #646970;">
					<div style="display: flex; justify-content: space-between; align-items: start; gap: 20px;">
						<div style="flex: 1;">
							<div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
								<h3 style="margin: 0; font-size: 16px;">${this.escapeHtml(exception.rule_id)}</h3>
								<span class="cleara11y-badge" style="background: ${severityColor}; color: #fff; padding: 4px 8px; border-radius: 3px; font-size: 11px;">${exception.severity}</span>
								<span class="cleara11y-badge" style="background: #646970; color: #fff; padding: 4px 8px; border-radius: 3px; font-size: 11px;">Exception</span>
							</div>

							<p style="margin: 5px 0 15px 0; color: #1d2327; line-height: 1.5;">${this.escapeHtml(exception.message)}</p>

							<div style="margin-bottom: 10px;">
								<strong style="color: #646970;">Page:</strong>
								<a href="${exception.page.post_url}" target="_blank" style="color: #2271b1; margin-left: 5px;">${this.escapeHtml(exception.page.post_title)}</a>
							</div>

							${exception.selector ? `
								<div style="margin-bottom: 10px;">
									<strong style="color: #646970;">Selector:</strong>
									<code style="background: #f0f0f1; padding: 2px 6px; border-radius: 3px; font-size: 12px; margin-left: 5px;">${this.escapeHtml(exception.selector)}</code>
								</div>
							` : ''}

							<div style="background: #f6f7f7; padding: 12px; border-radius: 4px; margin-top: 10px;">
								<div><strong>Reason:</strong> ${this.escapeHtml(exception.exception.reason_category)}</div>
								${exception.exception.note ? `<div style="margin-top: 5px;"><strong>Note:</strong> ${this.escapeHtml(exception.exception.note)}</div>` : ''}
								<div style="margin-top: 5px; font-size: 12px; color: #646970;">
									${exception.exception.duration_type === 'permanent' ? 'Permanent' : 'Temporary'} exception
									${exception.exception.expires_at ? ` until ${new Date(exception.exception.expires_at).toLocaleDateString()}` : ''}
									${exception.exception.created_by ? ` by ${exception.exception.created_by.display_name}` : ''}
								</div>
							</div>
						</div>
					</div>
				</div>
			`;
		},

		/**
		 * Render pagination controls
		 */
		renderPagination() {
			console.log('[ClearA11y IssuesViews] renderPagination() called');
			console.log('[ClearA11y IssuesViews] renderPagination() current pagination state:', {
				currentPage: this.pagination.page,
				totalPages: this.pagination.totalPages,
				perPage: this.pagination.perPage
			});

			const container = document.getElementById('cleara11y-view-content');
			if (!container) {
				console.error('[ClearA11y IssuesViews] renderPagination() container not found');
				return;
			}

			// Remove existing pagination
			const existing = container.querySelector('.cleara11y-pagination');
			if (existing) {
				console.log('[ClearA11y IssuesViews] renderPagination() removing existing pagination');
				existing.remove();
			}

			const pagination = document.createElement('div');
			pagination.className = 'cleara11y-pagination';
			pagination.style.cssText = 'margin: 20px 0; display: flex; justify-content: center; align-items: center; gap: 15px;';

			const isPrevDisabled = this.pagination.page === 1;
			const isNextDisabled = this.pagination.page === this.pagination.totalPages;

			pagination.innerHTML = `
				<button class="button" ${isPrevDisabled ? 'disabled' : ''}>
					Previous
				</button>
				<span>Page ${this.pagination.page} of ${this.pagination.totalPages}</span>
				<button class="button" ${isNextDisabled ? 'disabled' : ''}>
					Next
				</button>
			`;

			console.log('[ClearA11y IssuesViews] renderPagination() pagination HTML created', {
				prevDisabled: isPrevDisabled,
				nextDisabled: isNextDisabled
			});

			// Add event listeners
			const prevBtn = pagination.querySelector('button:first-child');
			const nextBtn = pagination.querySelector('button:last-child');

			if (prevBtn && !prevBtn.disabled) {
				console.log('[ClearA11y IssuesViews] renderPagination() attaching previous button handler');
				prevBtn.addEventListener('click', () => {
					console.log('[ClearA11y IssuesViews] Pagination: Previous button clicked, going from page', this.pagination.page);
					this.pagination.page--;
					this.loadCurrentView();
				});
			} else {
				console.log('[ClearA11y IssuesViews] renderPagination() previous button disabled or not found');
			}

			if (nextBtn && !nextBtn.disabled) {
				console.log('[ClearA11y IssuesViews] renderPagination() attaching next button handler');
				nextBtn.addEventListener('click', () => {
					console.log('[ClearA11y IssuesViews] Pagination: Next button clicked, going from page', this.pagination.page);
					this.pagination.page++;
					this.loadCurrentView();
				});
			} else {
				console.log('[ClearA11y IssuesViews] renderPagination() next button disabled or not found');
			}

			container.appendChild(pagination);
			console.log('[ClearA11y IssuesViews] renderPagination() pagination appended to container');
		},

		/**
		 * Attach event handlers for page cards
		 */
		attachPageHandlers() {
			// Add any specific handlers for page interactions
		},

		/**
		 * Load statistics
		 */
		async loadStats() {
			try {
				const params = new URLSearchParams();
				if (this.currentScanId) {
					params.append('scan_id', this.currentScanId);
				}

				const response = await fetch(`${this.API_URL}issues/stats?${params}`, {
					headers: { 'X-WP-Nonce': this.NONCE }
				});

				if (!response.ok) return;

				const stats = await response.json();
				this.updateStatsUI(stats);
			} catch (error) {
				console.error('Error loading stats:', error);
			}
		},

		/**
		 * Update stats UI
		 */
		updateStatsUI(stats) {
			// Update active issue counts
			const activeTotal = document.getElementById('cleara11y-active-total') || document.getElementById('cleara11y-total-issues');
			const activeCritical = document.getElementById('cleara11y-active-critical') || document.getElementById('cleara11y-critical-issues');
			const activeModerate = document.getElementById('cleara11y-active-moderate') || document.getElementById('cleara11y-moderate-issues');
			const activeMinor = document.getElementById('cleara11y-active-minor') || document.getElementById('cleara11y-minor-issues');

			if (activeTotal) activeTotal.textContent = stats.active?.total || 0;
			if (activeCritical) activeCritical.textContent = stats.active?.critical || 0;
			if (activeModerate) activeModerate.textContent = stats.active?.moderate || 0;
			if (activeMinor) activeMinor.textContent = stats.active?.minor || 0;

			// Update exception count
			const exceptionsTotal = document.getElementById('cleara11y-exceptions-total') || document.getElementById('cleara11y-ignored-issues');
			if (exceptionsTotal) exceptionsTotal.textContent = stats.exceptions?.total || 0;
		},

		/**
		 * Escape HTML to prevent XSS
		 */
		escapeHtml(text) {
			const div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		}
	};

	// Initialize on DOM ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', () => {
			// Check if we're on a scan detail page with scan data
			if (typeof cleara11yScanData !== 'undefined' && cleara11yScanData.scanId) {
				IssuesViews.init(cleara11yScanData.scanId);
			} else {
				IssuesViews.init();
			}
		});
	} else {
		// Check if we're on a scan detail page with scan data
		if (typeof cleara11yScanData !== 'undefined' && cleara11yScanData.scanId) {
			IssuesViews.init(cleara11yScanData.scanId);
		} else {
			IssuesViews.init();
		}
	}

	// Export for use in other scripts
	window.ClearA11y = window.ClearA11y || {};
	window.ClearA11y.IssuesViews = IssuesViews;

})();