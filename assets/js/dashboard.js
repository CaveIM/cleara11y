/**
 * ClearA11y Dashboard JavaScript
 *
 * @package ClearA11y
 */

(function() {
	'use strict';

	// API configuration
	const API_URL = cleara11yData.apiUrl;
	const WP_API_URL = cleara11yData.wpApiUrl;
	const AJAX_URL = cleara11yData.ajaxUrl;
	const NONCE = cleara11yData.nonce;
	const AJAX_NONCE = cleara11yData.ajaxNonce;
	const STRINGS = cleara11yData.strings;

	// Use admin-ajax fallback if REST API fails
	let useAjaxFallback = false;

	/**
	 * Dashboard App
	 */
	const Dashboard = {
		currentPostType: 'pages', // WordPress REST API uses plural (pages, posts)
		currentPage: 1,
		postTypeMap: {
			'page': 'pages',
			'post': 'posts'
		},
		isProcessingQueue: false,
		orchestrator: null,
		useParallelScanning: true, // Enable parallel scanning by default

		/**
		 * Initialize
		 */
		init() {
			// Get initial filter value and map to REST API endpoint
			const postTypeFilter = document.getElementById('cleara11y-post-type-filter');
			if (postTypeFilter) {
				const initialType = postTypeFilter.value;
				this.currentPostType = this.postTypeMap[initialType] || initialType;
			}
			this.bindEvents();
			this.loadPages();

			// Check for active queue and resume processing
			this.checkAndResumeQueue();

			// Listen for scanner messages
			window.addEventListener('message', this.handleScannerMessage.bind(this));
		},

		/**
		 * Check for active queue and resume processing
		 */
		async checkAndResumeQueue() {
			try {
				// First, expire any stuck jobs
				await fetch(API_URL + 'jobs/expire', {
					method: 'POST',
					headers: { 'X-WP-Nonce': NONCE }
				});

				// Clean up completed jobs from finished scans
				await fetch(API_URL + 'jobs/cleanup', {
					method: 'POST',
					headers: { 'X-WP-Nonce': NONCE }
				});

				// Get queue status to find the current scan_id
				const queueResponse = await fetch(API_URL + 'queue/status', {
					headers: { 'X-WP-Nonce': NONCE }
				});
				const queueData = await queueResponse.json();

				// Get current scan_id from queue if available
				const scanId = (queueData.queue && queueData.queue.length > 0) ? queueData.queue[0].scan_id : null;

				// Check job stats for parallel scanning, filtered by scan_id if available
				const statsUrl = scanId ? `${API_URL}jobs/stats?scan_id=${scanId}` : `${API_URL}jobs/stats`;
				const jobsResponse = await fetch(statsUrl, {
					headers: { 'X-WP-Nonce': NONCE }
				});
				const jobsData = await jobsResponse.json();

				// Start orchestrator if there are pending jobs or active queue
				if ((jobsData.pending > 0 || jobsData.active > 0) && this.useParallelScanning) {
					console.log('[ClearA11y Dashboard] Resuming with orchestrator, pending jobs:', jobsData.pending);
					this.showQueueStatus();
					this.startOrchestrator();
				} else if (queueData.active_count > 0) {
					// Use old sequential processing for backward compatibility
					console.log('[ClearA11y Dashboard] Resuming sequential queue processing');
					this.showQueueStatus();
					this.processQueue();
				}
			} catch (error) {
				console.error('Error checking queue status:', error);
			}
		},

		/**
		 * Start the parallel scan orchestrator
		 */
		startOrchestrator() {
			console.log('[ClearA11y Dashboard] Starting global scanner via event dispatch...');

			// Trigger the global scanner to start
			// The global scanner is loaded on all admin pages and will auto-detect the active scan
			if (window.ClearA11yGlobalScanner) {
				window.ClearA11yGlobalScanner.start();
				this.orchestrator = {
					stop: () => {
						if (window.ClearA11yGlobalScanner) {
							window.ClearA11yGlobalScanner.stop();
						}
					},
					getStatus: () => {
						return {
							running: window.ClearA11yGlobalScanner?.isScanning() || false,
							workerId: window.ClearA11yGlobalScanner?.getWorkerId() || null,
						};
					}
				};
				console.log('[ClearA11y Dashboard] Global scanner triggered successfully');
			} else {
				console.error('[ClearA11y Dashboard] ClearA11yGlobalScanner not available');
				console.error('[ClearA11y Dashboard] Available scripts:',
					Array.from(document.querySelectorAll('script[src*="cleara11y"]')).map(s => s.src));

				// Fall back to sequential processing
				console.warn('[ClearA11y Dashboard] Falling back to sequential processing');
				this.useParallelScanning = false;
				this.processQueue();
				return;
			}

			// Set up periodic UI updates while scan is running
			if (this.statusUpdateInterval) {
				clearInterval(this.statusUpdateInterval);
			}
			this.statusUpdateInterval = setInterval(() => {
				this.updateQueueStatus();
				this.updateSiteHealthStats();
			}, 5000);
		},

		/**
		 * Stop the parallel scan orchestrator
		 */
		stopOrchestrator() {
			if (this.orchestrator) {
				console.log('[ClearA11y Dashboard] Stopping orchestrator...');
				this.orchestrator.stop();
				this.orchestrator = null;
				this.isProcessingQueue = false;
			}
			// Clear status update interval
			if (this.statusUpdateInterval) {
				clearInterval(this.statusUpdateInterval);
				this.statusUpdateInterval = null;
			}
		},

		/**
		 * Update queue status (called during orchestrator operation)
		 */
		async updateQueueStatus() {
			try {
				await this.showQueueStatus();
				// Periodically refresh page list to show progress
				this.loadPages();
			} catch (error) {
				console.error('Error updating queue status:', error);
			}
		},

		/**
		 * Update Site Health stats
		 */
		async updateSiteHealthStats() {
			try {
				const response = await fetch(API_URL + 'stats/overview', {
					headers: { 'X-WP-Nonce': NONCE }
				});
				const stats = await response.json();

				// Update the stat boxes with animation
				const updateStat = (id, value) => {
					const el = document.getElementById(id);
					if (el) {
						const oldValue = parseInt(el.textContent) || 0;
						const newValue = parseInt(value) || 0;
						if (oldValue !== newValue) {
							el.textContent = newValue;
							// Add highlight animation
							el.style.transition = 'transform 0.2s ease';
							el.style.transform = 'scale(1.2)';
							setTimeout(() => {
								el.style.transform = 'scale(1)';
							}, 200);
						}
					}
				};

				updateStat('cleara11y-total-critical', stats.total_critical);
				updateStat('cleara11y-total-moderate', stats.total_moderate);
				updateStat('cleara11y-total-minor', stats.total_minor);
				updateStat('cleara11y-total-pages', stats.total_pages);

				console.log('[ClearA11y Dashboard] Site Health stats updated:', stats);
			} catch (error) {
				console.error('Error updating Site Health stats:', error);
			}
		},

		/**
		 * Handle messages from scanner
		 */
		handleScannerMessage(event) {
			if (event.data && event.data.source === 'cleara11y_scanner') {
				const msg = event.data.data;

				switch (msg.type) {
					case 'scan_started':
						console.log('Scan started for post:', msg.postId);
						break;
					case 'scan_complete':
						console.log('Scan complete for post:', msg.postId);
						break;
					case 'scan_error':
						console.error('Scan error for post:', msg.postId, msg.error);
						break;
				}
			}
		},

		/**
		 * Bind event listeners
		 */
		bindEvents() {
			// Post type filter
			const postTypeFilter = document.getElementById('cleara11y-post-type-filter');
			if (postTypeFilter) {
				postTypeFilter.addEventListener('change', (e) => {
					const selectedType = e.target.value;
					// Convert singular to plural for REST API
					this.currentPostType = this.postTypeMap[selectedType] || selectedType;
					this.currentPage = 1;
					this.loadPages();
				});
			}

			// Start full scan button
			const startScanBtn = document.querySelector('.cleara11y-start-scan');
			if (startScanBtn) {
				startScanBtn.addEventListener('click', () => this.startFullScan());
			}

		},

		/**
		 * Load pages with scan status
		 */
		async loadPages() {
			const container = document.getElementById('cleara11y-pages-container');
			if (!container) return;

			container.dataset.loading = 'true';
			container.innerHTML = `
				<div class="cleara11y-loading-spinner">
					<span class="spinner is-active"></span>
					${STRINGS.loading}
				</div>
			`;

			try {
				let posts;

				if (useAjaxFallback) {
					// Use admin-ajax fallback
					posts = await this.loadPagesViaAjax();
				} else {
					// Try REST API first
					try {
						posts = await this.loadPagesViaRest();
					} catch (restError) {
						console.warn('REST API failed, trying admin-ajax fallback:', restError);
						useAjaxFallback = true;
						posts = await this.loadPagesViaAjax();
					}
				}

				this.renderPages(posts);

			} catch (error) {
				console.error('Error loading pages:', error);
				container.innerHTML = '<p class="cleara11y-error">Error loading pages: ' + error.message + '</p>';
			} finally {
				container.dataset.loading = 'false';
			}
		},

		/**
		 * Load pages via REST API
		 */
		async loadPagesViaRest() {
			const postsResponse = await fetch(WP_API_URL + this.currentPostType + '?per_page=20&page=' + this.currentPage + '&_fields=id,title,link,meta', {
				headers: {
					'X-WP-Nonce': NONCE
				}
			});

			if (!postsResponse.ok) {
				throw new Error(`HTTP ${postsResponse.status}: ${postsResponse.statusText}`);
			}

			const posts = await postsResponse.json();

			if (!Array.isArray(posts)) {
				if (posts.code && posts.message) {
					throw new Error(posts.message);
				}
				throw new Error('Invalid response from server');
			}

			// Get issue counts for each post
			return await Promise.all(
				posts.map(async (post) => {
					try {
						const issuesUrl = API_URL + 'posts/' + post.id + '/issues';
						console.log('[ClearA11y Dashboard] Fetching issues from:', issuesUrl);

						const issuesResponse = await fetch(issuesUrl, {
							headers: {
								'X-WP-Nonce': NONCE
							}
						});

						console.log('[ClearA11y Dashboard] Issues response status:', issuesResponse.status);

						if (!issuesResponse.ok) {
							console.warn('[ClearA11y Dashboard] Issues request failed:', issuesResponse.status, issuesResponse.statusText);
							// Try to get error text for debugging
							const errorText = await issuesResponse.text();
							console.warn('[ClearA11y Dashboard] Error response:', errorText.substring(0, 200));
							return { ...post, issues: { counts: { total: 0, critical: 0, moderate: 0, minor: 0 } } };
						}

						const issuesData = await issuesResponse.json();
						return { ...post, issues: issuesData };
					} catch (e) {
						console.warn('Failed to load issues for post ' + post.id + ':', e);
						return { ...post, issues: { counts: { total: 0, critical: 0, moderate: 0, minor: 0 } } };
					}
				})
			);
		},

		/**
		 * Load pages via admin-ajax (fallback)
		 */
		async loadPagesViaAjax() {
			// Convert plural REST API type back to singular for WP_Query
			const postTypeMap = { 'pages': 'page', 'posts': 'post' };
			const postType = postTypeMap[this.currentPostType] || 'page';

			const formData = new URLSearchParams({
				action: 'cleara11y_get_posts',
				nonce: AJAX_NONCE,
				post_type: postType,
				page: this.currentPage.toString()
			});

			const response = await fetch(AJAX_URL, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: formData
			});

			const data = await response.json();

			if (!data.success) {
				throw new Error(data.data.message || 'Failed to load posts');
			}

			// Get issue counts for each post
			return await Promise.all(
				data.data.posts.map(async (post) => {
					try {
						const issueFormData = new URLSearchParams({
							action: 'cleara11y_get_post_issues',
							nonce: AJAX_NONCE,
							post_id: post.id.toString()
						});

						const issuesResponse = await fetch(AJAX_URL, {
							method: 'POST',
							headers: {
								'Content-Type': 'application/x-www-form-urlencoded',
							},
							body: issueFormData
						});

						const issuesData = await issuesResponse.json();

						if (issuesData.success) {
							return { ...post, issues: issuesData.data };
						}
						return { ...post, issues: { counts: { total: 0, critical: 0, moderate: 0, minor: 0 } } };
					} catch (e) {
						console.warn('Failed to load issues for post ' + post.id + ':', e);
						return { ...post, issues: { counts: { total: 0, critical: 0, moderate: 0, minor: 0 } } };
					}
				})
			);
		},

		/**
		 * Render pages list
		 */
		renderPages(posts) {
			 const container = document.getElementById('cleara11y-pages-container');
			 if (!container) return;

			 if (posts.length === 0) {
				 container.innerHTML = '<p class="cleara11y-empty-state">No pages found.</p>';
				 return;
			 }

			 // Add bulk actions bar
			 const bulkActions = `
				<div class="cleara11y-bulk-actions">
					<input type="checkbox" id="cleara11y-select-all" class="cleara11y-select-all">
					<label for="cleara11y-select-all">Select All</label>
					<button type="button" class="button button-primary cleara11y-bulk-scan">
						Scan Selected
					</button>
					<span class="cleara11y-selected-count"></span>
				</div>
			 `;

			 const html = bulkActions + posts.map(post => this.renderPageItem(post)).join('');
			 container.innerHTML = html;

			 // Bind scan buttons
			 container.querySelectorAll('.cleara11y-scan-page').forEach(btn => {
				 btn.addEventListener('click', (e) => {
					 const postId = e.target.closest('.cleara11y-scan-page').dataset.postId;
					 this.scanPage(postId);
				 });
			 });

			 // Bind select all checkbox
			 const selectAll = container.querySelector('.cleara11y-select-all');
			 if (selectAll) {
				 selectAll.addEventListener('change', (e) => {
					 const checked = e.target.checked;
					 container.querySelectorAll('.cleara11y-page-checkbox').forEach(cb => {
						 cb.checked = checked;
					 });
					 this.updateSelectedCount();
				 });
			 }

			 // Bind individual checkboxes
			 container.querySelectorAll('.cleara11y-page-checkbox').forEach(cb => {
				 cb.addEventListener('change', () => this.updateSelectedCount());
			 });

			 // Bind bulk scan button
			 const bulkScanBtn = container.querySelector('.cleara11y-bulk-scan');
			 if (bulkScanBtn) {
				 bulkScanBtn.addEventListener('click', () => this.bulkScanSelected());
			 }
		},

		/**
		 * Update selected count
		 */
		updateSelectedCount() {
			const count = document.querySelectorAll('.cleara11y-page-checkbox:checked').length;
			const countSpan = document.querySelector('.cleara11y-selected-count');
			if (countSpan) {
				countSpan.textContent = count > 0 ? `${count} selected` : '';
			}
		},

		/**
		 * Render single page item
		 */
		renderPageItem(post) {
			const counts = post.issues?.counts || { total: 0, critical: 0, moderate: 0, minor: 0 };

			let badges = '';
			if (counts.critical > 0) {
				badges += `<span class="cleara11y-issue-badge critical">${counts.critical}</span>`;
			}
			if (counts.moderate > 0) {
				badges += `<span class="cleara11y-issue-badge moderate">${counts.moderate}</span>`;
			}
			if (counts.minor > 0) {
				badges += `<span class="cleara11y-issue-badge minor">${counts.minor}</span>`;
			}

			const statusText = counts.total === 0 ? 'No issues' : counts.total + ' issue' + (counts.total !== 1 ? 's' : '');

			// Add view report button if there are issues
			const viewReportButton = counts.total > 0
				? `<a href="${this.getReportUrl(post.id)}" class="button button-small button-primary">View Report</a>`
				: '';

			return `
				<div class="cleara11y-page-item">
					<div class="cleara11y-page-checkbox-wrapper">
						<input type="checkbox" class="cleara11y-page-checkbox" value="${post.id}" id="cleara11y-cb-${post.id}">
						<label for="cleara11y-cb-${post.id}"></label>
					</div>
					<div class="cleara11y-page-info">
						<div class="cleara11y-page-title">${this.escapeHtml(post.title.rendered)}</div>
						<div class="cleara11y-page-status">${statusText}</div>
					</div>
					<div class="cleara11y-page-actions">
						${badges}
						${viewReportButton}
						<button type="button" class="button button-small cleara11y-scan-page" data-post-id="${post.id}">
							Scan
						</button>
					</div>
				</div>
			`;
		},

		/**
		 * Scan a single page
		 */
		async scanPage(postId) {
			const button = document.querySelector(`.cleara11y-scan-page[data-post-id="${postId}"]`);

			if (!button) return;

			button.disabled = true;
			button.textContent = 'Queued...';

			// Add to queue and let background processor handle it
			await this.addToQueue([postId], `Scan - Page ${postId}`, 'individual');

			// Button state will be updated when page list refreshes
		},

		/**
		 * Poll for scan completion
		 */
		async pollScanStatus(scanId, button) {
			const startTime = Date.now();
			const timeout = 300000; // 5 minutes timeout

			const checkStatus = async () => {
				try {
					// Check timeout
					if (Date.now() - startTime > timeout) {
						button.disabled = false;
						button.textContent = 'Scan';
						alert('Scan timed out. The popup window may have been blocked or closed. Please allow popups and try again.');
						return;
					}

					const response = await fetch(API_URL + 'scans/' + scanId, {
						headers: { 'X-WP-Nonce': NONCE }
					});

					const data = await response.json();

					if (data.scan && data.scan.status === 'completed') {
						button.disabled = false;
						button.textContent = 'Scan';
						this.loadPages(); // Reload the list
						this.updateSiteHealthStats(); // Update Site Health widget
						return;
					}

					if (data.scan && data.scan.status === 'failed') {
						button.disabled = false;
						button.textContent = 'Scan';
						alert(STRINGS.scanFailed);
						return;
					}

					// Continue polling
					setTimeout(checkStatus, 2000);

				} catch (error) {
					console.error('Status check error:', error);
					button.disabled = false;
					button.textContent = 'Scan';
				}
			};

			setTimeout(checkStatus, 2000);
		},

		/**
		 * Start full site scan
		 */
		async startFullScan() {
			if (!confirm('This will scan all published pages and posts. The scan may take several minutes. Continue?')) {
				return;
			}

			// Get all published pages and posts
			try {
				const [pagesResponse, postsResponse] = await Promise.all([
					fetch(WP_API_URL + 'pages?per_page=100&_fields=id', { headers: { 'X-WP-Nonce': NONCE } }),
					fetch(WP_API_URL + 'posts?per_page=100&_fields=id', { headers: { 'X-WP-Nonce': NONCE } })
				]);

				const pages = await pagesResponse.json();
				const posts = await postsResponse.json();

				const allPostIds = [
					...pages.map(p => p.id),
					...posts.map(p => p.id)
				];

				if (allPostIds.length === 0) {
					alert('No published pages or posts found to scan.');
					return;
				}

				await this.addToQueue(allPostIds, 'Full Site Scan');

			} catch (error) {
				console.error('Error starting full scan:', error);
				alert('Error starting full scan: ' + error.message);
			}
		},

		/**
		 * Add posts to scan queue
		 */
		async addToQueue(postIds, scanName = null, scanType = 'full') {
			try {
				const response = await fetch(API_URL + 'queue/add', {
					method: 'POST',
					headers: {
						'X-WP-Nonce': NONCE,
						'Content-Type': 'application/json'
					},
					body: JSON.stringify({
						post_ids: postIds,
						scan_name: scanName,
						scan_type: scanType
					})
				});

				// Check for "scan already active" error
				if (response.status === 409) {
					const errorData = await response.json();
					const message = errorData.message || 'Another scan is already in progress. Please wait for it to complete before starting a new scan.';
					alert('Cannot start scan: ' + message);
					return;
				}

				// Check for other errors
				if (!response.ok) {
					const errorData = await response.json().catch(() => ({ message: 'Unknown error' }));
					throw new Error(errorData.message || `HTTP ${response.status}: ${response.statusText}`);
				}

				const data = await response.json();

				if (data.scan_id) {
					// For parallel scanning, also create jobs in scan_jobs table
					if (this.useParallelScanning) {
						try {
							const jobsResponse = await fetch(API_URL + 'queue/create-jobs', {
								method: 'POST',
								headers: {
									'X-WP-Nonce': NONCE,
									'Content-Type': 'application/json'
								},
								body: JSON.stringify({
									post_ids: postIds,
									scan_id: data.scan_id,
									priority: 10
								})
							});

							const jobsData = await jobsResponse.json();
							console.log('[ClearA11y Dashboard] Created', jobsData.jobs_created, 'jobs for parallel processing');
						} catch (jobsError) {
							console.error('[ClearA11y Dashboard] Error creating jobs:', jobsError);
							// Fall back to sequential processing
							this.useParallelScanning = false;
						}
					}

					// Show queue status
					this.showQueueStatus();

					// Start processing (either orchestrator or sequential)
					if (this.useParallelScanning) {
						this.startOrchestrator();
					} else {
						this.processQueue();
					}

					// Reload page list to update status
					setTimeout(() => this.loadPages(), 1000);
				} else {
					throw new Error(data.message || 'Failed to add to queue');
				}

			} catch (error) {
				console.error('Queue error:', error);
				alert('Error adding to queue: ' + error.message);
			}
		},

		/**
		 * Get and display queue status
		 */
		async showQueueStatus() {
			try {
				// Check orchestrator status first
				let orchestratorStatus = null;
				if (this.orchestrator) {
					orchestratorStatus = this.orchestrator.getStatus();
				}

				// First, get queue status to find the current scan_id
				const queueResponse = await fetch(API_URL + 'queue/status', {
					headers: { 'X-WP-Nonce': NONCE }
				});
				const data = await queueResponse.json();

				// Get current scan_id from queue if available
				const scanId = (data.queue && data.queue.length > 0) ? data.queue[0].scan_id : null;

				// Check job stats for parallel scanning, filtered by scan_id if available
				const statsUrl = scanId ? `${API_URL}jobs/stats?scan_id=${scanId}` : `${API_URL}jobs/stats`;
				const jobsResponse = await fetch(statsUrl, {
					headers: { 'X-WP-Nonce': NONCE }
				});
				const jobsData = await jobsResponse.json();

				// Determine if we should show the indicator
				const hasActiveJobs = jobsData.pending > 0 || jobsData.active > 0;
				const hasActiveQueue = data.active_count > 0;
				const isOrchestratorRunning = orchestratorStatus && orchestratorStatus.running;

				if (hasActiveJobs || hasActiveQueue || isOrchestratorRunning) {
					// Show queue status indicator
					let existingIndicator = document.getElementById('cleara11y-queue-indicator');
					if (!existingIndicator) {
						existingIndicator = document.createElement('div');
						existingIndicator.id = 'cleara11y-queue-indicator';
						existingIndicator.innerHTML = `
							<div class="cleara11y-queue-status">
								<span class="spinner is-active"></span>
								<span class="cleara11y-queue-text">Processing scan queue...</span>
								<button type="button" class="button button-link cleara11y-queue-close">×</button>
							</div>
						`;
						document.querySelector('.wrap').prepend(existingIndicator);

						existingIndicator.querySelector('.cleara11y-queue-close').addEventListener('click', () => {
							this.stopOrchestrator();
							existingIndicator.remove();
						});
					}

					// Update with progress info
					const text = existingIndicator.querySelector('.cleara11y-queue-text');

					if (isOrchestratorRunning) {
						// Show orchestrator status
						const totalJobs = jobsData.pending + jobsData.active + jobsData.completed;
						const completed = jobsData.completed;
						// text.textContent = `Parallel Scanning: ${completed}/${totalJobs} pages complete (${orchestratorStatus.activeJobs} workers active)`;
						text.textContent = `Parallel Scanning: ${completed}/${totalJobs} pages complete`;
					} else if (hasActiveJobs) {
						// Jobs exist but orchestrator not running - show status
						const totalJobs = jobsData.pending + jobsData.active + jobsData.completed;
						const completed = jobsData.completed;
						text.textContent = `Scan Queue: ${completed}/${totalJobs} pages complete`;
					} else if (hasActiveQueue) {
						// Old sequential queue - show that status
						const queue = data.queue[0];
						if (queue) {
							text.textContent = `Scanning: ${queue.completed}/${queue.total_items} pages complete`;
						}
					}
				} else {
					// No active queue - remove the indicator
					const existingIndicator = document.getElementById('cleara11y-queue-indicator');
					if (existingIndicator) {
						existingIndicator.remove();
					}
				}

				return data;

			} catch (error) {
				console.error('Error getting queue status:', error);
			}
		},

		/**
		 * Process the scan queue in background (sequential mode)
		 */
		processQueue() {
			// Check if orchestrator is already running
			if (this.orchestrator) {
				console.log('[ClearA11y Dashboard] Orchestrator is running, skipping sequential processing');
				return;
			}

			// Check if already processing
			if (this.isProcessingQueue) {
				return;
			}

			this.isProcessingQueue = true;
			let isScanning = false; // Track if a scan is currently running

			// Use a loop instead of recursion to prevent concurrent scans
			(async () => {
				while (this.isProcessingQueue) {
					// Prevent concurrent scans by checking if a scan is already in progress
					if (isScanning) {
						console.log('[ClearA11y Dashboard] Scan already in progress, waiting...');
						// Wait a bit and retry
						await new Promise(resolve => setTimeout(resolve, 100));
						continue;
					}

					console.log('[ClearA11y Dashboard] processNext called - fetching next item...');

					try {
						// Get next item from queue
						const response = await fetch(API_URL + 'queue/next', {
						method: 'POST',
						headers: { 'X-WP-Nonce': NONCE }
					});

					console.log('[ClearA11y Dashboard] queue/next response status:', response.status);
					const data = await response.json();
					console.log('[ClearA11y Dashboard] queue/next data:', data);

					if (!data.item) {
						// No more items in queue (or item was skipped)
						if (data.skipped) {
							console.log('[ClearA11y Dashboard] Skipped invalid item:', data.message);
							// Update status and continue
							try {
								await this.showQueueStatus();
							} catch (e) {
								console.error('[ClearA11y Dashboard] showQueueStatus failed:', e);
							}
							try {
								this.loadPages();
							} catch (e) {
								console.error('[ClearA11y Dashboard] loadPages failed:', e);
							}
							try {
								await this.updateSiteHealthStats();
							} catch (e) {
								console.error('[ClearA11y Dashboard] updateSiteHealthStats failed:', e);
							}
							console.log('[ClearA11y Dashboard] Retrying in 500ms...');
							await new Promise(resolve => setTimeout(resolve, 500));
							continue;
						}
						// Queue is complete
						this.isProcessingQueue = false;
						try {
							await this.showQueueStatus(); // Update UI to hide queue indicator
						} catch (e) {
							console.error('[ClearA11y Dashboard] showQueueStatus failed:', e);
						}
						try {
							await this.updateSiteHealthStats(); // Final stats update
						} catch (e) {
							console.error('[ClearA11y Dashboard] updateSiteHealthStats failed:', e);
						}
						console.log('[ClearA11y Dashboard] Queue processing complete!');
						break;
					}

					// Mark scan as in progress
					isScanning = true;

					// Scan this item in background
					const item = data.item;
					const scanUrl = data.scan_url;

					console.log('[ClearA11y Dashboard] Scanning:', item.post_title, 'via injected axe-core');
					console.log('[ClearA11y Dashboard] Scan URL:', scanUrl);

					// Create a hidden iframe to load the page (1x1px, visibility:hidden - still in DOM for proper a11y checks)
					const iframe = document.createElement('iframe');
					iframe.style.cssText = 'position:fixed;bottom:0;right:0;width:1px;height:1px;visibility:hidden;opacity:0;pointer-events:none;border:none;z-index:-1;';
					iframe.title = 'Accessibility scanning iframe - this will be removed automatically';
					iframe.name = 'cleara11y-scan-' + item.scan_id;
					iframe.dataset.scanId = item.scan_id;
					iframe.dataset.postId = item.post_id;
					// Don't set src yet - set it after appending to DOM

					// Promise-based scan execution
					const scanComplete = new Promise((resolve, reject) => {
						const timeoutId = setTimeout(() => {
							iframe.remove();
							reject(new Error('Scan timeout after 90 seconds'));
						}, 90000);

						iframe.onload = () => {
							console.log('[ClearA11y Dashboard] iframe loaded, waiting for page to fully render...');

							// Wait for DOM to be ready and page to fully render (longer wait for complex pages)
							let domReadyCheckCount = 0;
							const maxDomChecks = 20; // Up to 10 seconds for DOM ready
							const domReadyCheck = setInterval(() => {
								domReadyCheckCount++;

								try {
									// Check if we can access iframe content and if DOM is ready
									if (!iframe.contentWindow || !iframe.contentDocument) {
										clearInterval(domReadyCheck);
										clearTimeout(timeoutId);
										iframe.remove();
										reject(new Error('Cannot access iframe content - possible cross-origin issue'));
										return;
									}

									const doc = iframe.contentDocument;
									const domReady = doc.readyState === 'complete' || doc.readyState === 'interactive';

									if (!domReady) {
										console.log('[ClearA11y Dashboard] Waiting for DOM ready... state:', doc.readyState);
										if (domReadyCheckCount >= maxDomChecks) {
											clearInterval(domReadyCheck);
											clearTimeout(timeoutId);
											iframe.remove();
											reject(new Error('DOM did not become ready after ' + (maxDomChecks * 500) + 'ms'));
										}
										return;
									}

									// DOM is ready, now inject axe-core
									clearInterval(domReadyCheck);
									console.log('[ClearA11y Dashboard] DOM is ready, injecting axe-core...');

									// Create and inject axe-core script into iframe (local copy)
									const axeScript = doc.createElement('script');
									axeScript.src = cleara11yData.pluginUrl + 'assets/js/axe.min.js';
									axeScript.onload = () => {
										console.log('[ClearA11y Dashboard] axe-core script loaded, waiting for initialization...');

										// Now inject evidence-extractor.js
										const evidenceExtractorScript = doc.createElement('script');
										evidenceExtractorScript.src = cleara11yData.pluginUrl + 'assets/js/evidence-extractor.js';
										evidenceExtractorScript.onload = () => {
											console.log('[ClearA11y Dashboard] evidence-extractor.js loaded');
										};
										evidenceExtractorScript.onerror = () => {
											console.error('[ClearA11y Dashboard] Failed to load evidence-extractor.js');
										};
										doc.head.appendChild(evidenceExtractorScript);

										// Poll for axe availability (up to 10 seconds)
										let pollCount = 0;
										const maxPolls = 20;
										const pollInterval = setInterval(() => {
											pollCount++;

											// Check if axe is available
											const hasAxe = iframe.contentWindow && iframe.contentWindow.axe;

											console.log('[ClearA11y Dashboard] Polling for axe... attempt', pollCount, 'hasAxe:', !!hasAxe);

											if (hasAxe) {
												clearInterval(pollInterval);
												console.log('[ClearA11y Dashboard] axe-core is ready, running scan...');

												// Function to run scan with retry logic for "already running" error
												const runScanWithRetry = async (retryCount = 0) => {
													const maxRetries = 5;
													const retryDelay = 500; // 500ms - minimal wait for axe cleanup

													// Wait a bit before starting if this is a retry
													if (retryCount > 0) {
														await new Promise(resolve => setTimeout(resolve, retryDelay));
													}

													try {
														// Use Promise-based API with proper cleanup
														const results = await new Promise((resolve, reject) => {
															iframe.contentWindow.axe.run(iframe.contentDocument, {
																// Run all WCAG 2.0 A, AA and WCAG 2.1 AA rules
																runOnly: {
																	type: 'tag',
																	values: ['wcag2a', 'wcag2aa', 'wcag21aa']
																},
																// Get all result types for comprehensive analysis
																resultTypes: ['violations', 'passes', 'incomplete']
															}, (err, results) => {
																if (err) {
																	reject(err);
																} else {
																	resolve(results);
																}
															});
														});

														// Extract evidence from scan results
														let evidence = [];
														if (iframe.contentWindow.extractEvidenceFromAxeResults) {
															console.log('[ClearA11y Dashboard] Extracting evidence from scan results...');
															try {
																evidence = await iframe.contentWindow.extractEvidenceFromAxeResults(results, {
																	maxSnippetLen: 4000,
																	maxTextLen: 400,
																	ancestorDepth: 6,
																	allowDataAttrs: true,
																	dataAttrWhitelist: ["data-testid", "data-qa", "data-cy"],
																});
																console.log('[ClearA11y Dashboard] Evidence extracted:', evidence.length, 'items');
															} catch (e) {
																console.error('[ClearA11y Dashboard] Evidence extraction failed:', e);
																evidence = [];
															}
														} else {
															console.warn('[ClearA11y Dashboard] extractEvidenceFromAxeResults not available in iframe');
														}

														return { results, evidence };
													} catch (err) {
														// Convert error to string for checking
														const errorStr = err ? (typeof err === 'string' ? err : (err.message || String(err))) : '';

														console.log('[ClearA11y Dashboard] Scan error detected:', errorStr);
														console.log('[ClearA11y Dashboard] Error object:', err);

														// Check if error is "already running" and we haven't exceeded retries
														if (errorStr.toLowerCase().includes('already running') && retryCount < maxRetries) {
															console.log(`[ClearA11y Dashboard] Axe already running, retrying (${retryCount + 1}/${maxRetries}) after ${retryDelay}ms...`);
															// Recursive retry with incremented count
															return runScanWithRetry(retryCount + 1);
														}
														// Re-throw if not a retryable error or retries exhausted
														console.log('[ClearA11y Dashboard] Retries exhausted or non-retryable error, throwing...');
														throw err;
													}
												};

												// Run scan with retry logic - no initial delay needed
												runScanWithRetry()
													.then(({ results, evidence }) => {
														clearInterval(pollInterval);
														clearTimeout(timeoutId);
														iframe.remove();

														// Log all violations for debugging
														console.log('[ClearA11y Dashboard] Scan results:', {
															violations: results.violations?.length || 0,
															passes: results.passes?.length || 0,
															incomplete: results.incomplete?.length || 0,
															evidenceExtracted: evidence.length
														});

														// Log each violation type
														if (results.violations) {
															results.violations.forEach(v => {
																console.log('[ClearA11y Dashboard] Violation:', v.id, '-', v.description, 'nodes:', v.nodes?.length || 0);
															});
														}

														// Additional post-scan filtering to remove any ClearA11y violations
														if (results.violations) {
															const originalCount = results.violations.length;
															results.violations = results.violations.filter(violation => {
																// Check all nodes in this violation
																return violation.nodes.some(node => {
																	const target = node.target && node.target[0];
																	if (!target) return true;

																	const selector = target.selector || (typeof target === 'string' ? target : '');
																	if (!selector) return true;

																	// Filter out violations targeting ClearA11y elements
																	const isClearA11yElement = [
																		selector.includes('[data-cleara11y-plugin]'),
																		selector.includes('[data-cleara11y-highlighted]'),
																		selector.includes('.cleara11y-toggle'),
																		selector.includes('.cleara11y-panel'),
																		selector.includes('.cleara11y-tooltip'),
																		selector.includes('.cleara11y-highlight-issue'),
																		selector.includes('.cleara11y-highlight-panel'),
																		selector.includes('.cleara11y-issue-severity'),
																		selector.includes('[data-issue-index]')
																	].some(check => check);

																	return !isClearA11yElement;
																});
															});

															if (results.violations.length !== originalCount) {
																console.log('[ClearA11y Dashboard] Filtered out', originalCount - results.violations.length, 'ClearA11y plugin violations');
															}
														}

														console.log('[ClearA11y Dashboard] Scan complete, found', results.violations?.length || 0, 'violations');

														// Save results via REST API with evidence
														return fetch(API_URL + 'scan/results', {
															method: 'POST',
															headers: {
																'Content-Type': 'application/json',
																'X-WP-Nonce': NONCE
															},
															body: JSON.stringify({
																token: data.token,
																results: results,
																evidence: evidence
															})
														});
													})
													.then(response => response.json())
													.then(saveData => {
														if (saveData.success) {
															console.log('[ClearA11y Dashboard] Results saved successfully');
															resolve(saveData.data || saveData);
														} else {
															reject(new Error(saveData.message || 'Failed to save results'));
														}
													})
													.catch(scanError => {
														console.error('[ClearA11y Dashboard] Scan error:', scanError);
														clearInterval(pollInterval);
														clearTimeout(timeoutId);
														if (iframe.parentNode) {
															iframe.remove();
														}
														reject(scanError);
													});
											} else if (pollCount >= maxPolls) {
												clearInterval(pollInterval);
												clearTimeout(timeoutId);
												iframe.remove();
												reject(new Error('axe-core failed to initialize after ' + (maxPolls * 500) + 'ms'));
											}
										}, 500);
									};

									axeScript.onerror = () => {
										clearTimeout(timeoutId);
										iframe.remove();
										reject(new Error('Failed to load axe-core script from CDN'));
									};

									// Inject axe-core into iframe
									iframe.contentDocument.head.appendChild(axeScript);

								} catch (error) {
									clearTimeout(timeoutId);
									iframe.remove();
									reject(new Error('Failed to inject axe-core: ' + error.message));
								}
							}, 500); // Check every 500ms
						};

						iframe.onerror = () => {
							clearTimeout(timeoutId);
							reject(new Error('Failed to load page in iframe'));
						};
					});

					// Append iframe and start scan
					document.body.appendChild(iframe);
					iframe.src = scanUrl;

					// Wait for scan completion
					try {
						await scanComplete;
						console.log('[ClearA11y Dashboard] Scan completed successfully for:', item.post_title);
					} catch (error) {
						console.error('[ClearA11y Dashboard] Scan failed for:', item.post_title, error);
						// Mark as failed in database
						try {
							await fetch(API_URL + 'scan-items/' + item.id + '/fail', {
								method: 'POST',
								headers: { 'X-WP-Nonce': NONCE },
								body: JSON.stringify({ error_message: error.message })
							});
						} catch (e) {
							console.error('[ClearA11y Dashboard] Failed to mark scan as failed:', e);
						}
					}

					// Update queue status display (don't let failure stop queue)
					try {
						await this.showQueueStatus();
					} catch (e) {
						console.error('[ClearA11y Dashboard] Failed to update queue status:', e);
					}

					// Reload page list to update statuses (don't let failure stop queue)
					try {
						this.loadPages();
					} catch (e) {
						console.error('[ClearA11y Dashboard] Failed to reload page list:', e);
					}

					// Update Site Health stats after each scan completes
					try {
						await this.updateSiteHealthStats();
					} catch (e) {
						console.error('[ClearA11y Dashboard] Failed to update Site Health stats:', e);
					}

					// Process next item immediately (new iframe = clean state)
					console.log('[ClearA11y Dashboard] Moving to next item...');
					// Loop continues to next iteration (will check isScanning flag)

					// Reset the scanning flag so the next iteration can proceed
					isScanning = false;

				} catch (error) {
					console.error('[ClearA11y Dashboard] Error processing queue item:', error);
					console.error('[ClearA11y Dashboard] Error stack:', error.stack);
					// Continue to next item even if this one failed
					console.log('[ClearA11y Dashboard] Continuing to next item...');
					// Loop continues to next iteration (will check isScanning flag)

					// Reset the scanning flag so the next iteration can proceed
					isScanning = false;
				}
				}
			})();
		},

		/**
		 * Bulk scan selected pages
		 */
		async bulkScanSelected() {
			const checkboxes = document.querySelectorAll('.cleara11y-page-checkbox:checked');
			const postIds = Array.from(checkboxes).map(cb => parseInt(cb.value));

			if (postIds.length === 0) {
				alert('Please select at least one page to scan.');
				return;
			}

			if (!confirm(`Scan ${postIds.length} selected page${postIds.length > 1 ? 's' : ''}?`)) {
				return;
			}

			await this.addToQueue(postIds, `Bulk Scan - ${postIds.length} pages`);
		},

		/**
		 * Escape HTML
		 */
		escapeHtml(text) {
			const div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		},

		/**
		 * Get page report URL for a post
		 */
		getReportUrl(postId) {
			return `${window.location.origin}/wp-admin/admin.php?page=cleara11y-page-report&post_id=${postId}`;
		}
	};

	// Initialize on DOM ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', () => Dashboard.init());
	} else {
		Dashboard.init();
	}

	// Make Dashboard accessible globally for onclick handlers
	window.cleara11y = Dashboard;

})();
