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

				// Inject axe-core
				this.injectAxeCore(doc, () => {
					// After axe-core is loaded, inject bootstrap and run scan
					this.injectBootstrapScript(win, doc, scanId);
				});

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

		injectAxeCore(doc, callback) {
			// Check if axe is already loaded
			if (doc.defaultView.axe) {
				callback();
				return;
			}

			const config = getApiConfig();
			if (!config || !config.pluginUrl) {
				throw new Error('ClearA11y: Plugin URL not available for loading axe-core');
			}

			const script = doc.createElement('script');
			script.src = config.pluginUrl + 'assets/js/axe.min.js';
			script.onload = callback;
			script.onerror = () => {
				throw new Error('Failed to load axe-core from local assets');
			};
			doc.head.appendChild(script);
		}

		injectBootstrapScript(win, doc, scanId) {
			// Build bootstrap code with proper escaping for regex patterns
			const bootstrapCode = `
				(function() {
					if (window.__CLEARA11Y_BOOTSTRAPPED__) return;
					window.__CLEARA11Y_BOOTSTRAPPED__ = true;

					// === Evidence Extraction Functions (Inline) ===

					function extractEvidenceFromAxeResults(results) {
						const out = [];
						for (const v of results.violations || []) {
							for (const node of v.nodes || []) {
								const selector = (node && node.target && node.target[0]) || null;
								const record = {
									rule_id: v.id,
									impact: v.impact || null,
									message: v.description || v.help || null,
									help_url: v.helpUrl || null,
									failure_summary: node.failureSummary || null,
									selector: selector,
									selector_match_count: null,
									selector_score: null,
									node_evidence: null,
									axe_node_raw: {
										html: node.html || null,
										target: node.target || null,
										any: node.any || null,
										all: node.all || null,
										none: node.none || null,
									},
								};
								if (!selector) {
									out.push(record);
									continue;
								}
								const matchResult = resolveSelector(selector, document);
								record.selector_match_count = matchResult.matchCount;
								if (matchResult.element) {
									record.node_evidence = buildNodeEvidence(matchResult.element);
								}
								out.push(record);
							}
						}
						return out;
					}

					function resolveSelector(selector, rootDoc) {
						let matchCount = 0;
						let element = null;
						try {
							const matches = rootDoc.querySelectorAll(selector);
							matchCount = matches.length;
							element = matches[0] || null;
						} catch (e) {
							matchCount = 0;
							element = null;
						}
						return { matchCount, element };
					}

					function selectorHasGeneratedTokens(selector) {
						const tokens = selector.split(/[^A-Za-z0-9_-]+/g).filter(Boolean);
						const uuidRe = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
						const longHexRe = /^[0-9a-f]{10,}$/i;
						const cssModuleRe = /__[a-zA-Z0-9_-]{6,}$/;
						const jssRe = /^jss[0-9]+$/i;
						const scRe = /^sc-[A-Za-z0-9]{6,}$/;
						const cssDashRe = /^css-[A-Za-z0-9]{6,}$/;
						for (const t of tokens) {
							if (uuidRe.test(t)) return true;
							if (longHexRe.test(t)) return true;
							if (cssModuleRe.test(t)) return true;
							if (jssRe.test(t)) return true;
							if (scRe.test(t)) return true;
							if (cssDashRe.test(t)) return true;
							if (t.length >= 18 && /[A-Za-z]/.test(t) && /[0-9]/.test(t)) return true;
						}
						return false;
					}

					function buildNodeEvidence(el) {
						const tagName = el.tagName.toLowerCase();
						const attrs = extractAttributes(el);
						const outerHtmlSnippet = (el.outerHTML || "").slice(0, 4000);
						const innerTextSnippet = normalizeWhitespace(el.innerText || el.textContent || "").slice(0, 400);
						const xpath = buildXPath(el);
						const domPath = buildDomPath(el);
						const ancestorChain = buildAncestorChain(el, 6);
						const rect = el.getBoundingClientRect();
						const boundingBox = {
							x: Math.round(rect.x * 100) / 100,
							y: Math.round(rect.y * 100) / 100,
							w: Math.round(rect.width * 100) / 100,
							h: Math.round(rect.height * 100) / 100,
						};
						const cs = window.getComputedStyle(el);
						const styleEvidence = {
							color: cs.color || null,
							backgroundColor: cs.backgroundColor || null,
							fontSize: cs.fontSize || null,
							fontWeight: cs.fontWeight || null,
							opacity: cs.opacity || null,
						};
						const accessibleName = deriveAccessibleName(el);
						const strictSource = JSON.stringify({
							tagName,
							attrs: pickStableAttrs(attrs),
							ancestorChain,
							accessibleName,
						});
						const looseSource = JSON.stringify({
							tagName,
							attrs: pickLooseAttrs(attrs),
							ancestorChain: ancestorChain.map(a => ({
								tag: a.tag,
								role: a.role || null,
								ariaLabel: a.ariaLabel || null,
								id: a.id || null,
							})),
							accessibleName: accessibleName || null,
						});

						return {
							tag_name: tagName,
							attributes: attrs,
							accessible_name: accessibleName,
							css_selector_hint: buildBestEffortSelector(el),
							xpath: xpath,
							dom_path: domPath,
							ancestor_chain: ancestorChain,
							outer_html_snippet: outerHtmlSnippet,
							inner_text_snippet: innerTextSnippet,
							bounding_box: boundingBox,
							computed_style: styleEvidence,
							fingerprint_strict: simpleHash(strictSource),
							fingerprint_loose: simpleHash(looseSource),
							signature_version: 1,
						};
					}

					function extractAttributes(el) {
						const out = {};
						const allow = new Set([
							"id", "class", "name", "type", "role",
							"href", "src", "alt", "title", "for", "value",
							"aria-label", "aria-labelledby", "aria-describedby",
							"aria-hidden", "tabindex",
						]);
						for (const attr of el.attributes) {
							if (allow.has(attr.name)) out[attr.name] = attr.value;
							if (attr.name.startsWith("data-")) out[attr.name] = attr.value;
						}
						if (out.class) {
							out.class_list = out.class.split(/\s+/).filter(Boolean);
						}
						return out;
					}

					function deriveAccessibleName(el) {
						const ariaLabel = el.getAttribute("aria-label");
						if (ariaLabel) return normalizeWhitespace(ariaLabel);
						const alt = el.getAttribute("alt");
						if (alt) return normalizeWhitespace(alt);
						const title = el.getAttribute("title");
						if (title) return normalizeWhitespace(title);
						const text = el.innerText || el.textContent;
						if (text) return normalizeWhitespace(text).slice(0, 120);
						return null;
					}

					function buildXPath(el) {
						const segments = [];
						let node = el;
						while (node && node.nodeType === Node.ELEMENT_NODE) {
							const tag = node.tagName.toLowerCase();
							const id = node.getAttribute("id");
							if (id && !selectorHasGeneratedTokens("#" + id)) {
								segments.unshift('//*[@id="' + id + '"]');
								break;
							}
							let index = 1;
							let sib = node.previousElementSibling;
							while (sib) {
								if (sib.tagName.toLowerCase() === tag) index++;
								sib = sib.previousElementSibling;
							}
							segments.unshift("/" + tag + "[" + index + "]");
							node = node.parentElement;
						}
						return segments.length ? segments.join("") : null;
					}

					function buildDomPath(el) {
						const path = [];
						let node = el;
						while (node && node.nodeType === Node.ELEMENT_NODE) {
							const tag = node.tagName.toLowerCase();
							const id = node.getAttribute("id") || null;
							let indexOfType = 1;
							let sib = node.previousElementSibling;
							while (sib) {
								if (sib.tagName.toLowerCase() === tag) indexOfType++;
								sib = sib.previousElementSibling;
							}
							path.unshift({ tag, id, indexOfType });
							if (id && !selectorHasGeneratedTokens("#" + id)) break;
							node = node.parentElement;
						}
						return path;
					}

					function buildAncestorChain(el, maxDepth) {
						const chain = [];
						let node = el;
						let depth = 0;
						while (node && node.nodeType === Node.ELEMENT_NODE && depth < maxDepth) {
							chain.push({
								tag: node.tagName.toLowerCase(),
								id: node.getAttribute("id") || null,
								class: node.getAttribute("class") || null,
								role: node.getAttribute("role") || null,
								ariaLabel: node.getAttribute("aria-label") || null,
							});
							node = node.parentElement;
							depth++;
						}
						return chain;
					}

					function buildBestEffortSelector(el) {
						const id = el.getAttribute("id");
						if (id && !selectorHasGeneratedTokens("#" + id)) return "#" + cssEscape(id);
						const dt = el.getAttribute("data-testid");
						if (dt) return el.tagName.toLowerCase() + '[data-testid="' + dt + '"]';
						const role = el.getAttribute("role");
						const ariaLabel = el.getAttribute("aria-label");
						if (role && ariaLabel) {
							return '[role="' + role + '"][aria-label="' + ariaLabel + '"]';
						}
						const classList = (el.getAttribute("class") || "").split(/\s+/).filter(Boolean);
						if (classList.length) {
							return el.tagName.toLowerCase() + "." + classList.slice(0, 2).map(cssEscape).join(".");
						}
						return el.tagName.toLowerCase();
					}

					function pickStableAttrs(attrs) {
						const out = {};
						for (const k of ["id", "role", "aria-label", "name", "type", "href", "for"]) {
							if (attrs[k]) out[k] = attrs[k];
						}
						if (Array.isArray(attrs.class_list)) {
							out.class_list = attrs.class_list.slice(0, 3);
						}
						return out;
					}

					function pickLooseAttrs(attrs) {
						const out = {};
						for (const k of ["role", "name", "type", "href"]) {
							if (attrs[k]) out[k] = attrs[k];
						}
						return out;
					}

					function simpleHash(str) {
						let hash = 0;
						for (let i = 0; i < str.length; i++) {
							const char = str.charCodeAt(i);
							hash = ((hash << 5) - hash) + char;
							hash = hash & hash;
						}
						return Math.abs(hash).toString(36);
					}

					function cssEscape(str) {
						if (window.CSS && typeof window.CSS.escape === "function") {
							return window.CSS.escape(str);
						}
						return String(str).replace(/[^a-zA-Z0-9_-]/g, s => "\\\\" + s);
					}

					function normalizeWhitespace(s) {
						return String(s).replace(/\s+/g, " ").trim();
					}

					// === Scan Execution ===

					setTimeout(async function() {
						try {
							if (!window.axe) {
								throw new Error('axe-core not loaded');
							}

							// Run accessibility scan
							const results = await window.axe.run(document, {
								runOnly: {
									type: 'tag',
									values: ['wcag2a', 'wcag2aa', 'wcag21aa']
								}
							});

							// Extract evidence from violations
							const evidence = extractEvidenceFromAxeResults(results);
							results.evidence = evidence;

							// Post results back to parent
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
