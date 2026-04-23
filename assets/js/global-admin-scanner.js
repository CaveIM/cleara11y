/**
 * Global Admin Scanner for ClearA11y
 *
 * This script runs on ALL wp-admin pages and automatically
 * detects and resumes any active accessibility scans.
 *
 * Architecture:
 * - Hidden iframe worker pool for parallel scanning
 * - AJAX communication for state persistence
 * - Auto-resume on page navigation
 * - postMessage for iframe communication
 *
 * @package ClearA11y
 */

(function() {
	'use strict';

	// Prevent double initialization
	if (window.__CLEARA11Y_GLOBAL_SCANNER__) {
		console.warn('[ClearA11y] Global scanner already initialized, skipping');
		return;
	}
	window.__CLEARA11Y_GLOBAL_SCANNER__ = true;

	// Log immediately that script is loading
	console.log('%c[ClearA11y Global Scanner] Script loaded v1.0.9.2 FIX', 'color: #00a0d2; font-weight: bold; font-size: 14px;');
	console.log('[ClearA11y] Build timestamp:', '2024-03-26-13-00');

	// Configuration
	const CONFIG = {
		// Maximum parallel iframes
		maxWorkers: 1, // Reduced to 1 for debugging
		// Lease duration in seconds - should be slightly longer than scan timeout
		leaseSeconds: 20, // Reduced from 180 to match scan timeout (45s + buffer)
		// Heartbeat interval in ms
		heartbeatInterval: 8000, // Reduced from 45000 to send heartbeats more frequently
		// Delay between scans in ms
		scanDelay: 1000,
		// Timeout for single scan in ms - increased for complex pages
		scanTimeout: 90000, // Increased to 90 seconds from 45 seconds
		// Poll interval for checking scan status
		statusCheckInterval: 2000,
	};

	// Worker ID (persisted in sessionStorage)
	let workerId = sessionStorage.getItem('cleara11y_worker_id');
	if (!workerId) {
		workerId = 'worker_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
		sessionStorage.setItem('cleara11y_worker_id', workerId);
	}

	// State
	let isRunning = false;
	let workers = [];
	let scanState = null;
	let statusCheckTimer = null;
	let heartbeatTimers = new Map();

	/**
	 * Logger for debugging
	 */
	const Logger = {
		enabled: true,
		log: function(...args) {
			if (this.enabled) {
				console.log('%c[ClearA11y Scanner]', 'color: #00a0d2; font-weight: bold;', ...args);
			}
		},
		error: function(...args) {
			if (this.enabled) {
				console.error('%c[ClearA11y Scanner]', 'color: #f00; font-weight: bold;', ...args);
			}
		},
		warn: function(...args) {
			if (this.enabled) {
				console.warn('%c[ClearA11y Scanner]', 'color: #ff9000; font-weight: bold;', ...args);
			}
		},
		group: function(title) {
			if (this.enabled) {
				console.group('%c[ClearA11y Scanner] ' + title, 'color: #00a0d2; font-weight: bold;');
			}
		},
		groupEnd: function() {
			if (this.enabled) {
				console.groupEnd();
			}
		}
	};

	/**
	 * IframeWorker class - manages a single scanning iframe
	 */
	class IframeWorker {
		constructor(id) {
			this.id = id;
			this.iframe = null;
			this.currentJob = null;
			this.busy = false;
			this.createIframe();
		}

		createIframe() {
			this.iframe = document.createElement('iframe');
			this.iframe.setAttribute('data-cleara11y-worker', this.id);
			this.iframe.style.cssText = `
				position: fixed;
				bottom: 0;
				right: 0;
				width: 1px;
				height: 1px;
				visibility: hidden;
				opacity: 0;
				pointer-events: none;
				border: none;
				z-index: -1;
			`;
			// Add sandbox attribute to allow scripts and same-origin access
			// This helps with some CSP restrictions but won't override X-Frame-Options
			this.iframe.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-forms allow-popups');
			this.iframe.setAttribute('aria-hidden', 'true');
			this.iframe.setAttribute('tabindex', '-1');
			this.iframe.title = 'Accessibility scanning iframe';
			document.body.appendChild(this.iframe);
		}

		destroyIframe() {
			if (this.iframe && this.iframe.parentNode) {
				this.iframe.parentNode.removeChild(this.iframe);
			}
			this.iframe = null;
		}

		async scanUrl(url, jobId, leaseToken) {
			if (this.busy) {
				throw new Error('Worker already busy');
			}

			this.busy = true;
			this.currentJob = { jobId, url, leaseToken };

			try {
				// Destroy and recreate iframe before each scan to avoid pollution
				this.destroyIframe();
				this.createIframe();

				// Return the scan result
				return await this.loadUrlAndScan(url);
			} finally {
				this.busy = false;
				this.currentJob = null;
				// Don't destroy iframe here - will be recreated on next scan
			}
		}

		async loadUrlAndScan(url) {
			return new Promise((resolve, reject) => {
				// Overall scan timeout
				const timeoutId = setTimeout(() => {
					cleanup();
					reject(new Error(`Scan timeout (${CONFIG.scanTimeout / 1000}s) - axe may have failed to initialize`));
				}, CONFIG.scanTimeout);

				// Page load timeout - detect when page doesn't load at all (X-Frame-Options, CSP, etc.)
				const loadTimeoutId = setTimeout(() => {
					cleanup();
					reject(new Error(`Page failed to load - may be blocked by X-Frame-Options or CSP headers`));
				}, 15000); // 15 seconds to load the page

				let messageHandler = null;
				let loadHandler = null;

				const cleanup = () => {
					clearTimeout(timeoutId);
					clearTimeout(loadTimeoutId);
					if (messageHandler) {
						window.removeEventListener('message', messageHandler);
					}
					if (loadHandler) {
						this.iframe.removeEventListener('load', loadHandler);
					}
				};

				// Wait for iframe to load, then inject scanner
				loadHandler = () => {
					// Page loaded! Clear the load timeout
					clearTimeout(loadTimeoutId);
					this.iframe.removeEventListener('load', loadHandler);
					// console.log('  [Job ' + this.currentJob?.jobId + '] Page loaded, waiting for DOM to settle...');

					// Wait longer for page to fully settle (scripts, styles, etc.)
					// This is especially important for WordPress pages with lots of JS
					setTimeout(() => {
						// console.log('  [Job ' + this.currentJob?.jobId + '] DOM settled, injecting scanner...');
						this.injectScanner(this.iframe);
					}, 3000); // Increased from 1500ms to 3000ms
				};

				this.iframe.addEventListener('load', loadHandler);

				// Listen for scan results via postMessage
				messageHandler = (event) => {
					// Verify origin (same-origin)
					if (event.origin !== window.location.origin) {
						return;
					}

					if (event.data && event.data.type === 'CLEARA11Y_SCAN_COMPLETE') {
						cleanup();
						resolve(event.data.payload);
					} else if (event.data && event.data.type === 'CLEARA11Y_SCAN_ERROR') {
						cleanup();
						reject(new Error(event.data.error || 'Scan failed'));
					} else if (event.data && event.data.type === 'CLEARA11Y_SCAN_DEBUG') {
						console.log('  [Job ' + this.currentJob?.jobId + '] Debug:', event.data.message);
					}
				};

				window.addEventListener('message', messageHandler);

				// Load the URL
				// No need to reset iframe - it's already fresh from recreation
				// console.log('  [Job ' + (this.currentJob?.jobId || '?') + '] Loading URL:', url);
				this.iframe.src = url;
			});
		}

		injectScanner(iframe) {
			try {
				const iframeWindow = iframe.contentWindow;
				const iframeDoc = iframeWindow.document;

				// console.log('  [Job ' + this.currentJob?.jobId + '] Injecting axe-core scanner...');

				// Check if axe-core is already loaded
				if (iframeWindow.axe) {
					// console.log('  [Job ' + this.currentJob?.jobId + '] axe-core already loaded');
					this.runScan(iframeWindow);
					return;
				}

				// Inject axe-core script
				const script = iframeDoc.createElement('script');
				script.src = cleara11yData.pluginUrl + 'assets/js/axe.min.js';
				script.async = false; // Ensure script loads in order
				script.onload = () => {
					// console.log('  [Job ' + this.currentJob?.jobId + '] axe-core script loaded');
					// Verify axe-core loaded properly
					if (typeof iframeWindow.axe === 'undefined') {
						console.error('  [Job ' + this.currentJob?.jobId + '] axe-core loaded but not available');
						this.postMessageError('axe-core loaded but not available in window');
						return;
					}
					console.log('  [Job ' + this.currentJob?.jobId + '] axe-core version:', iframeWindow.axe.version);
					// Wait a bit for axe to fully initialize
					setTimeout(() => {
						this.runScan(iframeWindow);
					}, 200);
				};
				script.onerror = (error) => {
					console.error('  [Job ' + this.currentJob?.jobId + '] Failed to load axe-core script:', error);
					this.postMessageError('Failed to load axe-core script');
				};
				iframeDoc.head.appendChild(script);

			} catch (error) {
				Logger.error('Failed to inject scanner:', error);
				this.postMessageError(error.message);
			}
		}

		postMessageError(error) {
			window.postMessage({
				type: 'CLEARA11Y_SCAN_ERROR',
				error: error
			}, '*');
		}

		runScan(iframeWindow) {
			try {
				// console.log('  [Job ' + this.currentJob?.jobId + '] Running accessibility scan...');

				// Verify axe-core is available
				if (!iframeWindow.axe) {
					console.error('  [Job ' + this.currentJob?.jobId + '] axe-core not available!');
					this.postMessageError('axe-core not available');
					return;
				}

				// Verify axe.run is a function
				if (typeof iframeWindow.axe.run !== 'function') {
					console.error('  [Job ' + this.currentJob?.jobId + '] axe.run is not a function!');
					console.log('  [Job ' + this.currentJob?.jobId + '] axe object:', iframeWindow.axe);
					this.postMessageError('axe.run is not a function');
					return;
				}

				// Inject a comprehensive scan script into the iframe
				const scanScript = iframeWindow.document.createElement('script');
				scanScript.id = 'cleara11y-scan-script';
				scanScript.textContent = `
					(function() {
						'use strict';
						console.log('[ClearA11y iframe] Scan script executing...');
						console.log('[ClearA11y iframe] axe available:', typeof window.axe !== 'undefined');
						console.log('[ClearA11y iframe] axe version:', window.axe ? window.axe.version : 'N/A');
						console.log('[ClearA11y iframe] axe.run type:', typeof window.axe?.run);

						// Function to safely post message to parent
						const postResult = (type, data) => {
							try {
								window.parent.postMessage({
									type: type,
									...data
								}, '*');
							} catch (e) {
								console.error('[ClearA11y iframe] Failed to post message:', e);
							}
						};

						// Check if axe is ready
						if (typeof window.axe === 'undefined' || typeof window.axe.run !== 'function') {
							console.error('[ClearA11y iframe] axe-core not properly loaded');
							postResult('CLEARA11Y_SCAN_ERROR', {
								error: 'axe-core not properly loaded'
							});
							return;
						}

						// Remove WP admin bar if present (affects layout)
						try {
							const adminBar = document.getElementById('wpadminbar');
							if (adminBar) {
								adminBar.remove();
								console.log('[ClearA11y iframe] Removed WP admin bar');
							}
						} catch (e) {
							console.warn('[ClearA11y iframe] Could not remove admin bar:', e);
						}

						// Configure axe-core with proper options (v4.10.2)
						// Note: reporter option is NOT used in axe-core v4 - it's auto-detected
						const axeOptions = {
							// Run only specific rules to speed up scanning
							runOnly: {
								type: 'tag',
								values: ['wcag2a', 'wcag2aa', 'wcag21aa']
							},
							// Set result limits
							resultLimit: 50000,
							// Specify what result types to return
							resultTypes: ['violations', 'passes', 'incomplete', 'inapplicable']
						};

						console.log('[ClearA11y iframe] Starting axe.run with options:', axeOptions);

						// Filter out ClearA11y plugin's own UI elements from results
						const filterClearA11yElements = (results) => {
							if (!results.violations) return results;

							const originalCount = results.violations.length;
							results.violations = results.violations.filter(violation => {
								// Check all nodes in this violation
								return violation.nodes.some(node => {
									// node.target is an array of target paths, each path is an array of selector strings
									if (!node.target || node.target.length === 0) return true;

									// Check each target path
									for (const targetPath of node.target) {
										// targetPath is an array of selector strings (e.g., ["html", "body", ".cleara11y-toggle"])
										if (!Array.isArray(targetPath)) continue;

										// Join the path to check for our patterns
										const selectorPath = targetPath.join(' ');

										// Filter out violations targeting ClearA11y elements
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
											return false; // This node targets a ClearA11y element, filter it out
										}
									}

									return true; // This node doesn't target ClearA11y elements
								});
							});

							if (results.violations.length !== originalCount) {
								console.log('[ClearA11y iframe] Filtered out', originalCount - results.violations.length, 'ClearA11y plugin violations');
							}

							return results;
						};

						// Run the scan with proper error handling
						try {
							const runPromise = window.axe.run(document, axeOptions);

							// Handle both promise and callback API
							if (runPromise && typeof runPromise.then === 'function') {
								// Promise API
								runPromise.then(results => {
									console.log('[ClearA11y iframe] Scan completed via Promise');
									console.log('[ClearA11y iframe] Violations:', results.violations.length);
									const filteredResults = filterClearA11yElements(results);
									postResult('CLEARA11Y_SCAN_COMPLETE', {
										payload: filteredResults
									});
								}).catch(err => {
									console.error('[ClearA11y iframe] Scan failed via Promise:', err);
									postResult('CLEARA11Y_SCAN_ERROR', {
										error: 'axe.run Promise error: ' + err.message
									});
								});
							} else {
								// Callback API (shouldn't happen with modern axe-core)
								console.warn('[ClearA11y iframe] axe.run did not return a promise, using callback');
								window.axe.run(document, axeOptions, (error, results) => {
									if (error) {
										console.error('[ClearA11y iframe] Scan failed via callback:', error);
										postResult('CLEARA11Y_SCAN_ERROR', {
											error: 'axe.run callback error: ' + error.message
										});
										return;
									}

									if (!results) {
										console.error('[ClearA11y iframe] axe.run returned null results');
										postResult('CLEARA11Y_SCAN_ERROR', {
											error: 'axe.run returned null results'
										});
										return;
									}

									console.log('[ClearA11y iframe] Scan completed via callback');
									console.log('[ClearA11y iframe] Violations:', results.violations.length);
									const filteredResults = filterClearA11yElements(results);
									postResult('CLEARA11Y_SCAN_COMPLETE', {
										payload: filteredResults
									});
								});
							}
						} catch (e) {
							console.error('[ClearA11y iframe] Exception during axe.run:', e);
							console.error('[ClearA11y iframe] Exception stack:', e.stack);
							postResult('CLEARA11Y_SCAN_ERROR', {
								error: 'Exception: ' + e.message + ' | Stack: ' + (e.stack || 'none')
							});
						}
					})();
				`;

				// Append and execute
				iframeWindow.document.head.appendChild(scanScript);
				console.log('  [Job ' + this.currentJob?.jobId + '] Scan script injected into iframe');

			} catch (error) {
				Logger.error('Failed to run scan:', error);
				console.error('  [Job ' + this.currentJob?.jobId + '] runScan error:', error);
				this.postMessageError('runScan exception: ' + error.message);
			}
		}
	}

	/**
	 * ScanManager - manages the scanning process
	 */
	const ScanManager = {
		async getActiveScan() {
			try {
				const url = cleara11yData.apiUrl + 'scan/active';
				console.log('  📡 Fetching:', url);

				const response = await fetch(url, {
					method: 'GET',
					headers: {
						'X-WP-Nonce': cleara11yData.nonce
					}
				});

				console.log('  📡 Response status:', response.status, response.statusText);

				if (!response.ok) {
					console.error('  ❌ Response not OK:', response.status, response.statusText);
					// Try to get error body
					const errorText = await response.text();
					console.error('  ❌ Error body:', errorText);
					return null;
				}

				const data = await response.json();
				console.log('  📦 Response data:', JSON.stringify(data, null, 2));

				return data.active ? data : null;
			} catch (error) {
				console.error('  ❌ Failed to get active scan:', error);
				return null;
			}
		},

		async leaseJobs(limit) {
			try {
				console.log('  📡 Leasing jobs request:', { workerId, limit, leaseSeconds: CONFIG.leaseSeconds });

				const response = await fetch(cleara11yData.apiUrl + 'jobs/lease', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': cleara11yData.nonce
					},
					body: JSON.stringify({
						workerId: workerId,
						limit: limit,
						leaseSeconds: CONFIG.leaseSeconds
					})
				});

				console.log('  📡 Lease response status:', response.status);

				if (!response.ok) {
					console.error('  ❌ Lease request failed:', response.status, await response.text());
					return [];
				}

				const data = await response.json();
				console.log('  📦 Lease response:', JSON.stringify(data, null, 2));
				return data.jobs || [];
			} catch (error) {
				console.error('  ❌ Failed to lease jobs:', error);
				return [];
			}
		},

		async completeJob(jobId, leaseToken, resultJson, error = null) {
			try {
				const body = {
					jobId: jobId,
					leaseToken: leaseToken,
					status: error ? 'failed' : 'done'
				};

				if (resultJson) {
					body.resultJson = resultJson;
				}
				if (error) {
					body.error = error;
				}

				const response = await fetch(cleara11yData.apiUrl + 'jobs/complete', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': cleara11yData.nonce
					},
					body: JSON.stringify(body)
				});

				if (!response.ok) {
					Logger.error('Failed to complete job:', await response.text());
					return false;
				}

				const data = await response.json();
				return data.ok || false;
			} catch (error) {
				Logger.error('Failed to complete job:', error);
				return false;
			}
		},

		async heartbeat(jobId, leaseToken) {
			try {
				const response = await fetch(cleara11yData.apiUrl + 'jobs/heartbeat', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': cleara11yData.nonce
					},
					body: JSON.stringify({
						jobId: jobId,
						leaseToken: leaseToken,
						leaseSeconds: CONFIG.leaseSeconds
					})
				});

				if (!response.ok) {
					return false;
				}

				const data = await response.json();
				return data.ok || false;
			} catch (error) {
				Logger.error('Failed to send heartbeat:', error);
				return false;
			}
		},

		async getScanStats(scanId) {
			try {
				const response = await fetch(cleara11yData.apiUrl + 'scans/' + scanId + '/stats', {
					method: 'GET',
					headers: {
						'X-WP-Nonce': cleara11yData.nonce
					}
				});

				if (!response.ok) {
					return null;
				}

				return await response.json();
			} catch (error) {
				Logger.error('Failed to get scan stats:', error);
				return null;
			}
		},

		async refreshAndDispatchState() {
			try {
				const activeScan = await this.getActiveScan();
				if (activeScan && activeScan.active) {
					// Dispatch event to update toolbar indicator
					window.dispatchEvent(new CustomEvent('cleara11y:scan-state', {
						detail: activeScan
					}));
				}
			} catch (error) {
				Logger.error('Failed to refresh scan state:', error);
			}
		}
	};

	/**
	 * Initialize worker pool
	 */
	function initWorkerPool() {
		for (let i = 0; i < CONFIG.maxWorkers; i++) {
			workers.push(new IframeWorker(i));
		}
		Logger.log('Worker pool initialized with', workers.length, 'workers');
	}

	/**
	 * Get available workers
	 */
	function getAvailableWorkers() {
		return workers.filter(w => !w.busy);
	}

	/**
	 * Start heartbeat for a job
	 */
	function startHeartbeat(jobId, leaseToken) {
		const intervalId = setInterval(async () => {
			const ok = await ScanManager.heartbeat(jobId, leaseToken);
			if (!ok) {
				Logger.warn('Heartbeat failed for job', jobId);
				clearInterval(intervalId);
				heartbeatTimers.delete(jobId);
			}
		}, CONFIG.heartbeatInterval);

		heartbeatTimers.set(jobId, intervalId);
		return intervalId;
	}

	/**
	 * Stop heartbeat for a job
	 */
	function stopHeartbeat(jobId) {
		const intervalId = heartbeatTimers.get(jobId);
		if (intervalId) {
			clearInterval(intervalId);
			heartbeatTimers.delete(jobId);
		}
	}

	/**
	 * Process a single job
	 */
	async function processJob(job) {
		const worker = workers.find(w => !w.busy);
		if (!worker) {
			Logger.warn('No available workers for job', job.id);
			return;
		}

		console.log('📋 [Job ' + job.id + '] Starting job processing...');
		console.log('   URL:', job.url);
		console.log('   Worker:', worker.id);
		console.log('   Lease token:', job.leaseToken.substring(0, 8) + '...');

		// Start heartbeat
		startHeartbeat(job.id, job.leaseToken);

		try {
			console.log('🔍 [Job ' + job.id + '] Starting URL scan...');
			// Scan the URL
			const result = await worker.scanUrl(job.url, job.id, job.leaseToken);

			console.log('✅ [Job ' + job.id + '] Scan completed!');
			console.log('   Result type:', typeof result);
			console.log('   Result value:', result);

			// Check if result is valid
			if (!result) {
				throw new Error('Scan returned empty result - iframe scan may have timed out or failed silently');
			}

			if (!result.violations) {
				console.warn('⚠️ [Job ' + job.id + '] Result missing violations property, result:', result);
				// Add empty violations array if missing
				result.violations = [];
			}

			console.log('   Has violations:', result.violations.length);

			// Complete the job with results
			const resultJson = JSON.stringify(result);
			console.log('📤 [Job ' + job.id + '] Sending results to server (' + resultJson.length + ' bytes)...');

			await ScanManager.completeJob(job.id, job.leaseToken, resultJson);

			console.log('✅ [Job ' + job.id + '] Job completed successfully!');
			Logger.log('Job', job.id, 'completed successfully');

		} catch (error) {
			console.error('❌ [Job ' + job.id + '] Job failed:', error);
			console.error('   Error message:', error.message);
			console.error('   Error stack:', error.stack);
			Logger.error('Job', job.id, 'failed:', error);
			await ScanManager.completeJob(job.id, job.leaseToken, null, error.message);
		} finally {
			stopHeartbeat(job.id);
		}
	}

	/**
	 * Main scanning loop
	 */
	async function scanningLoop() {
		console.log('🔄 Scanning loop started');

		while (isRunning) {
			try {
				// Check if scan is still active
				const activeScan = await ScanManager.getActiveScan();
				if (!activeScan) {
					console.log('ℹ️ No active scan found, stopping scanner');
					stop();
					break;
				}

				// Store scan state
				scanState = activeScan;

				// Get available workers
				const availableWorkers = getAvailableWorkers();
				if (availableWorkers.length === 0) {
					console.log('⏳ No workers available, waiting...');
					await sleep(500);
					continue;
				}

				console.log('🔓 Leasing jobs (' + availableWorkers.length + ' workers available)...');

				// Lease jobs
				const jobs = await ScanManager.leaseJobs(availableWorkers.length);
				if (jobs.length === 0) {
					console.log('⏳ No jobs leased, checking scan status...');

					// Check if scan is complete
					const stats = await ScanManager.getScanStats(activeScan.scan_id);
					console.log('📊 Scan stats:', stats);

					if (stats && stats.pending === 0 && stats.active === 0) {
						console.log('%c✅ SCAN COMPLETE!', 'color: #0f0; font-weight: bold;');
						stop();
						break;
					}

					await sleep(2000);
					continue;
				}

				console.log('✅ Leased ' + jobs.length + ' job(s):', jobs.map(j => ({ id: j.id, url: j.url })));

				// Process jobs in parallel
				await Promise.allSettled(
					jobs.map(job => processJob(job))
				);

			} catch (error) {
				console.error('❌ Error in scanning loop:', error);
				await sleep(5000);
			}
		}

		console.log('🔄 Scanning loop ended');
	}

	/**
	 * Start the scanner
	 */
	function start() {
		if (isRunning) {
			console.warn('⚠️ Scanner already running');
			return;
		}

		isRunning = true;
		console.log('%c🚀 STARTING GLOBAL SCANNER', 'color: #0f0; font-weight: bold; font-size: 14px;');
		console.log('   Worker ID:', workerId);
		console.log('   Max workers:', CONFIG.maxWorkers);
		scanningLoop();
	}

	/**
	 * Stop the scanner
	 */
	function stop() {
		isRunning = false;
		console.log('🛑 Scanner stopped');

		// Clear all heartbeats
		heartbeatTimers.forEach(intervalId => clearInterval(intervalId));
		heartbeatTimers.clear();
	}

	/**
	 * Sleep utility
	 */
	function sleep(ms) {
		return new Promise(resolve => setTimeout(resolve, ms));
	}

	/**
	 * Initialize the global scanner
	 */
	async function init() {
		console.group('%c[ClearA11y] Scanner Initialization', 'color: #00a0d2; font-weight: bold;');
		console.log('📄 Page:', window.location.href);
		console.log('📋 Document ready state:', document.readyState);

		// Wait for DOM to be ready
		if (document.readyState === 'loading') {
			console.log('⏳ Waiting for DOMContentLoaded...');
			await new Promise(resolve => {
				document.addEventListener('DOMContentLoaded', resolve);
			});
		}
		console.log('✅ DOM is ready');

		// Wait for cleara11yData to be available
		let attempts = 0;
		while (!window.cleara11yData && attempts < 50) {
			console.log('⏳ Waiting for cleara11yData... (' + (attempts + 1) + '/50)');
			await sleep(100);
			attempts++;
		}

		if (!window.cleara11yData) {
			console.error('❌ cleara11yData not available after 50 attempts');
			console.log('Checking all script tags...');
			document.querySelectorAll('script[src*="cleara11y"]').forEach(script => {
				console.log(' - Script:', script.src);
			});
			console.groupEnd();
			return;
		}

		console.log('✅ cleara11yData available:', {
			apiUrl: cleara11yData.apiUrl,
			pluginUrl: cleara11yData.pluginUrl,
			workerId: cleara11yData.workerId || 'none',
			hasNonce: !!cleara11yData.nonce
		});

		// Initialize worker pool
		initWorkerPool();
		console.log('✅ Worker pool initialized (' + CONFIG.maxWorkers + ' workers)');

		// Check for active scan
		console.log('🔍 Checking for active scan...');
		const activeScan = await ScanManager.getActiveScan();
		if (activeScan) {
			console.log('%c✅ ACTIVE SCAN FOUND!', 'color: #0f0; font-weight: bold; font-size: 16px;');
			console.log('Scan details:', activeScan);
			scanState = activeScan;

			// Dispatch scan state event for other components
			window.dispatchEvent(new CustomEvent('cleara11y:scan-state', {
				detail: activeScan
			}));

			// Start scanning
			console.log('🚀 Starting scanner...');
			start();
		} else {
			console.log('ℹ️ No active scan found, scanner ready and waiting');
			console.log('   (Navigate to ClearA11y dashboard and start a scan to test)');
		}

		console.groupEnd();

		// Listen for manual start events
		window.addEventListener('cleara11y:start-scan', () => {
			console.log('📢 Received cleara11y:start-scan event');
			start();
		});

		// Listen for manual stop events
		window.addEventListener('cleara11y:stop-scan', () => {
			console.log('📢 Received cleara11y:stop-scan event');
			stop();
		});

		// Listen for scan state updates
		window.addEventListener('cleara11y:scan-state', (event) => {
			scanState = event.detail;
			console.log('📊 Scan state updated:', scanState);
		});
	}

	// Auto-start when DOM is ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	// Expose API for external control and debugging
	window.ClearA11yGlobalScanner = {
		start,
		stop,
		isScanning: () => isRunning,
		getScanState: () => scanState,
		getWorkerId: () => workerId,
		Logger,
		// Debug helpers
		debug: {
			async diagnose() {
				console.group('%c[ClearA11y] DIAGNOSTIC', 'color: #f0f; font-weight: bold;');

				// Check 1: Script loaded
				console.log('✅ Script loaded: Yes');

				// Check 2: cleara11yData available
				console.log('cleara11yData:', window.cleara11yData ? '✅ Yes' : '❌ No');
				if (window.cleara11yData) {
					console.log('  apiUrl:', cleara11yData.apiUrl);
					console.log('  pluginUrl:', cleara11yData.pluginUrl);
					console.log('  hasNonce:', !!cleara11yData.nonce);
				}

				// Check 3: Worker pool
				console.log('Worker pool:', workers.length + ' workers created');

				// Check 4: Current state
				console.log('isRunning:', isRunning);
				console.log('scanState:', scanState);
				console.log('workerId:', workerId);

				// Check 5: API connectivity
				console.log('Testing API connectivity...');
				try {
					const testUrl = cleara11yData?.apiUrl + 'scan/active';
					console.log('  Fetching:', testUrl);
					const response = await fetch(testUrl, {
						headers: { 'X-WP-Nonce': cleara11yData?.nonce }
					});
					console.log('  Response status:', response.status);
					const data = await response.json();
					console.log('  Response data:', data);
					console.log('  ✅ API is reachable');
				} catch (e) {
					console.error('  ❌ API error:', e);
				}

				// Check 6: Scan indicator script
				const indicatorScript = document.querySelector('script[src*="scan-indicator"]');
				console.log('Scan indicator script:', indicatorScript ? '✅ Loaded' : '❌ Not loaded');

				// Check 7: DOM ready state
				console.log('Document ready state:', document.readyState);

				console.groupEnd();
			},
			async checkActiveScan() {
				console.log('Checking for active scan...');
				const result = await ScanManager.getActiveScan();
				console.log('Result:', result);
				return result;
			},
			getWorkers() {
				return workers.map(w => ({
					id: w.id,
					busy: w.busy,
					currentJob: w.currentJob
				}));
			},
			forceStart() {
				console.log('Force starting scanner');
				start();
			},
			forceStop() {
				console.log('Force stopping scanner');
				stop();
			},
			async testApi() {
				try {
					const response = await fetch(cleara11yData.apiUrl + 'scan/active', {
						headers: { 'X-WP-Nonce': cleara11yData.nonce }
					});
					const data = await response.json();
					console.log('Test API response:', data);
					return data;
				} catch (e) {
					console.error('Test API failed:', e);
					return null;
				}
			},
			showConfig() {
				console.log('Scanner Configuration:', CONFIG);
			}
		}
	};

	console.log('%c[ClearA11y] Global Scanner loaded!', 'color: #00a0d2; font-weight: bold;');
	console.log('🔧 Debug commands:');
	console.log('   ClearA11yGlobalScanner.debug.diagnose()  - Run full diagnostic');
	console.log('   ClearA11yGlobalScanner.debug.checkActiveScan() - Check for active scan');
	console.log('   ClearA11yGlobalScanner.debug.testApi() - Test API connection');
	console.log('   ClearA11yGlobalScanner.debug.forceStart() - Force start scanner');
	console.log('   ClearA11yGlobalScanner.debug.showConfig() - Show configuration');

})();
