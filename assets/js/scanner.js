/**
 * ClearA11y Client-Side Scanner
 *
 * Uses axe-core to scan the page for accessibility issues.
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

	const scanData = window.cleara11yScanData;
	const REST_URL = scanData.restUrl;
	const NONCE = scanData.nonce;

	/**
	 * Scanner Class
	 */
	const Scanner = {
		overlay: null,
		container: null,
		progressBar: null,
		statusText: null,
		detailsText: null,
		completeSection: null,
		errorSection: null,

		/**
		 * Initialize the scanner
		 */
		init() {
			this.createOverlay();
			this.showOverlay();
			this.runScan();
		},

		/**
		 * Create the scanner overlay UI
		 */
		createOverlay() {
			// Create overlay
			this.overlay = document.createElement('div');
			this.overlay.id = 'cleara11y-scanner-overlay';
			this.overlay.className = 'cleara11y-scanner-overlay';

			// Create container
			this.container = document.createElement('div');
			this.container.id = 'cleara11y-scanner-container';
			this.container.className = 'cleara11y-scanner-container';

			this.container.innerHTML = `
				<div class="cleara11y-scanner-logo"></div>
				<h2 class="cleara11y-scanner-title">Scanning for Accessibility Issues</h2>
				<p class="cleara11y-scanner-status">Please wait...</p>
				<div class="cleara11y-scanner-progress">
					<div class="cleara11y-scanner-progress-bar" id="cleara11y-progress-bar"></div>
				</div>
				<p class="cleara11y-scanner-details" id="cleara11y-scanner-details">Initializing scan...</p>

				<div class="cleara11y-scanner-error" id="cleara11y-scanner-error">
					<div class="cleara11y-scanner-error-title">Scan Failed</div>
					<div id="cleara11y-error-message"></div>
				</div>

				<div class="cleara11y-scanner-complete" id="cleara11y-scanner-complete">
					<div class="cleara11y-scanner-complete-icon"></div>
					<h3>Scan Complete!</h3>
					<div class="cleara11y-scanner-results" id="cleara11y-scanner-results"></div>
					<button type="button" class="button button-primary cleara11y-scanner-close-button" onclick="window.close()">
						Close Window
					</button>
				</div>
			`;

			this.overlay.appendChild(this.container);
			document.body.appendChild(this.overlay);

			// Cache references
			this.progressBar = document.getElementById('cleara11y-progress-bar');
			this.statusText = this.container.querySelector('.cleara11y-scanner-status');
			this.detailsText = document.getElementById('cleara11y-scanner-details');
			this.completeSection = document.getElementById('cleara11y-scanner-complete');
			this.errorSection = document.getElementById('cleara11y-scanner-error');
			this.resultsSection = document.getElementById('cleara11y-scanner-results');
			this.errorMessage = document.getElementById('cleara11y-error-message');
		},

		/**
		 * Show the overlay
		 */
		showOverlay() {
			this.overlay.classList.add('show');
			this.container.classList.add('show');
		},

		/**
		 * Update scan progress
		 */
		updateProgress(message, progress = null) {
			if (message) {
				this.detailsText.textContent = message;
			}
			if (progress !== null) {
				this.progressBar.style.width = progress + '%';
			}
		},

		/**
		 * Show error state
		 */
		showError(message) {
			this.progressBar.classList.remove('scanning');
			this.errorMessage.textContent = message;
			this.errorSection.classList.add('show');
			this.statusText.textContent = 'Scan Failed';
		},

		/**
		 * Show complete state
		 */
		showComplete(results) {
			this.progressBar.classList.remove('scanning');
			this.progressBar.style.width = '100%';

			this.statusText.textContent = 'Scan Complete!';
			this.detailsText.style.display = 'none';
			this.completeSection.classList.add('show');

			// Show results summary
			const total = results.total_issues || 0;
			const critical = results.critical || 0;
			const moderate = results.moderate || 0;
			const minor = results.minor || 0;

			let summary = '';
			if (total === 0) {
				summary = '<p style="text-align: center; color: #00a32a; font-weight: 500;">No accessibility issues found!</p>';
			} else {
				summary = `
					<div class="cleara11y-scanner-result-row">
						<span class="cleara11y-scanner-result-label">Total Issues</span>
						<span class="cleara11y-scanner-result-count" style="background: #646970;">${total}</span>
					</div>
				`;
				if (critical > 0) {
					summary += `
						<div class="cleara11y-scanner-result-row">
							<span class="cleara11y-scanner-result-label">Critical</span>
							<span class="cleara11y-scanner-result-count critical">${critical}</span>
						</div>
					`;
				}
				if (moderate > 0) {
					summary += `
						<div class="cleara11y-scanner-result-row">
							<span class="cleara11y-scanner-result-label">Moderate</span>
							<span class="cleara11y-scanner-result-count moderate">${moderate}</span>
						</div>
					`;
				}
				if (minor > 0) {
					summary += `
						<div class="cleara11y-scanner-result-row">
							<span class="cleara11y-scanner-result-label">Minor</span>
							<span class="cleara11y-scanner-result-count minor">${minor}</span>
						</div>
					`;
				}
			}

			this.resultsSection.innerHTML = summary;

			// Auto-close after 3 seconds if successful
			if (total === 0) {
				setTimeout(() => {
					if (window.opener) {
						window.close();
					}
				}, 3000);
			}
		},

		/**
		 * Run the accessibility scan
		 */
		async runScan() {
			// Check if axe-core is loaded
			if (typeof axe === 'undefined') {
				// Wait for axe-core to load
				await new Promise(resolve => {
					const checkAxe = setInterval(() => {
						if (typeof axe !== 'undefined') {
							clearInterval(checkAxe);
							resolve();
						}
					}, 100);
				});
			}

			// Wait for evidence extractor to be available
			if (typeof extractEvidenceFromAxeResults === 'undefined') {
				await new Promise((resolve, reject) => {
					const checkExtractor = setInterval(() => {
						if (typeof extractEvidenceFromAxeResults !== 'undefined') {
							clearInterval(checkExtractor);
							resolve();
						}
					}, 100);

					// Timeout after 10 seconds
					setTimeout(() => {
						clearInterval(checkExtractor);
						console.error('[ClearA11y] Timed out waiting for evidence-extractor.js to load');
						resolve(); // Continue anyway, evidence extraction will fail gracefully
					}, 10000);
				});
			}

			// Debug: Log function availability
			console.log('[ClearA11y] extractEvidenceFromAxeResults available:', typeof extractEvidenceFromAxeResults);
			if (typeof extractEvidenceFromAxeResults === 'undefined') {
				console.error('[ClearA11y] extractEvidenceFromAxeResults is not defined!');
			}

			// Start progress animation
			this.progressBar.classList.add('scanning');

			try {
				// Run axe-core scan
				this.updateProgress('Running accessibility checks...');

				const results = await axe.run(document, {
					runOnly: {
						type: 'tag',
						values: ['wcag2aa']
					}
				});

				// Debug: Check results
				console.log('[ClearA11y] Axe results:', results);
				console.log('[ClearA11y] Violations count:', results.violations?.length || 0);


					// Filter out ClearA11y plugin's own UI elements from results
					if (results.violations && results.violations.length > 0) {
						const originalCount = results.violations.length;
						results.violations = results.violations.filter(violation => {
							// Keep violation if at least one node doesn't target ClearA11y elements
							return violation.nodes.some(node => {
								if (!node.target || node.target.length === 0) return true;

								// Check each target path
								for (const targetPath of node.target) {
									if (!Array.isArray(targetPath)) continue;
									const selectorPath = targetPath.join(' ');

									// Check if this targets ClearA11y elements
									const isClearA11yElement = [
										selectorPath.includes('[data-cleara11y-plugin]'),
										selectorPath.includes('[data-cleara11y-highlighted]'),
										selectorPath.includes('.cleara11y-toggle'),
										selectorPath.includes('.cleara11y-panel'),
										selectorPath.includes('.cleara11y-tooltip'),
										selectorPath.includes('.cleara11y-highlight-issue'),
										selectorPath.includes('.cleara11y-highlight-panel'),
										selectorPath.includes('.cleara11y-issue-severity'),
										selectorPath.includes('[data-issue-index]')
									].some(check => check);

									if (isClearA11yElement) {
										return false; // This node targets ClearA11y, don't keep violation based on it
									}
								}
								return true; // This node is valid (doesn't target ClearA11y)
							});
						});

						if (results.violations.length !== originalCount) {
							console.log('[ClearA11y] Filtered out', originalCount - results.violations.length, 'ClearA11y plugin violations');
						}
					}

				// Extract evidence from results
				this.updateProgress('Extracting violation evidence...');

				console.log('[ClearA11y] extractEvidenceFromAxeResults function:', typeof extractEvidenceFromAxeResults);

				let evidence = [];
				try {
					evidence = await extractEvidenceFromAxeResults(results, {
						maxSnippetLen: 4000,
						maxTextLen: 400,
						ancestorDepth: 6,
						allowDataAttrs: true,
						dataAttrWhitelist: ["data-testid", "data-qa", "data-cy"],
					});

					// Debug: Check evidence
					console.log('[ClearA11y] Evidence extracted:', evidence);
					console.log('[ClearA11y] Evidence count:', Array.isArray(evidence) ? evidence.length : 'Not an array');

					if (!Array.isArray(evidence)) {
						console.error('[ClearA11y] Evidence is not an array:', typeof evidence, evidence);
						evidence = [];
					}
				} catch (e) {
					console.error('[ClearA11y] Evidence extraction failed:', e);
					evidence = [];
				}

				// Send results with evidence to server
				this.updateProgress('Saving results...', 90);

				const payload = {
					token: scanData.token,
					results: results,
					evidence: evidence
				};

				console.log('[ClearA11y] Sending payload:', {
					token: payload.token ? 'present' : 'missing',
					hasResults: !!payload.results,
					violationsCount: payload.results?.violations?.length || 0,
					evidenceType: Array.isArray(payload.evidence) ? 'array' : typeof payload.evidence,
					evidenceCount: Array.isArray(payload.evidence) ? payload.evidence.length : 'N/A'
				});

				const response = await fetch(REST_URL, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': NONCE
					},
					body: JSON.stringify(payload)
				});

				const data = await response.json();

				if (data.success) {
					this.showComplete(data.summary);
				} else {
					throw new Error(data.message || 'Failed to save results');
				}

			} catch (error) {
				console.error('Scan error:', error);
				this.showError(error.message || 'An unknown error occurred during the scan.');
			}
		}
	};

	// Start the scanner when DOM is ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', () => Scanner.init());
	} else {
		Scanner.init();
	}

})();
