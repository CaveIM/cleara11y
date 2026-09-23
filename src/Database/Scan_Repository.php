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

if (! defined('ABSPATH')) {
	exit;
}

use ClearA11y\Models\Scan;

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
			'created_at' => $scan->created_at ?: current_time('mysql', true),
		];

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Write to plugin-owned tables; no WordPress data API or cached result applies.
		$result = $wpdb->insert(
			self::get_table(),
			$data,
			['%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s']
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write to plugin-owned tables; no WordPress data API or cached result applies.
		$result = $wpdb->update(
			self::get_table(),
			$data,
			['id' => $scan->id],
			['%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s'],
			['%d']
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

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
		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live scan/exception state in custom tables; caching can return stale worker or suppression state; Schema-owned identifiers and fixed SQL fragments; variable values are prepared separately.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE id = %d",
				$scan_id
			)
		);
		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

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
		$query = $wpdb->prepare($query, ...$where_params); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Reviewed: fixed SQL fragments and schema table names; variable values are prepared.

		$rows = $wpdb->get_results($query); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Live scan/exception state in custom tables; caching can return stale worker or suppression state.

		return array_map(fn($row) => Scan::from_row($row), $rows ?: []);
	}

	/**
	 * Get scans with filters for admin list views.
	 *
	 * @param array $args Query arguments.
	 * @return Scan[]
	 */
	public static function get_filtered(array $args = []): array {
		global $wpdb;

		$defaults = [
			'status' => '',
			'scan_type' => '',
			'search' => '',
			'date_from' => '',
			'date_to' => '',
			'limit' => 20,
			'offset' => 0,
			'orderby' => 'created_at',
			'order' => 'DESC',
		];
		$args = wp_parse_args($args, $defaults);

		[$where, $params] = self::build_filtered_where($args);
		$orderby = self::sanitize_scan_orderby((string) $args['orderby']);
		$order = strtoupper((string) $args['order']) === 'ASC' ? 'ASC' : 'DESC';
		$limit = max(1, absint($args['limit']));
		$offset = max(0, absint($args['offset']));
		$table = self::get_table();

		$params[] = $limit;
		$params[] = $offset;

		$query = "SELECT * FROM `{$table}` {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		// @phpstan-ignore-next-line
		$rows = $wpdb->get_results($wpdb->prepare($query, ...$params)); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Live scan/exception state in custom tables; caching can return stale worker or suppression state.

		return array_map(fn($row) => Scan::from_row($row), $rows ?: []);
	}

	/**
	 * Count scans matching admin list filters.
	 *
	 * @param array $args Query arguments.
	 * @return int
	 */
	public static function count_filtered(array $args = []): int {
		global $wpdb;

		[$where, $params] = self::build_filtered_where($args);
		$table = self::get_table();
		$query = "SELECT COUNT(*) FROM `{$table}` {$where}";

		if (!empty($params)) {
			// @phpstan-ignore-next-line
			$query = $wpdb->prepare($query, ...$params); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Reviewed: fixed SQL fragments and schema table names; variable values are prepared.
		}

		return (int) $wpdb->get_var($query); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Live scan/exception state in custom tables; caching can return stale worker or suppression state.
	}

	/**
	 * Build WHERE clause for filtered scan queries.
	 *
	 * @param array $args Query arguments.
	 * @return array{0:string,1:array}
	 */
	private static function build_filtered_where(array $args): array {
		global $wpdb;

		$where = ['1=1'];
		$params = [];
		$table = self::get_table();
		$scan_items_table = Schema::get_table_name('scan_items');

		if (!empty($args['status']) && in_array($args['status'], Scan::STATUSES, true)) {
			$where[] = 'status = %s';
			$params[] = $args['status'];
		}

		if (!empty($args['scan_type']) && in_array($args['scan_type'], Scan::SCAN_TYPES, true)) {
			$where[] = 'scan_type = %s';
			$params[] = $args['scan_type'];
		}

		if (!empty($args['date_from']) && false !== strtotime((string) $args['date_from'])) {
			$where[] = 'created_at >= %s';
			$params[] = gmdate('Y-m-d 00:00:00', (int) strtotime((string) $args['date_from']));
		}

		if (!empty($args['date_to']) && false !== strtotime((string) $args['date_to'])) {
			$where[] = 'created_at <= %s';
			$params[] = gmdate('Y-m-d 23:59:59', (int) strtotime((string) $args['date_to']));
		}

		if (!empty($args['search'])) {
			$like = '%' . $wpdb->esc_like((string) $args['search']) . '%';
			$where[] = "(scan_name LIKE %s OR EXISTS (
				SELECT 1 FROM `{$scan_items_table}` si
				WHERE si.scan_id = `{$table}`.id
				AND (si.post_title LIKE %s OR si.post_url LIKE %s)
			))";
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		return ['WHERE ' . implode(' AND ', $where), $params];
	}

	/**
	 * Sanitize scan list orderby field.
	 *
	 * @param string $orderby Requested orderby field.
	 * @return string Safe SQL column.
	 */
	private static function sanitize_scan_orderby(string $orderby): string {
		$allowed = [
			'id' => 'id',
			'scan_name' => 'scan_name',
			'scan_type' => 'scan_type',
			'status' => 'status',
			'total_items' => 'total_items',
			'scanned_items' => 'scanned_items',
			'total_issues' => 'total_issues',
			'created_at' => 'created_at',
			'completed_at' => 'completed_at',
		];

		return $allowed[$orderby] ?? 'created_at';
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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write to plugin-owned tables; no WordPress data API or cached result applies.
		$result = $wpdb->delete(
			self::get_table(),
			['id' => $scan_id],
			['%d']
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write to plugin-owned tables; no WordPress data API or cached result applies.
		$result = $wpdb->update(
			self::get_table(),
			['status' => $status],
			['id' => $scan_id],
			['%s'],
			['%d']
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

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
		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Write to plugin-owned tables; no WordPress data API or cached result applies; Schema-owned identifiers and fixed SQL fragments; variable values are prepared separately.
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
		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

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
		$table = self::get_table();

		$where = $status ? $wpdb->prepare("WHERE status = %s", $status) : '';

		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live scan/exception state in custom tables; caching can return stale worker or suppression state; Schema-owned identifiers and fixed SQL fragments; variable values are prepared separately.
		$count = $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$table}` $where"
		);
		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $count;
	}

	/**
	 * Get latest completed scan.
	 *
	 * @return Scan|null
	 */
	public static function get_latest_completed(): ?Scan {
		global $wpdb;
		$table = self::get_table();

		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live scan/exception state in custom tables; caching can return stale worker or suppression state; Schema-owned identifiers and fixed SQL fragments; variable values are prepared separately.
		$row = $wpdb->get_row(
			"SELECT * FROM `{$table}`
			WHERE status = 'completed'
			ORDER BY completed_at DESC
			LIMIT 1"
		);
		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? Scan::from_row($row) : null;
	}

	/**
	 * Get the scan that should drive dashboard totals.
	 *
	 * Prefer the newest active scan so in-progress dashboards do not show stale totals.
	 * Fall back to the latest completed scan when nothing is running.
	 *
	 * @return Scan|null
	 */
	public static function get_latest_active_or_completed(): ?Scan {
		global $wpdb;

		$table = self::get_table();
		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live scan/exception state in custom tables; caching can return stale worker or suppression state; Schema-owned identifiers and fixed SQL fragments; variable values are prepared separately.
		$row = $wpdb->get_row(
			"SELECT * FROM `{$table}`
			WHERE status IN ('in_progress', 'pending')
			ORDER BY FIELD(status, 'in_progress', 'pending'), COALESCE(started_at, updated_at, created_at) DESC, id DESC
			LIMIT 1"
		);
		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ($row) {
			return Scan::from_row($row);
		}

		return self::get_latest_completed();
	}

	/**
	 * Clean up old scans.
	 *
	 * @param int $days_to_keep Number of days to keep scans.
	 * @return int Number of scans deleted.
	 */
	public static function cleanup_old(int $days_to_keep = 30): int {
		global $wpdb;
		$table = self::get_table();

		$cutoff_date = gmdate('Y-m-d H:i:s', strtotime("-{$days_to_keep} days"));

		// Get IDs to delete
		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live scan/exception state in custom tables; caching can return stale worker or suppression state; Schema-owned identifiers and fixed SQL fragments; variable values are prepared separately.
		$scan_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM `{$table}`
				WHERE status = 'completed'
				AND completed_at < %s",
				$cutoff_date
			)
		);
		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$count = 0;
		foreach ($scan_ids as $scan_id) {
			if (self::delete((int) $scan_id)) {
				$count++;
			}
		}

		return $count;
	}
}
