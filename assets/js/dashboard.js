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

			// View scan buttons
			document.querySelectorAll('.cleara11y-view-scan').forEach(btn => {
				btn.addEventListener('click', (e) => {
					const scanId = e.target.dataset.scanId;
					this.viewScanDetails(scanId);
				});
			});
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
		 * View scan details
		 */
		async viewScanDetails(scanId) {
			try {
				const response = await fetch(API_URL + 'scans/' + scanId, {
					headers: { 'X-WP-Nonce': NONCE }
				});

				const data = await response.json();

				if (!data.scan) {
					alert('Scan not found');
					return;
				}

				this.showScanDetailsModal(data.scan, data.items || []);

			} catch (error) {
				console.error('Error loading scan details:', error);
				alert('Error loading scan details: ' + error.message);
			}
		},

		/**
		 * Show scan details modal
		 */
		showScanDetailsModal(scan, items) {
			// Remove existing modal if any
			const existingModal = document.getElementById('cleara11y-scan-details-modal');
			if (existingModal) {
				existingModal.remove();
			}

			// Create modal
			const modal = document.createElement('div');
			modal.id = 'cleara11y-scan-details-modal';
			modal.className = 'cleara11y-modal-overlay active';

			// Calculate total issues across all items
			const totalCritical = items.reduce((sum, item) => sum + (item.critical_issues || 0), 0);
			const totalModerate = items.reduce((sum, item) => sum + (item.moderate_issues || 0), 0);
			const totalMinor = items.reduce((sum, item) => sum + (item.minor_issues || 0), 0);
			const totalIssues = items.reduce((sum, item) => sum + (item.total_issues || 0), 0);

			// Build items list HTML
			let itemsHtml = '';
			if (items.length > 0) {
				itemsHtml = `
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th>Page</th>
								<th>Status</th>
								<th>Issues</th>
								<th>Action</th>
							</tr>
						</thead>
						<tbody>
							${items.map(item => `
								<tr>
									<td>
										<strong>${this.escapeHtml(item.post_title || 'N/A')}</strong><br>
										<small><a href="${item.post_url}" target="_blank">${this.escapeHtml(item.post_url)}</a></small>
									</td>
									<td>
										<span class="cleara11y-status-badge cleara11y-status-${item.status}">
											${item.status.replace('_', ' ')}
										</span>
									</td>
									<td>
										${item.total_issues > 0 ? `
											<span class="cleara11y-issue-badges">
												${item.critical_issues > 0 ? `<span class="cleara11y-issue-badge critical">${item.critical_issues}</span>` : ''}
												${item.moderate_issues > 0 ? `<span class="cleara11y-issue-badge moderate">${item.moderate_issues}</span>` : ''}
												${item.minor_issues > 0 ? `<span class="cleara11y-issue-badge minor">${item.minor_issues}</span>` : ''}
											</span>
										` : '<span class="cleara11y-no-issues">No issues</span>'}
									</td>
									<td>
										${item.status === 'completed' ? `
											<button type="button" class="button button-small" onclick="cleara11y.viewPageIssues(${item.id})">
												View Issues
											</button>
										` : ''}
									</td>
								</tr>
							`).join('')}
						</tbody>
					</table>
				`;
			} else {
				itemsHtml = '<p>No pages in this scan.</p>';
			}

			modal.innerHTML = `
				<div class="cleara11y-modal">
					<div class="cleara11y-modal-header">
						<h3 class="cleara11y-modal-title">Scan Results</h3>
						<button type="button" class="cleara11y-modal-close">×</button>
					</div>
					<div class="cleara11y-modal-body">
						<div class="cleara11y-stats-grid" style="margin-bottom: 20px;">
							<div class="cleara11y-stat-box">
								<div class="cleara11y-stat-value cleara11y-stat-critical">${totalCritical}</div>
								<div class="cleara11y-stat-label">Critical</div>
							</div>
							<div class="cleara11y-stat-box">
								<div class="cleara11y-stat-value cleara11y-stat-moderate">${totalModerate}</div>
								<div class="cleara11y-stat-label">Moderate</div>
							</div>
							<div class="cleara11y-stat-box">
								<div class="cleara11y-stat-value cleara11y-stat-minor">${totalMinor}</div>
								<div class="cleara11y-stat-label">Minor</div>
							</div>
							<div class="cleara11y-stat-box">
								<div class="cleara11y-stat-value">${items.length}</div>
								<div class="cleara11y-stat-label">Pages</div>
							</div>
						</div>

						<h4>Pages Scanned</h4>
						${itemsHtml}

						<p style="margin-top: 15px; color: #646970;">
							<small>Scan ID: ${scan.id} | Created: ${new Date(scan.created_at).toLocaleString()}</small>
						</p>
					</div>
					<div class="cleara11y-modal-footer">
						<button type="button" class="button cleara11y-modal-close-btn">Close</button>
					</div>
				</div>
			`;

			// Add to document
			document.body.appendChild(modal);

			// Setup close handlers
			const closeBtn = modal.querySelector('.cleara11y-modal-close');
			const closeBtnFooter = modal.querySelector('.cleara11y-modal-close-btn');
			const closeHandler = () => modal.remove();
			closeBtn.addEventListener('click', closeHandler);
			closeBtnFooter.addEventListener('click', closeHandler);

			// Close on backdrop click
			modal.addEventListener('click', (e) => {
				if (e.target === modal) {
					modal.remove();
				}
			});
		},

		/**
		 * View page issues (called from modal)
		 */
		async viewPageIssues(scanItemId) {
			try {
				const response = await fetch(API_URL + 'scan-items/' + scanItemId + '/issues', {
					headers: { 'X-WP-Nonce': NONCE }
				});

				const data = await response.json();

				// Close the scan details modal
				const scanModal = document.getElementById('cleara11y-scan-details-modal');
				if (scanModal) {
					scanModal.remove();
				}

				// Show issues modal
				this.showPageIssuesModal(data.issues || [], data.scan_item || {});

			} catch (error) {
				console.error('Error loading page issues:', error);
				alert('Error loading page issues: ' + error.message);
			}
		},

		/**
		 * Show page issues modal with dismiss functionality
		 */
		showPageIssuesModal(issues, scanItem) {
			// Remove existing modal if any
			const existingModal = document.getElementById('cleara11y-issues-modal');
			if (existingModal) {
				existingModal.remove();
			}

			// Create modal
			const modal = document.createElement('div');
			modal.id = 'cleara11y-issues-modal';
			modal.className = 'cleara11y-modal-overlay active';

			// Store issues data on modal for filtering
			modal.issuesData = issues;
			modal.currentFilter = 'active'; // active, dismissed, all

			// Build the modal HTML
			this.renderIssuesModalContent(modal, scanItem);

			// Add to document
			document.body.appendChild(modal);

			// Setup close handlers
			const closeBtn = modal.querySelector('.cleara11y-modal-close');
			const closeBtnFooter = modal.querySelector('.cleara11y-modal-close-btn');
			const closeHandler = () => modal.remove();
			closeBtn.addEventListener('click', closeHandler);
			closeBtnFooter.addEventListener('click', closeHandler);

			// Setup filter tabs
			const filterTabs = modal.querySelectorAll('.cleara11y-filter-tab');
			filterTabs.forEach(tab => {
				tab.addEventListener('click', (e) => {
					const filter = e.target.dataset.filter;
					modal.currentFilter = filter;
					// Update active tab
					filterTabs.forEach(t => t.classList.remove('active'));
					e.target.classList.add('active');
					// Re-render content
					this.renderIssuesModalContent(modal, scanItem);
					// Re-attach event handlers
					this.attachIssuesModalHandlers(modal);
				});
			});

			// Attach other handlers
			this.attachIssuesModalHandlers(modal);

			// Close on backdrop click
			modal.addEventListener('click', (e) => {
				if (e.target === modal) {
					modal.remove();
				}
			});
		},

		/**
		 * Render issues modal content
		 */
		renderIssuesModalContent(modal, scanItem) {
			const issues = modal.issuesData || [];
			const currentFilter = modal.currentFilter || 'active';

			// Filter issues
			let filteredIssues = issues;
			if (currentFilter === 'active') {
				filteredIssues = issues.filter(i => !i.dismissed);
			} else if (currentFilter === 'dismissed') {
				filteredIssues = issues.filter(i => i.dismissed);
			}

			// Count by status
			const activeCount = issues.filter(i => !i.dismissed).length;
			const dismissedCount = issues.filter(i => i.dismissed).length;

			// Group issues by severity
			const critical = filteredIssues.filter(i => i.severity === 'critical');
			const moderate = filteredIssues.filter(i => i.severity === 'moderate');
			const minor = filteredIssues.filter(i => i.severity === 'minor');

			let issuesHtml = '';
			if (filteredIssues.length === 0) {
				if (currentFilter === 'dismissed') {
					issuesHtml = '<p class="cleara11y-empty-state">No dismissed issues.</p>';
				} else if (currentFilter === 'active') {
					issuesHtml = '<p class="cleara11y-empty-state">No active issues! Great job!</p>';
				} else {
					issuesHtml = '<p class="cleara11y-empty-state">No accessibility issues found!</p>';
				}
			} else {
				const renderIssue = (issue) => {
					const isDismissed = !!issue.dismissed;
					const dismissedInfo = isDismissed ? `
						<div class="cleara11y-dismissed-info" style="margin-top: 10px; padding: 8px; background: #f0f0f1; border-left: 3px solid #646970; border-radius: 3px;">
							<div style="font-size: 0.85em; color: #646970;">
								<strong>Dismissed:</strong>
								${issue.dismissed_at ? ` ${new Date(issue.dismissed_at).toLocaleDateString()}` : ''}
								${issue.dismissal_comment ? `<br><em>"${this.escapeHtml(issue.dismissal_comment)}"</em>` : ''}
							</div>
						</div>
					` : '';

					return `
						<div class="cleara11y-issue-item ${isDismissed ? 'dismissed' : ''}" data-issue-id="${issue.id}" style="padding: 15px; border-bottom: 1px solid #eee; ${isDismissed ? 'opacity: 0.6;' : ''}">
							<div style="display: flex; justify-content: space-between; align-items: start; gap: 15px;">
								<div style="flex: 1;">
									<h4 style="margin: 0 0 10px 0; color: #1d2327; display: flex; align-items: center; gap: 8px;">
										${this.escapeHtml(issue.rule_id || issue.issue_id || 'Unknown Issue')}
										${isDismissed ? '<span class="dashicons dashicons-hidden" style="color: #646970;" title="Dismissed"></span>' : ''}
									</h4>
									<p style="margin: 0 0 10px 0; color: #646970;">${this.escapeHtml(issue.message || issue.description || '')}</p>
									<div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 10px;">
										<span class="cleara11y-issue-badge ${issue.severity}">${issue.severity}</span>
										${issue.wcag_criterion ? `<span style="color: #646970; font-size: 0.85em;">WCAG: ${this.escapeHtml(issue.wcag_criterion)}</span>` : ''}
									</div>
									${issue.selector ? `
										<div style="margin-bottom: 10px;">
											<span style="color: #646970; font-size: 0.85em;">Target: </span>
											<code style="background: #f0f0f1; padding: 2px 6px; border-radius: 3px; font-size: 0.85em;">${this.escapeHtml(issue.selector)}</code>
										</div>
									` : ''}
									${issue.html ? `
										<div style="margin-bottom: 10px;">
											<span style="color: #646970; font-size: 0.85em;">HTML: </span>
											<code style="background: #f0f0f1; padding: 2px 6px; border-radius: 3px; font-size: 0.85em; max-width: 500px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: inline-block;">${this.escapeHtml(issue.html)}</code>
										</div>
									` : ''}
									${issue.help_url ? `
										<a href="${issue.help_url}" target="_blank" style="display: inline-block; margin-top: 5px; font-size: 0.85em;">
											Learn more →
										</a>
									` : ''}
									${dismissedInfo}
								</div>
								<div class="cleara11y-issue-actions" style="min-width: 150px;">
									${isDismissed ? `
										<button type="button" class="button button-small cleara11y-undismiss-btn" data-issue-id="${issue.id}">
											<span class="dashicons dashicons-visibility"></span>
											Undo Dismiss
										</button>
									` : `
										<button type="button" class="button button-small cleara11y-dismiss-btn" data-issue-id="${issue.id}">
											<span class="dashicons dashicons-hidden"></span>
											Dismiss
										</button>
									`}
								</div>
							</div>
							${!isDismissed ? `
								<div class="cleara11y-dismiss-comment-form" style="display: none; margin-top: 10px; padding: 10px; background: #f6f7f7; border-radius: 4px;">
									<label style="display: block; margin-bottom: 5px; font-size: 0.9em;">Comment (optional):</label>
									<textarea class="cleara11y-dismiss-comment" rows="2" style="width: 100%; max-width: 400px;" placeholder="Why are you dismissing this issue?"></textarea>
									<div style="margin-top: 8px;">
										<button type="button" class="button button-small button-primary cleara11y-confirm-dismiss">
											Confirm Dismiss
										</button>
										<button type="button" class="button button-small cleara11y-cancel-dismiss">
											Cancel
										</button>
									</div>
								</div>
							` : ''}
						</div>
					`;
				};

				issuesHtml = '';
				if (critical.length > 0) {
					issuesHtml += `<h4 style="color: #d63638; margin: 20px 0 10px 0;">Critical Issues (${critical.length})</h4>` + critical.map(renderIssue).join('');
				}
				if (moderate.length > 0) {
					issuesHtml += `<h4 style="color: #f56e28; margin: 20px 0 10px 0;">Moderate Issues (${moderate.length})</h4>` + moderate.map(renderIssue).join('');
				}
				if (minor.length > 0) {
					issuesHtml += `<h4 style="color: #ffb900; margin: 20px 0 10px 0;">Minor Issues (${minor.length})</h4>` + minor.map(renderIssue).join('');
				}
			}

			modal.innerHTML = `
				<div class="cleara11y-modal" style="max-width: 900px;">
					<div class="cleara11y-modal-header">
						<h3 class="cleara11y-modal-title">Issues: ${this.escapeHtml(scanItem.post_title || 'Page')}</h3>
						<button type="button" class="cleara11y-modal-close">×</button>
					</div>
					<div class="cleara11y-modal-body" style="max-height: 70vh; overflow-y: auto;">
						<!-- Filter Tabs -->
						<div class="cleara11y-filter-tabs" style="display: flex; gap: 5px; margin-bottom: 15px; border-bottom: 1px solid #ddd; padding-bottom: 10px;">
							<button class="cleara11y-filter-tab ${currentFilter === 'active' ? 'active' : ''}" data-filter="active" style="border: none; background: ${currentFilter === 'active' ? '#2271b1' : 'transparent'}; color: ${currentFilter === 'active' ? '#fff' : '#2271b1'}; padding: 6px 12px; cursor: pointer; border-radius: 3px;">
								Active (${activeCount})
							</button>
							<button class="cleara11y-filter-tab ${currentFilter === 'dismissed' ? 'active' : ''}" data-filter="dismissed" style="border: none; background: ${currentFilter === 'dismissed' ? '#2271b1' : 'transparent'}; color: ${currentFilter === 'dismissed' ? '#fff' : '#2271b1'}; padding: 6px 12px; cursor: pointer; border-radius: 3px;">
								Dismissed (${dismissedCount})
							</button>
							<button class="cleara11y-filter-tab ${currentFilter === 'all' ? 'active' : ''}" data-filter="all" style="border: none; background: ${currentFilter === 'all' ? '#2271b1' : 'transparent'}; color: ${currentFilter === 'all' ? '#fff' : '#2271b1'}; padding: 6px 12px; cursor: pointer; border-radius: 3px;">
								All (${issues.length})
							</button>
						</div>
						${issuesHtml}
					</div>
					<div class="cleara11y-modal-footer">
						<button type="button" class="button cleara11y-modal-close-btn">Close</button>
					</div>
				</div>
			`;
		},

		/**
		 * Attach event handlers for issues modal
		 */
		attachIssuesModalHandlers(modal) {
			// Dismiss button handlers
			const dismissBtns = modal.querySelectorAll('.cleara11y-dismiss-btn');
			dismissBtns.forEach(btn => {
				btn.addEventListener('click', (e) => {
					const issueItem = e.target.closest('.cleara11y-issue-item');
					const commentForm = issueItem.querySelector('.cleara11y-dismiss-comment-form');
					commentForm.style.display = 'block';
					btn.style.display = 'none';
				});
			});

			// Cancel dismiss handlers
			const cancelBtns = modal.querySelectorAll('.cleara11y-cancel-dismiss');
			cancelBtns.forEach(btn => {
				btn.addEventListener('click', (e) => {
					const issueItem = e.target.closest('.cleara11y-issue-item');
					const commentForm = issueItem.querySelector('.cleara11y-dismiss-comment-form');
					const dismissBtn = issueItem.querySelector('.cleara11y-dismiss-btn');
					commentForm.style.display = 'none';
					dismissBtn.style.display = 'inline-block';
				});
			});

			// Confirm dismiss handlers
			const confirmBtns = modal.querySelectorAll('.cleara11y-confirm-dismiss');
			confirmBtns.forEach(btn => {
				btn.addEventListener('click', (e) => {
					const issueItem = e.target.closest('.cleara11y-issue-item');
					const issueId = issueItem.dataset.issueId;
					const comment = issueItem.querySelector('.cleara11y-dismiss-comment').value;
					this.dismissIssue(parseInt(issueId), comment, modal);
				});
			});

			// Undismiss button handlers
			const undismissBtns = modal.querySelectorAll('.cleara11y-undismiss-btn');
			undismissBtns.forEach(btn => {
				btn.addEventListener('click', (e) => {
					const issueId = e.target.dataset.issueId || e.target.closest('[data-issue-id]').dataset.issueId;
					this.undismissIssue(parseInt(issueId), modal);
				});
			});
		},

		/**
		 * Dismiss an issue
		 */
		async dismissIssue(issueId, comment, modal) {
			try {
				const response = await fetch(API_URL + 'issues/' + issueId + '/dismiss', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': NONCE
					},
					body: JSON.stringify({ comment: comment })
				});

				const data = await response.json();

				if (data.success || response.ok) {
					// Update the issues data
					const issueIndex = modal.issuesData.findIndex(i => i.id === issueId);
					if (issueIndex !== -1) {
						modal.issuesData[issueIndex].dismissed = true;
						modal.issuesData[issueIndex].dismissal_comment = comment;
						modal.issuesData[issueIndex].dismissed_at = new Date().toISOString();
					}

					// Re-render the modal content
					const scanItem = { post_title: modal.querySelector('.cleara11y-modal-title').textContent.replace('Issues: ', '') };
					this.renderIssuesModalContent(modal, scanItem);
					this.attachIssuesModalHandlers(modal);

					console.log('[ClearA11y Dashboard] Issue dismissed successfully');
				} else {
					throw new Error(data.message || 'Failed to dismiss issue');
				}
			} catch (error) {
				console.error('[ClearA11y Dashboard] Error dismissing issue:', error);
				alert('Error dismissing issue: ' + error.message);
			}
		},

		/**
		 * Undismiss an issue
		 */
		async undismissIssue(issueId, modal) {
			try {
				const response = await fetch(API_URL + 'issues/' + issueId + '/undismiss', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': NONCE
					}
				});

				const data = await response.json();

				if (data.success || response.ok) {
					// Update the issues data
					const issueIndex = modal.issuesData.findIndex(i => i.id === issueId);
					if (issueIndex !== -1) {
						modal.issuesData[issueIndex].dismissed = false;
						modal.issuesData[issueIndex].dismissal_comment = null;
						modal.issuesData[issueIndex].dismissed_at = null;
					}

					// Re-render the modal content
					const scanItem = { post_title: modal.querySelector('.cleara11y-modal-title').textContent.replace('Issues: ', '') };
					this.renderIssuesModalContent(modal, scanItem);
					this.attachIssuesModalHandlers(modal);

					console.log('[ClearA11y Dashboard] Issue undismissed successfully');
				} else {
					throw new Error(data.message || 'Failed to undismiss issue');
				}
			} catch (error) {
				console.error('[ClearA11y Dashboard] Error undismissing issue:', error);
				alert('Error undismissing issue: ' + error.message);
			}
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
