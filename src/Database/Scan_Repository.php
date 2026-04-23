<?php
/**
 * Scan Repository
 *
 * Handles database operations for Scan records.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Database
 */

namespace ClearA11y\Database;

use ClearA11y\Models\Scan;

// Force OPcache to reload this file
if (function_exists('opcache_invalidate')) {
	opcache_invalidate(__FILE__, true);
}

/**
 * Scan Repository Class
 */
class Scan_Repository {

	/**
	 * Get table name.
	 *
	 * @return string
	 */
	private static function get_table(): string {
		return Schema::get_table_name('scans');
	}

	/**
	 * Insert a new scan.
	 *
	 * @param Scan $scan Scan object.
	 * @return int|false Inserted ID or false on failure.
	 */
	public static function insert(Scan $scan): int|false {
		global $wpdb;

		$data = [
			'scan_type' => $scan->scan_type,
			'scan_name' => $scan->scan_name,
			'status' => $scan->status,
			'total_items' => $scan->total_items,
			'scanned_items' => $scan->scanned_items,
			'total_issues' => $scan->total_issues,
			'critical_issues' => $scan->critical_issues,
			'moderate_issues' => $scan->moderate_issues,
			'minor_issues' => $scan->minor_issues,
			'started_at' => $scan->started_at,
			'completed_at' => $scan->completed_at,
			'created_at' => $scan->created_at ?? current_time('mysql'),
		];

		$result = $wpdb->insert(
			self::get_table(),
			$data,
			['%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s']
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Update an existing scan.
	 *
	 * @param Scan $scan Scan object.
	 * @return bool True on success, false on failure.
	 */
	public static function update(Scan $scan): bool {
		global $wpdb;

		$data = [
			'scan_type' => $scan->scan_type,
			'scan_name' => $scan->scan_name,
			'status' => $scan->status,
			'total_items' => $scan->total_items,
			'scanned_items' => $scan->scanned_items,
			'total_issues' => $scan->total_issues,
			'critical_issues' => $scan->critical_issues,
			'moderate_issues' => $scan->moderate_issues,
			'minor_issues' => $scan->minor_issues,
			'started_at' => $scan->started_at,
			'completed_at' => $scan->completed_at,
		];

		$result = $wpdb->update(
			self::get_table(),
			$data,
			['id' => $scan->id],
			['%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s'],
			['%d']
		);

		return $result !== false;
	}

	/**
	 * Get scan by ID.
	 *
	 * @param int $scan_id Scan ID.
	 * @return Scan|null
	 */
	public static function get_by_id(int $scan_id): ?Scan {
		global $wpdb;

		$table = self::get_table();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE id = %d",
				$scan_id
			)
		);

		return $row ? Scan::from_row($row) : null;
	}

	/**
	 * Get all scans with optional filtering.
	 *
	 * @param array $args {
	 *     Optional. Query arguments.
	 *
	 *     @type string $status     Filter by status.
	 *     @type string $scan_type  Filter by scan type.
	 *     @type int    $limit      Number of scans to return.
	 *     @type int    $offset     Offset for pagination.
	 *     @type string $orderby    Column to order by.
	 *     @type string $order      Order direction (ASC, DESC).
	 * }
	 * @return Scan[]
	 */
	public static function get_all(array $args = []): array {
		global $wpdb;

		$defaults = [
			'status' => null,
			'scan_type' => null,
			'limit' => 20,
			'offset' => 0,
			'orderby' => 'created_at',
			'order' => 'DESC',
		];

		$args = wp_parse_args($args, $defaults);

		$where = ['1=1'];
		$where_params = [];

		if (!empty($args['status'])) {
			$where[] = 'status = %s';
			$where_params[] = $args['status'];
		}

		if (!empty($args['scan_type'])) {
			$where[] = 'scan_type = %s';
			$where_params[] = $args['scan_type'];
		}

		$where_clause = implode(' AND ', $where);
		$orderby = sanitize_sql_orderby("{$args['orderby']} {$args['order']}");
		$limit = absint($args['limit']);
		$offset = absint($args['offset']);

		$table = self::get_table();
		$query = "SELECT * FROM `{$table}` WHERE {$where_clause} ORDER BY {$orderby} LIMIT %d OFFSET %d";

		$where_params[] = $limit;
		$where_params[] = $offset;

		// @phpstan-ignore-next-line
		$query = $wpdb->prepare($query, ...$where_params);

		$rows = $wpdb->get_results($query);

		return array_map(fn($row) => Scan::from_row($row), $rows ?: []);
	}

	/**
	 * Delete scan by ID.
	 *
	 * @param int $scan_id Scan ID.
	 * @return bool True on success, false on failure.
	 */
	public static function delete(int $scan_id): bool {
		global $wpdb;

		// Also delete related scan items and issues
		Scan_Item_Repository::delete_by_scan_id($scan_id);
		Issue_Repository::delete_by_scan_id($scan_id);

		$result = $wpdb->delete(
			self::get_table(),
			['id' => $scan_id],
			['%d']
		);

		return $result !== false;
	}

	/**
	 * Update scan status.
	 *
	 * @param int    $scan_id Scan ID.
	 * @param string $status  New status.
	 * @return bool True on success, false on failure.
	 */
	public static function update_status(int $scan_id, string $status): bool {
		global $wpdb;

		$result = $wpdb->update(
			self::get_table(),
			['status' => $status],
			['id' => $scan_id],
			['%s'],
			['%d']
		);

		return $result !== false;
	}

	/**
	 * Increment scan progress counters.
	 *
	 * @param int $scan_id Scan ID.
	 * @param int $scanned_items Number of newly scanned items.
	 * @param int $total_issues Number of new issues found.
	 * @param int $critical_issues Number of new critical issues.
	 * @param int $moderate_issues Number of new moderate issues.
	 * @param int $minor_issues Number of new minor issues.
	 * @return bool True on success, false on failure.
	 */
	public static function increment_progress(
		int $scan_id,
		int $scanned_items = 1,
		int $total_issues = 0,
		int $critical_issues = 0,
		int $moderate_issues = 0,
		int $minor_issues = 0
	): bool {
		global $wpdb;

		$table = self::get_table();
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET
					scanned_items = scanned_items + %d,
					total_issues = total_issues + %d,
					critical_issues = critical_issues + %d,
					moderate_issues = moderate_issues + %d,
					minor_issues = minor_issues + %d
				WHERE id = %d",
				$scanned_items,
				$total_issues,
				$critical_issues,
				$moderate_issues,
				$minor_issues,
				$scan_id
			)
		);

		return $result !== false;
	}

	/**
	 * Get count of scans by status.
	 *
	 * @param string|null $status Status to filter by, or null for all.
	 * @return int
	 */
	public static function get_count(?string $status = null): int {
		global $wpdb;

		$where = $status ? $wpdb->prepare("WHERE status = %s", $status) : '';

		$count = $wpdb->get_var(
			"SELECT COUNT(*) FROM `" . self::get_table() . "` $where"
		);

		return (int) $count;
	}

	/**
	 * Get latest completed scan.
	 *
	 * @return Scan|null
	 */
	public static function get_latest_completed(): ?Scan {
		global $wpdb;

		$row = $wpdb->get_row(
			"SELECT * FROM `" . self::get_table() . "`
			WHERE status = 'completed'
			ORDER BY completed_at DESC
			LIMIT 1"
		);

		return $row ? Scan::from_row($row) : null;
	}

	/**
	 * Clean up old scans.
	 *
	 * @param int $days_to_keep Number of days to keep scans.
	 * @return int Number of scans deleted.
	 */
	public static function cleanup_old(int $days_to_keep = 30): int {
		global $wpdb;

		$cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days_to_keep} days"));

		// Get IDs to delete
		$scan_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM `" . self::get_table() . "`
				WHERE status = 'completed'
				AND completed_at < %s",
				$cutoff_date
			)
		);

		$count = 0;
		foreach ($scan_ids as $scan_id) {
			if (self::delete((int) $scan_id)) {
				$count++;
			}
		}

		return $count;
	}
}
