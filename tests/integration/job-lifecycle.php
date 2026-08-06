<?php
/**
 * Integration test for UTC job leases, retries, resets, and cancellation.
 *
 * Run with:
 * wp eval-file tests/integration/job-lifecycle.php --path=/var/www/html --allow-root
 *
 * @package ClearA11y
 */

use ClearA11y\Database\Job_Repository;
use ClearA11y\Database\Scan_Item_Repository;
use ClearA11y\Database\Scan_Repository;
use ClearA11y\Database\Schema;
use ClearA11y\Models\Job;
use ClearA11y\Models\Scan;
use ClearA11y\Models\Scan_Item;
use ClearA11y\Services\Scan_Orchestrator;

$original_timezone = get_option('timezone_string');
$original_offset   = get_option('gmt_offset');
$scan_id           = 0;
$job_id            = 0;
$site_id           = 987654321;

try {
	update_option('timezone_string', 'America/New_York');
	update_option('gmt_offset', -5);

	$scan                = new Scan();
	$scan->scan_name     = 'Job lifecycle integration fixture';
	$scan->status        = 'in_progress';
	$scan->total_items   = 1;
	$scan->started_at    = current_time('mysql', true);
	$scan->created_at    = current_time('mysql', true);
	$scan_id             = Scan_Repository::insert($scan);
	if (! $scan_id) {
		throw new RuntimeException('Could not create the job lifecycle scan fixture.');
	}

	$item               = new Scan_Item();
	$item->scan_id      = $scan_id;
	$item->post_id      = 1;
	$item->post_url     = 'https://example.test/job-lifecycle/';
	$item->post_title   = 'Job lifecycle fixture';
	$item->status       = 'in_progress';
	$item->scan_method  = 'client';
	$item->created_at   = current_time('mysql', true);
	$item_id            = Scan_Item_Repository::insert($item);
	if (! $item_id) {
		throw new RuntimeException('Could not create the job lifecycle item fixture.');
	}

	$job             = new Job();
	$job->site_id    = $site_id;
	$job->scan_id    = $scan_id;
	$job->post_id    = 1;
	$job->url        = $item->post_url;
	$job->created_at = current_time('mysql', true);
	$job_id          = Job_Repository::insert($job);
	if (! $job_id) {
		throw new RuntimeException('Could not create the job lifecycle job fixture.');
	}

	$leased = Job_Repository::lease_jobs(1, $site_id, 'timezone-test', 20);
	if (1 !== count($leased) || $job_id !== (int) $leased[0]['id'] || 1 !== (int) $leased[0]['attempts']) {
		throw new RuntimeException('A single job lease did not record exactly one attempt.');
	}

	global $wpdb;
	$wpdb->update(
		Schema::get_table_name('scan_jobs'),
		['lease_expires_at' => gmdate('Y-m-d H:i:s', time() - 1)],
		['id' => $job_id],
		['%s'],
		['%d']
	);

	$reclaimed = Job_Repository::lease_jobs(1, $site_id, 'timezone-test-retry', 20);
	if (1 !== count($reclaimed) || $job_id !== (int) $reclaimed[0]['id'] || 2 !== (int) $reclaimed[0]['attempts']) {
		throw new RuntimeException('An expired UTC lease was not reclaimed under a non-UTC site timezone.');
	}

	if (1 !== Job_Repository::reset_active_by_scan_id($scan_id)) {
		throw new RuntimeException('The stuck-job reset did not release the active lease.');
	}

	$reset_job = Job_Repository::get_by_id($job_id);
	if (! $reset_job || 'pending' !== $reset_job->status || $reset_job->lease_token) {
		throw new RuntimeException('The reset job did not return to a clean pending state.');
	}

	Job_Repository::lease_jobs(1, $site_id, 'timezone-test-cancel', 20);
	if (! Scan_Orchestrator::cancel_scan($scan_id)) {
		throw new RuntimeException('The active scan could not be cancelled.');
	}

	$cancelled_scan = Scan_Repository::get_by_id($scan_id);
	$cancelled_item = Scan_Item_Repository::get_by_id($item_id);
	$cancelled_job  = Job_Repository::get_by_id($job_id);
	if (! $cancelled_scan || 'cancelled' !== $cancelled_scan->status || ! $cancelled_scan->completed_at) {
		throw new RuntimeException('Cancellation did not finalize the scan.');
	}
	if (! $cancelled_item || 'cancelled' !== $cancelled_item->status) {
		throw new RuntimeException('Cancellation did not stop the unfinished scan item.');
	}
	if (! $cancelled_job || 'cancelled' !== $cancelled_job->status || $cancelled_job->lease_token) {
		throw new RuntimeException('Cancellation did not invalidate the active job lease.');
	}
} finally {
	if ($job_id) {
		Job_Repository::delete_by_scan_id($scan_id);
	}
	if ($scan_id) {
		Scan_Repository::delete($scan_id);
	}
	update_option('timezone_string', $original_timezone);
	update_option('gmt_offset', $original_offset);
}

echo "Job lifecycle integration test passed.\n";
