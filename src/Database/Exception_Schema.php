<?php
/**
 * Exception System Database Schema
 *
 * Handles creation and management of exception-related database tables.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Database
 */

namespace ClearA11y\Database;

/**
 * Exception Schema Class
 */
class Exception_Schema {

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
	 * Create exception system tables.
	 *
	 * @return bool True if successful.
	 */
	public static function create_tables(): bool {
		global $wpdb;

		if (! self::migrate_legacy_table_names()) {
			return false;
		}

		$charset_collate = $wpdb->get_charset_collate();
		$prefix = self::get_prefix();

		// 1. Exception Rules table - Structured exception rule definitions.
		$query = "CREATE TABLE IF NOT EXISTS `{$prefix}exception_rules` (
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
			`violation_identity_v2` varchar(64) DEFAULT NULL,
			`element_identity_v2` varchar(64) DEFAULT NULL,
			`identity_signature_version` int(11) DEFAULT NULL,
			`legacy_reanchor_status` varchar(20) NOT NULL DEFAULT 'pending',
			`legacy_reanchor_attempted_at` datetime DEFAULT NULL,
			PRIMARY KEY (`id`),
			KEY `site_id` (`site_id`),
			KEY `status` (`status`),
			KEY `target_type` (`target_type`),
			KEY `created_by` (`created_by`),
			KEY `expires_at` (`expires_at`),
			KEY `violation_identity_v2` (`violation_identity_v2`),
			KEY `element_identity_v2` (`element_identity_v2`),
			KEY `legacy_reanchor_status` (`legacy_reanchor_status`)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta($query);

		// 2. Exception Audit Log table - Immutable audit events.
		$query = "CREATE TABLE IF NOT EXISTS `{$prefix}exception_audit_log` (
			`id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`exception_rule_id` varchar(36) DEFAULT NULL,
			`event_type` varchar(50) NOT NULL,
			`actor_user_id` bigint(20) UNSIGNED DEFAULT NULL,
			`timestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`metadata` text DEFAULT NULL,
			PRIMARY KEY (`id`),
			KEY `exception_rule_id` (`exception_rule_id`),
			KEY `event_type` (`event_type`),
			KEY `timestamp` (`timestamp`)
		) $charset_collate;";

		dbDelta($query);

		// 3. Issue Exception Matches table - Junction table for resolved matches.
		$query = "CREATE TABLE IF NOT EXISTS `{$prefix}issue_exception_matches` (
			`id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`violation_id` bigint(20) UNSIGNED NOT NULL,
			`exception_rule_id` varchar(36) NOT NULL,
			`site_id` bigint(20) UNSIGNED NOT NULL,
			`matched_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`match_confidence` varchar(20) DEFAULT 'high',
			`match_action` varchar(20) NOT NULL DEFAULT 'suppressed',
			`rule_snapshot` longtext DEFAULT NULL,
			PRIMARY KEY (`id`),
			KEY `violation_id` (`violation_id`),
			KEY `exception_rule_id` (`exception_rule_id`),
			KEY `site_id` (`site_id`),
			UNIQUE KEY `issue_exception_unique` (`violation_id`, `exception_rule_id`)
		) $charset_collate;";

		dbDelta($query);

		update_option('cleara11y_exception_db_version', '3.0');

		return true;
	}

	/**
	 * Add fail-safe v2 suppression matching fields to existing installations.
	 *
	 * @return bool True when the additive migration succeeds.
	 */
	public static function add_v2_matching_columns(): bool {
		global $wpdb;

		$rules_table = self::get_table_name('exception_rules');
		$matches_table = self::get_table_name('issue_exception_matches');
		$columns = [
			$rules_table => [
				'violation_identity_v2' => 'varchar(64) DEFAULT NULL',
				'element_identity_v2' => 'varchar(64) DEFAULT NULL',
				'identity_signature_version' => 'int(11) DEFAULT NULL',
				'legacy_reanchor_status' => "varchar(20) NOT NULL DEFAULT 'pending'",
				'legacy_reanchor_attempted_at' => 'datetime DEFAULT NULL',
			],
			$matches_table => [
				'match_action' => "varchar(20) NOT NULL DEFAULT 'suppressed'",
				'rule_snapshot' => 'longtext DEFAULT NULL',
			],
		];

		foreach ($columns as $table => $table_columns) {
			foreach ($table_columns as $column => $definition) {
				if (self::column_exists($table, $column)) {
					continue;
				}

				if (false === $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}")) {
					error_log(
						sprintf(
							'ClearA11y ERROR: Failed adding v2 suppression field. table=%s column=%s database_error=%s',
							$table,
							$column,
							$wpdb->last_error
						)
					);
					return false;
				}
			}
		}

		$indexes = [
			'violation_identity_v2' => '`violation_identity_v2`',
			'element_identity_v2' => '`element_identity_v2`',
			'legacy_reanchor_status' => '`legacy_reanchor_status`',
		];
		foreach ($indexes as $index => $definition) {
			if (
				! self::index_exists($rules_table, $index)
				&& false === $wpdb->query("ALTER TABLE `{$rules_table}` ADD INDEX `{$index}` ({$definition})")
			) {
				error_log(
					sprintf(
						'ClearA11y ERROR: Failed adding v2 suppression index. index=%s database_error=%s',
						$index,
						$wpdb->last_error
					)
				);
				return false;
			}
		}

		$wpdb->query(
			"UPDATE `{$rules_table}`
			SET legacy_reanchor_status = 'not_required'
			WHERE target_type = 'rule' AND legacy_reanchor_status = 'pending'"
		);

		update_option('cleara11y_exception_db_version', '3.0');

		return true;
	}

	/**
	 * Check whether a table column exists.
	 *
	 * @param string $table Table name.
	 * @param string $column Column name.
	 * @return bool
	 */
	private static function column_exists(string $table, string $column): bool {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
				WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				$table,
				$column
			)
		);
	}

	/**
	 * Check whether a table index exists.
	 *
	 * @param string $table Table name.
	 * @param string $index Index name.
	 * @return bool
	 */
	private static function index_exists(string $table, string $index): bool {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
				WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
				$table,
				$index
			)
		);
	}

	/**
	 * Rename pre-launch ignore tables without changing or copying their data.
	 *
	 * The migration deliberately refuses to choose between two populated table
	 * sets. That state needs manual inspection; silently preferring either side
	 * could orphan review history.
	 *
	 * @return bool True when legacy names are absent or were renamed.
	 */
	private static function migrate_legacy_table_names(): bool {
		global $wpdb;

		$pairs = [
			'ignore_rules' => 'exception_rules',
			'ignore_audit_log' => 'exception_audit_log',
			'violation_ignore_matches' => 'issue_exception_matches',
		];

		foreach ($pairs as $legacy_suffix => $current_suffix) {
			$legacy = self::get_table_name($legacy_suffix);
			$current = self::get_table_name($current_suffix);
			$legacy_exists = $legacy === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $legacy));
			$current_exists = $current === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $current));

			if ($legacy_exists && $current_exists) {
				error_log(
					sprintf(
						'ClearA11y ERROR: Exception table migration stopped because both legacy and current tables exist. legacy=%s current=%s',
						$legacy,
						$current
					)
				);
				return false;
			}

			if ($legacy_exists && false === $wpdb->query("RENAME TABLE `{$legacy}` TO `{$current}`")) {
				error_log(
					sprintf(
						'ClearA11y ERROR: Failed to rename exception table. legacy=%s current=%s database_error=%s',
						$legacy,
						$current,
						$wpdb->last_error
					)
				);
				return false;
			}
		}

		$columns = [
			self::get_table_name('exception_audit_log'),
			self::get_table_name('issue_exception_matches'),
		];
		foreach ($columns as $table) {
			if (
				self::column_exists($table, 'ignore_rule_id')
				&& ! self::column_exists($table, 'exception_rule_id')
				&& false === $wpdb->query(
					"ALTER TABLE `{$table}` CHANGE `ignore_rule_id` `exception_rule_id` varchar(36) NOT NULL"
				)
			) {
				error_log(
					sprintf(
						'ClearA11y ERROR: Failed renaming legacy exception relation column. table=%s database_error=%s',
						$table,
						$wpdb->last_error
					)
				);
				return false;
			}
		}

		$legacy_version = get_option('cleara11y_ignore_db_version');
		if (false !== $legacy_version && false === get_option('cleara11y_exception_db_version')) {
			update_option('cleara11y_exception_db_version', $legacy_version);
			delete_option('cleara11y_ignore_db_version');
		}

		return true;
	}

	/**
	 * Check if exception tables exist.
	 *
	 * @return bool True if all tables exist.
	 */
	public static function tables_exist(): bool {
		global $wpdb;

		$prefix = self::get_prefix();
		$tables = [
			"{$prefix}exception_rules",
			"{$prefix}exception_audit_log",
			"{$prefix}issue_exception_matches",
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
	 * Drop exception system tables.
	 *
	 * @return bool True if successful.
	 */
	public static function drop_tables(): bool {
		global $wpdb;

		$prefix = self::get_prefix();
		$tables = [
			"{$prefix}exception_rules",
			"{$prefix}exception_audit_log",
			"{$prefix}issue_exception_matches",
		];

		foreach ($tables as $table) {
			$wpdb->query("DROP TABLE IF EXISTS `$table`");
		}

		delete_option('cleara11y_exception_db_version');

		return true;
	}
}
