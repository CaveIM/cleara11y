<?php
/**
 * Integration checks for token-only template attribution.
 *
 * Run with:
 * wp eval-file tests/integration/template-attribution.php --path=/var/www/html --allow-root
 */

use ClearA11y\Services\Template_Attribution_Service;

$fixture = '<section><p>Unchanged visitor output</p></section>';

if ($fixture !== Template_Attribution_Service::attribute_content($fixture)) {
	throw new RuntimeException('Attribution changed output while disabled.');
}

Template_Attribution_Service::enable();
$attributed = Template_Attribution_Service::attribute_content($fixture);

if (
	1 !== substr_count($attributed, '<!--a11y:s:')
	|| 1 !== substr_count($attributed, '<!--a11y:e:')
	|| ! str_contains($attributed, $fixture)
) {
	throw new RuntimeException('Content attribution markers are not balanced.');
}

$unsafe = '<script>window.fixture = true;</script>';
if ($unsafe !== Template_Attribution_Service::attribute_content($unsafe)) {
	throw new RuntimeException('Raw-text output must not receive markers.');
}

Template_Attribution_Service::record_template(get_template_directory() . '/index.php');
ob_start();
Template_Attribution_Service::print_source_map();
$source_map = ob_get_clean();

if (
	! str_contains($source_map, 'cleara11y-attribution-map')
	|| ! str_contains($source_map, '"source_type"')
) {
	throw new RuntimeException('Attribution source map was not emitted safely.');
}

echo "Template attribution integration test passed.\n";
