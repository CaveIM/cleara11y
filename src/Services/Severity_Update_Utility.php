<?php
/**
 * Severity Update Utility
 *
 * Utility for updating issue severities based on the new rule-based classification.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Services
 */

namespace ClearA11y\Services;

use ClearA11y\Database\Issue_Repository;

// Force OPcache to reload this file
if (function_exists('opcache_invalidate')) {
	opcache_invalidate(__FILE__, true);
}

/**
 * Severity Update Utility Class
 */
class Severity_Update_Utility {

	/**
	 * Update all existing issues to use the new rule-based severity classification.
	 *
	 * @return array {
	 *     Update result.
	 *
	 *     @type bool   $success Whether update succeeded.
	 *     @type string $message Result message.
	 *     @type array  $stats   Update statistics.
	 * }
	 */
	public static function update_all_issues(): array {
		global $wpdb;

		$table = Issue_Repository::get_table();
		$map = Rule_Severity_Map::get_severity_map();

		// Get all issues
		$issues = $wpdb->get_results(
			"SELECT id, rule_id, severity FROM {$table}",
			ARRAY_A
		);

		if (empty($issues)) {
			return [
				'success' => true,
				'message' => __('No issues found to update.', 'cleara11y'),
				'stats' => [
					'total' => 0,
					'updated' => 0,
					'unchanged' => 0,
				],
			];
		}

		$updated = 0;
		$unchanged = 0;
		$changes = [];

		foreach ($issues as $issue) {
			$rule_id = $issue['rule_id'];
			$old_severity = $issue['severity'];

			// Calculate new severity using rule-based mapping
			$numeric_severity = Rule_Severity_Map::get_severity($rule_id);
			$new_severity = Rule_Severity_Map::severity_to_category($numeric_severity);

			// Only update if severity changed
			if ($new_severity !== $old_severity) {
				$wpdb->update(
					$table,
					['severity' => $new_severity],
					['id' => $issue['id']],
					['%s'],
					['%d']
				);

				$updated++;

				// Track changes for reporting
				if (!isset($changes[$rule_id])) {
					$changes[$rule_id] = [
						'old' => $old_severity,
						'new' => $new_severity,
						'count' => 0,
					];
				}
				$changes[$rule_id]['count']++;
			} else {
				$unchanged++;
			}
		}

		return [
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of issues updated */
				__('Updated %d issues with new severity classification.', 'cleara11y'),
				$updated
			),
			'stats' => [
				'total' => count($issues),
				'updated' => $updated,
				'unchanged' => $unchanged,
			],
			'changes' => $changes,
		];
	}

	/**
	 * Get severity update preview without applying changes.
	 *
	 * @return array Preview of changes that would be made.
	 */
	public static function preview_updates(): array {
		global $wpdb;

		$table = Issue_Repository::get_table();

		// Get all issues
		$issues = $wpdb->get_results(
			"SELECT id, rule_id, severity FROM {$table}",
			ARRAY_A
		);

		$changes = [];
		$severity_counts = [
			'critical' => ['before' => 0, 'after' => 0],
			'moderate' => ['before' => 0, 'after' => 0],
			'minor' => ['before' => 0, 'after' => 0],
		];

		foreach ($issues as $issue) {
			$rule_id = $issue['rule_id'];
			$old_severity = $issue['severity'];

			// Count old severities
			if (isset($severity_counts[$old_severity])) {
				$severity_counts[$old_severity]['before']++;
			}

			// Calculate new severity
			$numeric_severity = Rule_Severity_Map::get_severity($rule_id);
			$new_severity = Rule_Severity_Map::severity_to_category($numeric_severity);

			// Count new severities
			if (isset($severity_counts[$new_severity])) {
				$severity_counts[$new_severity]['after']++;
			}

			// Track changes
			if ($new_severity !== $old_severity) {
				if (!isset($changes[$rule_id])) {
					$changes[$rule_id] = [
						'old' => $old_severity,
						'new' => $new_severity,
						'count' => 0,
					];
				}
				$changes[$rule_id]['count']++;
			}
		}

		return [
			'severity_counts' => $severity_counts,
			'affected_rules' => count($changes),
			'total_issues' => count($issues),
			'changes' => $changes,
		];
	}

	/**
	 * Register admin AJAX handler for severity update.
	 *
	 * @return void
	 */
	public static function register_ajax_handler(): void {
		add_action('wp_ajax_cleara11y_update_severity', [__CLASS__, 'handle_ajax_request']);
		add_action('wp_ajax_cleara11y_preview_severity_update', [__CLASS__, 'handle_preview_request']);
	}

	/**
	 * Handle AJAX request for severity update.
	 *
	 * @return void
	 */
	public static function handle_ajax_request(): void {
		check_ajax_referer('cleara11y_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Permission denied.', 'cleara11y')]);
		}

		$result = self::update_all_issues();

		if ($result['success']) {
			wp_send_json_success($result);
		} else {
			wp_send_json_error($result);
		}
	}

	/**
	 * Handle AJAX request for severity update preview.
	 *
	 * @return void
	 */
	public static function handle_preview_request(): void {
		check_ajax_referer('cleara11y_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Permission denied.', 'cleara11y')]);
		}

		$preview = self::preview_updates();
		wp_send_json_success($preview);
	}
}
