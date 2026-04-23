<?php
/**
 * Scan Model
 *
 * Represents a single accessibility scan record.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Models
 */

namespace ClearA11y\Models;

/**
 * Scan Model Class
 */
class Scan {

	/**
	 * Scan ID.
	 *
	 * @var int
	 */
	public int $id = 0;

	/**
	 * Scan type (individual, full, scheduled).
	 *
	 * @var string
	 */
	public string $scan_type = 'individual';

	/**
	 * Scan name.
	 *
	 * @var string|null
	 */
	public ?string $scan_name = null;

	/**
	 * Scan status.
	 *
	 * @var string
	 */
	public string $status = 'pending';

	/**
	 * Total items to scan.
	 *
	 * @var int
	 */
	public int $total_items = 0;

	/**
	 * Number of items scanned.
	 *
	 * @var int
	 */
	public int $scanned_items = 0;

	/**
	 * Total issues found.
	 *
	 * @var int
	 */
	public int $total_issues = 0;

	/**
	 * Critical issues count.
	 *
	 * @var int
	 */
	public int $critical_issues = 0;

	/**
	 * Moderate issues count.
	 *
	 * @var int
	 */
	public int $moderate_issues = 0;

	/**
	 * Minor issues count.
	 *
	 * @var int
	 */
	public int $minor_issues = 0;

	/**
	 * Scan started timestamp.
	 *
	 * @var string|null
	 */
	public ?string $started_at = null;

	/**
	 * Scan completed timestamp.
	 *
	 * @var string|null
	 */
	public ?string $completed_at = null;

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
	 * Valid scan types.
	 *
	 * @var array
	 */
	public const SCAN_TYPES = ['individual', 'full', 'scheduled'];

	/**
	 * Valid statuses.
	 *
	 * @var array
	 */
	public const STATUSES = ['pending', 'in_progress', 'completed', 'failed', 'cancelled', 'paused'];

	/**
	 * Create Scan from database row.
	 *
	 * @param object $row Database row object.
	 * @return self
	 */
	public static function from_row(object $row): self {
		$scan = new self();

		$scan->id = (int) $row->id;
		$scan->scan_type = $row->scan_type ?? 'individual';
		$scan->scan_name = $row->scan_name ?? null;
		$scan->status = $row->status ?? 'pending';
		$scan->total_items = (int) $row->total_items;
		$scan->scanned_items = (int) $row->scanned_items;
		$scan->total_issues = (int) $row->total_issues;
		$scan->critical_issues = (int) $row->critical_issues;
		$scan->moderate_issues = (int) $row->moderate_issues;
		$scan->minor_issues = (int) $row->minor_issues;
		$scan->started_at = $row->started_at ?? null;
		$scan->completed_at = $row->completed_at ?? null;
		$scan->created_at = $row->created_at ?? current_time('mysql');
		$scan->updated_at = $row->updated_at ?? null;

		return $scan;
	}

	/**
	 * Get progress percentage.
	 *
	 * @return float Progress percentage (0-100).
	 */
	public function get_progress(): float {
		if ($this->total_items === 0) {
			return 0.0;
		}

		return min(100.0, round(($this->scanned_items / $this->total_items) * 100, 2));
	}

	/**
	 * Check if scan is complete.
	 *
	 * @return bool
	 */
	public function is_complete(): bool {
		return $this->status === 'completed';
	}

	/**
	 * Check if scan is in progress.
	 *
	 * @return bool
	 */
	public function is_in_progress(): bool {
		return $this->status === 'in_progress';
	}

	/**
	 * Check if scan is pending.
	 *
	 * @return bool
	 */
	public function is_pending(): bool {
		return $this->status === 'pending';
	}

	/**
	 * Check if scan failed.
	 *
	 * @return bool
	 */
	public function is_failed(): bool {
		return $this->status === 'failed';
	}

	/**
	 * Get severity breakdown as array.
	 *
	 * @return array
	 */
	public function get_severity_breakdown(): array {
		return [
			'critical' => $this->critical_issues,
			'moderate' => $this->moderate_issues,
			'minor' => $this->minor_issues,
		];
	}
}
