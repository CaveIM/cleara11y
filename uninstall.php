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

// Inactive plugins do not have their autoloader registered during uninstall.
require_once __DIR__ . '/src/Database/Schema.php';
require_once __DIR__ . '/src/Database/Exception_Schema.php';

// Clear scheduled cron events
wp_clear_scheduled_hook('cleara11y_cleanup_old_scans');
wp_clear_scheduled_hook('cleara11y_automated_scan');
wp_clear_scheduled_hook('cleara11y_process_scan_batch');

// Drop all custom database tables
ClearA11y\Database\Schema::drop_tables();
ClearA11y\Database\Exception_Schema::drop_tables();

// Delete all plugin options
$cleara11y_options = [
	'cleara11y_wcag_level',
	'cleara11y_scan_post_types',
	'cleara11y_results_retention_days',
	'cleara11y_enable_frontend_highlighting',
	'cleara11y_scan_permission',
	'cleara11y_scan_token_expiry',
	'cleara11y_batch_size',
	'cleara11y_db_version',
	'cleara11y_automated_enabled',
	'cleara11y_automated_frequency',
];

foreach ($cleara11y_options as $cleara11y_option) {
	delete_option($cleara11y_option);
}

// Clean up any scan tokens from options table
global $wpdb;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prefix-based token cleanup has no bulk options API; do not cache expiring tokens.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like('cleara11y_scan_token_') . '%'
	)
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

// Remove user display preferences only when no other site can share them.
if (! is_multisite()) {
	delete_metadata('user', 0, 'cleara11y_panel_settings', '', true);
	delete_metadata('user', 0, 'cleara11y_issue_view_options', '', true);
	delete_metadata('user', 0, 'cleara11y_issues_per_page', '', true);
}
delete_metadata('user', 0, $wpdb->prefix . 'cleara11y_issues_per_page', '', true);
