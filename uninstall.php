<?php
/**
 * Uninstall Plugin
 *
 * Fired when the plugin is uninstalled.
 *
 * @package ClearA11y
 */

// Exit if accessed directly or not uninstalling.
if (! defined('WP_UNINSTALL_PLUGIN') || WP_UNINSTALL_PLUGIN !== 'cleara11y/cleara11y.php') {
	exit;
}

// Load WordPress to access functions
require_once ABSPATH . 'wp-admin/includes/plugin.php';

// Clear scheduled cron events
wp_clear_scheduled_hook('cleara11y_cleanup_old_scans');
wp_clear_scheduled_hook('cleara11y_process_scheduled_scans');

// Drop all custom database tables
ClearA11y\Database\Schema::drop_tables();

// Delete all plugin options
$options = [
	'cleara11y_wcag_level',
	'cleara11y_scan_post_types',
	'cleara11y_results_retention_days',
	'cleara11y_enable_frontend_highlighting',
	'cleara11y_scan_permission',
	'cleara11y_scan_token_expiry',
	'cleara11y_batch_size',
	'cleara11y_db_version',
];

foreach ($options as $option) {
	delete_option($option);
}

// Clean up any scan tokens from options table
global $wpdb;
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	WHERE option_name LIKE 'cleara11y_scan_token_%'"
);
