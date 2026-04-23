<?php
/**
 * Issue Repository
 *
 * Handles database operations for Issue records.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Database
 */

namespace ClearA11y\Database;

use ClearA11y\Models\Issue;
use ClearA11y\Database\Scan_Item_Repository;

/**
 * Issue Repository Class
 */
class Issue_Repository {

	/**
	 * Get table name.
	 *
	 * @return string
	 */
	private static function get_table(): string {
		return Schema::get_table_name('issues');
	}

	/**
	 * Insert a new issue.
	 *
	 * @param Issue $issue Issue object.
	 * @return int|false Inserted ID or false on failure.
	 */
	public static function insert(Issue $issue): int|false {
		global $wpdb;

		$data = [
			'scan_id' => $issue->scan_id,
			'scan_item_id' => $issue->scan_item_id,
			'post_id' => $issue->post_id,
			'rule_id' => $issue->rule_id,
			'rule_type' => $issue->rule_type,
			'severity' => $issue->severity,
			'impact' => $issue->impact,
			'selector' => $issue->selector,
			'html' => $issue->html,
			'message' => $issue->message,
			'help_text' => $issue->help_text,
			'help_url' => $issue->help_url,
			'wcag_criterion' => $issue->wcag_criterion,
			'dismissed' => $issue->dismissed ? 1 : 0,
			'dismissed_by' => $issue->dismissed_by,
			'dismissed_at' => $issue->dismissed_at,
			'dismissal_comment' => $issue->dismissal_comment,
			'created_at' => $issue->created_at ?? current_time('mysql'),
			// Evidence fields
			'selector_score' => $issue->selector_score,
			'selector_match_count' => $issue->selector_match_count,
			'xpath' => $issue->xpath,
			'dom_path' => $issue->dom_path,
			'ancestor_chain' => $issue->ancestor_chain,
			'accessible_name' => $issue->accessible_name,
			'inner_text_snippet' => $issue->inner_text_snippet,
			'bounding_box' => $issue->bounding_box,
			'computed_style' => $issue->computed_style,
			'fingerprint_strict' => $issue->fingerprint_strict,
			'fingerprint_loose' => $issue->fingerprint_loose,
			'signature_version' => $issue->signature_version,
			'node_evidence' => $issue->node_evidence,
		];

		$format = [
			'%d', '%d', '%d', // scan_id, scan_item_id, post_id
			'%s', '%s', '%s', // rule_id, rule_type, severity
			'%s', // impact
			'%s', '%s', '%s', '%s', '%s', '%s', // selector, html, message, help_text, help_url, wcag_criterion
			'%d', '%d', '%s', '%s', // dismissed, dismissed_by, dismissed_at, dismissal_comment
			'%s', // created_at
			// Evidence fields
			'%d', '%d', // selector_score, selector_match_count
			'%s', '%s', '%s', // xpath, dom_path, ancestor_chain
			'%s', '%s', // accessible_name, inner_text_snippet
			'%s', '%s', // bounding_box, computed_style
			'%s', '%s', // fingerprint_strict, fingerprint_loose
			'%d', '%s', // signature_version, node_evidence
		];

		$result = $wpdb->insert(
			self::get_table(),
			$data,
			$format
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Insert multiple issues in a single query.
	 *
	 * @param Issue[] $issues Array of Issue objects.
	 * @return int Number of issues inserted.
	 */
	public static function insert_batch(array $issues): int {
		global $wpdb;

		if (empty($issues)) {
			return 0;
		}

		$count = 0;
		foreach ($issues as $issue) {
			if (self::insert($issue)) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Update an existing issue.
	 *
	 * @param Issue $issue Issue object.
	 * @return bool True on success, false on failure.
	 */
	public static function update(Issue $issue): bool {
		global $wpdb;

		$data = [
			'dismissed' => $issue->dismissed ? 1 : 0,
			'dismissed_by' => $issue->dismissed_by,
			'dismissed_at' => $issue->dismissed_at,
			'dismissal_comment' => $issue->dismissal_comment,
		];

		$result = $wpdb->update(
			self::get_table(),
			$data,
			['id' => $issue->id],
			['%d', '%d', '%s', '%s'],
			['%d']
		);

		return $result !== false;
	}

	/**
	 * Get issue by ID.
	 *
	 * @param int $issue_id Issue ID.
	 * @return Issue|null
	 */
	public static function get_by_id(int $issue_id): ?Issue {
		global $wpdb;

		$table = self::get_table();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE id = %d",
				$issue_id
			)
		);

		return $row ? Issue::from_row($row) : null;
	}

	/**
	 * Get all issues for a scan.
	 *
	 * @param int   $scan_id Scan ID.
	 * @param array $args    Optional query arguments.
	 * @return Issue[]
	 */
	public static function get_by_scan_id(int $scan_id, array $args = []): array {
		global $wpdb;

		$defaults = [
			'severity' => null,
			'dismissed' => null,
			'orderby' => 'created_at',
			'order' => 'DESC',
		];

		$args = wp_parse_args($args, $defaults);

		$where = ['scan_id = %d'];
		$where_params = [$scan_id];

		if (!empty($args['severity'])) {
			$where[] = 'severity = %s';
			$where_params[] = $args['severity'];
		}

		if (null !== $args['dismissed']) {
			$where[] = 'dismissed = %d';
			$where_params[] = $args['dismissed'] ? 1 : 0;
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

		return array_map(fn($row) => Issue::from_row($row), $rows ?: []);
	}

	/**
	 * Get all issues for a scan item.
	 *
	 * @param int $scan_item_id Scan Item ID.
	 * @return Issue[]
	 */
	public static function get_by_scan_item_id(int $scan_item_id): array {
		global $wpdb;

		$table = self::get_table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE scan_item_id = %d AND dismissed = 0 AND dismissed_global = 0 ORDER BY severity DESC, id ASC",
				$scan_item_id
			)
		);

		return array_map(fn($row) => Issue::from_row($row), $rows ?: []);
	}

	/**
	 * Get all issues for a post (from latest scan only).
	 *
	 * @param int $post_id Post ID.
	 * @return Issue[]
	 */
	public static function get_by_post_id(int $post_id): array {
		global $wpdb;

		$table = self::get_table();
		$scans_table = Schema::get_table_name('scans');

		// Get the latest completed scan_item for this post
		// Order by scanned_at (when scan completed) not created_at (when scan started)
		$scan_items_table = Schema::get_table_name('scan_items');

		$latest_scan_item = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT si.id, si.scan_id FROM `{$scan_items_table}` si
				INNER JOIN `{$scans_table}` s ON si.scan_id = s.id
				WHERE si.post_id = %d AND s.status = 'completed' AND si.status = 'completed'
				ORDER BY si.scanned_at DESC, si.id DESC
				LIMIT 1",
				$post_id
			)
		);

		if (!$latest_scan_item) {
			return [];
		}

		// Get issues only from the latest scan_item
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE scan_item_id = %d AND post_id = %d AND dismissed = 0 AND dismissed_global = 0 ORDER BY severity DESC, id ASC",
				$latest_scan_item->id,
				$post_id
			)
		);

		return array_map(fn($row) => Issue::from_row($row), $rows ?: []);
	}

	/**
	 * Delete issue by ID.
	 *
	 * @param int $issue_id Issue ID.
	 * @return bool True on success, false on failure.
	 */
	public static function delete(int $issue_id): bool {
		global $wpdb;

		$result = $wpdb->delete(
			self::get_table(),
			['id' => $issue_id],
			['%d']
		);

		return $result !== false;
	}

	/**
	 * Delete all issues for a scan.
	 *
	 * @param int $scan_id Scan ID.
	 * @return int Number of issues deleted.
	 */
	public static function delete_by_scan_id(int $scan_id): int {
		global $wpdb;

		return $wpdb->delete(
			self::get_table(),
			['scan_id' => $scan_id],
			['%d']
		);
	}

	/**
	 * Delete all issues for a scan item.
	 *
	 * @param int $scan_item_id Scan Item ID.
	 * @return int Number of issues deleted.
	 */
	public static function delete_by_scan_item_id(int $scan_item_id): int {
		global $wpdb;

		return $wpdb->delete(
			self::get_table(),
			['scan_item_id' => $scan_item_id],
			['%d']
		);
	}

	/**
	 * Dismiss an issue.
	 *
	 * @param int      $issue_id Issue ID.
	 * @param int|null $user_id  User ID dismissing the issue.
	 * @param string   $comment  Dismissal comment.
	 * @return bool True on success, false on failure.
	 */
	public static function dismiss(int $issue_id, ?int $user_id = null, string $comment = ''): bool {
		global $wpdb;

		$result = $wpdb->update(
			self::get_table(),
			[
				'dismissed' => 1,
				'dismissed_by' => $user_id,
				'dismissed_at' => current_time('mysql'),
				'dismissal_comment' => $comment,
			],
			['id' => $issue_id],
			['%d', '%d', '%s', '%s'],
			['%d']
		);

		return $result !== false;
	}

	/**
	 * Undismiss an issue.
	 *
	 * @param int $issue_id Issue ID.
	 * @return bool True on success, false on failure.
	 */
	public static function undismiss(int $issue_id): bool {
		global $wpdb;

		$result = $wpdb->update(
			self::get_table(),
			[
				'dismissed' => 0,
				'dismissed_by' => null,
				'dismissed_at' => null,
				'dismissal_comment' => null,
			],
			['id' => $issue_id],
			['%d', '%d', '%s', '%s'],
			['%d']
		);

		return $result !== false;
	}

	/**
	 * Get issue counts grouped by severity for a scan.
	 *
	 * @param int $scan_id Scan ID.
	 * @return array Array with counts for critical, moderate, minor.
	 */
	public static function get_severity_counts(int $scan_id): array {
		global $wpdb;

		$table = self::get_table();
		$counts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT severity, COUNT(*) as count FROM `{$table}`
				WHERE scan_id = %d AND dismissed = 0 AND dismissed_global = 0
				GROUP BY severity",
				$scan_id
			),
			ARRAY_A
		);

		$result = [
			'critical' => 0,
			'moderate' => 0,
			'minor' => 0,
		];

		foreach ($counts as $row) {
			$result[$row['severity']] = (int) $row['count'];
		}

		return $result;
	}

	/**
	 * Get issue counts grouped by rule for a scan item.
	 *
	 * @param int $scan_item_id Scan Item ID.
	 * @return array Array with rule_id as key and count as value.
	 */
	public static function get_rule_counts(int $scan_item_id): array {
		global $wpdb;

		$table = self::get_table();
		$counts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT rule_id, COUNT(*) as count FROM `{$table}`
				WHERE scan_item_id = %d AND dismissed = 0 AND dismissed_global = 0
				GROUP BY rule_id",
				$scan_item_id
			),
			ARRAY_A
		);

		$result = [];
		foreach ($counts as $row) {
			$result[$row['rule_id']] = (int) $row['count'];
		}

		return $result;
	}

	/**
	 * Get total issue count for a post (from latest scan_item only).
	 *
	 * This function counts issues for a single scan_item only (the latest one),
	 * preventing duplicate counting when a post has been scanned multiple times.
	 *
	 * @param int $post_id Post ID.
	 * @return array Array with total and severity breakdown.
	 */
	public static function get_post_issue_counts(int $post_id): array {
		global $wpdb;

		$table = self::get_table();
		$scan_items_table = Schema::get_table_name('scan_items');
		$scans_table = Schema::get_table_name('scans');


		// Get the latest scan_item for this post that has completed
		// Order by scanned_at (when scan completed) not created_at (when scan started)
		// This ensures we get the most recent COMPLETED scan results
		$latest_scan_item = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT si.id FROM `{$scan_items_table}` si
				INNER JOIN `{$scans_table}` s ON si.scan_id = s.id
				WHERE si.post_id = %d AND s.status = 'completed' AND si.status = 'completed'
				ORDER BY si.scanned_at DESC, si.id DESC
				LIMIT 1",
				$post_id
			)
		);

		if (!$latest_scan_item) {
			return [
				'total' => 0,
				'critical' => 0,
				'moderate' => 0,
				'minor' => 0,
			];
		}


		// Get counts grouped by severity for this specific scan_item
		// Using scan_item_id ensures we only count issues from ONE scan,
		// preventing doubling when multiple scans exist for the same post
		$counts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT severity, COUNT(*) as count FROM `{$table}`
				WHERE scan_item_id = %d AND dismissed = 0 AND dismissed_global = 0
				GROUP BY severity",
				$latest_scan_item->id
			),
			ARRAY_A
		);

		$result = [
			'total' => 0,
			'critical' => 0,
			'moderate' => 0,
			'minor' => 0,
		];

		foreach ($counts as $row) {
			$result[$row['severity']] = (int) $row['count'];
			$result['total'] += (int) $row['count'];
		}


		return $result;
	}
}
