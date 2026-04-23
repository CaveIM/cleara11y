<?php
/**
 * Schedule Model
 *
 * Represents a scheduled scan configuration.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Models
 */

namespace ClearA11y\Models;

/**
 * Schedule Model Class
 */
class Schedule {

	/**
	 * Schedule ID.
	 *
	 * @var int
	 */
	public int $id = 0;

	/**
	 * Schedule name.
	 *
	 * @var string
	 */
	public string $schedule_name = '';

	/**
	 * Frequency (daily, weekly, monthly).
	 *
	 * @var string
	 */
	public string $frequency = 'weekly';

	/**
	 * Schedule configuration (JSON encoded).
	 *
	 * @var array
	 */
	public array $schedule_config = [];

	/**
	 * Whether schedule is enabled.
	 *
	 * @var bool
	 */
	public bool $enabled = true;

	/**
	 * Last scan ID.
	 *
	 * @var int|null
	 */
	public ?int $last_scan_id = null;

	/**
	 * Last run timestamp.
	 *
	 * @var string|null
	 */
	public ?string $last_run = null;

	/**
	 * Next run timestamp.
	 *
	 * @var string|null
	 */
	public ?string $next_run = null;

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
	 * Valid frequencies.
	 *
	 * @var array
	 */
	public const FREQUENCIES = ['hourly', 'daily', 'weekly', 'monthly'];

	/**
	 * Create Schedule from database row.
	 *
	 * @param object $row Database row object.
	 * @return self
	 */
	public static function from_row(object $row): self {
		$schedule = new self();

		$schedule->id = (int) $row->id;
		$schedule->schedule_name = $row->schedule_name ?? '';
		$schedule->frequency = $row->frequency ?? 'weekly';
		$schedule->schedule_config = $row->schedule_config ? json_decode($row->schedule_config, true) : [];
		$schedule->enabled = (bool) $row->enabled;
		$schedule->last_scan_id = $row->last_scan_id ? (int) $row->last_scan_id : null;
		$schedule->last_run = $row->last_run ?? null;
		$schedule->next_run = $row->next_run ?? null;
		$schedule->created_at = $row->created_at ?? current_time('mysql');
		$schedule->updated_at = $row->updated_at ?? null;

		return $schedule;
	}

	/**
	 * Check if schedule is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return $this->enabled;
	}

	/**
	 * Check if schedule is due to run.
	 *
	 * @return bool
	 */
	public function is_due(): bool {
		if (!$this->enabled || !$this->next_run) {
			return false;
		}

		$current_time = current_time('mysql');
		return $current_time >= $this->next_run;
	}

	/**
	 * Get post types from config.
	 *
	 * @return array
	 */
	public function get_post_types(): array {
		return $this->schedule_config['post_types'] ?? ['page', 'post'];
	}

	/**
	 * Calculate next run time based on frequency.
	 *
	 * @return string Next run time in MySQL format.
	 */
	public function calculate_next_run(): string {
		$timestamp = time();
		$interval = '';

		switch ($this->frequency) {
			case 'hourly':
				$interval = '+1 hour';
				break;
			case 'daily':
				$interval = '+1 day';
				break;
			case 'weekly':
				$interval = '+1 week';
				break;
			case 'monthly':
				$interval = '+1 month';
				break;
		}

		return date('Y-m-d H:i:s', strtotime($interval, $timestamp));
	}

	/**
	 * Get schedule config as JSON.
	 *
	 * @return string
	 */
	public function get_config_json(): string {
		return wp_json_encode($this->schedule_config);
	}

	/**
	 * Set schedule config from array.
	 *
	 * @param array $config Configuration array.
	 * @return void
	 */
	public function set_config(array $config): void {
		$this->schedule_config = $config;
	}
}
