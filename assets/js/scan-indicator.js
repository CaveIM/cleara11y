/**
 * Scan Indicator for ClearA11y
 *
 * Updates the WordPress admin bar indicator with real-time scan progress.
 *
 * @package ClearA11y
 */

(function() {
	'use strict';

	// Prevent double initialization
	if (window.__CLEARA11Y_SCAN_INDICATOR__) {
		return;
	}
	window.__CLEARA11Y_SCAN_INDICATOR__ = true;

	const CONFIG = {
		// Poll interval in ms
		pollInterval: 2000,
		// API endpoint
		apiEndpoint: 'scan/active',
	};

	let pollTimer = null;
	let currentScanId = null;
	let lastKnownStats = null;

	/**
	 * Update the admin bar progress indicator
	 */
	function updateIndicator(activeScan) {
		const phpToolbar = document.getElementById('wp-admin-bar-cleara11y-toolbar');
		if (!phpToolbar) {
			return;
		}

		// If no active scan, show final state and stop polling
		if (!activeScan || !activeScan.active) {
			// Remove scanning class to stop blinking
			phpToolbar.classList.remove('cleara11y-scanning');
			// Show final completed state if we have last known stats
			if (lastKnownStats && lastKnownStats.total > 0) {
				updateProgressText(lastKnownStats.total, lastKnownStats.total);
			}
			stopPolling();
			return;
		}

		// Store stats for final update
		lastKnownStats = activeScan.stats;

		// Get stats
		const stats = activeScan.stats || {};
		const total = stats.total || 0;
		const completed = stats.completed || 0;

		// Add scanning class
		phpToolbar.classList.add('cleara11y-scanning');

		// Find or create progress text element
		let progressText = phpToolbar.querySelector('.cleara11y-progress-text');
		if (!progressText) {
			const abItem = phpToolbar.querySelector('.ab-item');
			if (abItem) {
				progressText = document.createElement('span');
				progressText.className = 'cleara11y-progress-text';
				abItem.appendChild(progressText);
			}
		}

		// Update progress text
		if (progressText) {
			progressText.textContent = `${completed}/${total}`;
		}

		currentScanId = activeScan.scan_id;

		// Start polling if not already running
		if (!pollTimer) {
			startPolling();
		}
	}

	/**
	 * Update the progress text with given values
	 */
	function updateProgressText(completed, total) {
		const phpToolbar = document.getElementById('wp-admin-bar-cleara11y-toolbar');
		if (!phpToolbar) {
			return;
		}

		let progressText = phpToolbar.querySelector('.cleara11y-progress-text');
		if (!progressText) {
			const abItem = phpToolbar.querySelector('.ab-item');
			if (abItem) {
				progressText = document.createElement('span');
				progressText.className = 'cleara11y-progress-text';
				abItem.appendChild(progressText);
			}
		}

		if (progressText) {
			progressText.textContent = `${completed}/${total}`;
		}
	}

	/**
	 * Check scan status from server
	 */
	async function checkScanStatus() {
		try {
			const response = await fetch(cleara11yData.apiUrl + CONFIG.apiEndpoint, {
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
			console.error('ClearA11y: Failed to check scan status:', error);
			return null;
		}
	}

	/**
	 * Start polling for scan status
	 */
	function startPolling() {
		if (pollTimer) {
			return;
		}

		pollTimer = setInterval(async () => {
			const status = await checkScanStatus();
			if (status && status.active) {
				updateIndicator(status);
			} else {
				updateIndicator(null);
			}
		}, CONFIG.pollInterval);
	}

	/**
	 * Stop polling for scan status
	 */
	function stopPolling() {
		if (pollTimer) {
			clearInterval(pollTimer);
			pollTimer = null;
		}
	}

	/**
	 * Initialize the scan indicator
	 */
	async function init() {
		// Wait for cleara11yData to be available
		let attempts = 0;
		while (!window.cleara11yData && attempts < 50) {
			await new Promise(resolve => setTimeout(resolve, 100));
			attempts++;
		}

		if (!window.cleara11yData) {
			return;
		}

		// Wait for admin bar to be ready
		let barAttempts = 0;
		while (!document.getElementById('wp-admin-bar-cleara11y-toolbar') && barAttempts < 50) {
			await new Promise(resolve => setTimeout(resolve, 100));
			barAttempts++;
		}

		// Check for active scan on load
		const status = await checkScanStatus();
		updateIndicator(status);

		// Listen for scan state updates from global scanner
		window.addEventListener('cleara11y:scan-state', (event) => {
			updateIndicator(event.detail);
		});
	}

	// Initialize when DOM is ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

})();
