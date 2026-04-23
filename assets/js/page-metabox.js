/**
 * ClearA11y Page Metabox JavaScript
 *
 * Handles the page edit metabox functionality including:
 * - Initiating scans for individual pages
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
		scanWindow: null,
		scanCheckInterval: null,

		/**
		 * Initialize
		 */
		init() {
			this.setupEventListeners();
			this.checkForScanResult();
			this.setupMessageListener();
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
		 * Setup message listener for scan window communication
		 */
		setupMessageListener() {
			window.addEventListener('message', (event) => {
				// Verify origin
				if (event.origin !== window.location.origin) {
					return;
				}

				if (event.data && event.data.type === 'cleara11y_scan_complete') {
					this.handleScanComplete(event.data);
				}
			});
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
		 * Initiate scan for this page
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

				if (!result.success) {
					throw new Error(result.data.message || 'Failed to initiate scan');
				}

				// Open scan window
				this.openScanWindow(result.data.scan_url, result.data.token);

			} catch (error) {
				console.error('[ClearA11y Metabox] Error initiating scan:', error);

				// Show error and restore button
				this.restoreScanButton();
				this.showError(error.message);
			}
		},

		/**
		 * Open scan window
		 */
		openScanWindow(scanUrl, token) {
			// Store token for checking results
			this.currentScanToken = token;

				// Calculate window position (center of screen)
				const width = 700;
				const height = 900;
				const left = Math.max(0, (window.screen.width - width) / 2);
				const top = Math.max(0, (window.screen.height - height) / 2);

				this.scanWindow = window.open(
					scanUrl,
					'cleara11y-scan-' + Date.now(),
					`width=${width},height=${height},left=${left},top=${top},resizable,scrollbars`
				);

				if (!this.scanWindow) {
					this.showError('Please allow popups for this site to scan the page.');
					this.restoreScanButton();
					return;
				}

				// Start checking for scan completion
				this.startScanCheck();
			},

		/**
		 * Start checking for scan completion
		 */
		startScanCheck() {
			let checkCount = 0;
			const maxChecks = 180; // 3 minutes max

			this.scanCheckInterval = setInterval(() => {
				checkCount++;

				if (checkCount >= maxChecks) {
					this.stopScanCheck();
					this.showError('Scan timed out. Please try again.');
					this.restoreScanButton();
					return;
				}

				// Check if window was closed
				if (this.scanWindow && this.scanWindow.closed) {
					this.stopScanCheck();
					// The scan might still be processing in background
					// Poll for results
					this.pollForResults();
					return;
				}

			}, 1000);
		},

		/**
		 * Stop checking for scan completion
		 */
		stopScanCheck() {
			if (this.scanCheckInterval) {
				clearInterval(this.scanCheckInterval);
				this.scanCheckInterval = null;
			}
		},

		/**
		 * Poll for scan results
		 */
		async pollForResults() {
			const progressDiv = document.querySelector('.cleara11y-scan-progress');

			if (progressDiv) {
				progressDiv.innerHTML = '<span class="spinner is-active"></span><span>Processing scan results...</span>';
			}

			let pollCount = 0;
			const maxPolls = 30;

			const poll = setInterval(async () => {
				pollCount++;

				if (pollCount >= maxPolls) {
					clearInterval(poll);
					this.restoreScanButton();
					this.showScanComplete(); // Show complete even if we couldn't verify
					return;
				}

				try {
					// Check for updated stats
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
						if (result.success && result.data.scan_status === 'completed') {
							clearInterval(poll);
							this.showScanComplete();
							this.updateMetabox(result.data);
							return;
						}
					}
				} catch (error) {
					console.error('[ClearA11y Metabox] Error polling for results:', error);
				}

			}, 2000);
		},

		/**
		 * Handle scan complete message
		 */
		handleScanComplete(data) {
			this.stopScanCheck();

			if (this.scanWindow && !this.scanWindow.closed) {
				this.scanWindow.close();
			}

			if (data.success) {
				// Redirect to verify token and show results
				const redirectUrl = window.location.href;
				const separator = redirectUrl.includes('?') ? '&' : '?';
				window.location.href = redirectUrl + separator + 'cleara11y_scan_result=' + (data.token || this.currentScanToken);
			} else {
				this.restoreScanButton();
				this.showError('Scan failed: ' + (data.error || 'Unknown error'));
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
