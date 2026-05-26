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

	// Check if scanner utilities are loaded
	if (!window.ClearA11yScannerUtils) {
		console.error('ClearA11y: Scanner utilities not loaded');
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
		closeButton: null,

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
					<button type="button" class="button button-primary cleara11y-scanner-close-button">
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
			this.closeButton = this.container.querySelector('.cleara11y-scanner-close-button');

			// Add event listener for close button (no inline onclick)
			this.closeButton.addEventListener('click', () => {
				if (window.opener) {
					window.close();
				}
			});
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
				}, ClearA11yConstants.AUTO_CLOSE_DELAY);
			}
		},

		/**
		 * Run the accessibility scan
		 */
		async runScan() {
			// Wait for axe-core using utility function
			if (typeof axe === 'undefined') {
				try {
					await ClearA11yScannerUtils.waitForGlobal('axe', ClearA11yConstants.WAIT_FOR_AXE_TIMEOUT);
				} catch (e) {
					this.showError('axe-core failed to load');
					return;
				}
			}

			// Wait for evidence extractor using utility function
			if (typeof extractEvidenceFromAxeResults === 'undefined') {
				try {
					await ClearA11yScannerUtils.waitForGlobal('extractEvidenceFromAxeResults', ClearA11yConstants.WAIT_FOR_EXTRACTOR_TIMEOUT);
				} catch (e) {
					ClearA11yScannerUtils.warn('Evidence extractor not available, continuing without it');
				}
			}

			// Start progress animation
			this.progressBar.classList.add('scanning');

			try {
				// Run axe-core scan
				this.updateProgress('Running accessibility checks...');

				const results = await axe.run(document, {
					runOnly: {
						type: 'tag',
						values: ClearA11yScannerConfig.AXE_RUN_TAGS
					}
				});

				ClearA11yScannerUtils.debug('Scan complete, violations:', results.violations?.length || 0);

				// Filter out ClearA11y plugin elements from results
				const filteredResults = ClearA11yScannerUtils.filterPluginElements(results, true);

				// Extract evidence from results
				this.updateProgress('Extracting violation evidence...');

				let evidence = [];
				if (typeof extractEvidenceFromAxeResults !== 'undefined') {
					try {
						evidence = await extractEvidenceFromAxeResults(filteredResults, {
							maxSnippetLen: ClearA11yConstants.MAX_SNIPPET_LENGTH,
							maxTextLen: ClearA11yConstants.MAX_TEXT_LENGTH,
							ancestorDepth: ClearA11yConstants.ANCESTOR_DEPTH,
							allowDataAttrs: true,
							dataAttrWhitelist: ClearA11yConstants.DATA_ATTR_WHITELIST,
						});
						ClearA11yScannerUtils.debug('Evidence extracted:', evidence.length || 0, 'items');
					} catch (e) {
						ClearA11yScannerUtils.error( Evidence extraction failed:', e);
					}
				}

				// Send results with evidence to server
				this.updateProgress('Saving results...', 90);

				const payload = {
					token: scanData.token,
					results: filteredResults,
					evidence: evidence
				};

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
				ClearA11yScannerUtils.error( Scan error:', error);
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
