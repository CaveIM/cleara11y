/**
 * ClearA11y Scanner Utilities
 *
 * Shared utility functions for scanner modules.
 *
 * @package ClearA11y
 */

(function() {
	'use strict';

	/**
	 * ClearA11y plugin selectors that should be filtered from scan results.
	 * These are the plugin's own UI elements and should not be flagged.
	 */
	const CLEARA11Y_SELECTORS = [
		'[data-cleara11y-plugin]',
		'.cleara11y-panel',
		'.cleara11y-backdrop',
		'.cleara11y-summary',
		'.cleara11y-stat',
		'.cleara11y-filter',
		'.cleara11y-panel-'
	];

	/**
	 * Filter out ClearA11y plugin elements from axe results.
	 * This prevents the scanner from flagging its own UI elements.
	 *
	 * @param {Object} results - The axe-core scan results
	 * @param {boolean} verbose - Whether to log filtering details
	 * @return {Object} Filtered results with ClearA11y elements removed
	 */
	function filterPluginElements(results, verbose = false) {
		if (!results.violations || results.violations.length === 0) {
			return results;
		}

		const originalCount = results.violations.length;

		results.violations.forEach(violation => {
			const beforeNodeCount = violation.nodes.length;
			violation.nodes = violation.nodes.filter(node => {
				if (!node.target || node.target.length === 0) return true;

				for (const targetPath of node.target) {
					if (!Array.isArray(targetPath)) continue;
					const selectorPath = targetPath.join(' ');

					if (isClearA11yElement(selectorPath)) {
						if (verbose) {
							console.log('[ClearA11y] Filtered plugin element:', selectorPath);
						}
						return false;
					}
				}
				return true;
			});

			if (violation.nodes.length !== beforeNodeCount && verbose) {
				console.log('[ClearA11y] Filtered nodes from violation:', violation.id);
			}
		});

		results.violations = results.violations.filter(v => v.nodes.length > 0);

		if (verbose) {
			console.log('[ClearA11y] Filtered out', originalCount - results.violations.length, 'plugin violations');
		}

		return results;
	}

	/**
	 * Check if a selector matches a ClearA11y plugin element.
	 *
	 * @param {string} selectorPath - CSS selector path to check
	 * @return {boolean} True if this is a plugin element
	 */
	function isClearA11yElement(selectorPath) {
		return CLEARA11Y_SELECTORS.some(selector => selectorPath.includes(selector));
	}

	/**
	 * Wait for a global function to be defined.
	 * Uses exponential backoff for efficiency.
	 *
	 * @param {string} functionName - Name of the global function to wait for
	 * @param {number} timeoutMs - Maximum time to wait (default: 10000)
	 * @return {Promise<void>}
	 */
	function waitForGlobal(functionName, timeoutMs = 10000) {
		return new Promise((resolve, reject) => {
			const startTime = Date.now();
			let delay = 50;

			const check = () => {
				if (typeof window[functionName] !== 'undefined') {
					resolve();
					return;
				}

				const elapsed = Date.now() - startTime;
				if (elapsed >= timeoutMs) {
					reject(new Error(`Timeout waiting for ${functionName}`));
					return;
				}

				setTimeout(check, delay);
				delay = Math.min(delay * 1.5, 500);
			};

			check();
		});
	}

	// Export to global scope
	window.ClearA11yScannerUtils = {
		filterPluginElements,
		isClearA11yElement,
		waitForGlobal
	};

})();
