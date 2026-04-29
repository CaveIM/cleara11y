/**
 * ClearA11y Page Metabox JavaScript
 *
 * Handles the page edit metabox functionality including:
 * - Initiating scans for individual pages (adds to job queue)
 * - Polling for scan completion
 * - Displaying scan results
 * - Viewing page reports
 *
 * @package ClearA11y
 */

(function() {
	'use strict';

	const API_URL = cleara11yMetaboxData.apiUrl;
	const AJAX_URL = cleara11yMetaboxData.ajaxUrl;
	const NONCE = cleara11yMetaboxData.nonce;
	const AJAX_NONCE = cleara11yMetaboxData.ajaxNonce;
	const POST_ID = cleara11yMetaboxData.postId;
	const POST_TITLE = cleara11yMetaboxData.postTitle;
	const PLUGIN_URL = cleara11yMetaboxData.pluginUrl;

	/**
	 * Page Metabox Module
	 */
	const PageMetabox = {
		pollInterval: null,
		currentScanId: null,

		/**
		 * Initialize
		 */
		init() {
			this.setupEventListeners();
			this.checkForScanResult();
			this.setupKeyboardShortcuts();
		},

		/**
		 * Setup event listeners
		 */
		setupEventListeners() {
			// Scan button
			const scanBtn = document.getElementById('cleara11y-scan-page-btn');
			if (scanBtn) {
				scanBtn.addEventListener('click', () => this.initiateScan());
			}

			// View issues button
			const viewBtn = document.getElementById('cleara11y-view-issues-btn');
			if (viewBtn) {
				viewBtn.addEventListener('click', () => this.viewPageReport());
			}

			// Report link - let it navigate naturally (no JavaScript interception)
			// The link already has the correct href, so we don't need to prevent default
		},

		/**
		 * Setup keyboard shortcuts
		 */
		setupKeyboardShortcuts() {
			document.addEventListener('keydown', (e) => {
				// Alt + S to scan (only when not in text inputs)
				if (e.altKey && (e.key === 's' || e.key === 'S') && !this.isTextInput(document.activeElement)) {
					e.preventDefault();
					this.initiateScan();
				}

				// Alt + V to view report (only when not in text inputs)
				if (e.altKey && (e.key === 'v' || e.key === 'V') && !this.isTextInput(document.activeElement)) {
					e.preventDefault();
					this.viewPageReport();
				}
			});
		},

		/**
		 * Check if element is a text input
		 */
		isTextInput(element) {
			if (!element) return false;
			const tag = element.tagName.toLowerCase();
			const type = element.type ? element.type.toLowerCase() : '';
			return tag === 'textarea' ||
				(tag === 'input' && ['text', 'email', 'url', 'search', 'password'].includes(type)) ||
				element.isContentEditable;
		},

		/**
		 * Check for scan result from URL parameter
		 */
		checkForScanResult() {
			const urlParams = new URLSearchParams(window.location.search);
			const scanToken = urlParams.get('cleara11y_scan_result');

			if (scanToken) {
				// Clear the URL parameter
				const newUrl = window.location.pathname + window.location.search.replace(/[?&]cleara11y_scan_result=[^&]+/, '').replace(/^&/, '?');
				window.history.replaceState({}, '', newUrl);

				// Show results
				this.showScanComplete();
				this.refreshMetaboxData();
			}
		},

		/**
		 * Initiate scan for this page - adds to job queue
		 */
		async initiateScan() {
			const scanBtn = document.getElementById('cleara11y-scan-page-btn');
			const progressDiv = document.querySelector('.cleara11y-scan-progress');
			const completeDiv = document.querySelector('.cleara11y-scan-complete');

			// Show progress
			if (scanBtn) scanBtn.style.display = 'none';
			if (progressDiv) progressDiv.style.display = 'flex';
			if (completeDiv) completeDiv.style.display = 'none';

			try {
				const response = await fetch(AJAX_URL, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded',
					},
					body: new URLSearchParams({
						action: 'cleara11y_initiate_scan',
						nonce: AJAX_NONCE,
						post_id: POST_ID,
					}),
				});

				if (!response.ok) {
					throw new Error('Failed to initiate scan');
				}

				const result = await response.json();
				console.log('[ClearA11y Metabox] Scan response:', result);

				if (!result.success) {
					throw new Error(result.data?.message || result.message || 'Failed to initiate scan');
				}

				// Store scan ID and start polling
				this.currentScanId = result.data?.scan_id;

				if (!this.currentScanId) {
					throw new Error('No scan ID returned from server');
				}

				console.log('[ClearA11y Metabox] Starting poll for scan ID:', this.currentScanId);
				this.startPolling();

			} catch (error) {
				console.error('[ClearA11y Metabox] Error initiating scan:', error);

				// Show error and restore button
				this.restoreScanButton();
				this.showError(error.message);
			}
		},

		/**
		 * Start polling for scan completion
		 */
		startPolling() {
			let pollCount = 0;
			const maxPolls = 120; // 4 minutes max (2 second intervals)

			const progressDiv = document.querySelector('.cleara11y-scan-progress');
			if (progressDiv) {
				progressDiv.innerHTML = '<span class="spinner is-active"></span><span>Scan queued - waiting for processing...</span>';
			}

			this.pollInterval = setInterval(async () => {
				pollCount++;

				if (pollCount >= maxPolls) {
					this.stopPolling();
					this.restoreScanButton();
					this.showError('Scan timed out. Please try again.');
					return;
				}

				try {
					// Check scan status via AJAX (not REST - no permission issues)
					const response = await fetch(AJAX_URL, {
						method: 'POST',
						headers: {
							'Content-Type': 'application/x-www-form-urlencoded',
						},
						body: new URLSearchParams({
							action: 'cleara11y_get_page_stats',
							nonce: AJAX_NONCE,
							post_id: POST_ID,
							scan_id: this.currentScanId,
						}),
					});

					if (response.ok) {
						const result = await response.json();

						if (result.success && result.data.scan_status === 'completed') {
							this.stopPolling();
							this.showScanComplete();
							this.updateMetabox(result.data);
							return;
						}

						// Update progress message
						if (progressDiv && result.success) {
							if (result.data.scan_status === 'in_progress') {
								progressDiv.innerHTML = '<span class="spinner is-active"></span><span>Scanning in progress...</span>';
							} else if (result.data.scan_status === 'pending') {
								progressDiv.innerHTML = '<span class="spinner is-active"></span><span>Scan queued - waiting for processing...</span>';
							}
						}
					}
				} catch (error) {
					console.error('[ClearA11y Metabox] Error polling scan status:', error);
				}

			}, 2000);
		},

		/**
		 * Stop polling for scan completion
		 */
		stopPolling() {
			if (this.pollInterval) {
				clearInterval(this.pollInterval);
				this.pollInterval = null;
			}
		},

		/**
		 * Show scan complete indicator
		 */
		showScanComplete() {
			const progressDiv = document.querySelector('.cleara11y-scan-progress');
			const completeDiv = document.querySelector('.cleara11y-scan-complete');

			if (progressDiv) progressDiv.style.display = 'none';
			if (completeDiv) {
				completeDiv.style.display = 'flex';
				setTimeout(() => {
					this.restoreScanButton();
					if (completeDiv) completeDiv.style.display = 'none';
				}, 3000);
			}
		},

		/**
		 * Update metabox with new data
		 */
		updateMetabox(data) {
			const scoreCircle = document.querySelector('.cleara11y-score-circle');
			const scoreValue = document.querySelector('.cleara11y-score-value');
			const counts = document.querySelectorAll('.cleara11y-issue-count');

			// Update score
			if (scoreValue) scoreValue.textContent = data.score;

			// Update score color
			if (scoreCircle) {
				const color = this.getScoreColor(data.score);
				scoreValue.style.color = color;
				scoreCircle.style.background = `conic-gradient(${color} ${data.score}%, transparent 0)`;
			}

			// Update counts
			if (counts.length >= 3) {
				counts[0].textContent = data.counts.critical;
				counts[1].textContent = data.counts.moderate;
				counts[2].textContent = data.counts.minor;
			}

			// Update scan date
			const scanDateEl = document.querySelector('.cleara11y-scan-date');
			if (scanDateEl && data.scan_date) {
				scanDateEl.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>' +
					this.escapeHtml(data.scan_date);
			}

			// Show/hide view issues button
			const viewBtn = document.getElementById('cleara11y-view-issues-btn');
			if (viewBtn) {
				if (data.counts.total > 0) {
					viewBtn.style.display = 'flex';
				} else {
					viewBtn.style.display = 'none';
				}
			}
		},

		/**
		 * Refresh metabox data from server
		 */
		async refreshMetaboxData() {
			try {
				const response = await fetch(AJAX_URL, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded',
					},
					body: new URLSearchParams({
						action: 'cleara11y_get_page_stats',
						nonce: AJAX_NONCE,
						post_id: POST_ID,
					}),
				});

				if (response.ok) {
					const result = await response.json();
					if (result.success) {
						this.updateMetabox(result.data);
					}
				}
			} catch (error) {
				console.error('[ClearA11y Metabox] Error refreshing data:', error);
			}
		},

		/**
		 * Get score color based on value
		 */
		getScoreColor(score) {
			if (score >= 90) return '#00a32a';
			if (score >= 70) return '#ffb900';
			if (score >= 50) return '#f56e28';
			return '#dc2626';
		},

		/**
		 * View page report
		 */
		async viewPageReport() {
			// Debug: Log to console
			console.log('[ClearA11y Metabox] viewPageReport called');
			console.log('[ClearA11y Metabox] Report URL:', this.getReportUrl());

			// Navigate to the page report
			window.location.href = this.getReportUrl();
		},

		/**
		 * Get report URL
		 */
		getReportUrl() {
			return new URL(
				add_query_arg(
					{
						page: 'cleara11y-page-report',
						post_id: POST_ID,
					},
					'admin.php'
				),
				window.location.origin
			).href;
		},

		/**
		 * Show error message
		 */
		showError(message) {
			// Create error notice if it doesn't exist
			let errorDiv = document.querySelector('.cleara11y-error-notice');
			if (!errorDiv) {
				errorDiv = document.createElement('div');
				errorDiv.className = 'cleara11y-error-notice notice notice-error';
				errorDiv.style.margin = '16px 0';
				const metabox = document.getElementById('cleara11y-metabox');
				if (metabox) {
					metabox.insertBefore(errorDiv, metabox.firstChild);
				}
			}

			errorDiv.innerHTML = '<p>' + this.escapeHtml(message) + '</p>';

			// Auto-hide after 5 seconds
			setTimeout(() => {
				if (errorDiv && errorDiv.parentNode) {
					errorDiv.parentNode.removeChild(errorDiv);
				}
			}, 5000);
		},

		/**
		 * Restore scan button
		 */
		restoreScanButton() {
			const scanBtn = document.getElementById('cleara11y-scan-page-btn');
			const progressDiv = document.querySelector('.cleara11y-scan-progress');

			if (scanBtn) scanBtn.style.display = 'flex';
			if (progressDiv) progressDiv.style.display = 'none';
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

	// Helper function to add query arg (if not available)
	function add_query_arg(args, url) {
		const urlObj = new URL(url, window.location.origin);
		Object.keys(args).forEach(key => {
			urlObj.searchParams.set(key, args[key]);
		});
		return urlObj.search + urlObj.hash;
	}

	// Initialize on DOM ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', () => PageMetabox.init());
	} else {
		PageMetabox.init();
	}
})();
