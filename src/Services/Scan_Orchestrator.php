<?php
/**
 * Scan Orchestrator
 *
 * Coordinates multi-page scans with progress tracking.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Services
 */

namespace ClearA11y\Services;

use ClearA11y\Database\Job_Repository;
use ClearA11y\Database\Scan_Repository;
use ClearA11y\Database\Scan_Item_Repository;
use ClearA11y\Database\PHP_Bulk_Scanner;
use ClearA11y\Models\Job;
use ClearA11y\Models\Scan;

// Force OPcache to reload this file
if (function_exists('opcache_invalidate')) {
    opcache_invalidate(__FILE__, true);
}

/**
 * Scan Orchestrator Class
 */
class Scan_Orchestrator {

	/**
	 * Check if there's already an active scan running.
	 *
	 * @return bool True if an active scan exists.
	 */
	public static function has_active_scan(): bool {
		global $wpdb;
		$scans_table = \ClearA11y\Database\Schema::get_table_name('scans');

		$active_scan = $wpdb->get_var(
			"SELECT id FROM `{$scans_table}`
			WHERE status IN ('pending', 'in_progress', 'paused')
			LIMIT 1"
		);

		return !empty($active_scan);
	}

	/**
	 * Start a full site scan.
	 *
	 * @param array $post_types Post types to include.
	 * @param int   $batch_size Number of items per batch.
	 * @return int|false Scan ID or false on failure.
	 */
	public static function start_full_scan(array $post_types = ['page', 'post'], int $batch_size = 20): int|false {
		// Check if there's already an active scan
		if (self::has_active_scan()) {

			return false;
		}

		// Collect all published posts
		$post_ids = self::collect_post_ids($post_types);

		if (empty($post_ids)) {
			return false;
		}

		// Create scan record
		$scan = new Scan();
		$scan->scan_type = 'full';
		$scan->scan_name = 'Full Site Scan - ' . count($post_ids) . ' items';
		$scan->status = 'pending';
		$scan->total_items = count($post_ids);
		$scan->scanned_items = 0;
		$scan->started_at = null;
		$scan->created_at = \current_time('mysql');

		$scan_id = Scan_Repository::insert($scan);

		if (!$scan_id) {
			return false;
		}

		// Create scan items
		Scan_Item_Repository::create_from_posts($scan_id, $post_ids);

		// Create jobs for iframe-based scanner
		$jobs_created = self::create_jobs_for_scan($scan_id);


		// Start processing (this sets status to 'in_progress')
		self::process_scan($scan_id, $batch_size);

		return $scan_id;
	}

	/**
	 * Start a scheduled scan.
	 *
	 * @param string $schedule_name Schedule name.
	 * @param array  $post_types    Post types to scan.
	 * @return int|false Scan ID or false on failure.
	 */
	public static function start_scheduled_scan(string $schedule_name, array $post_types = ['page', 'post']): int|false {
		// Check if there's already an active scan
		if (self::has_active_scan()) {

			return false;
		}

		$post_ids = self::collect_post_ids($post_types);

		if (empty($post_ids)) {
			return false;
		}

		// Create scan record
		$scan = new Scan();
		$scan->scan_type = 'scheduled';
		$scan->scan_name = 'Scheduled Scan - ' . $schedule_name;
		$scan->status = 'pending';
		$scan->total_items = count($post_ids);
		$scan->scanned_items = 0;
		$scan->created_at = \current_time('mysql');

		$scan_id = Scan_Repository::insert($scan);

		if (!$scan_id) {
			return false;
		}

		// Create scan items
		Scan_Item_Repository::create_from_posts($scan_id, $post_ids);

		// Create jobs for iframe-based scanner
		$jobs_created = self::create_jobs_for_scan($scan_id);


		// Update schedule with scan ID
		$schedule = \ClearA11y\Database\Schedule_Repository::get_by_name($schedule_name);
		if ($schedule) {
			$schedule->last_scan_id = $scan_id;
			\ClearA11y\Database\Schedule_Repository::update($schedule);
		}

		// Start processing (in background)
		wp_schedule_single_event(time() + 10, 'cleara11y_process_scan_batch', [$scan_id]);

		return $scan_id;
	}

	/**
	 * Process a scan (main entry point).
	 *
	 * @param int $scan_id    Scan ID.
	 * @param int $batch_size Number of items per batch.
	 * @return void
	 */
	public static function process_scan(int $scan_id, int $batch_size = 20): void {
		$scan = Scan_Repository::get_by_id($scan_id);

		if (!$scan || $scan->status === 'completed') {
			return;
		}

		// Update status to in_progress
		if ($scan->status === 'pending') {
			$scan->status = 'in_progress';
			$scan->started_at = \current_time('mysql');
			Scan_Repository::update($scan);
		}

		// Process pending items
		self::process_batch($scan_id, $batch_size);

		// Check if scan is complete
		$pending_count = Scan_Item_Repository::get_count($scan_id, 'pending');
		$in_progress_count = Scan_Item_Repository::get_count($scan_id, 'in_progress');

		if ($pending_count === 0 && $in_progress_count === 0) {
			// Mark scan as complete
			$scan->status = 'completed';
			$scan->completed_at = \current_time('mysql');
			Scan_Repository::update($scan);

			\do_action('cleara11y_scan_completed', $scan);
		} elseif ($pending_count > 0) {
			// Schedule next batch
			wp_schedule_single_event(time() + 5, 'cleara11y_process_scan_batch', [$scan_id]);
		}
	}

	/**
	 * Process a batch of scan items.
	 *
	 * @param int $scan_id    Scan ID.
	 * @param int $batch_size Number of items to process.
	 * @return array Processing results.
	 */
	public static function process_scan_batch(int $scan_id, int $batch_size = 20): array {
		$scan = Scan_Repository::get_by_id($scan_id);

		if (!$scan) {
			return [
				'success' => false,
				'message' => 'Scan not found.',
			];
		}

		// Get pending items
		$pending_items = Scan_Item_Repository::get_by_scan_id(
			$scan_id,
			['status' => 'pending', 'limit' => $batch_size]
		);

		if (empty($pending_items)) {
			return [
				'success' => true,
				'message' => 'No pending items to process.',
				'processed' => 0,
			];
		}

		$processed = 0;
		$failed = 0;

		foreach ($pending_items as $item) {
			// Mark as in progress
			Scan_Item_Repository::update_status($item->id, 'in_progress');

			// Scan using PHP bulk scanner
			$result = PHP_Bulk_Scanner::scan_post($item->post_id, $scan_id, $item->id);

			if ($result['success']) {
				$processed++;
			} else {
				$failed++;
				// Mark as failed
				Scan_Item_Repository::update_status($item->id, 'failed');
			}
		}

		return [
			'success' => true,
			'processed' => $processed,
			'failed' => $failed,
			'message' => sprintf('Processed %d items, %d failed.', $processed, $failed),
		];
	}

	/**
	 * Pause a running scan.
	 *
	 * @param int $scan_id Scan ID.
	 * @return bool True if paused successfully.
	 */
	public static function pause_scan(int $scan_id): bool {
		$scan = Scan_Repository::get_by_id($scan_id);

		if (!$scan || $scan->status !== 'in_progress') {
			return false;
		}

		$scan->status = 'paused';
		return Scan_Repository::update($scan);
	}

	/**
	 * Resume a paused scan.
	 *
	 * @param int $scan_id Scan ID.
	 * @return bool True if resumed successfully.
	 */
	public static function resume_scan(int $scan_id): bool {
		$scan = Scan_Repository::get_by_id($scan_id);

		if (!$scan || $scan->status !== 'paused') {
			return false;
		}

		$scan->status = 'in_progress';
		Scan_Repository::update($scan);

		// Trigger processing
		$batch_size = (int) \get_option('cleara11y_batch_size', 20);
		wp_schedule_single_event(time() + 5, 'cleara11y_process_scan_batch', [$scan_id]);

		return true;
	}

	/**
	 * Cancel a scan.
	 *
	 * @param int $scan_id Scan ID.
	 * @return bool True if cancelled successfully.
	 */
	public static function cancel_scan(int $scan_id): bool {
		$scan = Scan_Repository::get_by_id($scan_id);

		if (!$scan || in_array($scan->status, ['completed', 'failed'], true)) {
			return false;
		}

		$scan->status = 'cancelled';
		$scan->completed_at = \current_time('mysql');

		// Mark all pending items as cancelled
		$pending_items = Scan_Item_Repository::get_by_scan_id($scan_id, ['status' => 'pending']);
		foreach ($pending_items as $item) {
			Scan_Item_Repository::update_status($item->id, 'cancelled');
		}

		return Scan_Repository::update($scan);
	}

	/**
	 * Get scan progress.
	 *
	 * @param int $scan_id Scan ID.
	 * @return array Progress data.
	 */
	public static function get_progress(int $scan_id): array {
		$scan = Scan_Repository::get_by_id($scan_id);

		if (!$scan) {
			return [
				'success' => false,
				'message' => 'Scan not found.',
			];
		}

		$pending = Scan_Item_Repository::get_count($scan_id, 'pending');
		$in_progress = Scan_Item_Repository::get_count($scan_id, 'in_progress');
		$completed = Scan_Item_Repository::get_count($scan_id, 'completed');
		$failed = Scan_Item_Repository::get_count($scan_id, 'failed');

		return [
			'success' => true,
			'status' => $scan->status,
			'progress' => $scan->get_progress(),
			'total_items' => $scan->total_items,
			'scanned_items' => $scan->scanned_items,
			'pending' => $pending,
			'in_progress' => $in_progress,
			'completed' => $completed,
			'failed' => $failed,
			'issues' => [
				'total' => $scan->total_issues,
				'critical' => $scan->critical_issues,
				'moderate' => $scan->moderate_issues,
				'minor' => $scan->minor_issues,
			],
		];
	}

	/**
	 * Collect post IDs for scanning.
	 *
	 * @param array $post_types Post types to collect.
	 * @return array Array of post IDs.
	 */
	private static function collect_post_ids(array $post_types): array {
		$post_ids = [];

		foreach ($post_types as $post_type) {
			$posts = get_posts([
				'post_type' => $post_type,
				'post_status' => 'publish',
				'posts_per_page' => -1,
				'fields' => 'ids',
			]);

			$post_ids = array_merge($post_ids, $posts);
		}

		return array_unique($post_ids);
	}

	/**
	 * Process a batch (alias for process_scan_batch).
	 *
	 * @param int $scan_id Scan ID.
	 * @return array Processing results.
	 */
	public static function process_batch(int $scan_id): array {
		$batch_size = (int) \get_option('cleara11y_batch_size', 20);
		return self::process_scan_batch($scan_id, $batch_size);
	}

	/**
	 * Create parallel scan jobs for a scan.
	 *
	 * @param int $scan_id Scan ID.
	 * @return int Number of jobs created.
	 */
	public static function create_jobs_for_scan(int $scan_id): int {
		$scan = Scan_Repository::get_by_id($scan_id);

		if (!$scan) {
			return 0;
		}

		// Get pending scan items
		$scan_items = Scan_Item_Repository::get_by_scan_id($scan_id, ['status' => 'pending']);

		if (empty($scan_items)) {
			return 0;
		}

		$created = 0;
		$site_id = get_current_blog_id();

		foreach ($scan_items as $scan_item) {
			// Check if job already exists
			$existing_jobs = Job_Repository::get_by_scan_id($scan_id, [
				'status' => 'pending',
				'limit' => 1,
			]);

			// Skip if a pending job already exists for this scan_item's post
			$job_exists = false;
			foreach ($existing_jobs as $existing_job) {
				if ($existing_job->post_id === $scan_item->post_id) {
					$job_exists = true;
					break;
				}
			}

			if ($job_exists) {
				continue;
			}

			// Create new job
			$job = new Job();
			$job->site_id = $site_id;
			$job->url = $scan_item->post_url;
			$job->post_id = $scan_item->post_id;
			$job->scan_id = $scan_id;
			$job->status = 'pending';
			$job->priority = 10;
			$job->attempts = 0;
			$job->created_at = \current_time('mysql');

			$job_id = Job_Repository::insert($job);

			if ($job_id) {
				$created++;
			}
		}

		return $created;
	}
}
