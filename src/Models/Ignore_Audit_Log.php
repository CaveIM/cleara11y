<?php
/**
 * Ignore Audit Log Model
 *
 * Represents an audit log entry for ignore rule actions.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Models
 */

namespace ClearA11y\Models;

/**
 * Ignore Audit Log Model Class
 */
class Ignore_Audit_Log {

	/**
	 * Log entry ID.
	 *
	 * @var int
	 */
	public int $id = 0;

	/**
	 * Associated ignore rule ID.
	 *
	 * @var string|null
	 */
	public ?string $ignore_rule_id = null;

	/**
	 * Event type.
	 *
	 * @var string
	 */
	public string $event_type = '';

	/**
	 * User ID who performed the action.
	 *
	 * @var int|null
	 */
	public ?int $actor_user_id = null;

	/**
	 * Timestamp of the event.
	 *
	 * @var string
	 */
	public string $timestamp = '';

	/**
	 * Additional metadata as JSON.
	 *
	 * @var array
	 */
	public array $metadata = [];

	/**
	 * Valid event types.
	 *
	 * @var array
	 */
	public const EVENT_TYPES = [
		'ignore_created',
		'ignore_edited',
		'ignore_disabled',
		'ignore_enabled',
		'ignore_deleted',
		'ignore_expired',
		'quick_ignore_created',
		'violation_suppressed',
	];

	/**
	 * Create Audit_Log entry from database row.
	 *
	 * @param object $row Database row object.
	 * @return self
	 */
	public static function from_row(object $row): self {
		$log = new self();

		$log->id = (int) ($row->id ?? 0);
		$log->ignore_rule_id = $row->ignore_rule_id ?? null;
		$log->event_type = $row->event_type ?? '';
		$log->actor_user_id = isset($row->actor_user_id) ? (int) $row->actor_user_id : null;
		$log->timestamp = $row->timestamp ?? '';
		$log->metadata = isset($row->metadata) ? json_decode($row->metadata, true) : [];

		return $log;
	}

	/**
	 * Convert log entry to array for JSON serialization.
	 *
	 * @return array Log data as array.
	 */
	public function to_array(): array {
		$user = $this->actor_user_id ? get_userdata($this->actor_user_id) : null;

		return [
			'id' => $this->id,
			'ignore_rule_id' => $this->ignore_rule_id,
			'event_type' => $this->event_type,
			'event_label' => $this->get_event_label(),
			'actor_user_id' => $this->actor_user_id,
			'actor_name' => $user ? $user->display_name : null,
			'actor_email' => $user ? $user->user_email : null,
			'timestamp' => $this->timestamp,
			'metadata' => $this->metadata,
		];
	}

	/**
	 * Get human-readable event label.
	 *
	 * @return string
	 */
	private function get_event_label(): string {
			switch ($this->event_type) {
			case 'ignore_created':
				return 'Reviewed exception created';

			case 'ignore_edited':
				return 'Reviewed exception updated';

			case 'ignore_disabled':
				return 'Reviewed exception disabled';

			case 'ignore_enabled':
				return 'Reviewed exception enabled';

			case 'ignore_deleted':
				return 'Reviewed exception deleted';

			case 'ignore_expired':
				return 'Reviewed exception expired';

			case 'quick_ignore_created':
				return 'Temporary exception created';

			case 'violation_suppressed':
				return 'Issue marked as exception';

			default:
				return $this->event_type;
		}
	}

	/**
	 * Create a new audit log entry.
	 *
	 * @param string $event_type Event type.
	 * @param string|null $ignore_rule_id Associated ignore rule ID.
	 * @param int|null $actor_user_id User ID who performed the action.
	 * @param array $metadata Additional metadata.
	 * @return self
	 */
	public static function create(string $event_type, ?string $ignore_rule_id = null, ?int $actor_user_id = null, array $metadata = []): self {
		$log = new self();

		$log->event_type = $event_type;
		$log->ignore_rule_id = $ignore_rule_id;
		$log->actor_user_id = $actor_user_id;
		$log->timestamp = current_time('mysql');
		$log->metadata = $metadata;

		return $log;
	}
}
