<?php
/**
 * Exception Audit Log Model
 *
 * Represents an audit log entry for exception rule actions.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Models
 */

namespace ClearA11y\Models;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Exception Audit Log Model Class
 */
class Exception_Audit_Log {

	/**
	 * Log entry ID.
	 *
	 * @var int
	 */
	public int $id = 0;

	/**
	 * Associated exception rule ID.
	 *
	 * @var string|null
	 */
	public ?string $exception_rule_id = null;

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
		'exception_created',
		'exception_edited',
		'exception_disabled',
		'exception_enabled',
		'exception_revoked',
		'exception_expired',
		'occurrence_snoozed',
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
		$log->exception_rule_id = $row->exception_rule_id ?? null;
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
			'exception_rule_id' => $this->exception_rule_id,
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
			case 'exception_created':
				return 'Reviewed exception created';

			case 'exception_edited':
				return 'Reviewed exception updated';

			case 'exception_disabled':
				return 'Reviewed exception disabled';

			case 'exception_enabled':
				return 'Reviewed exception enabled';

			case 'exception_revoked':
				return 'Reviewed exception revoked';

			case 'exception_expired':
				return 'Reviewed exception expired';

			case 'occurrence_snoozed':
				return 'Issue snoozed until next scan';

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
	 * @param string|null $exception_rule_id Associated exception rule ID.
	 * @param int|null $actor_user_id User ID who performed the action.
	 * @param array $metadata Additional metadata.
	 * @return self
	 */
	public static function create(string $event_type, ?string $exception_rule_id = null, ?int $actor_user_id = null, array $metadata = []): self {
		$log = new self();

		$log->event_type = $event_type;
		$log->exception_rule_id = $exception_rule_id;
		$log->actor_user_id = $actor_user_id;
		$log->timestamp = current_time('mysql');
		$log->metadata = $metadata;

		return $log;
	}
}
