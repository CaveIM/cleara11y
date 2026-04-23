<?php
/**
 * Job Model
 *
 * Represents a single scan job for parallel processing.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Models
 */

namespace ClearA11y\Models;

/**
 * Job Model Class
 */
class Job {

	/**
	 * Job ID.
	 *
	 * @var int
	 */
	public int $id = 0;

	/**
	 * Site ID.
	 *
	 * @var int
	 */
	public int $site_id = 0;

	/**
	 * URL to scan.
	 *
	 * @var string
	 */
	public string $url = '';

	/**
	 * Post ID.
	 *
	 * @var int
	 */
	public int $post_id = 0;

	/**
	 * Scan ID.
	 *
	 * @var int
	 */
	public int $scan_id = 0;

	/**
	 * Job status.
	 *
	 * @var string
	 */
	public string $status = 'pending';

	/**
	 * Job priority (higher = more important).
	 *
	 * @var int
	 */
	public int $priority = 0;

	/**
	 * Number of attempts.
	 *
	 * @var int
	 */
	public int $attempts = 0;

	/**
	 * Lease token for worker identification.
	 *
	 * @var string|null
	 */
	public ?string $lease_token = null;

	/**
	 * Lease expiration timestamp.
	 *
	 * @var string|null
	 */
	public ?string $lease_expires_at = null;

	/**
	 * Last error message.
	 *
	 * @var string|null
	 */
	public ?string $last_error = null;

	/**
	 * Last started timestamp.
	 *
	 * @var string|null
	 */
	public ?string $last_started_at = null;

	/**
	 * Last finished timestamp.
	 *
	 * @var string|null
	 */
	public ?string $last_finished_at = null;

	/**
	 * Scan result JSON.
	 *
	 * @var string|null
	 */
	public ?string $result_json = null;

	/**
	 * Created timestamp.
	 *
	 * @var string
	 */
	public string $created_at = '';

	/**
	 * Updated timestamp.
	 *
	 * @var string|null
	 */
	public ?string $updated_at = null;

	/**
	 * Maximum attempts before marking as failed.
	 *
	 * @var int
	 */
	public const MAX_ATTEMPTS = 3;

	/**
	 * Valid statuses.
	 *
	 * @var array
	 */
	public const STATUSES = ['pending', 'active', 'done', 'failed'];

	/**
	 * Create Job from database row.
	 *
	 * @param object $row Database row object.
	 * @return self
	 */
	public static function from_row(object $row): self {
		$job = new self();

		$job->id = (int) $row->id;
		$job->site_id = (int) ($row->site_id ?? 0);
		$job->url = $row->url ?? '';
		$job->post_id = (int) $row->post_id;
		$job->scan_id = (int) $row->scan_id;
		$job->status = $row->status ?? 'pending';
		$job->priority = (int) ($row->priority ?? 0);
		$job->attempts = (int) ($row->attempts ?? 0);
		$job->lease_token = $row->lease_token ?? null;
		$job->lease_expires_at = $row->lease_expires_at ?? null;
		$job->last_error = $row->last_error ?? null;
		$job->last_started_at = $row->last_started_at ?? null;
		$job->last_finished_at = $row->last_finished_at ?? null;
		$job->result_json = $row->result_json ?? null;
		$job->created_at = $row->created_at ?? current_time('mysql');
		$job->updated_at = $row->updated_at ?? null;

		return $job;
	}

	/**
	 * Check if job lease is expired.
	 *
	 * @return bool
	 */
	public function is_expired(): bool {
		if (empty($this->lease_expires_at)) {
			return false;
		}

		$expires = strtotime($this->lease_expires_at);
		return $expires < time();
	}

	/**
	 * Check if job can be retried.
	 *
	 * @return bool
	 */
	public function can_retry(): bool {
		return $this->attempts < self::MAX_ATTEMPTS;
	}

	/**
	 * Check if job is pending.
	 *
	 * @return bool
	 */
	public function is_pending(): bool {
		return $this->status === 'pending';
	}

	/**
	 * Check if job is active (leased).
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return $this->status === 'active';
	}

	/**
	 * Check if job is done.
	 *
	 * @return bool
	 */
	public function is_done(): bool {
		return $this->status === 'done';
	}

	/**
	 * Check if job is failed.
	 *
	 * @return bool
	 */
	public function is_failed(): bool {
		return $this->status === 'failed';
	}

	/**
	 * Mark job as active with lease.
	 *
	 * @param string $lease_token Lease token.
	 * @param int    $lease_seconds Lease duration in seconds.
	 * @return void
	 */
	public function lease(string $lease_token, int $lease_seconds = 180): void {
		$this->status = 'active';
		$this->lease_token = $lease_token;
		$this->lease_expires_at = date('Y-m-d H:i:s', time() + $lease_seconds);
		$this->last_started_at = current_time('mysql');
		$this->attempts++;
	}

	/**
	 * Extend lease expiration.
	 *
	 * @param int $lease_seconds Lease duration in seconds.
	 * @return void
	 */
	public function extend_lease(int $lease_seconds = 180): void {
		$this->lease_expires_at = date('Y-m-d H:i:s', time() + $lease_seconds);
	}

	/**
	 * Mark job as complete.
	 *
	 * @param string|null $result_json JSON result data.
	 * @return void
	 */
	public function complete(?string $result_json = null): void {
		$this->status = 'done';
		$this->lease_token = null;
		$this->lease_expires_at = null;
		$this->last_finished_at = current_time('mysql');
		$this->result_json = $result_json;
		$this->last_error = null;
	}

	/**
	 * Mark job as failed.
	 *
	 * @param string $error Error message.
	 * @return void
	 */
	public function fail(string $error): void {
		$this->status = 'failed';
		$this->lease_token = null;
		$this->lease_expires_at = null;
		$this->last_finished_at = current_time('mysql');
		$this->last_error = $error;
	}

	/**
	 * Reset job to pending for retry.
	 *
	 * @return void
	 */
	public function reset_to_pending(): void {
		$this->status = 'pending';
		$this->lease_token = null;
		$this->lease_expires_at = null;
	}

	/**
	 * Get scan result as array.
	 *
	 * @return array|null
	 */
	public function get_result(): ?array {
		if (empty($this->result_json)) {
			return null;
		}

		$result = json_decode($this->result_json, true);
		return is_array($result) ? $result : null;
	}
}
