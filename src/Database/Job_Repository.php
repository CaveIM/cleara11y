<?php
/**
 * Job Repository
 *
 * Handles database operations for Job records (parallel scan queue).
 *
 * @package ClearA11y
 * @namespace ClearA11y\Database
 */

namespace ClearA11y\Database;

use ClearA11y\Models\Job;
use ClearA11y\Models\Scan_Item;

// Force OPcache to reload this file
if (function_exists('opcache_invalidate')) {
	opcache_invalidate(__FILE__, true);
}

/**
 * Job Repository Class
 */
class Job_Repository {

	/**
	 * Get table name.
	 *
	 * @return string
	 */
	private static function get_table(): string {
		return Schema::get_table_name('scan_jobs');
	}

	/**
	 * Insert a new job.
	 *
	 * @param Job $job Job object.
	 * @return int|false Inserted ID or false on failure.
	 */
	public static function insert(Job $job): int|false {
		global $wpdb;

		$data = [
			'site_id' => $job->site_id,
			'url' => $job->url,
			'post_id' => $job->post_id,
			'scan_id' => $job->scan_id,
			'status' => $job->status,
			'priority' => $job->priority,
			'attempts' => $job->attempts,
			'lease_token' => $job->lease_token,
			'lease_expires_at' => $job->lease_expires_at,
			'last_error' => $job->last_error,
			'last_started_at' => $job->last_started_at,
			'last_finished_at' => $job->last_finished_at,
			'result_json' => $job->result_json,
			'created_at' => $job->created_at ?? current_time('mysql'),
		];

		$result = $wpdb->insert(
			self::get_table(),
			$data,
			['%d', '%s', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Update an existing job.
	 *
	 * @param Job $job Job object.
	 * @return bool True on success, false on failure.
	 */
	public static function update(Job $job): bool {
		global $wpdb;

		$data = [
			'status' => $job->status,
			'priority' => $job->priority,
			'attempts' => $job->attempts,
			'lease_token' => $job->lease_token,
			'lease_expires_at' => $job->lease_expires_at,
			'last_error' => $job->last_error,
			'last_started_at' => $job->last_started_at,
			'last_finished_at' => $job->last_finished_at,
			'result_json' => $job->result_json,
		];

		$result = $wpdb->update(
			self::get_table(),
			$data,
			['id' => $job->id],
			['%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s'],
			['%d']
		);

		return $result !== false;
	}

	/**
	 * Get job by ID.
	 *
	 * @param int $job_id Job ID.
	 * @return Job|null
	 */
	public static function get_by_id(int $job_id): ?Job {
		global $wpdb;

		$table = self::get_table();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE id = %d",
				$job_id
			)
		);

		return $row ? Job::from_row($row) : null;
	}

	/**
	 * Get jobs by scan ID with optional filtering.
	 *
	 * @param int   $scan_id Scan ID.
	 * @param array $filters Optional filters (status, limit, offset).
	 * @return Job[]
	 */
	public static function get_by_scan_id(int $scan_id, array $filters = []): array {
		global $wpdb;

		$defaults = [
			'status' => null,
			'limit' => 100,
			'offset' => 0,
		];

		$filters = wp_parse_args($filters, $defaults);

		$where = ['scan_id = %d'];
		$where_params = [$scan_id];

		if (!empty($filters['status'])) {
			$where[] = 'status = %s';
			$where_params[] = $filters['status'];
		}

		$where_clause = implode(' AND ', $where);
		$limit = absint($filters['limit']);
		$offset = absint($filters['offset']);

		$table = self::get_table();
		$query = "SELECT * FROM `{$table}` WHERE {$where_clause} ORDER BY priority DESC, id ASC LIMIT %d OFFSET %d";

		$where_params[] = $limit;
		$where_params[] = $offset;

		// @phpstan-ignore-next-line
		$query = $wpdb->prepare($query, ...$where_params);

		$rows = $wpdb->get_results($query);

		return array_map(fn($row) => Job::from_row($row), $rows ?: []);
	}

	/**
	 * Lease pending jobs for parallel processing.
	 *
	 * @param int    $limit        Maximum number of jobs to lease.
	 * @param int    $site_id      Site ID.
	 * @param string $worker_id    Worker identifier.
	 * @param int    $lease_seconds Lease duration in seconds.
	 * @return array Leased jobs with lease tokens.
	 */
	public static function lease_jobs(int $limit, int $site_id, string $worker_id, int $lease_seconds = 180): array {
		global $wpdb;

		$table = self::get_table();
		$expires_at = date('Y-m-d H:i:s', time() + $lease_seconds);

		// Find pending or expired jobs, ordered by priority
		$jobs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}`
				WHERE site_id = %d
				AND (status = 'pending' OR lease_expires_at < %s)
				ORDER BY priority DESC, id ASC
				LIMIT %d",
				$site_id,
				current_time('mysql'),
				$limit
			)
		);

		if (empty($jobs)) {
			return [];
		}

		$leased = [];

		foreach ($jobs as $job_row) {
			$job = Job::from_row($job_row);
			$lease_token = wp_generate_password(32, false);

			// Lease the job
			$job->lease($lease_token, $lease_seconds);
			$job->attempts++; // Increment attempts on lease

			// Update in database
			$updated = $wpdb->update(
				$table,
				[
					'status' => 'active',
					'lease_token' => $lease_token,
					'lease_expires_at' => $expires_at,
					'attempts' => $job->attempts,
					'last_started_at' => current_time('mysql'),
				],
				['id' => $job->id],
				['%s', '%s', '%s', '%d', '%s'],
				['%d']
			);

			if ($updated !== false) {
				$leased[] = [
					'id' => $job->id,
					'url' => $job->url,
					'post_id' => $job->post_id,
					'scan_id' => $job->scan_id,
					'leaseToken' => $lease_token,
					'leaseExpiresAt' => $expires_at,
					'attempts' => $job->attempts,
				];
			}
		}

		return $leased;
	}

	/**
	 * Renew lease for a job.
	 *
	 * @param int    $job_id       Job ID.
	 * @param string $lease_token  Lease token for verification.
	 * @param int    $lease_seconds New lease duration in seconds.
	 * @return array|false Result with new expiration, or false on failure.
	 */
	public static function heartbeat(int $job_id, string $lease_token, int $lease_seconds = 180): array|false {
		global $wpdb;

		$table = self::get_table();
		$new_expires = date('Y-m-d H:i:s', time() + $lease_seconds);

		// Verify lease token and update expiration
		$updated = $wpdb->update(
			$table,
			['lease_expires_at' => $new_expires],
			[
				'id' => $job_id,
				'lease_token' => $lease_token,
				'status' => 'active',
			],
			['%s'],
			['%d', '%s', '%s']
		);

		if ($updated === false) {
			return false;
		}

		return [
			'ok' => true,
			'leaseExpiresAt' => $new_expires,
		];
	}

	/**
	 * Mark a job as complete or failed.
	 *
	 * @param int         $job_id       Job ID.
	 * @param string      $lease_token  Lease token for verification.
	 * @param string      $status       New status ('done' or 'failed').
	 * @param string|null $result_json  JSON result data (for 'done' status).
	 * @param string|null $error        Error message (for 'failed' status).
	 * @return bool True on success, false on failure.
	 */
	public static function complete(int $job_id, string $lease_token, string $status, ?string $result_json = null, ?string $error = null): bool {
		global $wpdb;

		if (!in_array($status, ['done', 'failed'], true)) {
			return false;
		}

		$table = self::get_table();

		$data = [
			'status' => $status,
			'lease_token' => null,
			'lease_expires_at' => null,
			'last_finished_at' => current_time('mysql'),
		];

		$data_format = ['%s', '%s', '%s', '%s'];

		if ($status === 'done' && $result_json !== null) {
			$data['result_json'] = $result_json;
			$data['last_error'] = null;
			$data_format[] = '%s';
			$data_format[] = '%s';
		} elseif ($status === 'failed' && $error !== null) {
			$data['last_error'] = $error;
			$data_format[] = '%s';
		}

		$where = [
			'id' => $job_id,
			'lease_token' => $lease_token,
		];

		$where_format = ['%d', '%s'];

		$updated = $wpdb->update(
			$table,
			$data,
			$where,
			$data_format,
			$where_format
		);

		// Update scan progress if job completed successfully
		if ($updated !== false && $status === 'done') {
			$job = self::get_by_id($job_id);
			if ($job) {
				self::update_scan_progress($job);
			}
		}

		return $updated !== false;
	}

	/**
	 * Get job statistics.
	 *
	 * @return array Statistics with pending, active, completed, failed counts.
	 */
	/**
	 * Get job statistics filtered by scan ID.
	 *
	 * @param int $scan_id Scan ID to filter by.
	 * @return array Statistics with pending, active, completed, failed counts.
	 */
	public static function get_stats_by_scan(int $scan_id): array {
		global $wpdb;

		$table = self::get_table();

		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) as total,
					SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
					SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
					SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) as completed,
					SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
				FROM `{$table}`
				WHERE scan_id = %d",
				$scan_id
			),
			ARRAY_A
		);

		return [
			'total' => (int) ($stats['total'] ?? 0),
			'pending' => (int) ($stats['pending'] ?? 0),
			'active' => (int) ($stats['active'] ?? 0),
			'completed' => (int) ($stats['completed'] ?? 0),
			'failed' => (int) ($stats['failed'] ?? 0),
		];
	}

	/**
	 * Get job statistics.
	 *
	 * @return array Statistics with pending, active, completed, failed counts.
	 */
	public static function get_stats(): array {
		global $wpdb;

		$table = self::get_table();

		$stats = $wpdb->get_row(
			"SELECT
				SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
				SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
				SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) as completed,
				SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
			FROM `{$table}`",
			ARRAY_A
		);

		return [
			'pending' => (int) ($stats['pending'] ?? 0),
			'active' => (int) ($stats['active'] ?? 0),
			'completed' => (int) ($stats['completed'] ?? 0),
			'failed' => (int) ($stats['failed'] ?? 0),
		];
	}

	/**
	 * Clean up completed jobs from completed scans.
	 *
	 * Removes jobs that are marked as 'done' from scans that are already completed.
	 * This helps keep the jobs table clean and prevents stats from including old data.
	 *
	 * @return int Number of jobs cleaned up.
	 */
	public static function cleanup_completed_jobs(): int {
		global $wpdb;

		$table = self::get_table();
		$scans_table = \ClearA11y\Database\Schema::get_table_name('scans');

		// Delete completed jobs from scans that are marked as completed
		$deleted = $wpdb->query(
			"DELETE j FROM `{$table}` j
			INNER JOIN `{$scans_table}` s ON j.scan_id = s.id
			WHERE j.status = 'done'
			AND s.status = 'completed'"
		);

		return (int) $deleted;
	}

	/**
	 * Expire stuck jobs (reset active jobs with expired leases to pending).
	 *
	 * @param bool $force_all If true, expire ALL active jobs regardless of lease time.
	 * @return int Number of jobs reset.
	 */
	public static function expire_stuck_jobs(bool $force_all = false): int {
		global $wpdb;

		$table = self::get_table();

		if ($force_all) {
			// Force expire ALL active jobs (useful for cleanup)
			$updated = $wpdb->update(
				$table,
				[
					'status' => 'pending',
					'lease_token' => null,
					'lease_expires_at' => null,
				],
				['status' => 'active'],
				['%s', '%s', '%s'],
				['%s']
			);
			error_log(sprintf('[ClearA11y] Force expired %d active jobs', $updated));
			return (int) $updated;
		}

		// Reset expired active jobs to pending
		$updated = $wpdb->update(
			$table,
			[
				'status' => 'pending',
				'lease_token' => null,
				'lease_expires_at' => null,
			],
			[
				'status' => 'active',
				'lease_expires_at' => null,
			],
			['%s', '%s', '%s'],
			['%s', '%s']
		);

		// Also handle explicitly expired jobs
		$updated_expired = $wpdb->query(
			"UPDATE `{$table}`
			SET status = 'pending',
				lease_token = NULL,
				lease_expires_at = NULL
			WHERE status = 'active'
			AND lease_expires_at < '" . current_time('mysql') . "'"
		);

		$total = (int) $updated + (int) $updated_expired;
		if ($total > 0) {
			error_log(sprintf('[ClearA11y] Expired %d stuck jobs', $total));
		}

		return $total;
	}

	/**
	 * Delete jobs by scan ID.
	 *
	 * @param int $scan_id Scan ID.
	 * @return int Number of jobs deleted.
	 */
	public static function delete_by_scan_id(int $scan_id): int {
		global $wpdb;

		return (int) $wpdb->delete(
			self::get_table(),
			['scan_id' => $scan_id],
			['%d']
		);
	}

	/**
	 * Update scan progress based on completed job.
	 *
	 * NOTE: This is now a no-op. Scan progress is handled by Scan_Results_Processor
	 * which properly recalculates totals from all scan_items.
	 *
	 * @param Job $job Completed job.
	 * @return void
	 */
	private static function update_scan_progress(Job $job): void {
		// No-op - handled by Scan_Results_Processor::process_results
	}

	/**
	 * Get count of jobs by status.
	 *
	 * @param string|null $status Status to filter by, or null for all.
	 * @param int|null    $scan_id Optional scan ID to filter by.
	 * @return int
	 */
	public static function get_count(?string $status = null, ?int $scan_id = null): int {
		global $wpdb;

		$where = [];
		$where_params = [];

		if ($status) {
			$where[] = 'status = %s';
			$where_params[] = $status;
		}

		if ($scan_id) {
			$where[] = 'scan_id = %d';
			$where_params[] = $scan_id;
		}

		$where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

		// @phpstan-ignore-next-line
		if (!empty($where_params)) {
			$query = $wpdb->prepare("SELECT COUNT(*) FROM `" . self::get_table() . "` $where_clause", ...$where_params);
		} else {
			$query = "SELECT COUNT(*) FROM `" . self::get_table() . "` $where_clause";
		}

		$count = $wpdb->get_var($query);

		return (int) $count;
	}
}
