<?php
/**
 * Ignore Rule Repository
 *
 * Handles database operations for Ignore Rule records.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Database
 */

namespace ClearA11y\Database;

use ClearA11y\Models\Ignore_Rule;
use ClearA11y\Models\Ignore_Audit_Log;

/**
 * Ignore Rule Repository Class
 */
class Ignore_Rule_Repository {

	/**
	 * Get table name.
	 *
	 * @return string
	 */
	private static function get_table(): string {
		return Ignore_Schema::get_table_name('ignore_rules');
	}

	/**
	 * Get audit log table name.
	 *
	 * @return string
	 */
	private static function get_audit_table(): string {
		return Ignore_Schema::get_table_name('ignore_audit_log');
	}

	/**
	 * Get violation matches table name.
	 *
	 * @return string
	 */
	private static function get_matches_table(): string {
		return Ignore_Schema::get_table_name('violation_ignore_matches');
	}

	/**
	 * Insert a new ignore rule.
	 *
	 * @param Ignore_Rule $rule Rule object.
	 * @return string|false Rule ID or false on failure.
	 */
	public static function insert(Ignore_Rule $rule) {
		global $wpdb;

		$data = [
			'id' => $rule->id,
			'site_id' => $rule->site_id,
			'status' => $rule->status,
			'target_type' => $rule->target_type,
			'rule_ids' => !empty($rule->rule_ids) ? wp_json_encode($rule->rule_ids) : null,
			'element_match' => !empty($rule->element_match) ? wp_json_encode($rule->element_match) : null,
			'scope' => wp_json_encode($rule->scope),
			'duration' => wp_json_encode($rule->duration),
			'reason_category' => $rule->reason_category,
			'note' => $rule->note,
			'system_generated' => $rule->system_generated ? 1 : 0,
			'created_by' => $rule->created_by,
			'created_at' => $rule->created_at ?? current_time('mysql'),
			'expires_at' => $rule->expires_at,
			'match_count' => 0,
			'violation_identity_v2' => $rule->violation_identity_v2,
			'element_identity_v2' => $rule->element_identity_v2,
			'identity_signature_version' => $rule->identity_signature_version,
			'legacy_reanchor_status' => 'rule' === $rule->target_type
				? 'not_required'
				: $rule->legacy_reanchor_status,
			'legacy_reanchor_attempted_at' => $rule->legacy_reanchor_attempted_at,
		];

		$format = [
			'%s', '%d', '%s', '%s', // id, site_id, status, target_type
			'%s', '%s', '%s', '%s', // rule_ids, element_match, scope, duration
			'%s', '%s', '%d', '%d', // reason_category, note, system_generated, created_by
			'%s', '%s', '%d', // created_at, expires_at, match_count
			'%s', '%s', '%d', '%s', '%s',
		];

		$result = $wpdb->insert(self::get_table(), $data, $format);

		if ($result !== false) {
			// Create audit log entry
			self::insert_audit_log('ignore_created', $rule->id, $rule->created_by, [
				'rule_label' => $rule->get_label(),
			]);

			return $rule->id;
		}

		return false;
	}

	/**
	 * Update an existing ignore rule.
	 *
	 * @param Ignore_Rule $rule Rule object.
	 * @return bool True on success, false on failure.
	 */
	public static function update(Ignore_Rule $rule): bool {
		global $wpdb;

		$data = [
			'status' => $rule->status,
			'rule_ids' => !empty($rule->rule_ids) ? wp_json_encode($rule->rule_ids) : null,
			'element_match' => !empty($rule->element_match) ? wp_json_encode($rule->element_match) : null,
			'scope' => wp_json_encode($rule->scope),
			'duration' => wp_json_encode($rule->duration),
			'reason_category' => $rule->reason_category,
			'note' => $rule->note,
			'expires_at' => $rule->expires_at,
			'violation_identity_v2' => $rule->violation_identity_v2,
			'element_identity_v2' => $rule->element_identity_v2,
			'identity_signature_version' => $rule->identity_signature_version,
			'legacy_reanchor_status' => $rule->legacy_reanchor_status,
			'legacy_reanchor_attempted_at' => $rule->legacy_reanchor_attempted_at,
		];

		$result = $wpdb->update(
			self::get_table(),
			$data,
			['id' => $rule->id],
			['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s'],
			['%s']
		);

		if ($result !== false) {
			// Create audit log entry
			self::insert_audit_log('ignore_edited', $rule->id, get_current_user_id(), [
				'changes' => array_keys($data),
			]);

			return true;
		}

		return false;
	}

	/**
	 * Get rule by ID.
	 *
	 * @param string $rule_id Rule ID.
	 * @return Ignore_Rule|null
	 */
	public static function get_by_id(string $rule_id): ?Ignore_Rule {
		global $wpdb;

		$table = self::get_table();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE id = %s",
				$rule_id
			)
		);

		return $row ? Ignore_Rule::from_row($row) : null;
	}

	/**
	 * Get all rules for a site.
	 *
	 * @param int   $site_id Site ID.
	 * @param array $args    Optional query arguments.
	 * @return Ignore_Rule[]
	 */
	public static function get_by_site_id(int $site_id, array $args = []): array {
		global $wpdb;

		$defaults = [
			'status' => null,
			'system_generated' => null,
			'orderby' => 'created_at',
			'order' => 'DESC',
			'limit' => null,
			'offset' => 0,
		];

		$args = wp_parse_args($args, $defaults);

		$where = ['site_id = %d'];
		$where_params = [$site_id];

		if (!empty($args['status'])) {
			$where[] = 'status = %s';
			$where_params[] = $args['status'];
		}

		if (null !== $args['system_generated']) {
			$where[] = 'system_generated = %d';
			$where_params[] = $args['system_generated'] ? 1 : 0;
		}

		$where_clause = implode(' AND ', $where);
		$orderby = sanitize_sql_orderby("{$args['orderby']} {$args['order']}");

		$table = self::get_table();
		// @phpstan-ignore-next-line
		$query = $wpdb->prepare(
			"SELECT * FROM `{$table}` WHERE {$where_clause} ORDER BY {$orderby}",
			...$where_params
		);

		if (isset($args['limit'])) {
			$query .= $wpdb->prepare(" LIMIT %d OFFSET %d", (int) $args['limit'], (int) $args['offset']);
		}

		$rows = $wpdb->get_results($query);

		return array_map(fn($row) => Ignore_Rule::from_row($row), $rows ?: []);
	}

	/**
	 * Get active rules for a site.
	 *
	 * @param int $site_id Site ID.
	 * @return Ignore_Rule[]
	 */
	public static function get_active(int $site_id): array {
		// Get all rules marked as active
		$rules = self::get_by_site_id($site_id, ['status' => 'active']);

		// Filter out expired rules
		return array_filter($rules, function($rule) {
			return !$rule->is_expired();
		});
	}

	/**
	 * Delete rule by ID.
	 *
	 * @param string $rule_id Rule ID.
	 * @return bool True on success, false on failure.
	 */
	public static function delete(string $rule_id): bool {
		global $wpdb;

		// Get rule for audit log
		$rule = self::get_by_id($rule_id);

		// Delete violation matches
		$wpdb->delete(
			self::get_matches_table(),
			['ignore_rule_id' => $rule_id],
			['%s']
		);

		// Delete rule
		$result = $wpdb->delete(
			self::get_table(),
			['id' => $rule_id],
			['%s']
		);

		if ($result !== false && $rule) {
			// Create audit log entry
			self::insert_audit_log('ignore_deleted', $rule_id, get_current_user_id(), [
				'rule_label' => $rule->get_label(),
			]);
		}

		return $result !== false;
	}

	/**
	 * Disable rule by ID.
	 *
	 * @param string $rule_id Rule ID.
	 * @return bool True on success, false on failure.
	 */
	public static function disable(string $rule_id): bool {
		global $wpdb;

		$result = $wpdb->update(
			self::get_table(),
			['status' => 'disabled'],
			['id' => $rule_id],
			['%s'],
			['%s']
		);

		if ($result !== false) {
			self::insert_audit_log('ignore_disabled', $rule_id, get_current_user_id());
		}

		return $result !== false;
	}

	/**
	 * Enable rule by ID.
	 *
	 * @param string $rule_id Rule ID.
	 * @return bool True on success, false on failure.
	 */
	public static function enable(string $rule_id): bool {
		global $wpdb;

		$result = $wpdb->update(
			self::get_table(),
			['status' => 'active'],
			['id' => $rule_id],
			['%s'],
			['%s']
		);

		if ($result !== false) {
			self::insert_audit_log('ignore_enabled', $rule_id, get_current_user_id());
		}

		return $result !== false;
	}

	/**
	 * Update match count for a rule.
	 *
	 * @param string $rule_id Rule ID.
	 * @param int    $count   New match count.
	 * @return bool True on success, false on failure.
	 */
	public static function update_match_count(string $rule_id, int $count): bool {
		global $wpdb;

		$result = $wpdb->update(
			self::get_table(),
			['match_count' => $count],
			['id' => $rule_id],
			['%d'],
			['%s']
		);

		return $result !== false;
	}

	/**
	 * Increment match count for a rule.
	 *
	 * @param string $rule_id Rule ID.
	 * @return bool True on success, false on failure.
	 */
	public static function increment_match_count(string $rule_id): bool {
		global $wpdb;

		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `" . self::get_table() . "` SET match_count = match_count + 1 WHERE id = %s",
				$rule_id
			)
		);

		return $result !== false;
	}

	/**
	 * Get stable post IDs from observations previously suppressed by a rule.
	 *
	 * @param string $rule_id Rule ID.
	 * @return int[] WordPress post IDs.
	 */
	public static function get_legacy_anchor_post_ids(string $rule_id): array {
		global $wpdb;

		$issues_table = Schema::get_table_name('issues');
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT i.post_id
				FROM `" . self::get_matches_table() . "` vm
				INNER JOIN `{$issues_table}` i ON i.id = vm.violation_id
				WHERE vm.ignore_rule_id = %s AND i.post_id > 0",
				$rule_id
			)
		);

		return array_map('intval', $rows ?: []);
	}

	/**
	 * Anchor a legacy occurrence exception to exact v2 identities.
	 *
	 * @param string $rule_id Rule ID.
	 * @param string $violation_identity Exact violation identity.
	 * @param string $element_identity Element identity.
	 * @param int    $signature_version Identity signature version.
	 * @return bool True when the anchor was stored.
	 */
	public static function reanchor_to_v2(
		string $rule_id,
		string $violation_identity,
		string $element_identity,
		int $signature_version
	): bool {
		global $wpdb;

		$result = $wpdb->update(
			self::get_table(),
			[
				'violation_identity_v2' => $violation_identity,
				'element_identity_v2' => $element_identity,
				'identity_signature_version' => $signature_version,
				'legacy_reanchor_status' => 'anchored',
				'legacy_reanchor_attempted_at' => current_time('mysql'),
			],
			[
				'id' => $rule_id,
				'legacy_reanchor_status' => 'pending',
			],
			['%s', '%s', '%d', '%s', '%s'],
			['%s', '%s']
		);

		if (false === $result) {
			error_log(
				sprintf(
					'ClearA11y ERROR: Failed to re-anchor legacy exception. rule_id=%s database_error=%s',
					$rule_id,
					$wpdb->last_error
				)
			);
			return false;
		}

		if (1 === $result) {
			self::insert_audit_log(
				'ignore_reanchored_v2',
				$rule_id,
				null,
				['identity_signature_version' => $signature_version]
			);
		}

		return 1 === $result;
	}

	/**
	 * Mark still-pending legacy occurrence exceptions unmatched after a scan.
	 *
	 * @param int         $site_id Site ID.
	 * @param string|null $since Only count anchors attempted during this scan.
	 * @param int|null    $scan_id Scan whose coverage determines unmatched rules.
	 * @return array{anchored:int,unmatched:int} Re-anchor results.
	 */
	public static function finalize_legacy_reanchoring(
		int $site_id,
		?string $since = null,
		?int $scan_id = null
	): array {
		global $wpdb;

		$pending_rules = array_filter(
			self::get_active($site_id),
			static fn(Ignore_Rule $rule): bool => in_array(
				$rule->target_type,
				['element', 'rule_on_element'],
				true
			) && 'pending' === $rule->legacy_reanchor_status
		);
		$covered_rule_ids = [];
		foreach ($pending_rules as $pending_rule) {
			if (self::legacy_rule_scope_was_scanned($pending_rule, $scan_id)) {
				$covered_rule_ids[] = $pending_rule->id;
			}
		}

		$unmatched = 0;
		foreach ($covered_rule_ids as $rule_id) {
			$result = $wpdb->update(
				self::get_table(),
				[
					'legacy_reanchor_status' => 'unmatched',
					'legacy_reanchor_attempted_at' => current_time('mysql'),
				],
				[
					'id' => $rule_id,
					'legacy_reanchor_status' => 'pending',
				],
				['%s', '%s'],
				['%s', '%s']
			);
			if (false === $result) {
				error_log(
					sprintf(
						'ClearA11y ERROR: Failed finalizing legacy exception re-anchoring. rule_id=%s database_error=%s',
						$rule_id,
						$wpdb->last_error
					)
				);
				continue;
			}
			$unmatched += (int) $result;
		}

		$anchored = 0;
		if ($since) {
			$anchored = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM `" . self::get_table() . "`
					WHERE site_id = %d
						AND legacy_reanchor_status = 'anchored'
						AND legacy_reanchor_attempted_at >= %s",
					$site_id,
					$since
				)
			);
		}

		return [
			'anchored' => $anchored,
			'unmatched' => $unmatched,
		];
	}

	/**
	 * Check whether a completed scan covered a legacy rule's declared scope.
	 *
	 * @param Ignore_Rule $rule Rule awaiting a one-time re-anchor.
	 * @param int|null    $scan_id Completed scan ID, or null for test/admin use.
	 * @return bool True when absence from this scan is meaningful.
	 */
	private static function legacy_rule_scope_was_scanned(Ignore_Rule $rule, ?int $scan_id): bool {
		if (null === $scan_id) {
			return true;
		}

		global $wpdb;

		$scans_table = Schema::get_table_name('scans');
		$items_table = Schema::get_table_name('scan_items');
		$scan_type = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT scan_type FROM `{$scans_table}` WHERE id = %d",
				$scan_id
			)
		);
		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, post_url, post_type FROM `{$items_table}` WHERE scan_id = %d",
				$scan_id
			)
		);
		if (! $scan_type || empty($items)) {
			return false;
		}

		$scope_type = $rule->scope['scope_type'] ?? '';
		if ('page' === $scope_type) {
			$anchor_post_ids = self::get_legacy_anchor_post_ids($rule->id);
			$scope_url = \ClearA11y\Services\Fingerprint_Service::normalize_url(
				(string) ($rule->scope['url'] ?? '')
			);
			foreach ($items as $item) {
				if (
					in_array((int) $item->post_id, $anchor_post_ids, true)
					||
					$scope_url === \ClearA11y\Services\Fingerprint_Service::normalize_url(
						(string) $item->post_url
					)
				) {
					return true;
				}
			}

			return false;
		}

		// A partial scan cannot prove that a site-, content-type-, or
		// URL-pattern-scoped legacy exception has no corresponding occurrence.
		return 'full' === $scan_type;
	}

	/**
	 * Check if a matching quick ignore already exists.
	 *
	 * @param int    $site_id    Site ID.
	 * @param string $rule_id    Rule ID.
	 * @param string $url        Page URL.
	 * @param string $selector   Element selector.
	 * @return Ignore_Rule|null Existing rule or null.
	 */
	public static function find_existing_quick_ignore(int $site_id, string $rule_id, string $url, string $selector): ?Ignore_Rule {
		global $wpdb;

		$table = self::get_table();

		// Find system-generated rule_on_element ignores for this rule on this page
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}`
				WHERE site_id = %d
				AND system_generated = 1
				AND target_type = 'rule_on_element'
				AND status = 'active'
				AND rule_ids LIKE %s
				AND scope LIKE %s
				ORDER BY created_at DESC
				LIMIT 1",
				$site_id,
				'%"' . $rule_id . '"%',
				'%"scope_type":"page"%"url":"' . $wpdb->esc_like($url) . '"%'
			)
		);

		return $row ? Ignore_Rule::from_row($row) : null;
	}

	/**
	 * Get rule count by status.
	 *
	 * @param int    $site_id Site ID.
	 * @param string $status  Status to count.
	 * @return int Count.
	 */
	public static function get_count_by_status(int $site_id, string $status): int {
		global $wpdb;

		$table = self::get_table();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE site_id = %d AND status = %s",
				$site_id,
				$status
			)
		);
	}

	/**
	 * Mark expired rules as expired.
	 *
	 * @param int $site_id Site ID.
	 * @return int Number of rules marked as expired.
	 */
	public static function mark_expired(int $site_id): int {
		global $wpdb;

		$table = self::get_table();

		// Find rules with expires_at in the past
		$expired = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM `{$table}`
				WHERE site_id = %d
				AND status = 'active'
				AND expires_at IS NOT NULL
				AND expires_at < NOW()",
				$site_id
			)
		);

		if (empty($expired)) {
			return 0;
		}

		// Mark as expired
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET status = 'expired'
				WHERE site_id = %d
				AND status = 'active'
				AND expires_at IS NOT NULL
				AND expires_at < NOW()",
				$site_id
			)
		);

		// Create audit log entries
		foreach ($expired as $rule_id) {
			self::insert_audit_log('ignore_expired', $rule_id, null);
		}

		return count($expired);
	}

	/**
	 * Insert audit log entry.
	 *
	 * @param string      $event_type Event type.
	 * @param string|null $rule_id    Associated rule ID.
	 * @param int|null    $user_id    User ID who performed the action.
	 * @param array       $metadata   Additional metadata.
	 * @return int|false Audit log ID or false on failure.
	 */
	private static function insert_audit_log(string $event_type, ?string $rule_id = null, ?int $user_id = null, array $metadata = []) {
		global $wpdb;

		$data = [
			'ignore_rule_id' => $rule_id,
			'event_type' => $event_type,
			'actor_user_id' => $user_id,
			'timestamp' => current_time('mysql'),
			'metadata' => !empty($metadata) ? wp_json_encode($metadata) : null,
		];

		$format = ['%s', '%s', '%d', '%s', '%s'];

		return $wpdb->insert(self::get_audit_table(), $data, $format) ? $wpdb->insert_id : false;
	}

	/**
	 * Get audit log entries for a rule.
	 *
	 * @param string $rule_id Rule ID.
	 * @param int    $limit   Number of entries to return.
	 * @return Ignore_Audit_Log[]
	 */
	public static function get_audit_log(string $rule_id, int $limit = 50): array {
		global $wpdb;

		$table = self::get_audit_table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE ignore_rule_id = %s ORDER BY timestamp DESC LIMIT %d",
				$rule_id,
				$limit
			)
		);

		return array_map(fn($row) => Ignore_Audit_Log::from_row($row), $rows ?: []);
	}

	/**
	 * Get all audit log entries for a site.
	 *
	 * @param int $site_id Site ID.
	 * @param int $limit   Number of entries to return.
	 * @return Ignore_Audit_Log[]
	 */
	public static function get_all_audit_log(int $site_id, int $limit = 100): array {
		global $wpdb;

		$table = self::get_audit_table();
		$rules_table = self::get_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT al.* FROM `{$table}` al
				INNER JOIN `{$rules_table}` ir ON al.ignore_rule_id = ir.id
				WHERE ir.site_id = %d
				ORDER BY al.timestamp DESC
				LIMIT %d",
				$site_id,
				$limit
			)
		);

		return array_map(fn($row) => Ignore_Audit_Log::from_row($row), $rows ?: []);
	}

	/**
	 * Create violation-ignore match record.
	 *
	 * @param int    $violation_id Violation ID.
	 * @param string $ignore_rule_id Ignore rule ID.
	 * @param int    $site_id      Site ID.
	 * @param string $confidence   Match confidence level.
	 * @param string $action       Match action: suppressed or resembles.
	 * @return bool True on success, false on failure.
	 */
	public static function create_match(
		int $violation_id,
		string $ignore_rule_id,
		int $site_id,
		string $confidence = 'high',
		string $action = 'suppressed'
	): bool {
		global $wpdb;

		$action = in_array($action, ['suppressed', 'resembles'], true) ? $action : 'resembles';

		// Check if match already exists
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM `" . self::get_matches_table() . "` WHERE violation_id = %d AND ignore_rule_id = %s",
				$violation_id,
				$ignore_rule_id
			)
		);

		if ($existing) {
			$result = $wpdb->update(
				self::get_matches_table(),
				[
					'matched_at' => current_time('mysql'),
					'match_confidence' => $confidence,
					'match_action' => $action,
				],
				['id' => (int) $existing],
				['%s', '%s', '%s'],
				['%d']
			);
			return false !== $result;
		}

		$result = $wpdb->insert(
			self::get_matches_table(),
			[
				'violation_id' => $violation_id,
				'ignore_rule_id' => $ignore_rule_id,
				'site_id' => $site_id,
				'matched_at' => current_time('mysql'),
				'match_confidence' => $confidence,
				'match_action' => $action,
			],
			['%d', '%s', '%d', '%s', '%s', '%s']
		);

		if ($result !== false && 'suppressed' === $action) {
			// Increment match count on rule
			self::increment_match_count($ignore_rule_id);
		}

		return $result !== false;
	}

	/**
	 * Get matches for a violation.
	 *
	 * @param int $violation_id Violation ID.
	 * @return array Array of matching ignore rule IDs.
	 */
	public static function get_matches_for_violation(int $violation_id): array {
		global $wpdb;

		$table = self::get_matches_table();

		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ignore_rule_id FROM `{$table}` WHERE violation_id = %d",
				$violation_id
			)
		);
	}

	/**
	 * Get matches for an ignore rule.
	 *
	 * @param string $ignore_rule_id Ignore rule ID.
	 * @return array Array of violation IDs.
	 */
	public static function get_violations_for_rule(string $ignore_rule_id): array {
		global $wpdb;

		$table = self::get_matches_table();

		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT violation_id FROM `{$table}` WHERE ignore_rule_id = %s",
				$ignore_rule_id
			)
		);
	}

	/**
	 * Delete all matches for a rule.
	 *
	 * @param string $ignore_rule_id Ignore rule ID.
	 * @return int Number of rows deleted.
	 */
	public static function delete_matches_for_rule(string $ignore_rule_id): int {
		global $wpdb;

		return $wpdb->delete(
			self::get_matches_table(),
			['ignore_rule_id' => $ignore_rule_id],
			['%s']
		);
	}
}
