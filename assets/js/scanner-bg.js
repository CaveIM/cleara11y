/**
 * ClearA11y Background Scanner
 *
 * Uses axe-core to scan the page for accessibility issues in background mode.
 * Runs in an iframe and auto-closes after completion.
 *
 * @package ClearA11y
 */

(function() {
	'use strict';

	// Check if scan data is available
	if (!window.cleara11yScanData) {
		console.error('ClearA11y: Scan data not available');
		return;
	}

	// Check if background mode
	const urlParams = new URLSearchParams(window.location.search);
	const isBackgroundMode = urlParams.get('cleara11y_bg') === '1';

	const scanData = window.cleara11yScanData;
	const REST_URL = scanData.restUrl;
	const NONCE = scanData.nonce;

	/**
	 * Background Scanner Class
	 */
	const BackgroundScanner = {
		/**
		 * Initialize the scanner
		 */
		async init() {
			// Notify parent that scanning has started
			this.postMessage({ type: 'scan_started', scanId: scanData.scanId, postId: scanData.postId });

			try {
				// Wait for axe-core to load
				if (typeof axe === 'undefined') {
					await new Promise(resolve => {
						const checkAxe = setInterval(() => {
							if (typeof axe !== 'undefined') {
								clearInterval(checkAxe);
								resolve();
							}
						}, 100);

						// Timeout after 10 seconds
						setTimeout(() => {
							clearInterval(checkAxe);
							this.handleError('axe-core failed to load');
						}, 10000);
					});
				}

				// Run the scan
				await this.runScan();

			} catch (error) {
				console.error('Background scan error:', error);
				this.handleError(error.message || 'Unknown error occurred');
			}
		},

		/**
		 * Run the accessibility scan
		 */
		async runScan() {
			console.log('[ClearA11y BG] Starting scan for postId:', scanData.postId);

			// Notify parent that axe scan is starting
			this.postMessage({ type: 'scan_running', scanId: scanData.scanId, postId: scanData.postId });

			// Wait for evidence extractor to be available
			if (typeof extractEvidenceFromAxeResults === 'undefined') {
				await new Promise(resolve => {
					const checkExtractor = setInterval(() => {
						if (typeof extractEvidenceFromAxeResults !== 'undefined') {
							clearInterval(checkExtractor);
							resolve();
						}
					}, 100);

					// Timeout after 10 seconds
					setTimeout(() => {
						clearInterval(checkExtractor);
						this.handleError('Evidence extractor failed to load');
					}, 10000);
				});
			}

			// Run axe-core scan
			console.log('[ClearA11y BG] Running axe-core...');
			const results = await axe.run(document, {
				runOnly: {
					type: 'tag',
					values: ['wcag2aa']
				}
			});
			console.log('[ClearA11y BG] Axe-core complete, found', results.violations?.length || 0, 'violations');

			// Extract evidence from results
			console.log('[ClearA11y BG] Extracting violation evidence...');
			console.log('[ClearA11y BG] extractEvidenceFromAxeResults function:', typeof extractEvidenceFromAxeResults);

			let evidence = [];
			try {
				evidence = await extractEvidenceFromAxeResults(results, {
					maxSnippetLen: 4000,
					maxTextLen: 400,
					ancestorDepth: 6,
					allowDataAttrs: true,
					dataAttrWhitelist: ["data-testid", "data-qa", "data-cy"],
				});
				console.log('[ClearA11y BG] Evidence extraction complete, count:', Array.isArray(evidence) ? evidence.length : 'Not an array');
			} catch (e) {
				console.error('[ClearA11y BG] Evidence extraction failed:', e);
				evidence = [];
			}

			// Send results to server
			this.postMessage({ type: 'saving_results', scanId: scanData.scanId, postId: scanData.postId });
			console.log('[ClearA11y BG] Saving results to:', REST_URL);

			const response = await fetch(REST_URL, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': NONCE
				},
				body: JSON.stringify({
					token: scanData.token,
					results: results,
					evidence: evidence
				})
			});

			console.log('[ClearA11y BG] Save response status:', response.status);

			const data = await response.json();
			console.log('[ClearA11y BG] Save response data:', data);

			if (data.success) {
				// Notify parent of completion
				console.log('[ClearA11y BG] Scan complete, notifying parent');
				this.postMessage({
					type: 'scan_complete',
					scanId: scanData.scanId,
					postId: scanData.postId,
					summary: data.summary
				});

				// In background mode, close the window after a short delay
				if (isBackgroundMode) {
					setTimeout(() => {
						// Try to close - will work for popups, won't affect iframes
						if (window.opener) {
							window.close();
						}
					}, 500);
				}
			} else {
				throw new Error(data.message || 'Failed to save results');
			}
		},

		/**
		 * Handle scan error
		 */
		handleError(message) {
			this.postMessage({
				type: 'scan_error',
				scanId: scanData.scanId,
				postId: scanData.postId,
				error: message
			});

			if (isBackgroundMode && window.opener) {
				// Close error popup after showing error
				setTimeout(() => window.close(), 2000);
			}
		},

		/**
		 * Send message to parent window
		 */
		postMessage(data) {
			console.log('[ClearA11y BG] postMessage:', data.type, 'scanId:', data.scanId);
			if (window.parent !== window) {
				// Running in iframe
				window.parent.postMessage({
					source: 'cleara11y_scanner',
					data: data
				}, '*');
				console.log('[ClearA11y BG] Message sent to parent (iframe mode)');
			} else if (window.opener) {
				// Running in popup
				window.opener.postMessage({
					source: 'cleara11y_scanner',
					data: data
				}, '*');
				console.log('[ClearA11y BG] Message sent to opener (popup mode)');
			} else {
				console.warn('[ClearA11y BG] No parent or opener found!');
			}
		}
	};

	// Start the scanner immediately for background mode
	console.log('[ClearA11y BG] Scanner loaded, readyState:', document.readyState);
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', () => {
			console.log('[ClearA11y BG] DOMContentLoaded, initializing...');
			BackgroundScanner.init();
		});
	} else {
		console.log('[ClearA11y BG] DOM ready, initializing...');
		BackgroundScanner.init();
	}

	// Listen for messages from parent
	window.addEventListener('message', (event) => {
		console.log('[ClearA11y BG] Received message:', event.data);
		if (event.data && event.data.source === 'cleara11y_dashboard') {
			// Handle messages from dashboard if needed
			if (event.data.action === 'ping') {
				BackgroundScanner.postMessage({ type: 'pong' });
			}
		}
	});

})();
