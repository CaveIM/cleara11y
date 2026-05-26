<?php
/**
 * Frontend Scanner
 *
 * Detects scan tokens in URL and loads the client-side scanner.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Frontend
 */

namespace ClearA11y\Frontend;

/**
 * Frontend Scanner Class
 */
class Scanner {

	/**
	 * Query parameter for scan token.
	 *
	 * @var string
	 */
	private const TOKEN_PARAM = 'cleara11y_scan';

	/**
	 * Stored token data for use in enqueue_scripts.
	 *
	 * @var array|null
	 */
	private static ?array $token_data = null;
	private static ?string $token = null;
	private static ?bool $is_background = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action('template_redirect', [$this, 'detect_scan_token']);
		add_action('wp_enqueue_scripts', [$this, 'enqueue_scanner_scripts']);
		add_filter('show_admin_bar', [$this, 'hide_admin_bar_during_scan']);
	}

	/**
	 * Detect scan token in URL and initiate scanning.
	 *
	 * @return void
	 */
	public function detect_scan_token(): void {
		// Check if scan token is present
		if (!isset($_GET[self::TOKEN_PARAM])) {
			return;
		}

		$token = sanitize_text_field(wp_unslash($_GET[self::TOKEN_PARAM]));
		$is_background = isset($_GET['cleara11y_bg']) && $_GET['cleara11y_bg'] === '1';

		// Validate token
		$token_data = \ClearA11y\Services\Scan_Token_Manager::validate_token($token);

		if (!$token_data) {
			wp_die('Invalid or expired scan token.', 'Scan Error', 403);
		}

		// Debug: Log what we're scanning
		$current_post_id = get_the_ID();
		$current_url = home_url($_SERVER['REQUEST_URI']);

		// Log for debugging (only in background mode or if debug is enabled)
		if ($is_background || (defined('WP_DEBUG') && WP_DEBUG)) {
			error_log(sprintf(
				'ClearA11y: Scanning post_id=%d, token_post_id=%d, url=%s',
				$current_post_id,
				$token_data['post_id'],
				$current_url
			));
		}

		// Store token data for use in enqueue_scripts
		self::$token = $token;
		self::$token_data = $token_data;
		self::$is_background = $is_background;

		do_action('cleara11y_scan_token_detected', $token, $token_data);
	}

	/**
	 * Enqueue scanner scripts and styles.
	 *
	 * @return void
	 */
	public function enqueue_scanner_scripts(): void {
		// Only load if scan token is present
		if (!isset($_GET[self::TOKEN_PARAM]) || self::$token_data === null) {
			return;
		}

		$is_background = self::$is_background;
		$token = self::$token;
		$token_data = self::$token_data;

		// Load axe-core from local copy (v4.10.2)
		wp_enqueue_script(
			'axe-core',
			CLEARA11Y_PLUGIN_URL . 'assets/js/axe.min.js',
			[],
			'4.10.2',
			true
		);

		// Load scanner configuration first (constants used by other modules)
		wp_enqueue_script(
			'cleara11y-scanner-config',
			CLEARA11Y_PLUGIN_URL . 'assets/js/scanner-config.js',
			[],
			CLEARA11Y_VERSION,
			true
		);

		// Load shared scanner utilities (used by both scanner modes)
		wp_enqueue_script(
			'cleara11y-scanner-utils',
			CLEARA11Y_PLUGIN_URL . 'assets/js/scanner-utils.js',
			['cleara11y-scanner-config'],
			CLEARA11Y_VERSION,
			true
		);

		// Load evidence extractor (used by both scanner modes)
		wp_enqueue_script(
			'cleara11y-evidence-extractor',
			CLEARA11Y_PLUGIN_URL . 'assets/js/evidence-extractor.js',
			[],
			CLEARA11Y_VERSION,
			true
		);

		$rest_url = rest_url('cleara11y/v1/scan/results');

		if ($is_background) {
			// Background mode - load minimal scanner
			wp_enqueue_script(
				'cleara11y-scanner-bg',
				CLEARA11Y_PLUGIN_URL . 'assets/js/scanner-bg.js',
				['axe-core', 'cleara11y-scanner-config', 'cleara11y-scanner-utils', 'cleara11y-evidence-extractor'],
				CLEARA11Y_VERSION,
				true
			);

			// Localize script AFTER enqueuing
			wp_localize_script('cleara11y-scanner-bg', 'cleara11yScanData', [
				'token' => $token,
				'scanId' => $token_data['scan_id'],
				'scanItemId' => $token_data['scan_item_id'],
				'postId' => $token_data['post_id'],
				'restUrl' => $rest_url,
				'nonce' => wp_create_nonce('wp_rest'),
				'isBackground' => $is_background,
				'debug' => defined('WP_DEBUG') && WP_DEBUG,
			]);
		} else {
			// Regular mode - load full scanner with UI
			wp_enqueue_script(
				'cleara11y-scanner',
				CLEARA11Y_PLUGIN_URL . 'assets/js/scanner.js',
				['axe-core', 'cleara11y-scanner-config', 'cleara11y-scanner-utils', 'cleara11y-evidence-extractor'],
				CLEARA11Y_VERSION,
				true
			);

			// Localize script AFTER enqueuing
			wp_localize_script('cleara11y-scanner', 'cleara11yScanData', [
				'token' => $token,
				'scanId' => $token_data['scan_id'],
				'scanItemId' => $token_data['scan_item_id'],
				'postId' => $token_data['post_id'],
				'restUrl' => $rest_url,
				'nonce' => wp_create_nonce('wp_rest'),
				'isBackground' => $is_background,
				'debug' => defined('WP_DEBUG') && WP_DEBUG,
			]);

			// Enqueue scanner CSS (overlay and loading indicator)
			wp_enqueue_style(
				'cleara11y-scanner',
				CLEARA11Y_PLUGIN_URL . 'assets/css/scanner.css',
				[],
				CLEARA11Y_VERSION
			);
		}
	}

	/**
	 * Hide admin bar during scanning to simulate visitor experience.
	 *
	 * @param bool $show Whether to show admin bar.
	 * @return bool False during scan, original value otherwise.
	 */
	public function hide_admin_bar_during_scan(bool $show): bool {
		// Hide admin bar when scanning
		if (self::is_scanning()) {
			return false;
		}
		return $show;
	}

	/**
	 * Check if we're currently on a scan page.
	 *
	 * @return bool
	 */
	public static function is_scanning(): bool {
		return isset($_GET[self::TOKEN_PARAM]);
	}

	/**
	 * Get the current scan token.
	 *
	 * @return string|null
	 */
	public static function get_current_token(): ?string {
		if (!isset($_GET[self::TOKEN_PARAM])) {
			return null;
		}

		return sanitize_text_field(wp_unslash($_GET[self::TOKEN_PARAM]));
	}
}
