<?php
/**
 * Transaction-backed integration test for fail-safe suppression matching.
 *
 * Run with:
 * wp eval-file tests/integration/exception-matching.php --path=/var/www/html --allow-root
 */

use ClearA11y\Database\Exception_Rule_Repository;
use ClearA11y\Database\Exception_Schema;
use ClearA11y\Database\Issue_Repository;
use ClearA11y\Database\Scan_Item_Repository;
use ClearA11y\Database\Scan_Repository;
use ClearA11y\Models\Exception_Rule;
use ClearA11y\Models\Issue;
use ClearA11y\Models\Scan;
use ClearA11y\Models\Scan_Item;
use ClearA11y\Services\Fingerprint_Service;
use ClearA11y\Services\Exception_Matcher_Service;

global $wpdb;

if (! Exception_Schema::add_v2_matching_columns()) {
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

	$exact_rule = new Exception_Rule();
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
	Exception_Rule_Repository::insert($exact_rule);

	$match = Exception_Matcher_Service::matches_rule($issue, $exact_rule);
	if (
		'suppressed' !== ($match['action'] ?? null)
		|| 'violation_identity_v2' !== ($match['matched_by'] ?? null)
	) {
		throw new RuntimeException('Exact violation identity did not suppress.');
	}

	$collision_scan = new Scan();
	$collision_scan->scan_type = 'individual';
	$collision_scan->status = 'completed';
	$collision_scan->created_at = current_time('mysql');
	$collision_scan_id = Scan_Repository::insert($collision_scan);
	$collision_item = new Scan_Item();
	$collision_item->scan_id = $collision_scan_id;
	$collision_item->post_id = 0;
	$collision_item->post_url = 'https://example.test/exception-collision/';
	$collision_item->status = 'completed';
	$collision_item->created_at = current_time('mysql');
	$collision_item_id = Scan_Item_Repository::insert($collision_item);

	$collision_issue = clone $issue;
	$collision_issue->scan_id = $collision_scan_id;
	$collision_issue->scan_item_id = $collision_item_id;
	$collision_issue->created_at = current_time('mysql');
	$collision_issue->id = (int) Issue_Repository::insert($collision_issue);
	$collision_duplicate = clone $collision_issue;
	$collision_duplicate->id = (int) Issue_Repository::insert($collision_duplicate);

	$match = Exception_Matcher_Service::matches_rule($collision_issue, $exact_rule);
	if (
		'resembles' !== ($match['action'] ?? null)
		|| 'violation_identity_collision' !== ($match['matched_by'] ?? null)
	) {
		throw new RuntimeException('A colliding occurrence identity was allowed to suppress.');
	}

	$moved_issue = clone $issue;
	$moved_issue->violation_identity_v2 = hash('sha256', 'violation-on-another-page');
	$match = Exception_Matcher_Service::matches_rule($moved_issue, $exact_rule);
	if (
		'resembles' !== ($match['action'] ?? null)
		|| 'element_identity_v2' !== ($match['matched_by'] ?? null)
	) {
		throw new RuntimeException('Element-only identity did not produce a non-suppressing resemblance.');
	}

	$unrelated_issue = clone $moved_issue;
	$unrelated_issue->element_identity_v2 = hash('sha256', 'different-element');
	if (null !== Exception_Matcher_Service::matches_rule($unrelated_issue, $exact_rule)) {
		throw new RuntimeException('A weak occurrence match must not suppress or resemble.');
	}

	$scoped_rule = new Exception_Rule();
	$scoped_rule->target_type = 'rule';
	$scoped_rule->rule_ids = ['button-name'];
	$scoped_rule->scope = ['scope_type' => 'site'];
	$match = Exception_Matcher_Service::matches_rule($issue, $scoped_rule);
	if ('suppressed' !== ($match['action'] ?? null) || 'rule_only' !== ($match['matched_by'] ?? null)) {
		throw new RuntimeException('Explicit scoped rule behavior changed.');
	}

	$legacy_rule = new Exception_Rule();
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
	Exception_Rule_Repository::insert($legacy_rule);

	$match = Exception_Matcher_Service::matches_rule($issue, $legacy_rule);
	if (null !== $match) {
		throw new RuntimeException('A selector-only occurrence exception was allowed to match.');
	}

	$element_rule = clone $exact_rule;
	$element_rule->target_type = 'element';
	$other_rule_issue = clone $issue;
	$other_rule_issue->rule_id = 'aria-valid-attr-value';
	$other_rule_issue->violation_identity_v2 = hash('sha256', 'another-rule-same-element');
	$match = Exception_Matcher_Service::matches_rule($other_rule_issue, $element_rule);
	if ('suppressed' !== ($match['action'] ?? null)) {
		throw new RuntimeException('All-rules element target did not suppress a different rule.');
	}
	$match = Exception_Matcher_Service::matches_rule($collision_issue, $element_rule);
	if ('resembles' !== ($match['action'] ?? null)) {
		throw new RuntimeException('All-rules element target suppressed an ambiguous identity.');
	}
	$element_rule->scope = ['scope_type' => 'content_type', 'post_types' => ['post']];
	if (null !== Exception_Matcher_Service::matches_rule($other_rule_issue, $element_rule)) {
		throw new RuntimeException('Element matching ignored an excluded scope.');
	}

	// Next-scan expiry must use page scope even when the scan finds no issues.
	$expiry_cases = [
		[['scope_type' => 'site'], true],
		[['scope_type' => 'page', 'url' => 'https://example.test/qa/item/'], true],
		[['scope_type' => 'page', 'url' => 'https://example.test/other/'], false],
		[['scope_type' => 'content_type', 'post_types' => ['page']], true],
		[['scope_type' => 'content_type', 'post_types' => ['post']], false],
		[['scope_type' => 'url_pattern', 'patterns' => ['*/qa/*']], true],
		[['scope_type' => 'url_pattern', 'patterns' => ['*/other/*']], false],
	];
	foreach ($expiry_cases as [$scope, $should_expire]) {
		$rule = clone $exact_rule;
		$rule->id = wp_generate_uuid4();
		$rule->scope = $scope;
		$rule->duration = ['duration_type' => 'until_next_scan'];
		$rule->system_generated = false;
		Exception_Rule_Repository::insert($rule);
		Exception_Rule_Repository::expire_snoozes_for_url($site_id, 'https://example.test/qa/item/', 'page');
		$status = Exception_Rule_Repository::get_by_id($rule->id)->status;
		if (($should_expire ? 'expired' : 'active') !== $status) {
			throw new RuntimeException('Wrong next-scan expiry for ' . wp_json_encode($scope));
		}
	}

	$prepare_notices = [];
	$notice_listener = static function ($function) use (&$prepare_notices) {
		if ('wpdb::prepare' === $function) {
			$prepare_notices[] = $function;
		}
	};
	add_action('doing_it_wrong_run', $notice_listener);
	try {
		$impact_rule = clone $exact_rule;
		$impact_rule->target_type = 'rule';
		$impact_rule->rule_ids = [];
		$impact_rule->scope = ['scope_type' => 'site'];
		$impact = Exception_Matcher_Service::calculate_impact($impact_rule, $site_id);
		if ($prepare_notices || $wpdb->last_error || $impact['issues'] < 2) {
			throw new RuntimeException('Unfiltered impact must count fixtures without invalid SQL preparation.');
		}
		$impact_rule->rule_ids = ['nonexistent-rule-with-quote\''];
		$impact = Exception_Matcher_Service::calculate_impact($impact_rule, $site_id);
		if ($prepare_notices || $wpdb->last_error || 0 !== $impact['issues']) {
			throw new RuntimeException('Filtered impact must safely bind rule values.');
		}
	} finally {
		remove_action('doing_it_wrong_run', $notice_listener);
	}

	echo "Fail-safe suppression matching integration test passed.\n";
} finally {
	$wpdb->query('ROLLBACK');
}
