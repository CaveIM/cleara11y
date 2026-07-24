<?php
/**
 * Transaction-backed integration test for fail-safe suppression matching.
 *
 * Run with:
 * wp eval-file tests/integration/suppression-matching.php --path=/var/www/html --allow-root
 */

use ClearA11y\Database\Ignore_Rule_Repository;
use ClearA11y\Database\Ignore_Schema;
use ClearA11y\Models\Ignore_Rule;
use ClearA11y\Models\Issue;
use ClearA11y\Services\Fingerprint_Service;
use ClearA11y\Services\Ignore_Matcher_Service;

global $wpdb;

if (! Ignore_Schema::add_v2_matching_columns()) {
	throw new RuntimeException('Could not migrate v2 suppression matching fields.');
}

$wpdb->query('START TRANSACTION');

try {
	$site_id = 987654;
	$issue = new Issue();
	$issue->rule_id = 'button-name';
	$issue->selector = 'main > button:nth-child(2)';
	$issue->element_identity_v2 = hash('sha256', 'element-a');
	$issue->violation_identity_v2 = hash('sha256', 'violation-a');
	$issue->identity_signature_version = Fingerprint_Service::IDENTITY_SIGNATURE_VERSION;

	$exact_rule = new Ignore_Rule();
	$exact_rule->id = wp_generate_uuid4();
	$exact_rule->site_id = $site_id;
	$exact_rule->status = 'active';
	$exact_rule->target_type = 'rule_on_element';
	$exact_rule->rule_ids = ['button-name'];
	$exact_rule->element_match = ['css_selector' => $issue->selector];
	$exact_rule->scope = ['scope_type' => 'site'];
	$exact_rule->duration = ['duration_type' => 'permanent'];
	$exact_rule->created_at = current_time('mysql');
	$exact_rule->violation_identity_v2 = $issue->violation_identity_v2;
	$exact_rule->element_identity_v2 = $issue->element_identity_v2;
	$exact_rule->identity_signature_version = Fingerprint_Service::IDENTITY_SIGNATURE_VERSION;
	$exact_rule->legacy_reanchor_status = 'anchored';
	Ignore_Rule_Repository::insert($exact_rule);

	$match = Ignore_Matcher_Service::matches_rule($issue, $exact_rule);
	if (
		'suppressed' !== ($match['action'] ?? null)
		|| 'violation_identity_v2' !== ($match['matched_by'] ?? null)
	) {
		throw new RuntimeException('Exact violation identity did not suppress.');
	}

	$moved_issue = clone $issue;
	$moved_issue->violation_identity_v2 = hash('sha256', 'violation-on-another-page');
	$match = Ignore_Matcher_Service::matches_rule($moved_issue, $exact_rule);
	if (
		'resembles' !== ($match['action'] ?? null)
		|| 'element_identity_v2' !== ($match['matched_by'] ?? null)
	) {
		throw new RuntimeException('Element-only identity did not produce a non-suppressing resemblance.');
	}

	$unrelated_issue = clone $moved_issue;
	$unrelated_issue->element_identity_v2 = hash('sha256', 'different-element');
	if (null !== Ignore_Matcher_Service::matches_rule($unrelated_issue, $exact_rule)) {
		throw new RuntimeException('A weak occurrence match must not suppress or resemble.');
	}

	$scoped_rule = new Ignore_Rule();
	$scoped_rule->target_type = 'rule';
	$scoped_rule->rule_ids = ['button-name'];
	$scoped_rule->scope = ['scope_type' => 'site'];
	$match = Ignore_Matcher_Service::matches_rule($issue, $scoped_rule);
	if ('suppressed' !== ($match['action'] ?? null) || 'rule_only' !== ($match['matched_by'] ?? null)) {
		throw new RuntimeException('Explicit scoped rule behavior changed.');
	}

	$legacy_rule = new Ignore_Rule();
	$legacy_rule->id = wp_generate_uuid4();
	$legacy_rule->site_id = $site_id;
	$legacy_rule->status = 'active';
	$legacy_rule->target_type = 'rule_on_element';
	$legacy_rule->rule_ids = ['button-name'];
	$legacy_rule->element_match = ['css_selector' => $issue->selector];
	$legacy_rule->scope = ['scope_type' => 'site'];
	$legacy_rule->duration = ['duration_type' => 'permanent'];
	$legacy_rule->created_at = current_time('mysql');
	$legacy_rule->legacy_reanchor_status = 'pending';
	Ignore_Rule_Repository::insert($legacy_rule);

	$match = Ignore_Matcher_Service::matches_rule($issue, $legacy_rule);
	$stored_legacy_rule = Ignore_Rule_Repository::get_by_id($legacy_rule->id);
	if (
		'legacy_reanchored_v2' !== ($match['matched_by'] ?? null)
		|| ! $stored_legacy_rule
		|| 'anchored' !== $stored_legacy_rule->legacy_reanchor_status
		|| $issue->violation_identity_v2 !== $stored_legacy_rule->violation_identity_v2
	) {
		throw new RuntimeException('Legacy selector exception was not re-anchored exactly once.');
	}

	$issue_after_selector_churn = clone $issue;
	$issue_after_selector_churn->selector = 'main > section > button:nth-child(9)';
	$match = Ignore_Matcher_Service::matches_rule($issue_after_selector_churn, $stored_legacy_rule);
	if ('violation_identity_v2' !== ($match['matched_by'] ?? null)) {
		throw new RuntimeException('Re-anchored exception continued relying on its selector.');
	}

	$unmatched_rule = clone $legacy_rule;
	$unmatched_rule->id = wp_generate_uuid4();
	$unmatched_rule->element_match = ['css_selector' => '.missing-forever'];
	$unmatched_rule->legacy_reanchor_status = 'pending';
	Ignore_Rule_Repository::insert($unmatched_rule);

	$partial_scan = new \ClearA11y\Models\Scan();
	$partial_scan->scan_type = 'individual';
	$partial_scan->status = 'completed';
	$partial_scan->total_items = 1;
	$partial_scan->created_at = current_time('mysql');
	$partial_scan_id = \ClearA11y\Database\Scan_Repository::insert($partial_scan);

	$partial_item = new \ClearA11y\Models\Scan_Item();
	$partial_item->scan_id = $partial_scan_id;
	$partial_item->post_id = 999;
	$partial_item->post_type = 'page';
	$partial_item->post_title = 'Unrelated partial scan';
	$partial_item->post_url = 'https://example.test/unrelated/';
	$partial_item->status = 'completed';
	$partial_item->created_at = current_time('mysql');
	\ClearA11y\Database\Scan_Item_Repository::insert($partial_item);

	$partial_report = Ignore_Rule_Repository::finalize_legacy_reanchoring(
		$site_id,
		gmdate('Y-m-d H:i:s', time() - 60),
		$partial_scan_id
	);
	$still_pending_rule = Ignore_Rule_Repository::get_by_id($unmatched_rule->id);
	if (
		0 !== $partial_report['unmatched']
		|| ! $still_pending_rule
		|| 'pending' !== $still_pending_rule->legacy_reanchor_status
	) {
		throw new RuntimeException('A partial scan finalized an exception outside its coverage.');
	}

	$report = Ignore_Rule_Repository::finalize_legacy_reanchoring($site_id, gmdate('Y-m-d H:i:s', time() - 60));
	$stored_unmatched_rule = Ignore_Rule_Repository::get_by_id($unmatched_rule->id);
	if (
		1 !== $report['anchored']
		|| 1 !== $report['unmatched']
		|| ! $stored_unmatched_rule
		|| 'unmatched' !== $stored_unmatched_rule->legacy_reanchor_status
	) {
		throw new RuntimeException('Legacy re-anchor completion report was incorrect.');
	}

	echo "Fail-safe suppression matching integration test passed.\n";
} finally {
	$wpdb->query('ROLLBACK');
}
