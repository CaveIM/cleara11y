<?php
/**
 * Exception Rule Repository
 *
 * Handles database operations for reviewed exception records.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Database
 */

namespace ClearA11y\Database;

if (! defined('ABSPATH')) {
	exit;
}

use ClearA11y\Models\Exception_Rule;
use ClearA11y\Models\Exception_Audit_Log;

/**
 * Exception Rule Repository Class
 */
class Exception_Rule_Repository {

	/**
	 * Get table name.
	 *
	 * @return string
	 */
	private static function get_table(): string {
		return Exception_Schema::get_table_name('exception_rules');
	}

	/**
	 * Get audit log table name.
	 *
	 * @return string
	 */
	private static function get_audit_table(): string {
		return Exception_Schema::get_table_name('exception_audit_log');
	}

	/**
	 * Get violation matches table name.
	 *
	 * @return string
	 */
	private static function get_matches_table(): string {
		return Exception_Schema::get_table_name('issue_exception_matches');
	}

	/**
	 * Insert a new exception rule.
	 *
	 * @param Exception_Rule $rule Rule object.
	 * @return string|false Rule ID or false on failure.
	 */
	public static function insert(Exception_Rule $rule) {
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

		$result = $wpdb->insert(self::get_table(), $data, $format); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Write to plugin-owned tables; no WordPress data API or cached result applies.

		if ($result !== false) {
			// Create audit log entry
			self::insert_audit_log($rule->system_generated ? 'occurrence_snoozed' : 'exception_created', $rule->id, $rule->created_by, [
				'rule_label' => $rule->get_label(),
			]);

			return $rule->id;
		}

		return false;
	}

	/**
	 * Update an existing exception rule.
	 *
	 * @param Exception_Rule $rule Rule object.
	 * @return bool True on success, false on failure.
	 */
	public static function update(Exception_Rule $rule): bool {
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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write to plugin-owned tables; no WordPress data API or cached result applies.
		$result = $wpdb->update(
			self::get_table(),
			$data,
			['id' => $rule->id],
			['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s'],
			['%s']
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ($result !== false) {
			// Create audit log entry
			self::insert_audit_log('exception_edited', $rule->id, get_current_user_id(), [
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
	 * @return Exception_Rule|null
	 */
	public static function get_by_id(string $rule_id): ?Exception_Rule {
		global $wpdb;

		$table = self::get_table();
		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live scan/exception state in custom tables; caching can return stale worker or suppression state; Schema-owned identifiers and fixed SQL fragments; variable values are prepared separately.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE id = %s",
				$rule_id
			)
		);
		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? Exception_Rule::from_row($row) : null;
	}

	/**
	 * Get all rules for a site.
	 *
	 * @param int   $site_id Site ID.
	 * @param array $args    Optional query arguments.
	 * @return Exception_Rule[]
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
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Fixed filter/IN fragments contain placeholders populated by the matching parameter list.
		$query = $wpdb->prepare(
			"SELECT * FROM `{$table}` WHERE {$where_clause} ORDER BY {$orderby}",
			...$where_params
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		if (isset($args['limit'])) {
			$query .= $wpdb->prepare(" LIMIT %d OFFSET %d", (int) $args['limit'], (int) $args['offset']);
		}

		$rows = $wpdb->get_results($query); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Live scan/exception state in custom tables; caching can return stale worker or suppression state.

		return array_map(fn($row) => Exception_Rule::from_row($row), $rows ?: []);
	}

	/**
	 * Get active rules for a site.
	 *
	 * @param int $site_id Site ID.
	 * @return Exception_Rule[]
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
	 * Revoke a rule without deleting its audit trail or historical matches.
	 *
	 * @param string $rule_id Rule ID.
	 * @return bool True on success, false on failure.
	 */
	public static function revoke(string $rule_id): bool {
		global $wpdb;

		$rule = self::get_by_id($rule_id);
		if (! $rule) {
			return false;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write to plugin-owned tables; no WordPress data API or cached result applies.
		$result = $wpdb->update(
			self::get_table(),
			['status' => 'revoked'],
			['id' => $rule_id],
			['%s'],
			['%s']
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if (false !== $result) {
			self::insert_audit_log('exception_revoked', $rule_id, get_current_user_id(), [
				'rule_label' => $rule->get_label(),
			]);
		}

		return false !== $result;
	}

	/**
	 * Backward-compatible alias for callers not yet updated.
	 *
	 * @param string $rule_id Rule ID.
	 * @return bool True on success.
	 */
	public static function delete(string $rule_id): bool {
		return self::revoke($rule_id);
	}

	/**
	 * Disable rule by ID.
	 *
	 * @param string $rule_id Rule ID.
	 * @return bool True on success, false on failure.
	 */
	public static function disable(string $rule_id): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write to plugin-owned tables; no WordPress data API or cached result applies.
		$result = $wpdb->update(
			self::get_table(),
			['status' => 'disabled'],
			['id' => $rule_id],
			['%s'],
			['%s']
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ($result !== false) {
			self::insert_audit_log('exception_disabled', $rule_id, get_current_user_id());
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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write to plugin-owned tables; no WordPress data API or cached result applies.
		$result = $wpdb->update(
			self::get_table(),
			['status' => 'active'],
			['id' => $rule_id],
			['%s'],
			['%s']
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ($result !== false) {
			self::insert_audit_log('exception_enabled', $rule_id, get_current_user_id());
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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write to plugin-owned tables; no WordPress data API or cached result applies.
		$result = $wpdb->update(
			self::get_table(),
			['match_count' => $count],
			['id' => $rule_id],
			['%d'],
			['%s']
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

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
		$table = self::get_table();

		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Write to plugin-owned tables; no WordPress data API or cached result applies; Schema-owned identifiers and fixed SQL fragments; variable values are prepared separately.
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET match_count = match_count + 1 WHERE id = %s",
				$rule_id
			)
		);
		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

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
		$matches_table = self::get_matches_table();

		$issues_table = Schema::get_table_name('issues');
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live scan/exception state in custom tables; caching can return stale worker or suppression state; Schema-owned identifiers and fixed SQL fragments; variable values are prepared separately.
		$rows = $wpdb->get_col( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Reviewed: fixed SQL fragments and schema table names; variable values are prepared.
			$wpdb->prepare(
				"SELECT DISTINCT i.post_id
				FROM `{$matches_table}` vm
				INNER JOIN `{$issues_table}` i ON i.id = vm.violation_id
				WHERE vm.exception_rule_id = %s AND i.post_id > 0",
				$rule_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write to plugin-owned tables; no WordPress data API or cached result applies.
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
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if (false === $result) {
			\cleara11y_debug_log(
				sprintf(
					'ClearA11y ERROR: Failed to re-anchor legacy exception. rule_id=%s database_error=%s',
					$rule_id,
					'Database operation failed; raw details omitted to protect scan evidence.'
				)
			);
			return false;
		}

		if (1 === $result) {
			self::insert_audit_log(
				'exception_reanchored_v2',
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
		$table = self::get_table();

		$pending_rules = array_filter(
			self::get_active($site_id),
			static fn(Exception_Rule $rule): bool => in_array(
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
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write to plugin-owned tables; no WordPress data API or cached result applies.
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
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if (false === $result) {
				\cleara11y_debug_log(
					sprintf(
						'ClearA11y ERROR: Failed finalizing legacy exception re-anchoring. rule_id=%s database_error=%s',
						$rule_id,
						'Database operation failed; raw details omitted to protect scan evidence.'
					)
				);
				continue;
			}
			$unmatched += (int) $result;
		}

		$anchored = 0;
		if ($since) {
			// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live scan/exception state in custom tables; caching can return stale worker or suppression state; Schema-owned identifiers and fixed SQL fragments; variable values are prepared separately.
			$anchored = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM `{$table}`
					WHERE site_id = %d
						AND legacy_reanchor_status = 'anchored'
						AND legacy_reanchor_attempted_at >= %s",
					$site_id,
					$since
				)
			);
			// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return [
			'anchored' => $anchored,
			'unmatched' => $unmatched,
		];
	}

	/**
	 * Check whether a completed scan covered a legacy rule's declared scope.
	 *
	 * @param Exception_Rule $rule Rule awaiting a one-time re-anchor.
	 * @param int|null    $scan_id Completed scan ID, or null for test/admin use.
	 * @return bool True when absence from this scan is meaningful.
	 */
	private static function legacy_rule_scope_was_scanned(Exception_Rule $rule, ?int $scan_id): bool {
		if (null === $scan_id) {
			return true;
		}

		global $wpdb;

		$scans_table = Schema::get_table_name('scans');
		$items_table = Schema::get_table_name('scan_items');
		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live scan/exception state in custom tables; caching can return stale worker or suppression state; Schema-owned identifiers and fixed SQL fragments; variable values are prepared separately.
		$scan_type = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT scan_type FROM `{$scans_table}` WHERE id = %d",
				$scan_id
			)
		);
		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live scan/exception state in custom tables; caching can return stale worker or suppression state; Schema-owned identifiers and fixed SQL fragments; variable values are prepared separately.
		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, post_url, post_type FROM `{$items_table}` WHERE scan_id = %d",
				$scan_id
			)
		);
		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
	 * Check if a matching one-scan snooze already exists.
	 *
	 * @param int    $site_id    Site ID.
	 * @param string $rule_id    Rule ID.
	 * @param string $url        Page URL.
	 * @param string $selector   Element selector.
	 * @return Exception_Rule|null Existing rule or null.
	 */
	public static function find_existing_snooze(int $site_id, string $rule_id, string $url, string $selector): ?Exception_Rule {
		global $wpdb;

		$table = self::get_table();

		// Find system-generated one-scan snoozes for this rule on this page.
		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live scan/exception state in custom tables; caching can return stale worker or suppression state; Schema-owned identifiers and fixed SQL fragments; variable values are prepared separately.
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
		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? Exception_Rule::from_row($row) : null;
	}

	/**
	 * Get rule count by status.
	 *
	 * @param int    $site_id Site ID.
	 * @param string $status  Status to count.
	 * @return int Count.
	 */
	public static function get_count_by_status(int $site_id, string $status, ?bool $system_generated = null): int {
		global $wpdb;

		$table = self::get_table();

		$system_clause = null === $system_generated ? '' : ($system_generated ? ' AND system_generated = 1' : ' AND system_generated = 0');

		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live scan/exception state in custom tables; caching can return stale worker or suppression state; Schema-owned identifiers and fixed SQL fragments; variable values are prepared separately.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE site_id = %d AND status = %s{$system_clause}",
				$site_id,
				$status
			)
		);
		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live scan/exception state in custom tables; caching can return stale worker or suppression state; Schema-owned identifiers and fixed SQL fragments; variable values are prepared separately.
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
		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if (empty($expired)) {
			return 0;
		}

		// Mark as expired
		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Write to plugin-owned tables; no WordPress data API or cached result applies; Schema-owned identifiers and fixed SQL fragments; variable values are prepared separately.
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
		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Create audit log entries
		foreach ($expired as $rule_id) {
			self::insert_audit_log('exception_expired', $rule_id, null);
		}

		return count($expired);
	}

	/**
	 * Expire next-scan exceptions after a page in their scope has been scanned.
	 *
	 * @param int    $site_id Site ID.
	 * @param string $page_url Scanned page URL.
	 * @param string $post_type Scanned content type.
	 * @return int Number of next-scan exceptions expired.
	 */
	public static function expire_snoozes_for_url(int $site_id, string $page_url, string $post_type = ''): int {
		$expired = 0;

		foreach (self::get_active($site_id) as $rule) {
			if (
				'until_next_scan' !== ($rule->duration['duration_type'] ?? '')
				|| ! \ClearA11y\Services\Exception_Matcher_Service::matches_page_scope($rule, $page_url, $post_type)
			) {
				continue;
			}

			global $wpdb;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write to plugin-owned tables; no WordPress data API or cached result applies.
			$result = $wpdb->update(
				self::get_table(),
				['status' => 'expired'],
				['id' => $rule->id, 'status' => 'active'],
				['%s'],
				['%s', '%s']
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if (1 === $result) {
				self::insert_audit_log('exception_expired', $rule->id, null, ['trigger' => 'next_scan']);
				$expired++;
			}
		}

		return $expired;
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
			'exception_rule_id' => $rule_id,
			'event_type' => $event_type,
			'actor_user_id' => $user_id,
			'timestamp' => current_time('mysql'),
			'metadata' => !empty($metadata) ? wp_json_encode($metadata) : null,
		];

		$format = ['%s', '%s', '%d', '%s', '%s'];

		return $wpdb->insert(self::get_audit_table(), $data, $format) ? $wpdb->insert_id : false; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Write to plugin-owned tables; no WordPress data API or cached result applies.
	}

	/**
	 * Get audit log entries for a rule.
	 *
	 * @param string $rule_id Rule ID.
	 * @param int    $limit   Number of entries to return.
	 * @return Exception_Audit_Log[]
	 */
	public static function get_audit_log(string $rule_id, int $limit = 50): array {
		global $wpdb;
		$table = self::get_table();

		$table = self::get_audit_table();
		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live scan/exception state in custom tables; caching can return stale worker or suppression state; Schema-owned identifiers and fixed SQL fragments; variable values are prepared separately.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE exception_rule_id = %s ORDER BY timestamp DESC LIMIT %d",
				$rule_id,
				$limit
			)
		);
		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map(fn($row) => Exception_Audit_Log::from_row($row), $rows ?: []);
	}

	/**
	 * Get all audit log entries for a site.
	 *
	 * @param int $site_id Site ID.
	 * @param int $limit   Number of entries to return.
	 * @return Exception_Audit_Log[]
	 */
	public static function get_all_audit_log(int $site_id, int $limit = 100): array {
		global $wpdb;
		$table = self::get_table();

		$table = self::get_audit_table();
		$rules_table = self::get_table();

		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live scan/exception state in custom tables; caching can return stale worker or suppression state; Schema-owned identifiers and fixed SQL fragments; variable values are prepared separately.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT al.* FROM `{$table}` al
				INNER JOIN `{$rules_table}` ir ON al.exception_rule_id = ir.id
				WHERE ir.site_id = %d
				ORDER BY al.timestamp DESC
				LIMIT %d",
				$site_id,
				$limit
			)
		);
		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map(fn($row) => Exception_Audit_Log::from_row($row), $rows ?: []);
	}

	/**
	 * Create violation-ignore match record.
	 *
	 * @param int    $violation_id Violation ID.
	 * @param string $exception_rule_id Exception rule ID.
	 * @param int    $site_id      Site ID.
	 * @param string $confidence   Match confidence level.
	 * @param string $action       Match action: suppressed or resembles.
	 * @return bool True on success, false on failure.
	 */
	public static function create_match(
		int $violation_id,
		string $exception_rule_id,
		int $site_id,
		string $confidence = 'high',
		string $action = 'suppressed'
	): bool {
		global $wpdb;
		$matches_table = self::get_matches_table();

		$action = in_array($action, ['suppressed', 'resembles'], true) ? $action : 'resembles';
		$rule = self::get_by_id($exception_rule_id);
		$snapshot = $rule ? wp_json_encode($rule->to_array()) : null;

		// Check if match already exists
		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live scan/exception state in custom tables; caching can return stale worker or suppression state; Schema-owned identifiers and fixed SQL fragments; variable values are prepared separately.
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM `{$matches_table}` WHERE violation_id = %d AND exception_rule_id = %s",
				$violation_id,
				$exception_rule_id
			)
		);
		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ($existing) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write to plugin-owned tables; no WordPress data API or cached result applies.
			$result = $wpdb->update(
				self::get_matches_table(),
				[
					'matched_at' => current_time('mysql'),
					'match_confidence' => $confidence,
					'match_action' => $action,
					'rule_snapshot' => $snapshot,
				],
				['id' => (int) $existing],
				['%s', '%s', '%s', '%s'],
				['%d']
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return false !== $result;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Write to plugin-owned tables; no WordPress data API or cached result applies.
		$result = $wpdb->insert(
			self::get_matches_table(),
			[
				'violation_id' => $violation_id,
				'exception_rule_id' => $exception_rule_id,
				'site_id' => $site_id,
				'matched_at' => current_time('mysql'),
				'match_confidence' => $confidence,
				'match_action' => $action,
				'rule_snapshot' => $snapshot,
			],
			['%d', '%s', '%d', '%s', '%s', '%s', '%s']
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

		if ($result !== false && 'suppressed' === $action) {
			// Increment match count on rule
			self::increment_match_count($exception_rule_id);
		}

		return $result !== false;
	}

	/**
	 * Count observations sharing an identity in the same scan item.
	 *
	 * An occurrence-level exception is safe only when the identity resolves to
	 * exactly one observation in the current snapshot.
	 *
	 * @param int    $scan_item_id Scan item ID.
	 * @param string $identity Violation identity.
	 * @param int    $signature_version Identity signature version.
	 * @return int Matching observation count.
	 */
	public static function count_identity_observations(
		int $scan_item_id,
		string $identity,
		int $signature_version
	): int {
		global $wpdb;

		if ($scan_item_id < 1 || '' === $identity || $signature_version < 1) {
			return 0;
		}

		$issues_table = Schema::get_table_name('issues');
		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live scan/exception state in custom tables; caching can return stale worker or suppression state; Schema-owned identifiers and fixed SQL fragments; variable values are prepared separately.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$issues_table}`
				WHERE scan_item_id = %d
					AND violation_identity_v2 = %s
					AND identity_signature_version = %d",
				$scan_item_id,
				$identity,
				$signature_version
			)
		);
		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Downgrade ambiguous occurrence matches after a scan item is persisted.
	 *
	 * @param int $scan_item_id Scan item ID.
	 * @return int Number of matches changed to resemblance-only.
	 */
	public static function downgrade_colliding_matches(int $scan_item_id): int {
		global $wpdb;

		$issues_table = Schema::get_table_name('issues');
		$matches_table = self::get_matches_table();
		$rules_table = self::get_table();
		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Write to plugin-owned tables; no WordPress data API or cached result applies; Schema-owned identifiers and fixed SQL fragments; variable values are prepared separately.
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$matches_table}` matches
				INNER JOIN `{$issues_table}` issues ON issues.id = matches.violation_id
				INNER JOIN `{$rules_table}` rules ON rules.id = matches.exception_rule_id
				INNER JOIN (
					SELECT violation_identity_v2, identity_signature_version
					FROM `{$issues_table}`
					WHERE scan_item_id = %d
						AND violation_identity_v2 IS NOT NULL
					GROUP BY violation_identity_v2, identity_signature_version
					HAVING COUNT(*) > 1
				) collisions
					ON collisions.violation_identity_v2 = issues.violation_identity_v2
					AND collisions.identity_signature_version = issues.identity_signature_version
				SET matches.match_action = 'resembles',
					matches.match_confidence = 'partial'
				WHERE issues.scan_item_id = %d
					AND rules.target_type IN ('element', 'rule_on_element')
					AND matches.match_action = 'suppressed'",
				$scan_item_id,
				$scan_item_id
			)
		);
		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if (false === $result) {
			\cleara11y_debug_log(
				sprintf(
					'ClearA11y ERROR: Failed to downgrade ambiguous exception matches. scan_item_id=%d database_error=%s',
					$scan_item_id,
					'Database operation failed; raw details omitted to protect scan evidence.'
				)
			);
			return 0;
		}

		return (int) $result;
	}

	/**
	 * Get matches for a violation.
	 *
	 * @param int $violation_id Violation ID.
	 * @return array Array of matching exception rule IDs.
	 */
	public static function get_matches_for_violation(int $violation_id): array {
		global $wpdb;
		$table = self::get_table();

		$table = self::get_matches_table();

		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live scan/exception state in custom tables; caching can return stale worker or suppression state; Schema-owned identifiers and fixed SQL fragments; variable values are prepared separately.
		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT exception_rule_id FROM `{$table}` WHERE violation_id = %d",
				$violation_id
			)
		);
		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Get matches for an exception rule.
	 *
	 * @param string $exception_rule_id Exception rule ID.
	 * @return array Array of violation IDs.
	 */
	public static function get_violations_for_rule(string $exception_rule_id): array {
		global $wpdb;
		$table = self::get_table();

		$table = self::get_matches_table();

		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live scan/exception state in custom tables; caching can return stale worker or suppression state; Schema-owned identifiers and fixed SQL fragments; variable values are prepared separately.
		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT violation_id FROM `{$table}` WHERE exception_rule_id = %s",
				$exception_rule_id
			)
		);
		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Delete all matches for a rule.
	 *
	 * @param string $exception_rule_id Exception rule ID.
	 * @return int Number of rows deleted.
	 */
	public static function delete_matches_for_rule(string $exception_rule_id): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write to plugin-owned tables; no WordPress data API or cached result applies.
		return $wpdb->delete(
			self::get_matches_table(),
			['exception_rule_id' => $exception_rule_id],
			['%s']
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
