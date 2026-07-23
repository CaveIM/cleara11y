<?php
/**
 * Transaction-backed integration test for evidence persistence.
 *
 * Run with:
 * wp eval-file tests/integration/evidence-persistence.php --path=/var/www/html --allow-root
 */

global $wpdb;

$wpdb->query('START TRANSACTION');

try {
	$scan = new ClearA11y\Models\Scan();
	$scan->scan_name = 'Evidence persistence integration test';
	$scan->status = 'in_progress';
	$scan->total_items = 1;
	$scan->created_at = current_time('mysql');
	$scan_id = ClearA11y\Database\Scan_Repository::insert($scan);

	$item = new ClearA11y\Models\Scan_Item();
	$item->scan_id = $scan_id;
	$item->post_id = 1;
	$item->post_type = 'post';
	$item->post_title = 'Evidence fixture';
	$item->post_url = 'https://example.test/evidence-fixture/';
	$item->status = 'in_progress';
	$item->created_at = current_time('mysql');
	$item_id = ClearA11y\Database\Scan_Item_Repository::insert($item);

	if (! $scan_id || ! $item_id) {
		throw new RuntimeException('Could not create integration fixture.');
	}

	$node = [
		'impact' => 'serious',
		'html' => '<button></button>',
		'target' => ['button'],
		'failureSummary' => 'Button has no accessible name.',
	];
	$finding = [
		'id' => 'button-name',
		'impact' => 'serious',
		'tags' => ['wcag2a', 'wcag412'],
		'description' => 'Ensure buttons have discernible text.',
		'help' => 'Buttons must have discernible text.',
		'helpUrl' => 'https://example.test/button-name',
		'nodes' => [$node],
	];
	$evidence_base = [
		'rule_id' => 'button-name',
		'selector' => 'button',
		'selector_match_count' => 1,
		'selector_score' => ['score' => 80],
		'node_evidence' => [
			'tag_name' => 'button',
			'xpath' => '/html/body/button',
			'dom_path' => [['tag' => 'button']],
			'ancestor_chain' => [['tag' => 'button'], ['tag' => 'body']],
			'accessible_name' => '',
			'fingerprint_strict' => 'strict-fixture',
			'fingerprint_loose' => 'loose-fixture',
			'signature_version' => 1,
		],
	];

	$violation_evidence = array_merge($evidence_base, ['result_type' => 'violation']);
	$incomplete_evidence = array_merge(
		$evidence_base,
		[
			'result_type' => 'incomplete',
			'node_evidence' => array_merge(
				$evidence_base['node_evidence'],
				[
					'xpath' => '/html/body/button[2]',
					'fingerprint_strict' => 'strict-review-fixture',
					'fingerprint_loose' => 'loose-review-fixture',
				]
			),
		]
	);

	$result = ClearA11y\Services\Scan_Results_Processor::process_results(
		$item_id,
		[
			'violations' => [$finding],
			'incomplete' => [$finding],
			'passes' => [],
			'inapplicable' => [],
		],
		[$violation_evidence, $incomplete_evidence]
	);
	$issues = ClearA11y\Database\Issue_Repository::get_by_scan_item_id($item_id);

	if (2 !== count($issues) || 2 !== ($result['evidence']['persisted'] ?? 0)) {
		throw new RuntimeException('Expected two evidence-backed observations.');
	}

	$by_type = [];
	foreach ($issues as $issue) {
		$by_type[$issue->result_type] = $issue;
	}

	if (
		! isset($by_type['violation'], $by_type['incomplete'])
		|| '/html/body/button' !== $by_type['violation']->xpath
		|| '/html/body/button[2]' !== $by_type['incomplete']->xpath
	) {
		throw new RuntimeException('Result-type evidence was mismatched.');
	}

	echo "Evidence persistence integration test passed.\n";
} finally {
	$wpdb->query('ROLLBACK');
}
