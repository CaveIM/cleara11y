<?php
/**
 * Integration test for UTC scan timestamp storage and site-timezone display.
 *
 * Run with:
 * wp eval-file tests/integration/scan-timezone-formatting.php --path=/var/www/html --allow-root
 *
 * @package ClearA11y
 */

use ClearA11y\Admin\Scans_Page;
use ClearA11y\Database\Scan_Repository;
use ClearA11y\Models\Scan;

$original_timezone = get_option('timezone_string');
$original_offset   = get_option('gmt_offset');
$original_date     = get_option('date_format');
$original_time     = get_option('time_format');
$scan_id           = 0;

try {
	update_option('date_format', 'Y-m-d');
	update_option('time_format', 'H:i');
	update_option('timezone_string', 'America/New_York');
	update_option('gmt_offset', -5);

	$winter = Scans_Page::format_date('2026-01-15 12:00:00');
	$summer = Scans_Page::format_date('2026-07-15 12:00:00');

	if ('2026-01-15 07:00' !== $winter) {
		throw new RuntimeException('UTC scan time did not use the site timezone in standard time.');
	}

	if ('2026-07-15 08:00' !== $summer) {
		throw new RuntimeException('UTC scan time did not honor daylight saving time.');
	}

	$scan            = new Scan();
	$scan->scan_name = 'Timezone integration fixture';
	$scan_id         = Scan_Repository::insert($scan);
	$stored_scan     = $scan_id ? Scan_Repository::get_by_id($scan_id) : null;

	if (! $stored_scan || abs(strtotime($stored_scan->created_at . ' UTC') - time()) > 2) {
		throw new RuntimeException('New scan creation timestamps were not stored in UTC.');
	}
} finally {
	if ($scan_id) {
		Scan_Repository::delete($scan_id);
	}
	update_option('timezone_string', $original_timezone);
	update_option('gmt_offset', $original_offset);
	update_option('date_format', $original_date);
	update_option('time_format', $original_time);
}

echo "Scan timezone formatting integration test passed.\n";
