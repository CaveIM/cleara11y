<?php
/** Run with: wp eval-file /workspaces/cleara11y/tests/php/plugin-review.php --allow-root */
use ClearA11y\Services\Scan_Result_Sanitizer;
use ClearA11y\Services\Template_Attribution_Service;

function cleara11y_review_assert($condition, $message) {
	if (! $condition) {
		throw new RuntimeException($message);
	}
}
$markup = '<button onclick="example()" aria-label="A & B">\'Test\'</button>';
$selector = '[data-value="<x>%20"]';
$payload = [
	'violations' => [[
		'id' => 'button-name', 'impact' => 'critical', 'tags' => ['wcag2a', 'wcag412'],
		'description' => '<b>Button</b> name', 'helpUrl' => 'javascript:alert(1)',
		'nodes' => [['html' => $markup, 'target' => [$selector]]],
	]],
	'incomplete' => [],
	'evidence' => [['selector' => $selector, 'rule_id' => 'button-name', 'node_evidence' => ['outer_html_snippet' => $markup]]],
	'ignoredExtra' => '<script>bad()</script>',
];
$clean = Scan_Result_Sanitizer::sanitize(wp_json_encode($payload));
cleara11y_review_assert(! is_wp_error($clean), 'Valid evidence rejected');
cleara11y_review_assert($clean['violations'][0]['nodes'][0]['html'] === $markup, 'HTML evidence changed');
cleara11y_review_assert($clean['evidence'][0]['selector'] === $selector, 'Selector changed');
cleara11y_review_assert($clean['violations'][0]['description'] === 'Button name', 'Description not sanitized');
cleara11y_review_assert($clean['violations'][0]['helpUrl'] === '', 'Executable help URL accepted');
cleara11y_review_assert(! isset($clean['ignoredExtra']), 'Unknown metadata retained');
foreach (['{', 'null', '{"violations":{},"incomplete":[],"evidence":null}'] as $bad) {
	cleara11y_review_assert(is_wp_error(Scan_Result_Sanitizer::sanitize($bad)), 'Malformed JSON accepted');
}
$payload['violations'][0]['nodes'][0]['html'] = ['bad'];
cleara11y_review_assert(is_wp_error(Scan_Result_Sanitizer::sanitize(wp_json_encode($payload))), 'Invalid node shape accepted');
Template_Attribution_Service::enable();
$wrapped = Template_Attribution_Service::attribute_block($markup, ['blockName' => 'core/button']);
cleara11y_review_assert(str_contains($wrapped, $markup), 'Attribution changed rendered HTML');
cleara11y_review_assert(preg_match('/^<!--a11y:s:[a-z0-9]+-->/', $wrapped), 'Attribution marker missing');
ob_start();
Template_Attribution_Service::print_source_map();
$map = ob_get_clean();
cleara11y_review_assert(str_contains($map, '<template ') && ! str_contains($map, '<script'), 'Map uses executable script markup');
cleara11y_review_assert(preg_match('/data-sources="([^"]+)"/', $map, $matches), 'Map missing');
cleara11y_review_assert(is_array(json_decode(html_entity_decode($matches[1], ENT_QUOTES), true)), 'Map does not round trip');
echo "Plugin review regression checks passed.\n";

// REST must reject unknown scan types before any persistence occurs.
wp_set_current_user(1);
$request = new WP_REST_Request('POST', '/cleara11y/v1/scans');
$request->set_param('scan_type', '"><svg onload=alert(1)>');
$response = rest_get_server()->dispatch($request);
cleara11y_review_assert(400 === $response->get_status(), 'Unknown scan type accepted');
$validator = new ReflectionMethod(ClearA11y\API\Exception_REST_Controller::class, 'validate_exception_params');
$validator->setAccessible(true);
foreach ([['note' => ['bad']], ['scope' => ['patterns' => [['bad']]]], ['element_match' => ['css_selector' => ['bad']]]] as $bad) {
	$result = $validator->invoke(new ClearA11y\API\Exception_REST_Controller(), $bad);
	cleara11y_review_assert(is_wp_error($result) && 'invalid_exception' === $result->get_error_code(), 'Malformed exception not rejected by schema');
}
$scanner = new ReflectionMethod(ClearA11y\Services\PHP_Bulk_Scanner::class, 'scan_html');
$scanner->setAccessible(true);
$previous = libxml_use_internal_errors();
foreach ([false, true] as $mode) {
	libxml_use_internal_errors($mode);
	$scanner->invoke(null, '<p>Valid content</p>', 0, 0, 0);
	cleara11y_review_assert($mode === libxml_use_internal_errors(), 'Scanner changed global XML error mode');
}
libxml_use_internal_errors($previous);
echo "Broader review input and global-state checks passed.\n";
