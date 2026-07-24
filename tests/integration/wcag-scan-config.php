<?php
/**
 * Cumulative WCAG axe-core configuration contract.
 *
 * Run with:
 * wp eval-file tests/integration/wcag-scan-config.php --path=/var/www/html --allow-root
 */

use ClearA11y\Services\Scan_Results_Processor;

$expected = [
	'wcag2a' => ['wcag2a'],
	'wcag2aa' => ['wcag2a', 'wcag2aa'],
	'wcag2aaa' => ['wcag2a', 'wcag2aa', 'wcag2aaa'],
	'wcag21a' => ['wcag2a', 'wcag21a'],
	'wcag21aa' => ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'],
];

foreach ($expected as $level => $tags) {
	if ($tags !== Scan_Results_Processor::get_wcag_tags($level)) {
		throw new RuntimeException("Cumulative axe tags are incorrect for {$level}.");
	}
}

if ($expected['wcag21aa'] !== Scan_Results_Processor::get_wcag_tags('invalid-level')) {
	throw new RuntimeException('Invalid WCAG settings did not recover to the supported default.');
}

$original = get_option('cleara11y_wcag_level', 'wcag21aa');

try {
	update_option('cleara11y_wcag_level', 'wcag21aa');
	$config = Scan_Results_Processor::get_axe_config();
	$actual = $config['runOnly']['values'] ?? [];

	if ($expected['wcag21aa'] !== $actual) {
		throw new RuntimeException('The production axe configuration is not cumulative.');
	}
} finally {
	update_option('cleara11y_wcag_level', $original);
}

echo "Cumulative WCAG scan configuration test passed.\n";
