<?php
/**
 * Scan Item Repository
 *
 * Handles database operations for Scan_Item records.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Database
 */

namespace ClearA11y\Database;

use ClearA11y\Models\Scan_Item;

/**
 * Scan Item Repository Class
 */
class Scan_Item_Repository {

	/**
	 * Get table name.
	 *
	 * @return string
	 */
	private static function get_table(): string {
		return Schema::get_table_name('scan_items');
	}

	/**
	 * Insert a new scan item.
	 *
	 * @param Scan_Item $item Scan Item object.
	 * @return int|false Inserted ID or false on failure.
	 */
	public static function insert(Scan_Item $item): int|false {
		global $wpdb;

		$data = [
			'scan_id' => $item->scan_id,
			'post_id' => $item->post_id,
			'post_type' => $item->post_type,
			'post_title' => $item->post_title,
			'post_url' => $item->post_url,
			'status' => $item->status,
			'scan_method' => $item->scan_method,
			'total_issues' => $item->total_issues,
			'critical_issues' => $item->critical_issues,
			'moderate_issues' => $item->moderate_issues,
			'minor_issues' => $item->minor_issues,
			'error_message' => $item->error_message,
			'scanned_at' => $item->scanned_at,
			'created_at' => $item->created_at ?? current_time('mysql'),
		];

		$result = $wpdb->insert(
			self::get_table(),
			$data,
			['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s']
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Update an existing scan item.
	 *
	 * @param Scan_Item $item Scan Item object.
	 * @return bool True on success, false on failure.
	 */
	public static function update(Scan_Item $item): bool {
		global $wpdb;

		$data = [
			'status' => $item->status,
			'scan_method' => $item->scan_method,
			'total_issues' => $item->total_issues,
			'critical_issues' => $item->critical_issues,
			'moderate_issues' => $item->moderate_issues,
			'minor_issues' => $item->minor_issues,
			'error_message' => $item->error_message,
			'scanned_at' => $item->scanned_at,
		];

		$result = $wpdb->update(
			self::get_table(),
			$data,
			['id' => $item->id],
			['%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s'],
			['%d']
		);

		return $result !== false;
	}

	/**
	 * Get scan item by ID.
	 *
	 * @param int $item_id Scan Item ID.
	 * @return Scan_Item|null
	 */
	public static function get_by_id(int $item_id): ?Scan_Item {
		global $wpdb;

		$table = self::get_table();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE id = %d",
				$item_id
			)
		);

		return $row ? Scan_Item::from_row($row) : null;
	}

	/**
	 * Get all scan items for a scan.
	 *
	 * @param int   $scan_id Scan ID.
	 * @param array $args    Optional query arguments.
	 * @return Scan_Item[]
	 */
	public static function get_by_scan_id(int $scan_id, array $args = []): array {
		global $wpdb;

		$defaults = [
			'status' => null,
			'orderby' => 'created_at',
			'order' => 'ASC',
		];

		$args = wp_parse_args($args, $defaults);

		$where = ['scan_id = %d'];
		$where_params = [$scan_id];

		if (!empty($args['status'])) {
			$where[] = 'status = %s';
			$where_params[] = $args['status'];
		}

		$where_clause = implode(' AND ', $where);
		$orderby = sanitize_sql_orderby("{$args['orderby']} {$args['order']}");

		$table = self::get_table();
		// @phpstan-ignore-next-line
		$query = $wpdb->prepare(
			"SELECT * FROM `{$table}` WHERE {$where_clause} ORDER BY {$orderby}",
			...$where_params
		);

		$rows = $wpdb->get_results($query);

		return array_map(fn($row) => Scan_Item::from_row($row), $rows ?: []);
	}

	/**
	 * Get scan item by scan ID and post ID.
	 *
	 * @param int $scan_id Scan ID.
	 * @param int $post_id Post ID.
	 * @return Scan_Item|null
	 */
	public static function get_by_scan_and_post(int $scan_id, int $post_id): ?Scan_Item {
		global $wpdb;

		$table = self::get_table();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE scan_id = %d AND post_id = %d",
				$scan_id,
				$post_id
			)
		);

		return $row ? Scan_Item::from_row($row) : null;
	}

	/**
	 * Delete scan item by ID.
	 *
	 * @param int $item_id Scan Item ID.
	 * @return bool True on success, false on failure.
	 */
	public static function delete(int $item_id): bool {
		global $wpdb;

		// Also delete related issues
		Issue_Repository::delete_by_scan_item_id($item_id);

		$result = $wpdb->delete(
			self::get_table(),
			['id' => $item_id],
			['%d']
		);

		return $result !== false;
	}

	/**
	 * Delete all scan items for a scan.
	 *
	 * @param int $scan_id Scan ID.
	 * @return int Number of items deleted.
	 */
	public static function delete_by_scan_id(int $scan_id): int {
		global $wpdb;

		// Get item IDs to delete their issues first
		$item_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM `" . self::get_table() . "` WHERE scan_id = %d",
				$scan_id
			)
		);

		foreach ($item_ids as $item_id) {
			Issue_Repository::delete_by_scan_item_id((int) $item_id);
		}

		return $wpdb->delete(
			self::get_table(),
			['scan_id' => $scan_id],
			['%d']
		);
	}

	/**
	 * Update scan item status.
	 *
	 * @param int    $item_id Scan Item ID.
	 * @param string $status  New status.
	 * @return bool True on success, false on failure.
	 */
	public static function update_status(int $item_id, string $status): bool {
		global $wpdb;

		$result = $wpdb->update(
			self::get_table(),
			['status' => $status],
			['id' => $item_id],
			['%s'],
			['%d']
		);

		return $result !== false;
	}

	/**
	 * Get count of scan items by status for a scan.
	 *
	 * @param int         $scan_id Scan ID.
	 * @param string|null $status  Status to filter by, or null for all.
	 * @return int
	 */
	public static function get_count(int $scan_id, ?string $status = null): int {
		global $wpdb;

		$where = ['scan_id = %d'];
		$params = [$scan_id];

		if ($status) {
			$where[] = 'status = %s';
			$params[] = $status;
		}

		$where_clause = implode(' AND ', $where);

		$table = self::get_table();
		// @phpstan-ignore-next-line
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE {$where_clause}",
				...$params
			)
		);

		return (int) $count;
	}

	/**
	 * Get pending scan items for batch processing.
	 *
	 * @param int $scan_id Scan ID.
	 * @param int $limit   Number of items to return.
	 * @return Scan_Item[]
	 */
	public static function get_pending_batch(int $scan_id, int $limit = 20): array {
		return self::get_by_scan_id(
			$scan_id,
			[
				'status' => 'pending',
				'orderby' => 'created_at',
				'order' => 'ASC',
				'limit' => $limit,
			]
		);
	}

	/**
	 * Create scan items for a scan from post IDs.
	 *
	 * @param int   $scan_id  Scan ID.
	 * @param array $post_ids Array of post IDs.
	 * @return bool True on success, false on failure.
	 */
	public static function create_from_posts(int $scan_id, array $post_ids): bool {
		foreach ($post_ids as $post_id) {
			$post = get_post($post_id);

			if (!$post || $post->post_status !== 'publish') {
				continue;
			}

			$item = new Scan_Item();
			$item->scan_id = $scan_id;
			$item->post_id = $post_id;
			$item->post_type = $post->post_type;
			$item->post_title = $post->post_title;
			$item->post_url = get_permalink($post_id);
			$item->status = 'pending';
			$item->scan_method = 'client';
			$item->created_at = current_time('mysql');

			self::insert($item);
		}

		return true;
	}
}
