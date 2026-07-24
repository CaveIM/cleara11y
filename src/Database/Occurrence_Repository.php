<?php
/**
 * Canonical occurrence lifecycle persistence.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Database
 */

namespace ClearA11y\Database;

use ClearA11y\Models\Issue;
use ClearA11y\Services\Fingerprint_Service;

/**
 * Maintains live occurrence state while issue rows remain scan observations.
 */
class Occurrence_Repository {

	/**
	 * Get occurrence state table name.
	 *
	 * @return string
	 */
	public static function get_table(): string {
		return Schema::get_table_name('occurrence_states');
	}

	/**
	 * Check whether lifecycle storage is available.
	 *
	 * @return bool
	 */
	public static function table_exists(): bool {
		global $wpdb;

		$table = self::get_table();
		return $table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
	}

	/**
	 * Record one immutable scan observation against canonical live state.
	 *
	 * @param Issue $issue Newly persisted observation.
	 * @return bool True when state was recorded.
	 */
	public static function record_observation(Issue $issue): bool {
		global $wpdb;

		if (
			! self::table_exists()
			|| empty($issue->id)
			|| empty($issue->violation_identity_v2)
			|| empty($issue->element_identity_v2)
			|| empty($issue->identity_signature_version)
			|| empty($issue->page_object_key)
		) {
			return false;
		}

		$table = self::get_table();
		$site_id = get_current_blog_id();
		$observed_at = $issue->created_at ?: current_time('mysql');
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, status, reappearance_count
				FROM `{$table}`
				WHERE site_id = %d
					AND violation_identity_v2 = %s
					AND identity_signature_version = %d",
				$site_id,
				$issue->violation_identity_v2,
				$issue->identity_signature_version
			)
		);

		if ($existing) {
			$reappearance_count = (int) $existing->reappearance_count;
			if ('resolved' === $existing->status) {
				$reappearance_count++;
			}

			$result = $wpdb->update(
				$table,
				[
					'element_identity_v2' => $issue->element_identity_v2,
					'page_object_key' => $issue->page_object_key,
					'post_id' => $issue->post_id,
					'rule_id' => $issue->rule_id,
					'status' => 'active',
					'last_seen_at' => $observed_at,
					'resolved_at' => null,
					'reappearance_count' => $reappearance_count,
					'latest_issue_id' => $issue->id,
					'latest_scan_id' => $issue->scan_id,
				],
				['id' => (int) $existing->id],
				['%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d'],
				['%d']
			);
		} else {
			$result = $wpdb->insert(
				$table,
				[
					'site_id' => $site_id,
					'violation_identity_v2' => $issue->violation_identity_v2,
					'element_identity_v2' => $issue->element_identity_v2,
					'identity_signature_version' => $issue->identity_signature_version,
					'page_object_key' => $issue->page_object_key,
					'post_id' => $issue->post_id,
					'rule_id' => $issue->rule_id,
					'status' => 'active',
					'first_seen_at' => $observed_at,
					'last_seen_at' => $observed_at,
					'resolved_at' => null,
					'reappearance_count' => 0,
					'latest_issue_id' => $issue->id,
					'latest_scan_id' => $issue->scan_id,
				],
				['%d', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d']
			);
		}

		if (false === $result) {
			error_log(
				sprintf(
					'ClearA11y ERROR: Failed recording occurrence lifecycle. issue_id=%d identity=%s database_error=%s',
					$issue->id,
					$issue->violation_identity_v2,
					$wpdb->last_error
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Resolve active occurrences absent from a completed page observation.
	 *
	 * @param int      $scan_item_id Scan item that has finished.
	 * @param string[] $observed_identities Identities observed by this item.
	 * @return int Number of occurrences resolved.
	 */
	public static function resolve_absent_for_scan_item(int $scan_item_id, array $observed_identities): int {
		global $wpdb;

		if (! self::table_exists()) {
			return 0;
		}

		$items_table = Schema::get_table_name('scan_items');
		$item = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT post_id, post_url, scanned_at FROM `{$items_table}` WHERE id = %d",
				$scan_item_id
			)
		);
		if (! $item) {
			return 0;
		}

		$page_object_key = Fingerprint_Service::resolve_page_object_key(
			(string) $item->post_url,
			(int) $item->post_id
		);
		$where = [
			'site_id = %d',
			'page_object_key = %s',
			"status = 'active'",
		];
		$params = [get_current_blog_id(), $page_object_key];
		$observed_identities = array_values(
			array_unique(
				array_filter(array_map('strval', $observed_identities))
			)
		);
		if (! empty($observed_identities)) {
			$placeholders = implode(',', array_fill(0, count($observed_identities), '%s'));
			$where[] = "violation_identity_v2 NOT IN ({$placeholders})";
			$params = array_merge($params, $observed_identities);
		}

		$resolved_at = $item->scanned_at ?: current_time('mysql');
		$query = "UPDATE `" . self::get_table() . "`
			SET status = 'resolved', resolved_at = %s
			WHERE " . implode(' AND ', $where);
		$params = array_merge([$resolved_at], $params);
		$result = $wpdb->query($wpdb->prepare($query, ...$params));

		if (false === $result) {
			error_log(
				sprintf(
					'ClearA11y ERROR: Failed resolving absent occurrences. scan_item_id=%d database_error=%s',
					$scan_item_id,
					$wpdb->last_error
				)
			);
			return 0;
		}

		return (int) $result;
	}
}
