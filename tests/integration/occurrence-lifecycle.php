<?php
/**
 * Transaction-backed canonical occurrence lifecycle test.
 *
 * Run with:
 * wp eval-file tests/integration/occurrence-lifecycle.php --path=/var/www/html --allow-root
 */

use ClearA11y\Database\Occurrence_Repository;
use ClearA11y\Database\Scan_Item_Repository;
use ClearA11y\Database\Scan_Repository;
use ClearA11y\Models\Issue;
use ClearA11y\Models\Scan;
use ClearA11y\Models\Scan_Item;
use ClearA11y\Services\Fingerprint_Service;

global $wpdb;

if (! \ClearA11y\Database\Schema::add_occurrence_state_table()) {
	throw new RuntimeException('Could not create occurrence lifecycle table.');
}

$wpdb->query('START TRANSACTION');

try {
	$fixture_id = wp_generate_uuid4();
	$post_id = 0;
	$page_url = 'https://example.test/lifecycle/' . rawurlencode($fixture_id) . '/';
	$page_key = Fingerprint_Service::resolve_page_object_key($page_url, $post_id);
	$element_identity = hash('sha256', 'lifecycle-element-' . $fixture_id);
	$violation_identity = hash('sha256', 'lifecycle-violation-' . $fixture_id);

	$make_scan_item = static function() use ($post_id, $page_url): array {
		$scan = new Scan();
		$scan->scan_type = 'individual';
		$scan->status = 'completed';
		$scan->total_items = 1;
		$scan->scanned_items = 1;
		$scan->started_at = current_time('mysql');
		$scan->completed_at = current_time('mysql');
		$scan->created_at = current_time('mysql');
		$scan_id = Scan_Repository::insert($scan);

		$item = new Scan_Item();
		$item->scan_id = $scan_id;
		$item->post_id = $post_id;
		$item->post_type = 'post';
		$item->post_title = 'Lifecycle fixture';
		$item->post_url = $page_url;
		$item->status = 'completed';
		$item->scanned_at = current_time('mysql');
		$item->created_at = current_time('mysql');
		$item_id = Scan_Item_Repository::insert($item);

		return [$scan_id, $item_id];
	};

	$make_issue = static function(int $id, int $scan_id, int $item_id) use (
		$post_id,
		$page_key,
		$element_identity,
		$violation_identity
	): Issue {
		$issue = new Issue();
		$issue->id = $id;
		$issue->scan_id = $scan_id;
		$issue->scan_item_id = $item_id;
		$issue->post_id = $post_id;
		$issue->rule_id = 'button-name';
		$issue->page_object_key = $page_key;
		$issue->element_identity_v2 = $element_identity;
		$issue->violation_identity_v2 = $violation_identity;
		$issue->identity_signature_version = Fingerprint_Service::IDENTITY_SIGNATURE_VERSION;
		$issue->created_at = current_time('mysql');
		return $issue;
	};

	[$scan_one, $item_one] = $make_scan_item();
	$first = $make_issue(900001, $scan_one, $item_one);
	if (
		! Occurrence_Repository::record_observation($first)
		|| 0 !== Occurrence_Repository::resolve_absent_for_scan_item($item_one, [$violation_identity])
	) {
		throw new RuntimeException('Could not establish the initial active occurrence.');
	}

	[$scan_two, $item_two] = $make_scan_item();
	$persisting = $make_issue(900002, $scan_two, $item_two);
	Occurrence_Repository::record_observation($persisting);
	Occurrence_Repository::resolve_absent_for_scan_item($item_two, [$violation_identity]);

	$table = Occurrence_Repository::get_table();
	$state = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM `{$table}` WHERE violation_identity_v2 = %s",
			$violation_identity
		)
	);
	if (
		! $state
		|| 'active' !== $state->status
		|| 900002 !== (int) $state->latest_issue_id
		|| 0 !== (int) $state->reappearance_count
	) {
		throw new RuntimeException('Persisting observation did not retain one canonical occurrence.');
	}

	[, $item_three] = $make_scan_item();
	if (1 !== Occurrence_Repository::resolve_absent_for_scan_item($item_three, [])) {
		throw new RuntimeException('Deleting or fixing an occurrence did not resolve it.');
	}

	[$scan_four, $item_four] = $make_scan_item();
	$reappeared = $make_issue(900004, $scan_four, $item_four);
	Occurrence_Repository::record_observation($reappeared);
	Occurrence_Repository::resolve_absent_for_scan_item($item_four, [$violation_identity]);

	$state = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM `{$table}` WHERE violation_identity_v2 = %s",
			$violation_identity
		)
	);
	if (
		'active' !== $state->status
		|| 1 !== (int) $state->reappearance_count
		|| 900004 !== (int) $state->latest_issue_id
		|| null !== $state->resolved_at
	) {
		throw new RuntimeException('Regression/reappearance state was not recorded correctly.');
	}

	echo "Canonical occurrence lifecycle integration test passed.\n";
} finally {
	$wpdb->query('ROLLBACK');
}
