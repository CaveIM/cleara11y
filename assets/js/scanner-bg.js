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

	// Check if scanner utilities are loaded
	if (!window.ClearA11yScannerUtils) {
		console.error('ClearA11y: Scanner utilities not loaded');
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
						}, ClearA11yConstants.WAIT_FOR_AXE_TIMEOUT);
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
			ClearA11yScannerUtils.debug(' Starting scan for postId:', scanData.postId);

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
					}, ClearA11yConstants.WAIT_FOR_AXE_TIMEOUT);
				});
			}

			// Run axe-core scan
			ClearA11yScannerUtils.debug(' Running axe-core...');
			const results = await axe.run(document, {
				runOnly: {
					type: 'tag',
					values: ClearA11yScannerConfig.AXE_RUN_TAGS
				}
			});
			ClearA11yScannerUtils.debug(' Axe-core complete, found', results.violations?.length || 0, 'violations');

			// Filter out ClearA11y plugin elements from results
			if (results.violations && results.violations.length > 0) {
				const originalCount = results.violations.length;

				results.violations.forEach(violation => {
					const beforeNodeCount = violation.nodes.length;
					violation.nodes = violation.nodes.filter(node => {
						if (!node.target || node.target.length === 0) return true;
						for (const targetPath of node.target) {
							if (!Array.isArray(targetPath)) continue;
							const selectorPath = targetPath.join(' ');
							const isClearA11yElement = [
								selectorPath.includes('[data-cleara11y-plugin]'),
								selectorPath.includes('.cleara11y-panel'),
								selectorPath.includes('.cleara11y-backdrop'),
								selectorPath.includes('.cleara11y-summary'),
								selectorPath.includes('.cleara11y-stat'),
								selectorPath.includes('.cleara11y-filter'),
								selectorPath.includes('.cleara11y-panel-')
							].some(check => check);
							if (isClearA11yElement) {
								ClearA11yScannerUtils.debug(' Filtered ClearA11y element:', selectorPath);
								return false;
							}
						}
						return true;
					});
					if (violation.nodes.length !== beforeNodeCount) {
						ClearA11yScannerUtils.debug(' Filtered nodes from violation:', violation.id);
					}
				});

				results.violations = results.violations.filter(v => v.nodes.length > 0);
				ClearA11yScannerUtils.debug(' Filtered out', originalCount - results.violations.length, 'ClearA11y violations');
			}

			// Extract evidence from results
			ClearA11yScannerUtils.debug(' Extracting violation evidence...');
			ClearA11yScannerUtils.debug(' extractEvidenceFromAxeResults function:', typeof extractEvidenceFromAxeResults);

			let evidence = [];
			try {
				evidence = await extractEvidenceFromAxeResults(results, {
					maxSnippetLen: ClearA11yConstants.MAX_SNIPPET_LENGTH,
					maxTextLen: ClearA11yConstants.MAX_TEXT_LENGTH,
					ancestorDepth: ClearA11yConstants.ANCESTOR_DEPTH,
					allowDataAttrs: true,
					dataAttrWhitelist: ClearA11yConstants.DATA_ATTR_WHITELIST,
				});
				ClearA11yScannerUtils.debug(' Evidence extraction complete, count:', Array.isArray(evidence) ? evidence.length : 'Not an array');
			} catch (e) {
				ClearA11yScannerUtils.error(' Evidence extraction failed:', e);
				evidence = [];
			}

			// Send results to server
			this.postMessage({ type: 'saving_results', scanId: scanData.scanId, postId: scanData.postId });
			ClearA11yScannerUtils.debug(' Saving results to:', REST_URL);

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

			ClearA11yScannerUtils.debug(' Save response status:', response.status);

			const data = await response.json();
			ClearA11yScannerUtils.debug(' Save response data:', data);

			if (data.success) {
				// Notify parent of completion
				ClearA11yScannerUtils.debug(' Scan complete, notifying parent');
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
					}, ClearA11yScannerConfig.BG_SCANNER_CLOSE_DELAY);
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
				setTimeout(() => window.close(), ClearA11yScannerConfig.ERROR_POPUP_CLOSE_DELAY);
			}
		},

		/**
		 * Send message to parent window
		 */
		postMessage(data) {
			ClearA11yScannerUtils.debug(' postMessage:', data.type, 'scanId:', data.scanId);
			if (window.parent !== window) {
				// Running in iframe
				window.parent.postMessage({
					source: 'cleara11y_scanner',
					data: data
				}, '*');
				ClearA11yScannerUtils.debug(' Message sent to parent (iframe mode)');
			} else if (window.opener) {
				// Running in popup
				window.opener.postMessage({
					source: 'cleara11y_scanner',
					data: data
				}, '*');
				ClearA11yScannerUtils.debug(' Message sent to opener (popup mode)');
			} else {
				console.warn('[ClearA11y BG] No parent or opener found!');
			}
		}
	};

	// Start the scanner immediately for background mode
	ClearA11yScannerUtils.debug(' Scanner loaded, readyState:', document.readyState);
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', () => {
			ClearA11yScannerUtils.debug(' DOMContentLoaded, initializing...');
			BackgroundScanner.init();
		});
	} else {
		ClearA11yScannerUtils.debug(' DOM ready, initializing...');
		BackgroundScanner.init();
	}

	// Listen for messages from parent
	window.addEventListener('message', (event) => {
		ClearA11yScannerUtils.debug(' Received message:', event.data);
		if (event.data && event.data.source === 'cleara11y_dashboard') {
			// Handle messages from dashboard if needed
			if (event.data.action === 'ping') {
				BackgroundScanner.postMessage({ type: 'pong' });
			}
		}
	});

})();
