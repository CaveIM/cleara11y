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
			'result_type' => $issue->result_type,
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
			'source_type' => $issue->source_type,
			'source_ref' => $issue->source_ref,
			'owner_type' => $issue->owner_type,
			'owner_name' => $issue->owner_name,
			'source_key' => $issue->source_key,
			'page_object_key' => $issue->page_object_key,
			'element_identity_v2' => $issue->element_identity_v2,
			'element_identity_v2_inputs' => $issue->element_identity_v2_inputs,
			'violation_identity_v2' => $issue->violation_identity_v2,
			'violation_identity_v2_inputs' => $issue->violation_identity_v2_inputs,
			'identity_signature_version' => $issue->identity_signature_version,
		];

		$format = [
			'%d', '%d', '%d', // scan_id, scan_item_id, post_id
			'%s', '%s', '%s', '%s', // rule_id, rule_type, result_type, severity
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
			'%s', '%s', '%s', '%s', '%s', // Source attribution
			'%s', '%s', '%s', '%s', '%s', '%d', // Version 2 identity
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
				"SELECT * FROM `{$table}` WHERE scan_item_id = %d ORDER BY severity DESC, id ASC",
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
				"SELECT * FROM `{$table}` WHERE scan_item_id = %d AND post_id = %d ORDER BY severity DESC, id ASC",
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
				WHERE scan_id = %d
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
				WHERE scan_item_id = %d
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
				WHERE scan_item_id = %d
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

	/**
	 * Query issue occurrences for the global explorer.
	 *
	 * Live mode is a projection over the latest successfully completed scan item
	 * for each post. Scan mode returns the immutable observations for one scan.
	 *
	 * @param array $args Validated explorer arguments.
	 * @return array Explorer result data.
	 */
	public static function query_explorer(array $args = []): array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			[
				'status' => 'active',
				'severity' => '',
				'rule_id' => '',
				'post_id' => 0,
				'scan_id' => 0,
				'search' => '',
				'group_by' => 'page',
				'sort' => 'severity',
				'page' => 1,
				'per_page' => 20,
			]
		);

		$issues_table = self::get_table();
		$items_table = Schema::get_table_name('scan_items');
		$scans_table = Schema::get_table_name('scans');
		$matches_table = Ignore_Schema::get_table_name('violation_ignore_matches');
		$rules_table = Ignore_Schema::get_table_name('ignore_rules');
		$occurrence_table = Occurrence_Repository::get_table();
		$has_occurrence_state = Occurrence_Repository::table_exists();

		$active_ignores = "
			LEFT JOIN (
				SELECT DISTINCT vm.violation_id
				FROM `{$matches_table}` vm
				INNER JOIN `{$rules_table}` ir ON ir.id = vm.ignore_rule_id
				WHERE ir.status = 'active'
					AND vm.match_action = 'suppressed'
					AND (ir.expires_at IS NULL OR ir.expires_at > NOW())
			) active_ignores ON active_ignores.violation_id = i.id";

		$occurrence_join = $has_occurrence_state
			? "LEFT JOIN `{$occurrence_table}` os
				ON os.site_id = " . get_current_blog_id() . "
				AND os.violation_identity_v2 = i.violation_identity_v2
				AND os.identity_signature_version = i.identity_signature_version
				AND os.latest_scan_id = i.scan_id"
			: '';
		$from = "FROM `{$issues_table}` i
			INNER JOIN `{$items_table}` si ON si.id = i.scan_item_id
			INNER JOIN `{$scans_table}` s ON s.id = i.scan_id
			{$occurrence_join}
			{$active_ignores}";

		$where = [];
		$params = [];
		$scan_id = absint($args['scan_id']);

		if ($scan_id > 0) {
			$where[] = 'i.scan_id = %d';
			$params[] = $scan_id;
		} else {
			$where[] = "si.status = 'completed'";
			$where[] = "s.status = 'completed'";
			$latest_observation = "NOT EXISTS (
				SELECT 1
				FROM `{$items_table}` newer_si
				INNER JOIN `{$scans_table}` newer_s ON newer_s.id = newer_si.scan_id
				WHERE newer_si.post_id = si.post_id
					AND newer_si.status = 'completed'
					AND newer_s.status = 'completed'
					AND (
						newer_si.scanned_at > si.scanned_at
						OR (newer_si.scanned_at = si.scanned_at AND newer_si.id > si.id)
					)
			)";
			if ($has_occurrence_state) {
				$where[] = "(
					os.status = 'active'
					OR (os.id IS NULL AND {$latest_observation})
				)";
			} else {
				$where[] = $latest_observation;
			}

			$exception_expression = '(i.dismissed = 1 OR i.dismissed_global = 1 OR active_ignores.violation_id IS NOT NULL)';
			if ('active' === $args['status']) {
				$where[] = "NOT {$exception_expression}";
			} elseif ('ignored' === $args['status']) {
				$where[] = $exception_expression;
			}
		}

		if (! empty($args['severity'])) {
			$where[] = 'i.severity = %s';
			$params[] = $args['severity'];
		}
		if (! empty($args['rule_id'])) {
			$where[] = 'i.rule_id = %s';
			$params[] = $args['rule_id'];
		}
		if (absint($args['post_id']) > 0) {
			$where[] = 'i.post_id = %d';
			$params[] = absint($args['post_id']);
		}
		if (! empty($args['search'])) {
			$term = '%' . $wpdb->esc_like($args['search']) . '%';
			$where[] = '(i.rule_id LIKE %s OR i.message LIKE %s OR i.help_text LIKE %s
				OR i.selector LIKE %s OR i.accessible_name LIKE %s
				OR i.inner_text_snippet LIKE %s OR si.post_title LIKE %s OR si.post_url LIKE %s)';
			for ($index = 0; $index < 8; $index++) {
				$params[] = $term;
			}
		}

		$where_sql = empty($where) ? '1=1' : implode(' AND ', $where);
		$count_sql = "SELECT COUNT(DISTINCT i.id) {$from} WHERE {$where_sql}";
		$pages_sql = "SELECT COUNT(DISTINCT i.post_id) {$from} WHERE {$where_sql}";
		$severity_sql = "SELECT i.severity, COUNT(DISTINCT i.id) AS count
			{$from} WHERE {$where_sql} GROUP BY i.severity";

		$total = (int) self::get_var_prepared($count_sql, $params);
		$affected_pages = (int) self::get_var_prepared($pages_sql, $params);
		$severity_rows = self::get_results_prepared($severity_sql, $params, ARRAY_A);
		$by_severity = ['critical' => 0, 'moderate' => 0, 'minor' => 0];
		foreach ($severity_rows as $row) {
			if (isset($by_severity[$row['severity']])) {
				$by_severity[$row['severity']] = (int) $row['count'];
			}
		}

		$sorts = [
			'severity' => "FIELD(i.severity, 'critical', 'moderate', 'minor'), i.id DESC",
			'newest' => 'i.created_at DESC, i.id DESC',
			'page' => 'si.post_title ASC, i.id DESC',
			'rule' => 'i.rule_id ASC, i.id DESC',
		];
		$order_by = $sorts[$args['sort']] ?? $sorts['severity'];
		if ('page' === $args['group_by']) {
			$order_by = "si.post_title ASC, {$order_by}";
		} elseif ('rule' === $args['group_by']) {
			$order_by = "i.rule_id ASC, {$order_by}";
		}

		$page = max(1, absint($args['page']));
		$per_page = min(100, max(1, absint($args['per_page'])));
		$offset = ($page - 1) * $per_page;
		$lifecycle_select = $has_occurrence_state
			? 'os.status AS occurrence_status, os.first_seen_at, os.last_seen_at, os.resolved_at,
				os.reappearance_count, os.id AS occurrence_state_id'
			: 'NULL AS occurrence_status, NULL AS first_seen_at, NULL AS last_seen_at, NULL AS resolved_at,
				0 AS reappearance_count, NULL AS occurrence_state_id';
		$item_sql = "SELECT i.*, si.post_title, si.post_url, si.scanned_at,
				s.scan_name, s.status AS scan_status, s.completed_at AS scan_completed_at,
				{$lifecycle_select},
				CASE WHEN i.dismissed = 1 OR i.dismissed_global = 1
					OR active_ignores.violation_id IS NOT NULL THEN 1 ELSE 0 END AS is_ignored,
				EXISTS (
					SELECT 1 FROM `{$matches_table}` resemblance_vm
					INNER JOIN `{$rules_table}` resemblance_ir
						ON resemblance_ir.id = resemblance_vm.ignore_rule_id
					WHERE resemblance_vm.violation_id = i.id
						AND resemblance_vm.match_action = 'resembles'
						AND resemblance_ir.status = 'active'
						AND (resemblance_ir.expires_at IS NULL OR resemblance_ir.expires_at > NOW())
				) AS resembles_exception
			{$from}
			WHERE {$where_sql}
			ORDER BY {$order_by}
			LIMIT %d OFFSET %d";
		$item_params = array_merge($params, [$per_page, $offset]);
		$items = self::get_results_prepared($item_sql, $item_params, ARRAY_A);

		return [
			'items' => array_map([self::class, 'normalize_explorer_row'], $items),
			'summary' => [
				'total_occurrences' => $total,
				'affected_pages' => $affected_pages,
				'by_severity' => $by_severity,
			],
			'pagination' => [
				'page' => $page,
				'per_page' => $per_page,
				'total_pages' => max(1, (int) ceil($total / $per_page)),
			],
		];
	}

	/**
	 * Get a normalized explorer occurrence by ID.
	 *
	 * @param int $issue_id Issue ID.
	 * @return array|null Normalized occurrence.
	 */
	public static function get_explorer_occurrence(int $issue_id): ?array {
		global $wpdb;

		$issues_table = self::get_table();
		$items_table = Schema::get_table_name('scan_items');
		$scans_table = Schema::get_table_name('scans');
		$matches_table = Ignore_Schema::get_table_name('violation_ignore_matches');
		$rules_table = Ignore_Schema::get_table_name('ignore_rules');
		$occurrence_table = Occurrence_Repository::get_table();
		$has_occurrence_state = Occurrence_Repository::table_exists();
		$lifecycle_join = $has_occurrence_state
			? "LEFT JOIN `{$occurrence_table}` os
				ON os.site_id = " . get_current_blog_id() . "
				AND os.violation_identity_v2 = i.violation_identity_v2
				AND os.identity_signature_version = i.identity_signature_version"
			: '';
		$lifecycle_select = $has_occurrence_state
			? 'os.status AS occurrence_status, os.first_seen_at, os.last_seen_at, os.resolved_at,
				os.reappearance_count, os.id AS occurrence_state_id'
			: 'NULL AS occurrence_status, NULL AS first_seen_at, NULL AS last_seen_at, NULL AS resolved_at,
				0 AS reappearance_count, NULL AS occurrence_state_id';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT i.*, si.post_title, si.post_url, si.scanned_at,
					s.scan_name, s.status AS scan_status, s.created_at AS scan_created_at,
					s.completed_at AS scan_completed_at,
					{$lifecycle_select},
					CASE WHEN i.dismissed = 1 OR i.dismissed_global = 1 OR EXISTS (
						SELECT 1 FROM `{$matches_table}` vm
						INNER JOIN `{$rules_table}` ir ON ir.id = vm.ignore_rule_id
						WHERE vm.violation_id = i.id AND ir.status = 'active'
							AND vm.match_action = 'suppressed'
							AND (ir.expires_at IS NULL OR ir.expires_at > NOW())
					) THEN 1 ELSE 0 END AS is_ignored,
					EXISTS (
						SELECT 1 FROM `{$matches_table}` resemblance_vm
						INNER JOIN `{$rules_table}` resemblance_ir
							ON resemblance_ir.id = resemblance_vm.ignore_rule_id
						WHERE resemblance_vm.violation_id = i.id
							AND resemblance_vm.match_action = 'resembles'
							AND resemblance_ir.status = 'active'
							AND (resemblance_ir.expires_at IS NULL OR resemblance_ir.expires_at > NOW())
					) AS resembles_exception
				FROM `{$issues_table}` i
				INNER JOIN `{$items_table}` si ON si.id = i.scan_item_id
				INNER JOIN `{$scans_table}` s ON s.id = i.scan_id
				{$lifecycle_join}
				WHERE i.id = %d",
				$issue_id
			),
			ARRAY_A
		);

		return $row ? self::normalize_explorer_row($row) : null;
	}

	/**
	 * Get filter choices for an explorer combobox.
	 *
	 * @param string $type Filter type.
	 * @param string $search Search text.
	 * @param int    $limit Maximum results.
	 * @return array Filter options.
	 */
	public static function get_explorer_filter_options(string $type, string $search = '', int $limit = 50): array {
		global $wpdb;

		$limit = min(100, max(1, $limit));
		$term = '%' . $wpdb->esc_like($search) . '%';
		$issues_table = self::get_table();
		$items_table = Schema::get_table_name('scan_items');
		$scans_table = Schema::get_table_name('scans');

		if ('rule' === $type) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT rule_id AS id, MAX(COALESCE(help_text, rule_id)) AS label
					FROM `{$issues_table}`
					WHERE rule_id LIKE %s OR help_text LIKE %s
					GROUP BY rule_id ORDER BY label ASC LIMIT %d",
					$term,
					$term,
					$limit
				),
				ARRAY_A
			);
		}

		if ('page' === $type) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id AS id, MAX(COALESCE(NULLIF(post_title, ''), post_url)) AS label
					FROM `{$items_table}`
					WHERE post_title LIKE %s OR post_url LIKE %s
					GROUP BY post_id ORDER BY label ASC LIMIT %d",
					$term,
					$term,
					$limit
				),
				ARRAY_A
			);
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, COALESCE(NULLIF(scan_name, ''), CONCAT('Scan #', id)) AS label
				FROM `{$scans_table}`
				WHERE scan_name LIKE %s OR CAST(id AS CHAR) LIKE %s
				ORDER BY created_at DESC LIMIT %d",
				$term,
				$term,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Normalize a database row for the explorer API.
	 *
	 * @param array $row Database row.
	 * @return array Normalized row.
	 */
	private static function normalize_explorer_row(array $row): array {
		return [
			'id' => (int) $row['id'],
			'rule' => [
				'id' => (string) $row['rule_id'],
				'title' => (string) ($row['help_text'] ?: $row['rule_id']),
				'description' => (string) ($row['message'] ?? ''),
				'help_url' => $row['help_url'] ?: null,
				'wcag_criterion' => $row['wcag_criterion'] ?: null,
			],
			'page' => [
				'id' => (int) $row['post_id'],
				'title' => (string) ($row['post_title'] ?: __('Untitled', 'cleara11y')),
				'url' => (string) $row['post_url'],
			],
			'scan' => [
				'id' => (int) $row['scan_id'],
				'name' => (string) ($row['scan_name'] ?: sprintf(__('Scan #%d', 'cleara11y'), $row['scan_id'])),
				'status' => (string) ($row['scan_status'] ?? ''),
				'scanned_at' => $row['scanned_at'] ?? null,
				'completed_at' => $row['scan_completed_at'] ?? null,
			],
			'severity' => (string) $row['severity'],
			'impact' => $row['impact'] ?: null,
			'finding_type' => ('incomplete' === ($row['result_type'] ?? '') || 'review' === $row['rule_type']) ? 'review' : 'violation',
			'status' => ! empty($row['is_ignored']) ? 'ignored' : 'active',
			'resembles_exception' => ! empty($row['resembles_exception']),
			'message' => (string) ($row['message'] ?? ''),
			'help_text' => (string) ($row['help_text'] ?? ''),
			'selector' => $row['selector'] ?: null,
			'xpath' => $row['xpath'] ?: null,
			'html' => $row['html'] ?: null,
			'accessible_name' => $row['accessible_name'] ?: null,
			'inner_text_snippet' => $row['inner_text_snippet'] ?: null,
			'node_evidence' => $row['node_evidence'] ?: null,
			'source' => ! empty($row['source_key'])
				? [
					'type' => $row['source_type'] ?: null,
					'ref' => $row['source_ref'] ?: null,
					'owner_type' => $row['owner_type'] ?: null,
					'owner_name' => $row['owner_name'] ?: null,
					'key' => $row['source_key'],
				]
				: null,
			'created_at' => $row['created_at'] ?? null,
			'history' => [
				'current_occurrence_status' => $row['occurrence_status'] ?? null,
				'first_seen_at' => $row['first_seen_at'] ?? null,
				'last_seen_at' => $row['last_seen_at'] ?? null,
				'resolved_at' => $row['resolved_at'] ?? null,
				'reappearance_count' => (int) ($row['reappearance_count'] ?? 0),
			],
		];
	}

	/**
	 * Run a prepared scalar query, allowing queries with no placeholders.
	 *
	 * @param string $sql Query SQL.
	 * @param array  $params Query parameters.
	 * @return mixed Query result.
	 */
	private static function get_var_prepared(string $sql, array $params) {
		global $wpdb;
		return empty($params) ? $wpdb->get_var($sql) : $wpdb->get_var($wpdb->prepare($sql, ...$params));
	}

	/**
	 * Run a prepared results query, allowing queries with no placeholders.
	 *
	 * @param string     $sql Query SQL.
	 * @param array      $params Query parameters.
	 * @param string|int $output Output format.
	 * @return array Query results.
	 */
	private static function get_results_prepared(string $sql, array $params, $output = OBJECT): array {
		global $wpdb;
		$results = empty($params)
			? $wpdb->get_results($sql, $output)
			: $wpdb->get_results($wpdb->prepare($sql, ...$params), $output);
		return $results ?: [];
	}
}
