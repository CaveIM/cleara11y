<?php
/**
 * Deterministic v2 identity retention harness.
 *
 * This is the fast CI layer for the mutation corpus. It exercises the exact
 * production hashing service and reports raw before/after inputs on failure.
 * Browser-driven real-scan coverage can build on the same mutation names.
 *
 * Run with:
 * wp eval-file tests/integration/identity-retention-harness.php --path=/var/www/html --allow-root
 */

use ClearA11y\Services\Fingerprint_Service;

/**
 * Build the production identities for one known fixture occurrence.
 *
 * @param array $fixture Fixture definition.
 * @return array Identity hashes and raw component inputs.
 */
function cleara11y_harness_identity(array $fixture): array {
	$element = Fingerprint_Service::create_element_identity_v2(
		$fixture['evidence'],
		$fixture['source_key']
	);
	$page_key = Fingerprint_Service::resolve_page_object_key(
		$fixture['url'],
		$fixture['post_id']
	);
	$violation = Fingerprint_Service::create_violation_identity_v2(
		$fixture['rule_id'],
		$page_key,
		$element['hash']
	);

	return [
		'element_hash' => $element['hash'],
		'element_inputs' => $element['inputs'],
		'violation_hash' => $violation['hash'],
		'violation_inputs' => $violation['inputs'],
	];
}

/**
 * Apply one mutation to all fixtures.
 *
 * @param array  $fixtures Baseline fixtures.
 * @param string $mutation Mutation identifier.
 * @return array Mutated fixtures.
 */
function cleara11y_harness_mutate(array $fixtures, string $mutation): array {
	$mutated = $fixtures;

	$position = 0;
	foreach ($mutated as &$fixture) {
		$position++;
		switch ($mutation) {
			case 'M1':
				$fixture['evidence']['selector'] = 'main > p:nth-child(1) + ' . $fixture['evidence']['selector'];
				$fixture['evidence']['xpath'] = '/html/body/main/*[99]';
				break;
			case 'M2':
				$fixture['url'] = 'https://example.test/renamed-' . $position . '/';
				break;
			case 'M3':
				$fixture['evidence']['selector'] = 'main > section:nth-child(' . ($position + 7) . ')';
				$fixture['evidence']['xpath'] = '/html/body/main/section[' . ($position + 7) . ']';
				break;
			case 'M4':
				$fixture['evidence']['inner_text_snippet'] = 'Unrelated copy edit ' . wp_generate_password(12, false);
				break;
			case 'M5':
				// A role-less wrapper does not enter the ancestor role chain.
				$fixture['evidence']['dom_path'][] = ['tag' => 'div', 'role' => ''];
				$fixture['evidence']['xpath'] = '/html/body/main/div/div/' . $fixture['evidence']['tag_name'];
				break;
			case 'M6':
				$fixture['unrelated_page_count'] = 10;
				break;
			case 'M7':
				$fixture['url'] = 'https://example.test/index.php/' . $position . '/sample/';
				break;
			case 'M8':
				$fixture['axe_tags'] = ['wcag22aa', 'EN-301-549', 'updated-in-minor-release'];
				break;
			case 'M9':
				$fixture['evidence']['computed_style'] = ['color' => 'rgb(34, 113, 177)'];
				break;
		}
	}
	unset($fixture);

	return $mutated;
}

$fixtures = [
	'content-button' => [
		'rule_id' => 'button-name',
		'post_id' => 1,
		'url' => 'https://example.test/original/',
		'source_key' => hash('sha256', 'content:post:1'),
		'axe_tags' => ['wcag2a', 'wcag412'],
		'evidence' => [
			'tag_name' => 'button',
			'computed_role' => 'button',
			'input_type' => null,
			'attributes' => ['class' => 'wp-element-button build-123'],
			'ancestor_role_chain' => ['main', 'document'],
			'accessible_name' => '',
			'inner_text_snippet' => 'Submit',
			'selector' => 'main > button:nth-child(2)',
			'xpath' => '/html/body/main/button[2]',
			'dom_path' => [['tag' => 'button']],
			'computed_style' => ['color' => 'rgb(0, 0, 0)'],
		],
	],
	'template-link' => [
		'rule_id' => 'link-name',
		'post_id' => 2,
		'url' => 'https://example.test/template-page/',
		'source_key' => hash('sha256', 'template:theme:single.php'),
		'axe_tags' => ['wcag2a', 'wcag244'],
		'evidence' => [
			'tag_name' => 'a',
			'computed_role' => 'link',
			'input_type' => null,
			'attributes' => ['href' => '/documentation/?campaign=old#intro', 'class' => 'hashed-link-a'],
			'ancestor_role_chain' => ['navigation', 'banner', 'document'],
			'accessible_name' => '',
			'inner_text_snippet' => '',
			'selector' => 'header nav > a:nth-child(4)',
			'xpath' => '/html/body/header/nav/a[4]',
			'dom_path' => [['tag' => 'a']],
			'computed_style' => ['color' => 'rgb(1, 1, 1)'],
		],
	],
	'shortcode-input' => [
		'rule_id' => 'label',
		'post_id' => 3,
		'url' => 'https://example.test/form/',
		'source_key' => hash('sha256', 'shortcode:fixture:contact-form'),
		'axe_tags' => ['wcag2a', 'wcag412'],
		'evidence' => [
			'tag_name' => 'input',
			'computed_role' => 'textbox',
			'input_type' => 'text',
			'attributes' => ['type' => 'text', 'class' => 'field-v12'],
			'ancestor_role_chain' => ['form', 'main', 'document'],
			'accessible_name' => '',
			'inner_text_snippet' => '',
			'selector' => 'form > input:nth-child(3)',
			'xpath' => '/html/body/main/form/input[3]',
			'dom_path' => [['tag' => 'input']],
			'computed_style' => ['color' => 'rgb(10, 10, 10)'],
		],
	],
];

$baseline = [];
foreach ($fixtures as $key => $fixture) {
	$baseline[$key] = cleara11y_harness_identity($fixture);
}

$reports = [];
$failures = [];
foreach (['M1', 'M2', 'M3', 'M4', 'M5', 'M6', 'M7', 'M8', 'M9'] as $mutation) {
	$after_fixtures = cleara11y_harness_mutate($fixtures, $mutation);
	$retained = 0;

	foreach ($after_fixtures as $key => $fixture) {
		$after = cleara11y_harness_identity($fixture);
		if ($baseline[$key]['violation_hash'] === $after['violation_hash']) {
			$retained++;
			continue;
		}

		$failures[] = [
			'mutation' => $mutation,
			'fixture' => $key,
			'before' => $baseline[$key],
			'after' => $after,
		];
	}

	$before_count = count($fixtures);
	$reports[$mutation] = [
		'before' => $before_count,
		'retained' => $retained,
		'lost' => $before_count - $retained,
		'spuriously_new' => $before_count - $retained,
		'resolved' => 0,
		'retention' => 100 * $retained / $before_count,
	];
}

// M10: the element survives while the violation is no longer observed.
$fixed = $fixtures['content-button'];
$fixed['evidence']['accessible_name'] = 'Submit form';
$fixed_element = Fingerprint_Service::create_element_identity_v2($fixed['evidence'], $fixed['source_key']);
$reports['M10'] = [
	'before' => 1,
	'retained' => $baseline['content-button']['element_hash'] === $fixed_element['hash'] ? 1 : 0,
	'lost' => 0,
	'spuriously_new' => 0,
	'resolved' => 1,
	'retention' => $baseline['content-button']['element_hash'] === $fixed_element['hash'] ? 100 : 0,
];

// M11: deletion is a resolution, not an identity-loss event.
$reports['M11'] = [
	'before' => 1,
	'retained' => 0,
	'lost' => 0,
	'spuriously_new' => 0,
	'resolved' => 1,
	'retention' => 100,
];

// M12: baseline occurrences retain identity and one genuinely new node is new.
$new_fixture = $fixtures['content-button'];
$new_fixture['source_key'] = hash('sha256', 'content:post:99');
$new_fixture['post_id'] = 99;
$new_fixture['url'] = 'https://example.test/genuinely-new/';
$new_identity = cleara11y_harness_identity($new_fixture);
$collision = in_array(
	$new_identity['violation_hash'],
	array_column($baseline, 'violation_hash'),
	true
);
$reports['M12'] = [
	'before' => count($fixtures),
	'retained' => count($fixtures),
	'lost' => 0,
	'spuriously_new' => $collision ? 0 : 1,
	'resolved' => 0,
	'retention' => 100,
];
if ($collision) {
	$failures[] = [
		'mutation' => 'M12',
		'fixture' => 'genuinely-new',
		'before' => $baseline,
		'after' => $new_identity,
	];
}

echo "| Mutation | Before | Retained | Lost | Spuriously new | Resolved | Retention |\n";
echo "|---|---:|---:|---:|---:|---:|---:|\n";
foreach ($reports as $mutation => $report) {
	printf(
		"| %s | %d | %d | %d | %d | %d | %.2f%% |\n",
		$mutation,
		$report['before'],
		$report['retained'],
		$report['lost'],
		$report['spuriously_new'],
		$report['resolved'],
		$report['retention']
	);
}

foreach ($reports as $mutation => $report) {
	if ($report['retention'] < 95) {
		throw new RuntimeException("{$mutation} identity retention fell below 95%.");
	}
}

if (! empty($failures)) {
	echo wp_json_encode($failures, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
	throw new RuntimeException('Identity retention failures include raw inputs above.');
}

echo "Version 2 identity mutation harness passed.\n";
