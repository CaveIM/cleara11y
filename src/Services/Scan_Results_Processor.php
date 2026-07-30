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
use ClearA11y\Database\Exception_Rule_Repository;
use ClearA11y\Database\Exception_Schema;
use ClearA11y\Database\Occurrence_Repository;
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

		// Build evidence index by result type, rule, and selector.
		$evidence_index = [];
		foreach ($evidence as $ev) {
			if (!empty($ev['selector'])) {
				$key = self::evidence_key(
					(string) ($ev['result_type'] ?? 'violation'),
					(string) ($ev['rule_id'] ?? ''),
					(string) $ev['selector']
				);
				$evidence_index[$key] = $ev;
			}
		}

		// Persist both confirmed violations and findings axe could not fully
		// classify. Incomplete findings require review, but hiding them creates a
		// dangerous false-negative in an auditing product.
		$findings = [
			'error' => $results['violations'] ?? [],
			'review' => $results['incomplete'] ?? [],
		];
		$issues_inserted = 0;
		$observed_identities = [];
		$lifecycle_recorded = 0;
		$evidence_diagnostics = [
			'expected' => 0,
			'matched' => 0,
			'persisted' => 0,
			'missing' => 0,
		];
		$severity_counts = [
			'critical' => 0,
			'moderate' => 0,
			'minor' => 0,
		];

		foreach ($findings as $finding_type => $rules) {
			foreach ($rules as $violation) {
				// Apply filters to allow skipping certain rules.
				if (!apply_filters('cleara11y_include_issue', true, $violation, $scan_item)) {
					continue;
				}

				foreach ($violation['nodes'] ?? [] as $node) {
					$issue_data = $violation;
					$issue_data['nodes'] = [$node];

					// Find matching evidence record.
					$node_selector = $node['target'][0] ?? null;
					$node_evidence = [];
					$result_type = 'review' === $finding_type ? 'incomplete' : 'violation';
					$selector_is_resolvable = is_string($node_selector) && '' !== $node_selector;
					if ($selector_is_resolvable) {
						$evidence_diagnostics['expected']++;
					}
					$evidence_key = self::evidence_key(
						$result_type,
						(string) ($violation['id'] ?? ''),
						$selector_is_resolvable ? $node_selector : ''
					);
					if ($selector_is_resolvable && isset($evidence_index[$evidence_key])) {
						$node_evidence = $evidence_index[$evidence_key];
						if (! empty($node_evidence['node_evidence'])) {
							$evidence_diagnostics['matched']++;
						}
					}

					$issue = Issue::from_axe_result(
						$issue_data,
						$scan_item->scan_id,
						$scan_item_id,
						$scan_item->post_id,
						$node_evidence,
						$scan_item->post_url
					);
					$issue->rule_type = $finding_type;
					$issue->result_type = $result_type;
					if ('review' === $finding_type && ! empty($node['failureSummary'])) {
						$issue->message = sanitize_textarea_field($node['failureSummary']);
					}

					$inserted_id = Issue_Repository::insert($issue);
					if ($inserted_id) {
						$issue->id = (int) $inserted_id;
						if ($selector_is_resolvable) {
							if (self::verify_evidence_persistence($issue)) {
								$evidence_diagnostics['persisted']++;
							} else {
								$evidence_diagnostics['missing']++;
							}
						}
						if (Exception_Schema::tables_exist()) {
							$exception_matches = Exception_Matcher_Service::find_matches($issue, get_current_blog_id());
							foreach ($exception_matches as $exception_match) {
								Exception_Rule_Repository::create_match(
									$issue->id,
									$exception_match['rule']->id,
									get_current_blog_id(),
									$exception_match['confidence'],
									$exception_match['action']
								);
							}
						}
						if (! empty($issue->violation_identity_v2)) {
							$observed_identities[] = $issue->violation_identity_v2;
							if (Occurrence_Repository::record_observation($issue)) {
								$lifecycle_recorded++;
							}
						}
						$issues_inserted++;
						$severity_counts[$issue->severity]++;
					}
				}
			}
		}

		if (Exception_Schema::tables_exist()) {
			Exception_Rule_Repository::downgrade_colliding_matches($scan_item_id);
			Exception_Rule_Repository::expire_snoozes_for_url(
				get_current_blog_id(),
				(string) $scan_item->post_url
			);
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

		// Only resolve absent occurrences when every inserted observation had a
		// v2 identity and was recorded. Partial evidence must never look like a
		// successful remediation.
		$lifecycle_complete = count($observed_identities) === $issues_inserted
			&& $lifecycle_recorded === count($observed_identities);
		if ($lifecycle_complete) {
			Occurrence_Repository::resolve_absent_for_scan_item(
				$scan_item_id,
				$observed_identities
			);
		} elseif (Occurrence_Repository::table_exists()) {
			error_log(
				sprintf(
					'ClearA11y WARNING: Occurrence resolution skipped because lifecycle evidence was incomplete. scan_item_id=%d issues=%d identities=%d recorded=%d',
					$scan_item_id,
					$issues_inserted,
					count($observed_identities),
					$lifecycle_recorded
				)
			);
		}

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
			'evidence' => $evidence_diagnostics,
		];
	}

	/**
	 * Create a collision-resistant lookup key for extracted node evidence.
	 *
	 * @param string $result_type Axe result type.
	 * @param string $rule_id Axe rule ID.
	 * @param string $selector Node selector.
	 * @return string
	 */
	private static function evidence_key(string $result_type, string $rule_id, string $selector): string {
		return $result_type . "\0" . $rule_id . "\0" . $selector;
	}

	/**
	 * Verify evidence survived the database write.
	 *
	 * @param Issue $issue Inserted issue.
	 * @return bool True when expected evidence is present and complete.
	 */
	private static function verify_evidence_persistence(Issue $issue): bool {
		$stored = Issue_Repository::get_by_id($issue->id);
		$expected_bytes = strlen((string) $issue->node_evidence);
		$stored_bytes = $stored ? strlen((string) $stored->node_evidence) : 0;
		$valid = $stored
			&& $expected_bytes > 0
			&& $expected_bytes === $stored_bytes
			&& ! empty($stored->xpath)
			&& ! empty($stored->fingerprint_strict)
			&& ! empty($stored->fingerprint_loose)
			&& ! empty($stored->source_key)
			&& ! empty($stored->page_object_key)
			&& ! empty($stored->element_identity_v2)
			&& ! empty($stored->element_identity_v2_inputs)
			&& ! empty($stored->violation_identity_v2)
			&& ! empty($stored->violation_identity_v2_inputs)
			&& \ClearA11y\Services\Fingerprint_Service::IDENTITY_SIGNATURE_VERSION === $stored->identity_signature_version;

		if (! $valid) {
			error_log(
				sprintf(
					'ClearA11y WARNING: Evidence missing or truncated after occurrence write. issue_id=%d scan_item_id=%d rule_id=%s result_type=%s expected_bytes=%d stored_bytes=%d',
					$issue->id,
					$issue->scan_item_id,
					sanitize_key($issue->rule_id),
					sanitize_key($issue->result_type),
					$expected_bytes,
					$stored_bytes
				)
			);
		}

		return $valid;
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
				'values' => self::get_wcag_tags((string) $wcag_level),
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
	 * Resolve a configured WCAG target to cumulative axe-core tags.
	 *
	 * Axe tags identify only the rules introduced at a particular version and
	 * level. A WCAG 2.1 AA scan therefore needs the 2.0 A/AA and 2.1 A/AA tags,
	 * rather than only the literal wcag21aa tag.
	 *
	 * @param string $wcag_level Configured WCAG target.
	 * @return array<int, string> Cumulative axe-core tags.
	 */
	public static function get_wcag_tags(string $wcag_level = 'wcag21aa'): array {
		$levels = [
			'wcag2a' => ['wcag2a'],
			'wcag2aa' => ['wcag2a', 'wcag2aa'],
			'wcag2aaa' => ['wcag2a', 'wcag2aa', 'wcag2aaa'],
			'wcag21a' => ['wcag2a', 'wcag21a'],
			'wcag21aa' => ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'],
		];

		$wcag_level = sanitize_key($wcag_level);

		return $levels[$wcag_level] ?? $levels['wcag21aa'];
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
