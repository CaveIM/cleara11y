<?php
/**
 * Exception Rule Model
 *
 * Represents a structured exception rule for suppressing accessibility violations.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Models
 */

namespace ClearA11y\Models;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Exception Rule Model Class
 */
class Exception_Rule {

	/**
	 * Rule ID (UUID).
	 *
	 * @var string
	 */
	public string $id = '';

	/**
	 * Site ID.
	 *
	 * @var int
	 */
	public int $site_id = 0;

	/**
	 * Rule status.
	 *
	 * @var string
	 */
	public string $status = 'active';

	/**
	 * Target type (rule, element, rule_on_element).
	 *
	 * @var string
	 */
	public string $target_type = '';

	/**
	 * Array of rule IDs covered by this exception.
	 *
	 * @var array
	 */
	public array $rule_ids = [];

	/**
	 * Element matching criteria.
	 *
	 * @var array
	 */
	public array $element_match = [];

	/**
	 * Scope configuration.
	 *
	 * @var array
	 */
	public array $scope = [];

	/**
	 * Duration configuration.
	 *
	 * @var array
	 */
	public array $duration = [];

	/**
	 * Reason category.
	 *
	 * @var string|null
	 */
	public ?string $reason_category = null;

	/**
	 * Optional note.
	 *
	 * @var string|null
	 */
	public ?string $note = null;

	/**
	 * Whether this is a system-generated rule.
	 *
	 * @var bool
	 */
	public bool $system_generated = false;

	/**
	 * User ID who created this rule.
	 *
	 * @var int|null
	 */
	public ?int $created_by = null;

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
	 * Expiration timestamp.
	 *
	 * @var string|null
	 */
	public ?string $expires_at = null;

	/**
	 * Number of violations matched by this rule.
	 *
	 * @var int
	 */
	public int $match_count = 0;

	/**
	 * Exact v2 violation identity anchored to this occurrence exception.
	 *
	 * @var string|null
	 */
	public ?string $violation_identity_v2 = null;

	/**
	 * V2 element identity retained for non-suppressing resemblance signals.
	 *
	 * @var string|null
	 */
	public ?string $element_identity_v2 = null;

	/**
	 * Identity signature version used by the anchored hashes.
	 *
	 * @var int|null
	 */
	public ?int $identity_signature_version = null;

	/**
	 * One-time legacy selector re-anchoring state.
	 *
	 * @var string
	 */
	public string $legacy_reanchor_status = 'pending';

	/**
	 * Timestamp of the one-time legacy re-anchor attempt.
	 *
	 * @var string|null
	 */
	public ?string $legacy_reanchor_attempted_at = null;

	/**
	 * Valid target types.
	 *
	 * @var array
	 */
	public const TARGET_TYPES = ['rule', 'element', 'rule_on_element'];

	/**
	 * Valid statuses.
	 *
	 * @var array
	 */
	public const STATUSES = ['active', 'disabled', 'expired', 'revoked'];

	/**
	 * Valid scope types.
	 *
	 * @var array
	 */
	public const SCOPE_TYPES = ['page', 'site', 'content_type', 'url_pattern'];

	/**
	 * Valid duration types.
	 *
	 * @var array
	 */
	public const DURATION_TYPES = ['until_next_scan', 'permanent', 'until_date', 'until_content_changes'];

	/**
	 * Valid reason categories.
	 *
	 * @var array
	 */
	public const REASON_CATEGORIES = [
		'false_positive',
		'not_applicable',
		'acceptable_in_context',
		'accepted_risk',
		'third_party_code',
		'tracked_elsewhere',
		'planned_fix',
		'design_limitation',
		'other',
	];

	/**
	 * Valid audit event types.
	 *
	 * @var array
	 */
	public const AUDIT_EVENTS = [
		'exception_created',
		'exception_edited',
		'exception_disabled',
		'exception_enabled',
		'exception_revoked',
		'exception_expired',
		'occurrence_snoozed',
		'violation_suppressed',
		'exception_reanchored_v2',
	];

	/**
	 * Create Exception_Rule from database row.
	 *
	 * @param object $row Database row object.
	 * @return self
	 */
	public static function from_row(object $row): self {
		$rule = new self();

		$rule->id = $row->id ?? '';
		$rule->site_id = (int) ($row->site_id ?? 0);
		$rule->status = $row->status ?? 'active';
		$rule->target_type = $row->target_type ?? '';
		$rule->rule_ids = isset($row->rule_ids) ? json_decode($row->rule_ids, true) : [];
		$rule->element_match = isset($row->element_match) ? json_decode($row->element_match, true) : [];
		$rule->scope = isset($row->scope) ? json_decode($row->scope, true) : [];
		$rule->duration = isset($row->duration) ? json_decode($row->duration, true) : [];
		$rule->reason_category = $row->reason_category ?? null;
		$rule->note = $row->note ?? null;
		$rule->system_generated = (bool) ($row->system_generated ?? 0);
		$rule->created_by = isset($row->created_by) ? (int) $row->created_by : null;
		$rule->created_at = $row->created_at ?? '';
		$rule->updated_at = $row->updated_at ?? null;
		$rule->expires_at = $row->expires_at ?? null;
		$rule->match_count = (int) ($row->match_count ?? 0);
		$rule->violation_identity_v2 = $row->violation_identity_v2 ?? null;
		$rule->element_identity_v2 = $row->element_identity_v2 ?? null;
		$rule->identity_signature_version = isset($row->identity_signature_version)
			? (int) $row->identity_signature_version
			: null;
		$rule->legacy_reanchor_status = $row->legacy_reanchor_status
			?? ('rule' === $rule->target_type ? 'not_required' : 'pending');
		$rule->legacy_reanchor_attempted_at = $row->legacy_reanchor_attempted_at ?? null;

		return $rule;
	}

	/**
	 * Convert rule to array for JSON serialization.
	 *
	 * @return array Rule data as array.
	 */
	public function to_array(): array {
		$user = $this->created_by ? get_userdata($this->created_by) : null;

		return [
			'id' => $this->id,
			'site_id' => $this->site_id,
			'status' => $this->status,
			'target_type' => $this->target_type,
			'rule_ids' => $this->rule_ids,
			'element_match' => $this->element_match,
			'scope' => $this->scope,
			'duration' => $this->duration,
			'reason_category' => $this->reason_category,
			'note' => $this->note,
			'system_generated' => $this->system_generated,
			'created_by' => $this->created_by,
			'created_by_name' => $user ? $user->display_name : null,
			'created_at' => $this->created_at,
			'updated_at' => $this->updated_at,
			'expires_at' => $this->expires_at,
			'match_count' => $this->match_count,
			'violation_identity_v2' => $this->violation_identity_v2,
			'element_identity_v2' => $this->element_identity_v2,
			'identity_signature_version' => $this->identity_signature_version,
			'legacy_reanchor_status' => $this->legacy_reanchor_status,
			'legacy_reanchor_attempted_at' => $this->legacy_reanchor_attempted_at,
			'is_expired' => $this->is_expired(),
			'is_active' => $this->is_active(),
		];
	}

	/**
	 * Check if rule is expired.
	 *
	 * @return bool
	 */
	public function is_expired(): bool {
		if ($this->status === 'expired') {
			return true;
		}

		if ($this->expires_at === null) {
			return false;
		}

		return strtotime($this->expires_at) < time();
	}

	/**
	 * Check if rule is active (not disabled or expired).
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		if ($this->status === 'disabled' || $this->status === 'expired') {
			return false;
		}

		return !$this->is_expired();
	}

	/**
	 * Generate a human-readable label for this rule.
	 *
	 * @return string
	 */
	public function get_label(): string {
		$target_label = $this->get_target_label();
		$scope_label = $this->get_scope_label();

		return sprintf('%s %s', $target_label, $scope_label);
	}

	/**
	 * Get human-readable target label.
	 *
	 * @return string
	 */
	private function get_target_label(): string {
		switch ($this->target_type) {
			case 'rule':
				$rule_names = implode(', ', array_slice($this->rule_ids, 0, 2));
				if (count($this->rule_ids) > 2) {
					$rule_names .= sprintf(' + %d more', count($this->rule_ids) - 2);
				}
				return sprintf('Exception for rule(s): %s', $rule_names);

			case 'element':
				$element = $this->element_match['tag_name'] ?? 'element';
				if (isset($this->element_match['css_selector'])) {
					return sprintf('Exception for element: %s', $this->element_match['css_selector']);
				}
				return sprintf('Exception for element: %s', $element);

			case 'rule_on_element':
				$rule = $this->rule_ids[0] ?? 'rule';
				$element = $this->element_match['css_selector'] ?? ($this->element_match['tag_name'] ?? 'element');
				return sprintf('Exception for %s on %s', $rule, $element);

			default:
				return 'Reviewed exception';
		}
	}

	/**
	 * Get human-readable scope label.
	 *
	 * @return string
	 */
	private function get_scope_label(): string {
		if (empty($this->scope)) {
			return '';
		}

		$scope_type = $this->scope['scope_type'] ?? '';

		switch ($scope_type) {
			case 'page':
				$url = $this->scope['url'] ?? '';
				return sprintf('on page: %s', $url);

			case 'site':
				return 'across entire site';

			case 'content_type':
				$types = implode(', ', $this->scope['post_types'] ?? []);
				return sprintf('on content types: %s', $types);

			case 'url_pattern':
				$patterns = implode(', ', $this->scope['patterns'] ?? []);
				return sprintf('on URLs matching: %s', $patterns);

			default:
				return '';
		}
	}

	/**
	 * Get human-readable duration label.
	 *
	 * @return string
	 */
	public function get_duration_label(): string {
		if (empty($this->duration)) {
			return 'Unknown';
		}

		$duration_type = $this->duration['duration_type'] ?? '';

		switch ($duration_type) {
			case 'until_next_scan':
				return 'Until next scan';

			case 'permanent':
				return 'Permanent';

			case 'until_date':
				$date = isset($this->duration['expires_at'])
					? gmdate('Y-m-d', strtotime($this->duration['expires_at']))
					: 'unknown date';
				return sprintf('Until %s', $date);

			case 'until_content_changes':
				return 'Until content changes';

			default:
				return 'Unknown';
		}
	}
}
