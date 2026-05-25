<?php
/**
 * Plugin Name: ClearA11y
 * Plugin URI: https://github.com/caveim/cleara11y
 * Description: WordPress accessibility plugin that scans published content for WCAG 2.1 AA compliance issues using hybrid client-side (axe-core) and server-side (PHP) scanning.
 * Version: 1.6.1
 * Author: caveim
 * Author URI: https://github.com/caveim
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cleara11y
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 *
 * @package ClearA11y
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

// Plugin version constant.
define('CLEARA11Y_VERSION', '1.6.1');

// Plugin directory path constant.
define('CLEARA11Y_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Plugin directory URL constant.
define('CLEARA11Y_PLUGIN_URL', plugin_dir_url(__FILE__));

// Plugin base name constant.
define('CLEARA11Y_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Database version constant.
define('CLEARA11Y_DB_VERSION', '1.7.0');

/**
 * PSR-4 Autoloader
 *
 * @param string $class Class name.
 * @return void
 */
spl_autoload_register(function ($class) {
	// Only autoload classes in our namespace.
	if (strpos($class, 'ClearA11y\\') !== 0) {
		return;
	}

	// Convert namespace to file path.
	$class = str_replace('ClearA11y\\', '', $class);
	$class = str_replace('\\', '/', $class);
	$file = CLEARA11Y_PLUGIN_DIR . 'src/' . $class . '.php';

	// Load file if exists.
	if (file_exists($file)) {
		require_once $file;
	}
});

/**
 * Main plugin class.
 */
class ClearA11y_Plugin {

	/**
	 * Single instance of the plugin.
	 *
	 * @var ClearA11y_Plugin|null
	 */
	private static ?ClearA11y_Plugin $instance = null;

	/**
	 * Get the single instance of the plugin.
	 *
	 * @return ClearA11y_Plugin
	 */
	public static function get_instance(): ClearA11y_Plugin {
		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->setup_hooks();
	}

	/**
	 * Setup WordPress hooks.
	 */
	private function setup_hooks(): void {
		add_action('plugins_loaded', [$this, 'init']);
		register_activation_hook(__FILE__, [$this, 'activate']);
		register_deactivation_hook(__FILE__, [$this, 'deactivate']);

		// WP Cron hooks for background scanning
		add_action('cleara11y_process_scan_batch', [$this, 'process_scan_batch']);
		add_action('cleara11y_process_scheduled_scans', [$this, 'process_scheduled_scans']);
		add_action('cleara11y_cleanup_old_scans', [$this, 'cleanup_old_scans']);

		// Admin hooks
		add_action('admin_init', [$this, 'check_tables_exist']);
		add_action('admin_init', [$this, 'handle_manual_recreate_tables']);
		add_action('admin_init', [$this, 'run_database_migrations']);
		add_action('admin_post_cleara11y_migrate_db', [$this, 'handle_manual_migration']);
		// Test runner (development only)
		add_action('admin_init', [$this, 'run_test_if_requested']);
	}

	/**
	 * Check if tables exist, show notice if not.
	 */
	public function check_tables_exist(): void {
		if (!current_user_can('manage_options')) {
			return;
		}

		if (!\ClearA11y\Database\Schema::tables_exist()) {
			add_action('admin_notices', function() {
				printf(
					'<div class="notice notice-warning"><p><strong>%1$s:</strong> Database tables are missing. %2$s</p></div>',
					esc_html('ClearA11y'),
					sprintf(
						'<a href="%s" class="button button-primary">Create Tables Now</a>',
						esc_url(wp_nonce_url(admin_url('admin.php?page=cleara11y&cleara11y_recreate_tables=1'), 'cleara11y_recreate_tables'))
					)
				);
			});
		}
	}

	/**
	 * Handle manual table recreation via URL parameter.
	 * Usage: /wp-admin/admin.php?page=cleara11y&cleara11y_recreate_tables=1
	 */
	public function handle_manual_recreate_tables(): void {
		// Debug logging
		error_log('[ClearA11y] handle_manual_recreate_tables called');
		error_log('[ClearA11y] GET params: ' . print_r($_GET, true));

		if (!isset($_GET['cleara11y_recreate_tables']) || $_GET['cleara11y_recreate_tables'] !== '1') {
			error_log('[ClearA11y] cleara11y_recreate_tables param not set or not equal to 1');
			return;
		}

		if (!current_user_can('manage_options')) {
			error_log('[ClearA11y] User does not have manage_options capability');
			wp_die('You do not have permission to perform this action.');
		}

		// Debug nonce
		error_log('[ClearA11y] _wpnonce: ' . (isset($_GET['_wpnonce']) ? sanitize_text_field($_GET['_wpnonce']) : 'not set'));

		// Verify nonce
		if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'cleara11y_recreate_tables')) {
			error_log('[ClearA11y] Nonce verification failed');
			error_log('[ClearA11y] Expected nonce action: cleara11y_recreate_tables');
			wp_die('Security check failed.');
		}

		error_log('[ClearA11y] Nonce verified successfully, proceeding to recreate tables');

		// Recreate tables (drop and recreate fresh)
		$result = \ClearA11y\Database\Schema::recreate_tables();

		if ($result) {
			add_action('admin_notices', function() {
				echo '<div class="notice notice-success is-dismissible"><p>ClearA11y database tables have been reset and recreated successfully!</p></div>';
			});
		} else {
			add_action('admin_notices', function() {
				echo '<div class="notice notice-error"><p>Failed to create ClearA11y database tables. Please check error logs.</p></div>';
			});
		}
	}

	/**
	 * Run database migrations when needed.
	 */
	public function run_database_migrations(): void {
		if (!current_user_can('manage_options')) {
			return;
		}

		$current_db_version = get_option('cleara11y_db_version', '1.0.0');

		// Need to run migration if version is older than 1.1.0
		if (version_compare($current_db_version, '1.1.0', '<')) {
			$result = \ClearA11y\Database\Schema::add_evidence_columns();

			if ($result) {
				update_option('cleara11y_db_version', '1.1.0');
				add_action('admin_notices', function() {
					echo '<div class="notice notice-success is-dismissible"><p>ClearA11y: Evidence columns added to database successfully!</p></div>';
				});
			} else {
				add_action('admin_notices', function() {
					echo '<div class="notice notice-warning"><p><strong>ClearA11y:</strong> Database migration needed. <a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=cleara11y_migrate_db'), 'cleara11y_migrate_db')) . '" class="button button-primary">Run Migration Now</a></p></div>';
				});
			}
		}

		// Need to run migration if version is older than 1.4.0
		if (version_compare($current_db_version, '1.4.0', '<')) {
			$result = \ClearA11y\Database\Schema::add_global_dismiss_columns();

			if ($result) {
				update_option('cleara11y_db_version', '1.4.0');
				add_action('admin_notices', function() {
					echo '<div class="notice notice-success is-dismissible"><p>ClearA11y: Global ignore columns added to database successfully!</p></div>';
				});
			}
		}

		// Need to run migration if version is older than 1.5.0
		if (version_compare($current_db_version, '1.5.0', '<')) {
			$result = \ClearA11y\Database\Schema::add_scan_jobs_table();

			if ($result) {
				update_option('cleara11y_db_version', '1.5.0');
				add_action('admin_notices', function() {
					echo '<div class="notice notice-success is-dismissible"><p>ClearA11y: Scan jobs table added for parallel scanning support!</p></div>';
				});
			}
		}

		// Need to run migration if version is older than 1.6.0
		if (version_compare($current_db_version, '1.6.0', '<')) {
			$result = \ClearA11y\Database\Schema::add_scoring_columns();

			if ($result) {
				update_option('cleara11y_db_version', '1.6.0');

				// Recalculate scoring data for existing completed scans
				$recalc_result = \ClearA11y\Database\Schema::recalculate_scoring_data();

				add_action('admin_notices', function() use ($recalc_result) {
					echo '<div class="notice notice-success is-dismissible"><p>ClearA11y: Scoring columns added! ' . esc_html($recalc_result['message']) . '</p></div>';
				});
			}
		}

			// Need to run migration if version is older than 1.7.0
			if (version_compare($current_db_version, '1.7.0', '<')) {
			$result = \ClearA11y\Database\Ignore_Schema::create_tables();

			if ($result) {
				update_option('cleara11y_db_version', '1.7.0');
				add_action('admin_notices', function() {
					echo '<div class="notice notice-success is-dismissible"><p>ClearA11y: Ignore system tables added successfully!</p></div>';
				});
			}
			}
	}

	/**
	 * Handle manual database migration.
	 */
	public function handle_manual_migration(): void {
		if (!current_user_can('manage_options')) {
			wp_die('You do not have permission to perform this action.');
		}

		// Verify nonce
		if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'cleara11y_migrate_db')) {
			wp_die('Security check failed.');
		}

		// Run migration
		$result = \ClearA11y\Database\Schema::add_evidence_columns();

		if ($result) {
			update_option('cleara11y_db_version', '1.1.0');
			wp_redirect(admin_url('admin.php?page=cleara11y&migrated=1'));
			exit;
		} else {
			wp_redirect(admin_url('admin.php?page=cleara11y&migration_failed=1'));
			exit;
		}
	}

	/**
	 * Initialize the plugin.
	 */
	public function init(): void {
		// Check PHP and WordPress versions
		if (! $this->check_requirements()) {
			return;
		}

		// Load text domain for translations
		load_plugin_textdomain('cleara11y', false, dirname(CLEARA11Y_PLUGIN_BASENAME) . '/languages');

		// Initialize admin components
		if (is_admin()) {
			ClearA11y\Admin\Admin::get_instance();
			ClearA11y\Admin\Dashboard_Page::get_instance();
			ClearA11y\Admin\Metabox::get_instance();
			ClearA11y\Admin\Page_Report::get_instance();
			ClearA11y\Admin\Settings_Page::get_instance();

			// Register severity update utility AJAX handlers
			ClearA11y\Services\Severity_Update_Utility::register_ajax_handler();
		}

		// Initialize REST API
		new ClearA11y\API\REST_Controller();
			new ClearA11y\API\Ignore_REST_Controller();

		// Initialize frontend scanner (checks for scan tokens)
		new ClearA11y\Frontend\Scanner();

		// Initialize frontend highlighter (shows issues on pages)
		new ClearA11y\Frontend\Highlighter();

		do_action('cleara11y_loaded');
	}

	/**
	 * Check plugin requirements.
	 *
	 * @return bool True if requirements are met.
	 */
	private function check_requirements(): bool {
		$php_version = PHP_VERSION;
		$wp_version = get_bloginfo('version');

		if (version_compare($php_version, '8.0', '<')) {
			add_action('admin_notices', function() use ($php_version) {
				printf(
					'<div class="notice notice-error"><p><strong>%1$s</strong> requires PHP 8.0 or higher. You are running PHP %2$s.</p></div>',
					esc_html('ClearA11y'),
					esc_html($php_version)
				);
			});
			return false;
		}

		if (version_compare($wp_version, '6.0', '<')) {
			add_action('admin_notices', function() use ($wp_version) {
				printf(
					'<div class="notice notice-error"><p><strong>%1$s</strong> requires WordPress 6.0 or higher. You are running WordPress %2$s.</p></div>',
					esc_html('ClearA11y'),
					esc_html($wp_version)
				);
			});
			return false;
		}

		return true;
	}

	/**
	 * Plugin activation.
	 */
	public function activate(): void {
		// Check requirements first
		if (! $this->check_requirements()) {
			return;
		}

		// Create database tables
		ClearA11y\Database\Schema::create_tables();

		// Set default options
		$this->set_default_options();

		// Schedule WP Cron events
		$this->schedule_cron_events();

		// Flush rewrite rules
		flush_rewrite_rules();

		do_action('cleara11y_activated');
	}

	/**
	 * Plugin deactivation.
	 */
	public function deactivate(): void {
		// Clear scheduled WP Cron events
		$this->clear_cron_events();

		// Flush rewrite rules
		flush_rewrite_rules();

		do_action('cleara11y_deactivated');
	}

	/**
	 * Schedule WP Cron events.
	 */
	private function schedule_cron_events(): void {
		if (! wp_next_scheduled('cleara11y_cleanup_old_scans')) {
			wp_schedule_event(time(), 'daily', 'cleara11y_cleanup_old_scans');
		}

		if (! wp_next_scheduled('cleara11y_process_scheduled_scans')) {
			wp_schedule_event(time(), 'hourly', 'cleara11y_process_scheduled_scans');
		}
	}

	/**
	 * Clear scheduled WP Cron events.
	 */
	private function clear_cron_events(): void {
		wp_clear_scheduled_hook('cleara11y_cleanup_old_scans');
		wp_clear_scheduled_hook('cleara11y_process_scheduled_scans');
	}

	/**
	 * Process a batch of scan items (WP Cron callback).
	 *
	 * @param int $scan_id Scan ID.
	 */
	public function process_scan_batch(int $scan_id): void {
		ClearA11y\Services\Scan_Orchestrator::process_batch($scan_id);
	}

	/**
	 * Process scheduled scans that are due (WP Cron callback).
	 */
	public function process_scheduled_scans(): void {
		$due_schedules = ClearA11y\Database\Schedule_Repository::get_due();

		foreach ($due_schedules as $schedule) {
			if ($schedule->enabled) {
				$post_types = $schedule->get_post_types();
				ClearA11y\Services\Scan_Orchestrator::start_scheduled_scan(
					$schedule->schedule_name,
					$post_types
				);

				// Update schedule with next run time
				ClearA11y\Database\Schedule_Repository::update_after_run(
					$schedule->id,
					$schedule->last_scan_id
				);
			}
		}
	}

	/**
	 * Clean up old scan data (WP Cron callback).
	 */
	public function cleanup_old_scans(): void {
		$retention_days = (int) get_option('cleara11y_results_retention_days', 30);
		ClearA11y\Database\Scan_Repository::cleanup_old($retention_days);
	}

	/**
	 * Set default plugin options.
	 */
	private function set_default_options(): void {
		$defaults = [
			// Accessibility standard
			'cleara11y_wcag_level' => 'wcag21aa',

			// Post types to scan
			'cleara11y_scan_post_types' => ['page', 'post'],

			// Results retention (days)
			'cleara11y_results_retention_days' => 30,

			// Frontend highlighting (show issues on page)
			'cleara11y_enable_frontend_highlighting' => true,

			// Scan permission capability
			'cleara11y_scan_permission' => 'edit_posts',

			// Client-side scan token expiry (seconds)
			'cleara11y_scan_token_expiry' => 300, // 5 minutes

			// Bulk scan batch size
			'cleara11y_batch_size' => 20,
		];

		foreach ($defaults as $key => $value) {
			if (get_option($key) === false) {
				add_option($key, $value);
			}
		}
	}

		/**
		 * Run test if requested via URL parameter (development only).
		 */
		public function run_test_if_requested(): void {
			// Only run if specific parameter is set and user is admin
			if (!isset($_GET['cleara11y_test_quick_ignore']) || !current_user_can('manage_options')) {
				return;
			}

			// Require test file
			$test_file = CLEARA11Y_PLUGIN_DIR . 'tests/test-quick-ignore.php';
			if (file_exists($test_file)) {
				require_once $test_file;
				exit;
			}
		}
}

/**
 * Initialize the plugin.
 */
function cleara11y(): ClearA11y_Plugin {
	return ClearA11y_Plugin::get_instance();
}

// Start the plugin.
cleara11y();
