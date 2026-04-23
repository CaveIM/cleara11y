<?php
/**
 * Scan Item Model
 *
 * Represents a single page/item within a scan.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Models
 */

namespace ClearA11y\Models;

/**
 * Scan Item Model Class
 */
class Scan_Item {

	/**
	 * Scan Item ID.
	 *
	 * @var int
	 */
	public int $id = 0;

	/**
	 * Parent Scan ID.
	 *
	 * @var int
	 */
	public int $scan_id = 0;

	/**
	 * Post ID.
	 *
	 * @var int
	 */
	public int $post_id = 0;

	/**
	 * Post type.
	 *
	 * @var string
	 */
	public string $post_type = 'page';

	/**
	 * Post title.
	 *
	 * @var string|null
	 */
	public ?string $post_title = null;

	/**
	 * Post URL.
	 *
	 * @var string
	 */
	public string $post_url = '';

	/**
	 * Scan status.
	 *
	 * @var string
	 */
	public string $status = 'pending';

	/**
	 * Scan method (client, server).
	 *
	 * @var string
	 */
	public string $scan_method = 'client';

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
	 * Error message if scan failed.
	 *
	 * @var string|null
	 */
	public ?string $error_message = null;

	/**
	 * Scanned timestamp.
	 *
	 * @var string|null
	 */
	public ?string $scanned_at = null;

	/**
	 * Created timestamp.
	 *
	 * @var string
	 */
	public string $created_at = '';

	/**
	 * Valid scan methods.
	 *
	 * @var array
	 */
	public const SCAN_METHODS = ['client', 'server'];

	/**
	 * Valid statuses.
	 *
	 * @var array
	 */
	public const STATUSES = ['pending', 'in_progress', 'completed', 'failed', 'skipped'];

	/**
	 * Create Scan_Item from database row.
	 *
	 * @param object $row Database row object.
	 * @return self
	 */
	public static function from_row(object $row): self {
		$item = new self();

		$item->id = (int) $row->id;
		$item->scan_id = (int) $row->scan_id;
		$item->post_id = (int) $row->post_id;
		$item->post_type = $row->post_type ?? 'page';
		$item->post_title = $row->post_title ?? null;
		$item->post_url = $row->post_url ?? '';
		$item->status = $row->status ?? 'pending';
		$item->scan_method = $row->scan_method ?? 'client';
		$item->total_issues = (int) $row->total_issues;
		$item->critical_issues = (int) $row->critical_issues;
		$item->moderate_issues = (int) $row->moderate_issues;
		$item->minor_issues = (int) $row->minor_issues;
		$item->error_message = $row->error_message ?? null;
		$item->scanned_at = $row->scanned_at ?? null;
		$item->created_at = $row->created_at ?? current_time('mysql');

		return $item;
	}

	/**
	 * Check if item is complete.
	 *
	 * @return bool
	 */
	public function is_complete(): bool {
		return $this->status === 'completed';
	}

	/**
	 * Check if item is in progress.
	 *
	 * @return bool
	 */
	public function is_in_progress(): bool {
		return $this->status === 'in_progress';
	}

	/**
	 * Check if item is pending.
	 *
	 * @return bool
	 */
	public function is_pending(): bool {
		return $this->status === 'pending';
	}

	/**
	 * Check if item failed.
	 *
	 * @return bool
	 */
	public function is_failed(): bool {
		return $this->status === 'failed';
	}

	/**
	 * Check if item was skipped.
	 *
	 * @return bool
	 */
	public function is_skipped(): bool {
		return $this->status === 'skipped';
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
