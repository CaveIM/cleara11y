<?php
/**
 * Database Schema
 *
 * Handles creation and management of custom database tables.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Database
 */

namespace ClearA11y\Database;

/**
 * Schema Class
 */
class Schema {

	/**
	 * Get table prefix.
	 *
	 * @return string
	 */
	private static function get_prefix(): string {
		global $wpdb;
		return $wpdb->prefix . 'cleara11y_';
	}

	/**
	 * Get full table name.
	 *
	 * @param string $table Table name without prefix.
	 * @return string
	 */
	public static function get_table_name(string $table): string {
		return self::get_prefix() . $table;
	}

	/**
	 * Create all custom tables.
	 *
	 * @return bool True if successful.
	 */
	public static function create_tables(): bool {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$prefix = self::get_prefix();

		$queries = [];

		// 1. Scans table - Main scan records
		$queries[] = "CREATE TABLE IF NOT EXISTS `{$prefix}scans` (
			`id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`scan_type` varchar(20) NOT NULL DEFAULT 'individual',
			`scan_name` varchar(255) DEFAULT NULL,
			`status` varchar(20) NOT NULL DEFAULT 'pending',
			`total_items` int(11) NOT NULL DEFAULT 0,
			`scanned_items` int(11) NOT NULL DEFAULT 0,
			`total_issues` int(11) NOT NULL DEFAULT 0,
			`critical_issues` int(11) NOT NULL DEFAULT 0,
			`moderate_issues` int(11) NOT NULL DEFAULT 0,
			`minor_issues` int(11) NOT NULL DEFAULT 0,
			`started_at` datetime DEFAULT NULL,
			`completed_at` datetime DEFAULT NULL,
			`created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			KEY `scan_type` (`scan_type`),
			KEY `status` (`status`),
			KEY `created_at` (`created_at`)
		) $charset_collate;";

		// 2. Scan Items table - Individual page scans linked to scans
		$queries[] = "CREATE TABLE IF NOT EXISTS `{$prefix}scan_items` (
			`id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`scan_id` bigint(20) UNSIGNED NOT NULL,
			`post_id` bigint(20) UNSIGNED NOT NULL,
			`post_type` varchar(20) NOT NULL,
			`post_title` varchar(255) DEFAULT NULL,
			`post_url` varchar(255) NOT NULL,
			`status` varchar(20) NOT NULL DEFAULT 'pending',
			`scan_method` varchar(20) NOT NULL DEFAULT 'client',
			`total_issues` int(11) NOT NULL DEFAULT 0,
			`critical_issues` int(11) NOT NULL DEFAULT 0,
			`moderate_issues` int(11) NOT NULL DEFAULT 0,
			`minor_issues` int(11) NOT NULL DEFAULT 0,
			`error_message` text DEFAULT NULL,
			`scanned_at` datetime DEFAULT NULL,
			`created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			KEY `scan_id` (`scan_id`),
			KEY `post_id` (`post_id`),
			KEY `status` (`status`),
			KEY `scan_method` (`scan_method`),
			KEY `created_at` (`created_at`)
		) $charset_collate;";

		// 3. Issues table - Individual accessibility issues
		$queries[] = "CREATE TABLE IF NOT EXISTS `{$prefix}issues` (
			`id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`scan_id` bigint(20) UNSIGNED NOT NULL,
			`scan_item_id` bigint(20) UNSIGNED NOT NULL,
			`post_id` bigint(20) UNSIGNED NOT NULL,
			`rule_id` varchar(100) NOT NULL,
			`rule_type` varchar(20) NOT NULL,
			`severity` varchar(20) NOT NULL,
			`impact` varchar(20) DEFAULT NULL,
			`selector` varchar(500) DEFAULT NULL,
			`html` text DEFAULT NULL,
			`message` text DEFAULT NULL,
			`help_text` text DEFAULT NULL,
			`help_url` varchar(500) DEFAULT NULL,
			`wcag_criterion` varchar(100) DEFAULT NULL,
			`dismissed` tinyint(1) NOT NULL DEFAULT 0,
			`dismissed_by` bigint(20) UNSIGNED DEFAULT NULL,
			`dismissed_at` datetime DEFAULT NULL,
			`dismissal_comment` text DEFAULT NULL,
			`dismissed_global` tinyint(1) NOT NULL DEFAULT 0,
			`dismissed_global_by` bigint(20) UNSIGNED DEFAULT NULL,
			`dismissed_global_at` datetime DEFAULT NULL,
			`dismissed_global_comment` text DEFAULT NULL,
			`selector_score` int(11) DEFAULT NULL,
			`selector_match_count` int(11) DEFAULT NULL,
			`xpath` varchar(500) DEFAULT NULL,
			`dom_path` text DEFAULT NULL,
			`ancestor_chain` text DEFAULT NULL,
			`accessible_name` varchar(500) DEFAULT NULL,
			`inner_text_snippet` varchar(500) DEFAULT NULL,
			`bounding_box` varchar(100) DEFAULT NULL,
			`computed_style` text DEFAULT NULL,
			`fingerprint_strict` varchar(64) DEFAULT NULL,
			`fingerprint_loose` varchar(64) DEFAULT NULL,
			`signature_version` int(11) DEFAULT 1,
			`node_evidence` longtext DEFAULT NULL,
			`created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			KEY `scan_id` (`scan_id`),
			KEY `scan_item_id` (`scan_item_id`),
			KEY `post_id` (`post_id`),
			KEY `rule_id` (`rule_id`),
			KEY `severity` (`severity`),
			KEY `dismissed` (`dismissed`),
			KEY `dismissed_global` (`dismissed_global`),
			KEY `fingerprint_strict` (`fingerprint_strict`),
			KEY `fingerprint_loose` (`fingerprint_loose`),
			KEY `created_at` (`created_at`)
		) $charset_collate;";

		// 4. Schedules table - Scheduled scan configurations
		$queries[] = "CREATE TABLE IF NOT EXISTS `{$prefix}schedules` (
			`id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`schedule_name` varchar(100) NOT NULL,
			`frequency` varchar(20) NOT NULL DEFAULT 'weekly',
			`schedule_config` text DEFAULT NULL,
			`enabled` tinyint(1) NOT NULL DEFAULT 1,
			`last_scan_id` bigint(20) UNSIGNED DEFAULT NULL,
			`last_run` datetime DEFAULT NULL,
			`next_run` datetime DEFAULT NULL,
			`created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			KEY `schedule_name` (`schedule_name`),
			KEY `frequency` (`frequency`),
			KEY `enabled` (`enabled`),
			KEY `next_run` (`next_run`)
		) $charset_collate;";

		// 5. Scan Jobs table - Job queue with leasing for parallel scanning
		$queries[] = "CREATE TABLE IF NOT EXISTS `{$prefix}scan_jobs` (
			`id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`site_id` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			`url` text NOT NULL,
			`post_id` bigint(20) UNSIGNED NOT NULL,
			`scan_id` bigint(20) UNSIGNED NOT NULL,
			`status` varchar(20) NOT NULL DEFAULT 'pending',
			`priority` int(11) NOT NULL DEFAULT 0,
			`attempts` int(11) NOT NULL DEFAULT 0,
			`lease_token` varchar(64) DEFAULT NULL,
			`lease_expires_at` datetime DEFAULT NULL,
			`last_error` text DEFAULT NULL,
			`last_started_at` datetime DEFAULT NULL,
			`last_finished_at` datetime DEFAULT NULL,
			`result_json` longtext DEFAULT NULL,
			`created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			KEY `status_priority` (`status`, `priority`),
			KEY `lease_expires` (`lease_expires_at`),
			KEY `post_id` (`post_id`),
			KEY `scan_id` (`scan_id`),
			KEY `site_id` (`site_id`)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ($queries as $query) {
			dbDelta($query);
		}

		// Update database version
		update_option('cleara11y_db_version', CLEARA11Y_DB_VERSION);

		return true;
	}

	/**
	 * Drop all custom tables.
	 *
	 * @return bool True if successful.
	 */
	public static function drop_tables(): bool {
		global $wpdb;

		$prefix = self::get_prefix();
		$tables = [
			"{$prefix}scans",
			"{$prefix}scan_items",
			"{$prefix}issues",
			"{$prefix}schedules",
			"{$prefix}scan_jobs",
		];

		foreach ($tables as $table) {
			$wpdb->query("DROP TABLE IF EXISTS `$table`");
		}

		delete_option('cleara11y_db_version');

		return true;
	}

	/**
	 * Recreate all tables (for development/debugging).
	 *
	 * @return bool True if successful.
	 */
	public static function recreate_tables(): bool {
		self::drop_tables();
		return self::create_tables();
	}

	/**
	 * Check if tables exist.
	 *
	 * @return bool True if all tables exist.
	 */
	public static function tables_exist(): bool {
		global $wpdb;

		$prefix = self::get_prefix();
		$tables = [
			"{$prefix}scans",
			"{$prefix}scan_items",
			"{$prefix}issues",
			"{$prefix}schedules",
			"{$prefix}scan_jobs",
		];

		foreach ($tables as $table) {
			$result = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
			if ($result !== $table) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Add evidence columns to issues table (for existing installations).
	 *
	 * @return bool True if successful.
	 */
	public static function add_evidence_columns(): bool {
		global $wpdb;

		$table = self::get_table_name('issues');

		// Check if selector_score column exists (if yes, evidence columns already added)
		$column_exists = $wpdb->get_var(
			"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
			WHERE TABLE_SCHEMA = DATABASE()
			AND TABLE_NAME = '{$table}'
			AND COLUMN_NAME = 'selector_score'"
		);

		if ($column_exists) {
			return true; // Already migrated
		}

		// Add new columns
		$columns = [
			"selector_score INT(11) DEFAULT NULL",
			"selector_match_count INT(11) DEFAULT NULL",
			"xpath VARCHAR(500) DEFAULT NULL",
			"dom_path TEXT DEFAULT NULL",
			"ancestor_chain TEXT DEFAULT NULL",
			"accessible_name VARCHAR(500) DEFAULT NULL",
			"inner_text_snippet VARCHAR(500) DEFAULT NULL",
			"bounding_box VARCHAR(100) DEFAULT NULL",
			"computed_style TEXT DEFAULT NULL",
			"fingerprint_strict VARCHAR(64) DEFAULT NULL",
			"fingerprint_loose VARCHAR(64) DEFAULT NULL",
			"signature_version INT(11) DEFAULT 1",
			"node_evidence LONGTEXT DEFAULT NULL",
		];

		foreach ($columns as $column) {
			$result = $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN {$column}");
			if ($result === false) {
				error_log("ClearA11y: Failed to add column {$column} to {$table}");
				return false;
			}
		}

		// Add indexes
		$indexes = [
			"ADD INDEX fingerprint_strict (fingerprint_strict)",
			"ADD INDEX fingerprint_loose (fingerprint_loose)",
		];

		foreach ($indexes as $index) {
			// Check if index exists first
			$index_name = preg_match('/INDEX\s+(\w+)/', $index, $matches) ? $matches[1] : '';
			if ($index_name) {
				$index_exists = $wpdb->get_var(
					"SHOW INDEX FROM `{$table}` WHERE Key_name = '{$index_name}'"
				);
				if (!$index_exists) {
					$wpdb->query("ALTER TABLE `{$table}` {$index}");
				}
			}
		}

		return true;
	}

	/**
	 * Add global dismiss columns to issues table (for existing installations).
	 *
	 * @return bool True if successful.
	 */
	public static function add_global_dismiss_columns(): bool {
		global $wpdb;

		$table = self::get_table_name('issues');

		// Check if dismissed_global column exists
		$column_exists = $wpdb->get_var(
			"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
			WHERE TABLE_SCHEMA = DATABASE()
			AND TABLE_NAME = '{$table}'
			AND COLUMN_NAME = 'dismissed_global'"
		);

		if ($column_exists) {
			return true; // Already migrated
		}

		// Add new columns for global dismissal
		$columns = [
			"dismissed_global TINYINT(1) NOT NULL DEFAULT 0",
			"dismissed_global_by BIGINT(20) UNSIGNED DEFAULT NULL",
			"dismissed_global_at DATETIME DEFAULT NULL",
			"dismissed_global_comment TEXT DEFAULT NULL",
		];

		foreach ($columns as $column) {
			$result = $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN {$column}");
			if ($result === false) {
				error_log("ClearA11y: Failed to add column {$column} to {$table}");
				return false;
			}
		}

		// Add index for dismissed_global
		$wpdb->query("ALTER TABLE `{$table}` ADD INDEX dismissed_global (dismissed_global)");

		return true;
	}

	/**
	 * Add scan_jobs table for parallel scanning with job leasing.
	 *
	 * @return bool True if successful.
	 */
	public static function add_scan_jobs_table(): bool {
		global $wpdb;

		$table_name = self::get_table_name('scan_jobs');

		// Check if table already exists
		$table_exists = $wpdb->get_var(
			$wpdb->prepare("SHOW TABLES LIKE %s", $table_name)
		);

		if ($table_exists === $table_name) {
			return true; // Already exists
		}

		$charset_collate = $wpdb->get_charset_collate();

		$query = "CREATE TABLE `{$table_name}` (
			`id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`site_id` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			`url` text NOT NULL,
			`post_id` bigint(20) UNSIGNED NOT NULL,
			`scan_id` bigint(20) UNSIGNED NOT NULL,
			`status` varchar(20) NOT NULL DEFAULT 'pending',
			`priority` int(11) NOT NULL DEFAULT 0,
			`attempts` int(11) NOT NULL DEFAULT 0,
			`lease_token` varchar(64) DEFAULT NULL,
			`lease_expires_at` datetime DEFAULT NULL,
			`last_error` text DEFAULT NULL,
			`last_started_at` datetime DEFAULT NULL,
			`last_finished_at` datetime DEFAULT NULL,
			`result_json` longtext DEFAULT NULL,
			`created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			KEY `status_priority` (`status`, `priority`),
			KEY `lease_expires` (`lease_expires_at`),
			KEY `post_id` (`post_id`),
			KEY `scan_id` (`scan_id`),
			KEY `site_id` (`site_id`)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta($query);

		// Verify table was created
		$created = $wpdb->get_var(
			$wpdb->prepare("SHOW TABLES LIKE %s", $table_name)
		);

		return $created === $table_name;
	}
}
