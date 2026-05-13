<?php
/**
 * Scan Results Processor
 *
 * Processes scan results from axe-core and stores them in the database.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Services
 */

namespace ClearA11y\Services;

use ClearA11y\Database\Issue_Repository;
use ClearA11y\Database\Scan_Repository;
use ClearA11y\Database\Scan_Item_Repository;
use ClearA11y\Models\Issue;
use ClearA11y\Models\Scan;
use ClearA11y\Models\Scan_Item;

// Force OPcache to reload this file
if (function_exists('opcache_invalidate')) {
    opcache_invalidate(__FILE__, true);
}

/**
 * Scan Results Processor Class
 */
class Scan_Results_Processor {

	/**
	 * Process scan results from axe-core.
	 *
	 * @param int   $scan_item_id Scan Item ID.
	 * @param array $results      Axe-core results.
	 * @param array $evidence     Evidence data from evidence extractor (optional).
	 * @return array {
	 *     Processing result.
	 *
	 *     @type bool   $success  Whether processing succeeded.
	 *     @type string $message  Result message.
	 *     @type array  $summary  Issue summary.
	 * }
	 */
	public static function process_results(int $scan_item_id, array $results, array $evidence = []): array {
		// Get scan item
		$scan_item = Scan_Item_Repository::get_by_id($scan_item_id);

		if (!$scan_item) {
			return [
				'success' => false,
				'message' => 'Scan item not found.',
			];
		}

		// Delete existing issues for this scan item
		$deleted = Issue_Repository::delete_by_scan_item_id($scan_item_id);

		// Build evidence index by selector for quick lookup
		$evidence_index = [];
		foreach ($evidence as $ev) {
			if (!empty($ev['selector'])) {
				$evidence_index[$ev['selector']] = $ev;
			}
		}

		// Process new issues
		$violations = $results['violations'] ?? [];
		$issues_inserted = 0;
		$severity_counts = [
			'critical' => 0,
			'moderate' => 0,
			'minor' => 0,
		];

		foreach ($violations as $violation) {
			// Apply filters to allow skipping certain rules
			if (!apply_filters('cleara11y_include_issue', true, $violation, $scan_item)) {
				continue;
			}

			foreach ($violation['nodes'] ?? [] as $node) {
				$issue_data = $violation;
				$issue_data['nodes'] = [$node];

				// Find matching evidence record
				$node_selector = $node['target'][0] ?? null;
				$node_evidence = [];
				if ($node_selector && isset($evidence_index[$node_selector])) {
					$node_evidence = $evidence_index[$node_selector];
				} else {
				}

				$issue = Issue::from_axe_result(
					$issue_data,
					$scan_item->scan_id,
					$scan_item_id,
					$scan_item->post_id,
					$node_evidence
				);

				$inserted_id = Issue_Repository::insert($issue);
				if ($inserted_id) {
					$issues_inserted++;
					$severity_counts[$issue->severity]++;
				}
			}
		}

		// Calculate scoring data
		$scoring_data = Scoring_Service::calculate_score($results);

		// Update scan item with results
		$scan_item->status = 'completed';
		$scan_item->total_issues = $issues_inserted;
		$scan_item->critical_issues = $severity_counts['critical'];
		$scan_item->moderate_issues = $severity_counts['moderate'];
		$scan_item->minor_issues = $severity_counts['minor'];
		$scan_item->scanned_at = \current_time('mysql');

		// Add scoring data to scan item
		$scan_item->rules_checked = $scoring_data['total_rules'];
		$scan_item->rules_passed = $scoring_data['passed_count'];
		$scan_item->rules_failed = $scoring_data['failed_count'];
		$scan_item->rules_incomplete = $scoring_data['incomplete_count'];
		$scan_item->pass_percentage = $scoring_data['pass_percentage'];
		$scan_item->fail_percentage = $scoring_data['fail_percentage'];
		$scan_item->score_grade = $scoring_data['grade'];
		$scan_item->rules_checked_list = !empty($scoring_data['rules_checked']) ? wp_json_encode($scoring_data['rules_checked']) : null;
		$scan_item->rules_passed_list = !empty($scoring_data['rules_passed']) ? wp_json_encode($scoring_data['rules_passed']) : null;
		$scan_item->rules_failed_list = !empty($scoring_data['rules_failed']) ? wp_json_encode($scoring_data['rules_failed']) : null;
		$scan_item->rules_incomplete_list = !empty($scoring_data['rules_incomplete']) ? wp_json_encode($scoring_data['rules_incomplete']) : null;

		Scan_Item_Repository::update($scan_item);

		// Update parent scan
		self::update_scan_progress($scan_item->scan_id, $severity_counts);

		// Delete the scan token
		$token = \ClearA11y\Frontend\Scanner::get_current_token();
		if ($token) {
			\ClearA11y\Services\Scan_Token_Manager::delete_token($token);
		}

		\do_action('cleara11y_scan_results_processed', $scan_item, $results, $severity_counts, $scoring_data);

		return [
			'success' => true,
			'message' => 'Scan results processed successfully.',
			'summary' => [
				'total_issues' => $issues_inserted,
				'critical' => $severity_counts['critical'],
				'moderate' => $severity_counts['moderate'],
				'minor' => $severity_counts['minor'],
			],
			'scoring' => Scoring_Service::format_for_display($scoring_data),
		];
	}

	/**
	 * Handle scan error.
	 *
	 * @param int    $scan_item_id Scan Item ID.
	 * @param string $error_message Error message.
	 * @return bool True if handled successfully.
	 */
	public static function handle_error(int $scan_item_id, string $error_message): bool {
		$scan_item = Scan_Item_Repository::get_by_id($scan_item_id);

		if (!$scan_item) {
			return false;
		}

		// Update scan item with error status
		$scan_item->status = 'failed';
		$scan_item->error_message = $error_message;
		$scan_item->scanned_at = \current_time('mysql');

		Scan_Item_Repository::update($scan_item);

		// Update parent scan
		$scan = Scan_Repository::get_by_id($scan_item->scan_id);

		if ($scan) {
			$scan->scanned_items++;
			Scan_Repository::update($scan);

			// Check if all items are done (completed or failed)
			$total_items = Scan_Item_Repository::get_count($scan_item->scan_id);
			$completed_items = Scan_Item_Repository::get_count($scan_item->scan_id, 'completed');
			$failed_items = Scan_Item_Repository::get_count($scan_item->scan_id, 'failed');

			if ($total_items === ($completed_items + $failed_items)) {
				$scan->status = 'completed';
				$scan->completed_at = \current_time('mysql');
				Scan_Repository::update($scan);
					// Expire "until_next_scan" ignore rules for this page/post
					self::expire_until_next_scan_rules($scan->post_id);
			}
		}

		return true;
	}

	/**
	 * Update parent scan progress after processing a scan item.
	 *
	 * @param int   $scan_id          Scan ID.
	 * @param array $severity_counts  Severity counts to add.
	 * @return void
	 */
	private static function update_scan_progress(int $scan_id, array $severity_counts): void {
		$scan = Scan_Repository::get_by_id($scan_id);

		if (!$scan) {
			return;
		}

		// Recalculate totals from all scan items (not increment)
		// This ensures re-scans replace old counts instead of adding to them
		$scan_items = Scan_Item_Repository::get_by_scan_id($scan_id);

		$total_issues = 0;
		$critical_issues = 0;
		$moderate_issues = 0;
		$minor_issues = 0;
		$scanned_items = 0;


		foreach ($scan_items as $item) {
			if ($item->status === 'completed' || $item->status === 'failed') {
				$scanned_items++;
			}
			if ($item->status === 'completed') {
				$total_issues += (int) $item->total_issues;
				$critical_issues += (int) $item->critical_issues;
				$moderate_issues += (int) $item->moderate_issues;
				$minor_issues += (int) $item->minor_issues;
				}
			}

			$scan->scanned_items = $scanned_items;
		$scan->total_issues = $total_issues;
		$scan->critical_issues = $critical_issues;
		$scan->moderate_issues = $moderate_issues;
		$scan->minor_issues = $minor_issues;

		// Check if scan is complete
		$total_items = count($scan_items);
		$completed_items = Scan_Item_Repository::get_count($scan_id, 'completed');
		$failed_items = Scan_Item_Repository::get_count($scan_id, 'failed');

		if ($total_items === ($completed_items + $failed_items)) {
			$scan->status = 'completed';
					// Expire "until_next_scan" ignore rules for this page/post
					self::expire_until_next_scan_rules($scan->post_id);
			$scan->completed_at = \current_time('mysql');
		}

		Scan_Repository::update($scan);
	}

	/**
	 * Get axe-core configuration.
	 *
	 * @return array Axe-core run options.
	 */
	public static function get_axe_config(): array {
		$wcag_level = \get_option('cleara11y_wcag_level', 'wcag21aa');

		$default_config = [
			'runOnly' => [
				'type' => 'tag',
				'values' => [$wcag_level],
			],
			// Note: reporter option removed for axe-core v4.x compatibility
			// In v4, the reporter format is auto-detected
			'resultTypes' => ['violations', 'passes', 'incomplete', 'inapplicable'],
		];

		/**
		 * Filter axe-core configuration.
		 *
		 * @param array $config Axe-core configuration.
		 */
		return apply_filters('cleara11y_axe_config', $default_config);
	}

	/**
	 * Format results for display.
	 *
	 * @param int $scan_item_id Scan Item ID.
	 * @return array Formatted results.
	 */
	public static function get_formatted_results(int $scan_item_id): array {
		$issues = Issue_Repository::get_by_scan_item_id($scan_item_id);
		$scan_item = Scan_Item_Repository::get_by_id($scan_item_id);

		if (!$scan_item) {
			return [];
		}

		// Group issues by rule
		$grouped = [];
		foreach ($issues as $issue) {
			if (!isset($grouped[$issue->rule_id])) {
				$grouped[$issue->rule_id] = [
					'rule_id' => $issue->rule_id,
					'message' => $issue->message,
					'help_text' => $issue->help_text,
					'help_url' => $issue->help_url,
					'wcag_criterion' => $issue->wcag_criterion,
					'severity' => $issue->severity,
					'count' => 0,
					'nodes' => [],
				];
			}

			$grouped[$issue->rule_id]['count']++;
			$grouped[$issue->rule_id]['nodes'][] = [
				'id' => $issue->id,
				'selector' => $issue->selector,
				'html' => $issue->html,
				'dismissed' => $issue->dismissed,
			];
		}

		// Sort by severity then count
		uasort($grouped, function($a, $b) {
			$severity_order = ['critical' => 0, 'moderate' => 1, 'minor' => 2];
			$a_severity = $severity_order[$a['severity']] ?? 3;
			$b_severity = $severity_order[$b['severity']] ?? 3;

			if ($a_severity !== $b_severity) {
				return $a_severity - $b_severity;
			}

			return $b['count'] - $a['count'];
		});

		return [
			'scan_item' => [
				'id' => $scan_item->id,
				'post_id' => $scan_item->post_id,
				'post_title' => $scan_item->post_title,
				'post_url' => $scan_item->post_url,
				'status' => $scan_item->status,
				'scanned_at' => $scan_item->scanned_at,
			],
			'summary' => [
				'total_issues' => count($issues),
				'critical' => $scan_item->critical_issues,
				'moderate' => $scan_item->moderate_issues,
				'minor' => $scan_item->minor_issues,
			],
			'scoring' => $scan_item->get_scoring_data(),
			'issues' => array_values($grouped),
		];
	}
}

	/**
	 * Expire "until_next_scan" ignore rules for a specific post.
	 *
	 * When a scan completes, all "until_next_scan" rules for that post
	 * should be expired so that if issues still exist, they reappear.
	 *
	 * @param int $post_id The post ID that was scanned.
	 * @return void
	 */
	private static function expire_until_next_scan_rules(int $post_id): void {
		global $wpdb;

		$ignore_rules_table = \ClearA11y\Database\Ignore_Schema::get_table_name('ignore_rules');

		// Find all "until_next_scan" rules for this post/page
		// We need to check the scope JSON for the post URL
		$post = get_post($post_id);
		if (!$post) {
			return;
		}

		$post_url = get_permalink($post_id);

		// Update rules to expire them
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$ignore_rules_table}`
				SET status = 'expired', updated_at = NOW()
				WHERE status = 'active'
				AND system_generated = 1
				AND JSON_EXTRACT(duration, '$.duration_type') = 'until_next_scan'
				AND JSON_EXTRACT(scope, '$.url') = %s",
				$post_url
			)
		);
	}
