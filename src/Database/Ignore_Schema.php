<?php
/**
 * Ignore System Database Schema
 *
 * Handles creation and management of ignore-related database tables.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Database
 */

namespace ClearA11y\Database;

/**
 * Ignore Schema Class
 */
class Ignore_Schema {

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
	 * Create ignore system tables.
	 *
	 * @return bool True if successful.
	 */
	public static function create_tables(): bool {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$prefix = self::get_prefix();

		// 1. Ignore Rules table - Structured ignore rule definitions
		$query = "CREATE TABLE IF NOT EXISTS `{$prefix}ignore_rules` (
			`id` varchar(36) NOT NULL,
			`site_id` bigint(20) UNSIGNED NOT NULL,
			`status` varchar(20) NOT NULL DEFAULT 'active',
			`target_type` varchar(50) NOT NULL,
			`rule_ids` text DEFAULT NULL,
			`element_match` text DEFAULT NULL,
			`scope` text NOT NULL,
			`duration` text NOT NULL,
			`reason_category` varchar(50) DEFAULT NULL,
			`note` text DEFAULT NULL,
			`system_generated` tinyint(1) NOT NULL DEFAULT 0,
			`created_by` bigint(20) UNSIGNED DEFAULT NULL,
			`created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
			`expires_at` datetime DEFAULT NULL,
			`match_count` int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY (`id`),
			KEY `site_id` (`site_id`),
			KEY `status` (`status`),
			KEY `target_type` (`target_type`),
			KEY `created_by` (`created_by`),
			KEY `expires_at` (`expires_at`)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta($query);

		// 2. Ignore Audit Log table - Immutable audit events
		$query = "CREATE TABLE IF NOT EXISTS `{$prefix}ignore_audit_log` (
			`id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`ignore_rule_id` varchar(36) DEFAULT NULL,
			`event_type` varchar(50) NOT NULL,
			`actor_user_id` bigint(20) UNSIGNED DEFAULT NULL,
			`timestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`metadata` text DEFAULT NULL,
			PRIMARY KEY (`id`),
			KEY `ignore_rule_id` (`ignore_rule_id`),
			KEY `event_type` (`event_type`),
			KEY `timestamp` (`timestamp`)
		) $charset_collate;";

		dbDelta($query);

		// 3. Violation Ignore Matches table - Junction table for resolved matches
		$query = "CREATE TABLE IF NOT EXISTS `{$prefix}violation_ignore_matches` (
			`id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`violation_id` bigint(20) UNSIGNED NOT NULL,
			`ignore_rule_id` varchar(36) NOT NULL,
			`site_id` bigint(20) UNSIGNED NOT NULL,
			`matched_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`match_confidence` varchar(20) DEFAULT 'high',
			PRIMARY KEY (`id`),
			KEY `violation_id` (`violation_id`),
			KEY `ignore_rule_id` (`ignore_rule_id`),
			KEY `site_id` (`site_id`),
			UNIQUE KEY `violation_ignore_unique` (`violation_id`, `ignore_rule_id`)
		) $charset_collate;";

		dbDelta($query);

		update_option('cleara11y_ignore_db_version', '1.0');

		return true;
	}

	/**
	 * Check if ignore tables exist.
	 *
	 * @return bool True if all tables exist.
	 */
	public static function tables_exist(): bool {
		global $wpdb;

		$prefix = self::get_prefix();
		$tables = [
			"{$prefix}ignore_rules",
			"{$prefix}ignore_audit_log",
			"{$prefix}violation_ignore_matches",
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
	 * Drop ignore system tables.
	 *
	 * @return bool True if successful.
	 */
	public static function drop_tables(): bool {
		global $wpdb;

		$prefix = self::get_prefix();
		$tables = [
			"{$prefix}ignore_rules",
			"{$prefix}ignore_audit_log",
			"{$prefix}violation_ignore_matches",
		];

		foreach ($tables as $table) {
			$wpdb->query("DROP TABLE IF EXISTS `$table`");
		}

		delete_option('cleara11y_ignore_db_version');

		return true;
	}
}
