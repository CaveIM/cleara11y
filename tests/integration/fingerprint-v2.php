<?php
/**
 * Contract tests for version 2 occurrence identity.
 *
 * Run with:
 * wp eval-file tests/integration/fingerprint-v2.php --path=/var/www/html --allow-root
 */

use ClearA11y\Services\Fingerprint_Service;

$source_key = hash('sha256', 'source-fixture');
$base = [
	'tag_name' => 'a',
	'computed_role' => 'link',
	'input_type' => null,
	'attributes' => [
		'href' => '/documentation/?campaign=test#overview',
		'class' => 'button build-123',
	],
	'ancestor_role_chain' => ['navigation', 'banner', 'document', 'group', 'region', 'main'],
	'accessible_name' => 'Old label',
	'inner_text_snippet' => 'Old copy',
	'xpath' => '/html/body/nav/a[2]',
];

$identity = Fingerprint_Service::create_element_identity_v2($base, $source_key);
$mutated = $base;
$mutated['accessible_name'] = 'Fixed accessible name';
$mutated['inner_text_snippet'] = 'Completely different copy';
$mutated['attributes']['class'] = 'different generated-class';
$mutated['xpath'] = '/html/body/nav/a[9]';
$mutated['ancestor_role_chain'][5] = 'complementary';
$retained = Fingerprint_Service::create_element_identity_v2($mutated, $source_key);

if ($identity['hash'] !== $retained['hash']) {
	throw new RuntimeException('Excluded evidence or a role beyond depth five changed element identity.');
}

if (
	'/documentation' !== $identity['inputs']['href_path']
	|| 5 !== count($identity['inputs']['ancestor_role_chain'])
	|| Fingerprint_Service::IDENTITY_SIGNATURE_VERSION !== $identity['signature_version']
) {
	throw new RuntimeException('Element identity inputs were not normalized to the v2 contract.');
}

$different_source = Fingerprint_Service::create_element_identity_v2($base, hash('sha256', 'other-source'));
if ($identity['hash'] === $different_source['hash']) {
	throw new RuntimeException('Changing the stable source key must change element identity.');
}

$page_key_before = Fingerprint_Service::resolve_page_object_key('https://example.test/old-slug/', 1);
$page_key_after = Fingerprint_Service::resolve_page_object_key('https://example.test/new-slug/', 1);
if ('post:1' !== $page_key_before || $page_key_before !== $page_key_after) {
	throw new RuntimeException('WordPress post identity did not survive a URL change.');
}

$violation = Fingerprint_Service::create_violation_identity_v2('link-name', $page_key_before, $identity['hash']);
$changed_rule = Fingerprint_Service::create_violation_identity_v2('color-contrast', $page_key_before, $identity['hash']);
if ($violation['hash'] === $changed_rule['hash']) {
	throw new RuntimeException('Changing the rule must change violation identity.');
}

if (
	['rule_id', 'page_object_key', 'element_identity_v2'] !== array_keys($violation['inputs'])
	|| array_intersect(['selector', 'wcag', 'accessible_name'], array_keys($violation['inputs']))
) {
	throw new RuntimeException('Violation identity inputs drifted from the v2 contract.');
}

echo "Version 2 fingerprint contract test passed.\n";
