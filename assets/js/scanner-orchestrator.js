/**
 * Scanner Orchestrator
 *
 * Manages parallel scanning using hidden iframe workers with job leasing.
 * This enables multiple pages to be scanned simultaneously for improved throughput.
 *
 * @package ClearA11y
 */

(function() {
	'use strict';

	console.log('[ClearA11y Orchestrator] Script loading...');

	// Get API config - will be available when dashboard initializes
	function getApiConfig() {
		if (window.cleara11yData) {
			return {
				apiUrl: window.cleara11yData.apiUrl,
				nonce: window.cleara11yData.nonce,
				pluginUrl: window.cleara11yData.pluginUrl
			};
		}
		console.error('ClearA11y: cleara11yData not found - orchestrator initialized but not ready');
		return null;
	}

	/**
	 * Utility: Sleep for specified milliseconds
	 */
	function sleep(ms) {
		return new Promise(resolve => setTimeout(resolve, ms));
	}

	/**
	 * Utility: Generate UUID v4
	 */
	function generateUUID() {
		if (typeof crypto !== 'undefined' && crypto.randomUUID) {
			return crypto.randomUUID();
		}
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
			const r = Math.random() * 16 | 0;
			const v = c === 'x' ? r : (r & 0x3 | 0x8);
			return v.toString(16);
		});
	}

	/**
	 * Utility: Fetch with JSON body and nonce
	 */
	async function fetchJSON(url, data = {}) {
		const config = getApiConfig();
		if (!config) {
			throw new Error('ClearA11y: API configuration not available');
		}
		const response = await fetch(url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
			},
			body: JSON.stringify(data),
		});

		if (!response.ok) {
			// Try to get error details from response
			let errorDetails = '';
			try {
				const errorData = await response.json();
				errorDetails = errorData.message || JSON.stringify(errorData);
			} catch (e) {
				errorDetails = await response.text();
			}
			console.error('ClearA11y: API Error Details:', errorDetails);
			throw new Error(`HTTP ${response.status}: ${response.statusText} - ${errorDetails}`);
		}

		return response.json();
	}

	/**
	 * Iframe Worker Pool
	 *
	 * Manages a pool of hidden iframe workers for parallel scanning.
	 */
	class IframeWorkerPool {
		constructor(size = 2) {
			this.size = size;
			this.workers = [];
			this.initialize();
		}

		initialize() {
			for (let i = 0; i < this.size; i++) {
				const iframe = this.createHiddenIframe();
				this.workers.push({
					id: i,
					iframe: iframe,
					busy: false,
					currentJobId: null,
				});
			}
			console.log(`ClearA11y: Worker pool initialized with ${this.size} workers`);
		}

		createHiddenIframe() {
			const iframe = document.createElement('iframe');
			iframe.style.cssText = `
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
				aria-hidden: 'true';
				tabindex: -1;
			`;
			iframe.title = 'Accessibility scanning iframe';
			iframe.setAttribute('aria-hidden', 'true');
			iframe.setAttribute('tabindex', '-1');
			document.body.appendChild(iframe);
			return iframe;
		}

		freeSlots() {
			return this.workers.filter(w => !w.busy).length;
		}

		acquire() {
			const worker = this.workers.find(w => !w.busy);
			if (worker) {
				worker.busy = true;
				console.log(`ClearA11y: Worker ${worker.id} acquired`);
			}
			return worker;
		}

		release(worker) {
			worker.busy = false;
			worker.currentJobId = null;
			// Load about:blank to reclaim memory
			try {
				worker.iframe.src = 'about:blank';
			} catch (e) {
				// Ignore errors when clearing iframe
			}
			console.log(`ClearA11y: Worker ${worker.id} released`);
		}

		getById(id) {
			return this.workers.find(w => w.id === id);
		}

		destroy() {
			this.workers.forEach(worker => {
				if (worker.iframe && worker.iframe.parentNode) {
					worker.iframe.parentNode.removeChild(worker.iframe);
				}
			});
			this.workers = [];
		}
	}

	/**
	 * Heartbeat Manager
	 *
	 * Manages periodic heartbeat for a job lease.
	 */
	class HeartbeatManager {
		constructor(job, leaseSeconds, heartbeatCallback) {
			this.job = job;
			this.leaseSeconds = leaseSeconds;
			this.heartbeatCallback = heartbeatCallback;
			this.intervalId = null;
			this.stopped = false;
		}

		start() {
			// Send heartbeat at 1/3 of lease interval (e.g., every 60s for 180s lease)
			const intervalMs = Math.max(this.leaseSeconds * 1000 / 3, 30000);
			this.intervalId = setInterval(async () => {
				if (this.stopped) return;

				try {
					await this.heartbeatCallback(this.job);
				} catch (error) {
					console.error('ClearA11y: Heartbeat failed:', error);
					// Don't stop on heartbeat failure - let lease expire naturally
				}
			}, intervalMs);

			console.log(`ClearA11y: Heartbeat started for job ${this.job.id} (${intervalMs}ms interval)`);
		}

		stop() {
			this.stopped = true;
			if (this.intervalId) {
				clearInterval(this.intervalId);
				this.intervalId = null;
			}
			console.log(`ClearA11y: Heartbeat stopped for job ${this.job.id}`);
		}
	}

	/**
	 * Scanner Orchestrator
	 *
	 * Main orchestrator that manages job leasing and distributes work to workers.
	 */
	class ScannerOrchestrator {
		constructor(options = {}) {
			this.maxConcurrency = options.maxConcurrency || 2;
			this.leaseSeconds = options.leaseSeconds || 180;
			this.heartbeatEveryMs = options.heartbeatEveryMs || 45000;
			this.workerPool = new IframeWorkerPool(this.maxConcurrency);
			this.workerId = this.getOrCreateWorkerId();
			this.stopped = true;
			this.activeHeartbeats = new Map(); // jobId -> HeartbeatManager
			this.activeJobs = new Map(); // jobId -> { worker, scanId }

			// Event callbacks
			this.onJobComplete = options.onJobComplete || (() => {});
			this.onJobFail = options.onJobFail || (() => {});
			this.onQueueEmpty = options.onQueueEmpty || (() => {});
			this.onError = options.onError || (() => {});

			console.log(`ClearA11y: Orchestrator created (workerId: ${this.workerId}, concurrency: ${this.maxConcurrency})`);
		}

		getOrCreateWorkerId() {
			let workerId = localStorage.getItem('cleara11y_worker_id');
			if (!workerId) {
				workerId = generateUUID();
				localStorage.setItem('cleara11y_worker_id', workerId);
			}
			return workerId;
		}

		async start() {
			if (!this.stopped) {
				console.warn('ClearA11y: Orchestrator already running');
				return;
			}

			this.stopped = false;
			console.log('ClearA11y: Orchestrator starting...');
			this.mainLoop();
		}

		async stop() {
			console.log('ClearA11y: Orchestrator stopping...');
			this.stopped = true;

			// Stop all heartbeats
			for (const heartbeat of this.activeHeartbeats.values()) {
				heartbeat.stop();
			}
			this.activeHeartbeats.clear();
			this.activeJobs.clear();

			// Destroy worker pool
			this.workerPool.destroy();

			console.log('ClearA11y: Orchestrator stopped');
		}

		async mainLoop() {
			while (!this.stopped) {
				try {
					const freeSlots = this.workerPool.freeSlots();

					if (freeSlots <= 0) {
						await sleep(1000);
						continue;
					}

					// Lease jobs
					const response = await this.leaseJobs(freeSlots);

					if (!response.jobs || response.jobs.length === 0) {
						// No jobs available
						if (this.activeJobs.size === 0) {
							// No active jobs either - queue is empty
							this.onQueueEmpty();
						}
						await sleep(5000);
						continue;
					}

					// Run jobs in parallel
					await Promise.allSettled(
						response.jobs.map(job => this.runJob(job))
					);

				} catch (error) {
					console.error('ClearA11y: Main loop error:', error);
					this.onError(error);
					await sleep(5000);
				}
			}
		}

		async leaseJobs(limit) {
			const config = getApiConfig();
			if (!config) {
				throw new Error('ClearA11y: API configuration not available');
			}
			const response = await fetchJSON(`${config.apiUrl}jobs/lease`, {
				workerId: this.workerId,
				limit: limit,
				leaseSeconds: this.leaseSeconds,
			});

			if (!response) {
				throw new Error('Failed to lease jobs');
			}

			console.log(`ClearA11y: Leased ${response.leased} jobs`);
			return response;
		}

		async runJob(job) {
			// Validate job structure
			if (!job.id || !job.leaseToken) {
				throw new Error(`Invalid job structure: ${JSON.stringify(job)}`);
			}

			console.log('ClearA11y: Starting job:', { id: job.id, leaseToken: job.leaseToken?.substring(0, 8) + '...' });
			const worker = this.workerPool.acquire();

			if (!worker) {
				console.warn('ClearA11y: No worker available for job', job);
				return;
			}

			const heartbeat = new HeartbeatManager(
				job,
				this.leaseSeconds,
				this.sendHeartbeat.bind(this)
			);

			this.activeHeartbeats.set(job.id, heartbeat);
			this.activeJobs.set(job.id, { worker, job });

			try {
				heartbeat.start();

				const result = await this.scanInIframe(worker.iframe, job);

				await this.completeJob(job, 'done', result);

				this.onJobComplete(job, result);

			} catch (error) {
				console.error(`ClearA11y: Job ${job.id} failed:`, error);
				await this.completeJob(job, 'failed', null, error.message);
				this.onJobFail(job, error);
			} finally {
				heartbeat.stop();
				this.activeHeartbeats.delete(job.id);
				this.activeJobs.delete(job.id);
				this.workerPool.release(worker);
			}
		}

		async sendHeartbeat(job) {
			const config = getApiConfig();
			if (!config) {
				throw new Error('ClearA11y: API configuration not available');
			}
			const response = await fetchJSON(`${config.apiUrl}jobs/heartbeat`, {
				jobId: job.id,
				leaseToken: job.leaseToken,
				leaseSeconds: this.leaseSeconds,
			});

			if (!response || !response.ok) {
				throw new Error('Heartbeat failed');
			}

			console.log(`ClearA11y: Heartbeat sent for job ${job.id}, lease expires at ${response.leaseExpiresAt}`);
		}

		async scanInIframe(iframe, job) {
			const scanId = `scan-${job.id}-${Date.now()}`;

			return new Promise((resolve, reject) => {
				// Set up message listener for scan results
				const messageHandler = (event) => {
					// Verify origin for security
					if (event.origin !== window.location.origin) {
						return;
					}

					const data = event.data;

					if (data.type === 'CLEARA11Y_SCAN_RESULT' && data.scanId === scanId) {
						window.removeEventListener('message', messageHandler);

						if (data.error) {
							reject(new Error(data.error));
						} else {
							resolve(data.payload);
						}
					}
				};

				window.addEventListener('message', messageHandler);

				// Set up timeout
				const timeout = setTimeout(() => {
					window.removeEventListener('message', messageHandler);
					reject(new Error('Scan timeout'));
				}, this.leaseSeconds * 1000);

				// Load page in iframe
				iframe.src = job.url;

				// Wait for iframe to load, then inject bootstrap
				iframe.onload = () => {
					clearTimeout(timeout);

					try {
						// Inject inline bootstrap and axe-core
						this.injectScanBootstrap(iframe, scanId);
					} catch (error) {
						window.removeEventListener('message', messageHandler);
						reject(error);
					}
				};

				iframe.onerror = () => {
					clearTimeout(timeout);
					window.removeEventListener('message', messageHandler);
					reject(new Error('Failed to load iframe'));
				};
			});
		}

		injectScanBootstrap(iframe, scanId) {
			try {
				const doc = iframe.contentDocument || iframe.contentWindow.document;
				const win = iframe.contentWindow;

				if (!doc || !win) {
					throw new Error('Cannot access iframe content');
				}

				// Remove WP Admin Bar
				this.removeWpAdminBar(doc);

				// Inject external scripts in order
				this.injectExternalScripts(doc, win, scanId);

			} catch (error) {
				console.error('ClearA11y: Failed to inject scan bootstrap:', error);
				throw error;
			}
		}

		removeWpAdminBar(doc) {
			const bar = doc.getElementById('wpadminbar');
			if (bar) {
				bar.remove();
			}
			if (doc.documentElement) {
				doc.documentElement.style.marginTop = '0px';
			}
			if (doc.body) {
				doc.body.style.marginTop = '0px';
				doc.body.classList.remove('admin-bar');
			}
		}

			injectExternalScripts(doc, win, scanId) {
				const config = getApiConfig();
				if (!config || !config.pluginUrl) {
					throw new Error('ClearA11y: Plugin URL not available');
				}

				// Check if axe is already loaded
				if (!win.axe) {
					const axeScript = doc.createElement('script');
					axeScript.src = config.pluginUrl + 'assets/js/axe.min.js';
					axeScript.onerror = () => {
						throw new Error('Failed to load axe-core');
					};
					doc.head.appendChild(axeScript);
				}

				// Load evidence extractor
				const extractorScript = doc.createElement('script');
				extractorScript.src = config.pluginUrl + 'assets/js/evidence-extractor.js';
				extractorScript.onerror = () => {
					throw new Error('Failed to load evidence extractor');
				};
				doc.head.appendChild(extractorScript);

				// Wait for scripts to load, then run scan
				const checkInterval = setInterval(() => {
					if (win.axe && win.extractEvidenceFromAxeResults) {
						clearInterval(checkInterval);
						this.runSimpleBootstrap(win, doc, scanId);
					}
				}, 50);

				// Timeout after 10 seconds
				setTimeout(() => {
					clearInterval(checkInterval);
					if (!win.axe || !win.extractEvidenceFromAxeResults) {
						this.postMessage({
							type: 'scan_error',
							error: 'Timeout loading scanner scripts'
						});
					}
				}, 10000);
				}

				runSimpleBootstrap(win, doc, scanId) {
				const axeTags = window.ClearA11yScannerConfig?.AXE_ORCHESTRATOR_TAGS || ['wcag2a', 'wcag2aa', 'wcag21aa'];
				const maxSnippet = window.ClearA11yConstants?.MAX_SNIPPET_LENGTH || 4000;
				const maxText = window.ClearA11yConstants?.MAX_TEXT_LENGTH || 400;
				const ancestorDepth = window.ClearA11yConstants?.ANCESTOR_DEPTH || 6;
				const whitelist = window.ClearA11yConstants?.DATA_ATTR_WHITELIST || ["data-testid", "data-qa", "data-cy"];

				const bootstrapCode = `
					(function() {
						if (window.__CLEARA11Y_BOOTSTRAPPED__) return;
						window.__CLEARA11Y_BOOTSTRAPPED__ = true;

						setTimeout(async function() {
							try {
								if (!window.axe) {
									throw new Error('axe-core not loaded');
								}

								const results = await window.axe.run(document, {
									runOnly: { type: 'tag', values: ${JSON.stringify(axeTags)} }
								});

								let evidence = [];
								if (window.extractEvidenceFromAxeResults) {
									try {
										evidence = window.extractEvidenceFromAxeResults(results, {
											maxSnippetLen: ${maxSnippet},
											maxTextLen: ${maxText},
											ancestorDepth: ${ancestorDepth},
											allowDataAttrs: true,
											dataAttrWhitelist: ${JSON.stringify(whitelist)},
										});
									} catch (e) {
										console.warn('[ClearA11y] Evidence extraction failed:', e);
									}
								}

								window.parent.postMessage({
									type: 'CLEARA11Y_SCAN_RESULT',
									scanId: '${scanId}',
									payload: results
								}, '*');

							} catch (error) {
								window.parent.postMessage({
									type: 'CLEARA11Y_SCAN_RESULT',
									scanId: '${scanId}',
									error: error.message
								}, '*');
							}
						}, 150);
					})();
				`;

				const script = doc.createElement('script');
				script.textContent = bootstrapCode;
				doc.documentElement.appendChild(script);
				}

		async completeJob(job, status, resultJson = null, error = null) {
				const config = getApiConfig();
				if (!config) {
				throw new Error('ClearA11y: API configuration not available');
				}

				const resultJsonString = resultJson ? JSON.stringify(resultJson) : null;
				console.log('ClearA11y: Sending job complete:', {
				jobId: job.id,
				status: status,
				resultJsonLength: resultJsonString?.length || 0,
				hasResultJson: !!resultJsonString,
				});

				const response = await fetchJSON(`${config.apiUrl}jobs/complete`, {
				jobId: job.id,
				leaseToken: job.leaseToken,
				status: status,
				...(resultJsonString !== null && { resultJson: resultJsonString }),
				...(error !== null && { error: error }),
				});

				if (!response || !response.ok) {
				throw new Error('Failed to complete job');
				}

				console.log(`ClearA11y: Job ${job.id} completed with status: ${status}`);
		}

		getStatus() {
				return {
				running: !this.stopped,
				workerId: this.workerId,
				maxConcurrency: this.maxConcurrency,
				freeSlots: this.workerPool.freeSlots(),
				activeJobs: this.activeJobs.size,
				activeHeartbeats: this.activeHeartbeats.size,
				};
		}
	}

	// Export to global scope for use in dashboard.js
	window.ClearA11yScannerOrchestrator = ScannerOrchestrator;

	console.log('[ClearA11y Orchestrator] Script loaded successfully, class exported to window.ClearA11yScannerOrchestrator');
	console.log('[ClearA11y Orchestrator] Available:', typeof window.ClearA11yScannerOrchestrator);
})();
