/**
 * ClearA11y Scanner Configuration
 *
 * Centralized constants for scanner modules.
 *
 * @package ClearA11y
 */

(function() {
	'use strict';

	/**
	 * Scanner Configuration Constants
	 * All timeout values, delays, and limits in one place.
	 */
	const SCANNER_CONFIG = {
		// Timing / Timeout values (in milliseconds)
		WAIT_FOR_AXE_TIMEOUT: 10000,           // Max time to wait for axe-core to load
		WAIT_FOR_EXTRACTOR_TIMEOUT: 10000,     // Max time to wait for evidence extractor
		SCANNER_AUTO_CLOSE_DELAY: 3000,        // Auto-close delay after successful scan (no issues)
		BG_SCANNER_CLOSE_DELAY: 500,           // Delay before closing background scanner window
		ERROR_POPUP_CLOSE_DELAY: 2000,         // Delay before closing error popup

		// Evidence extraction limits
		MAX_SNIPPET_LENGTH: 4000,              // Maximum HTML snippet length
		MAX_TEXT_LENGTH: 400,                  // Maximum inner text length
		ANCESTOR_DEPTH: 6,                     // How many ancestors to capture in evidence
		MAX_ACCESSIBLE_NAME_LENGTH: 120,       // Maximum accessible name length

		// Allowed data attributes for evidence extraction
		DATA_ATTR_WHITELIST: [
			'data-testid',
			'data-qa',
			'data-cy'
		],

		// Scanner orchestration
		MAX_CONCURRENT_WORKERS: 2,             // Default number of parallel iframe workers
		LEASE_SECONDS: 180,                    // Default job lease duration (3 minutes)
		HEARTBEAT_INTERVAL_MS: 60000,          // Heartbeat interval (1/3 of lease)

		// UI constants
		PROGRESS_UPDATE_INTERVAL: 100,         // Progress bar update interval

		// Axe-core configuration
		AXE_RUN_TAGS: ['wcag2aa'],             // WCAG level to test against
		AXE_ORCHESTRATOR_TAGS: ['wcag2a', 'wcag2aa', 'wcag21aa'], // More comprehensive for orchestrator

		// API endpoints (relative to REST API base)
		ENDPOINTS: {
			SCAN_RESULTS: 'scan/results',
			JOBS_LEASE: 'jobs/lease',
			JOBS_HEARTBEAT: 'jobs/heartbeat',
			JOBS_COMPLETE: 'jobs/complete'
		}
	};

	// Export to global scope
	window.ClearA11yScannerConfig = SCANNER_CONFIG;

	// Also export individual constants for easier access
	window.ClearA11yConstants = {
		// Timeouts
		WAIT_FOR_AXE_TIMEOUT: SCANNER_CONFIG.WAIT_FOR_AXE_TIMEOUT,
		WAIT_FOR_EXTRACTOR_TIMEOUT: SCANNER_CONFIG.WAIT_FOR_EXTRACTOR_TIMEOUT,
		AUTO_CLOSE_DELAY: SCANNER_CONFIG.SCANNER_AUTO_CLOSE_DELAY,

		// Evidence extraction
		MAX_SNIPPET_LENGTH: SCANNER_CONFIG.MAX_SNIPPET_LENGTH,
		MAX_TEXT_LENGTH: SCANNER_CONFIG.MAX_TEXT_LENGTH,
		ANCESTOR_DEPTH: SCANNER_CONFIG.ANCESTOR_DEPTH,
		DATA_ATTR_WHITELIST: SCANNER_CONFIG.DATA_ATTR_WHITELIST,

		// Orchestration
		MAX_CONCURRENT_WORKERS: SCANNER_CONFIG.MAX_CONCURRENT_WORKERS,
		LEASE_SECONDS: SCANNER_CONFIG.LEASE_SECONDS,
		HEARTBEAT_INTERVAL_MS: SCANNER_CONFIG.HEARTBEAT_INTERVAL_MS
	};

})();
