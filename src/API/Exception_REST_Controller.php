<?php
/**
 * Exception REST API Controller
 *
 * Handles REST API endpoints for exception rule operations.
 *
 * @package ClearA11y
 * @namespace ClearA11y\API
 */

namespace ClearA11y\API;

use ClearA11y\Database\Exception_Rule_Repository;
use ClearA11y\Database\Exception_Schema;
use ClearA11y\Database\Issue_Repository;
use ClearA11y\Models\Exception_Rule;
use ClearA11y\Services\Exception_Matcher_Service;
use ClearA11y\Services\Fingerprint_Service;

/**
 * Exception REST Controller Class
 */
class Exception_REST_Controller {

	/**
	 * API namespace.
	 *
	 * @var string
	 */
	private const NAMESPACE = 'cleara11y/v1';

	/**
	 * Generate UUID v4 with fallback for older WordPress/PHP versions.
	 *
	 * @return string UUID v4
	 */
	private static function generate_uuid_v4(): string {
		if (function_exists('wp_generate_uuid_v4')) {
			return \wp_generate_uuid_v4();
		}

		// Fallback: Generate UUID v4 manually
		$data = random_bytes(16);
		$data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Version 4
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Variant RFC 4122

		// Convert to hexadecimal and format as UUID
		$hex = bin2hex($data);
		return sprintf(
			'%08s-%04s-%04s-%04s-%012s',
			substr($hex, 0, 8),
			substr($hex, 8, 4),
			substr($hex, 12, 4),
			substr($hex, 16, 4),
			substr($hex, 20, 12)
		);
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action('rest_api_init', [$this, 'register_routes']);
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes(): void {
		// Ensure tables exist
		add_action('rest_api_init', function() {
			if (!Exception_Schema::tables_exist()) {
				Exception_Schema::create_tables();
			}
		}, 5);

		// Get exception rules list
		register_rest_route(
			self::NAMESPACE,
			'/exceptions',
			[
				[
					'methods' => 'GET',
					'callback' => [$this, 'get_exception_rules'],
					'permission_callback' => [$this, 'can_manage_exceptions'],
					'args' => [
						'status' => [
							'type' => 'string',
							'enum' => ['active', 'disabled', 'expired', 'revoked', 'all'],
							'default' => 'active',
							'description' => 'Filter by status.',
						],
						'system_generated' => [
							'type' => 'boolean',
							'description' => 'Filter by system_generated flag.',
						],
						'page' => [
							'type' => 'integer',
							'default' => 1,
							'minimum' => 1,
						],
						'per_page' => [
							'type' => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 100,
						],
					],
				],
				[
					'methods' => 'POST',
					'callback' => [$this, 'create_exception_rule'],
					'permission_callback' => [$this, 'can_manage_exceptions'],
				],
			]
		);

		// Get single exception rule
		register_rest_route(
			self::NAMESPACE,
			'/exceptions/(?P<id>[a-zA-Z0-9-]+)',
			[
				[
					'methods' => 'GET',
					'callback' => [$this, 'get_exception_rule'],
					'permission_callback' => [$this, 'can_manage_exceptions'],
				],
				[
					'methods' => 'PUT',
					'callback' => [$this, 'update_exception_rule'],
					'permission_callback' => [$this, 'can_manage_exceptions'],
				],
				[
					'methods' => 'DELETE',
					'callback' => [$this, 'revoke_exception_rule'],
					'permission_callback' => [$this, 'can_manage_exceptions'],
				],
			]
		);

		// One-scan snooze endpoint.
		register_rest_route(
			self::NAMESPACE,
			'/exceptions/snooze',
			[
				'methods' => 'POST',
				'callback' => [$this, 'snooze_occurrence'],
				'permission_callback' => [$this, 'can_manage_exceptions'],
				'args' => [
					'violation_id' => [
						'required' => true,
						'type' => 'integer',
						'description' => 'Issue ID to mark as an exception.',
					],
				],
			]
		);

		// Undo a one-scan snooze.
		register_rest_route(
			self::NAMESPACE,
			'/exceptions/(?P<id>[a-zA-Z0-9-]+)/undo',
			[
				'methods' => 'POST',
				'callback' => [$this, 'undo_snooze'],
				'permission_callback' => [$this, 'can_manage_exceptions'],
			]
		);

		// Enable/disable exception rule
		register_rest_route(
			self::NAMESPACE,
			'/exceptions/(?P<id>[a-zA-Z0-9-]+)/(?P<action>enable|disable)',
			[
				'methods' => 'POST',
				'callback' => [$this, 'toggle_exception_rule'],
				'permission_callback' => [$this, 'can_manage_exceptions'],
			]
		);

		// Get audit log
		register_rest_route(
			self::NAMESPACE,
			'/exceptions/(?P<id>[a-zA-Z0-9-]+)/audit',
			[
				'methods' => 'GET',
				'callback' => [$this, 'get_audit_log'],
				'permission_callback' => [$this, 'can_manage_exceptions'],
			]
		);

		// Get site-wide audit log
		register_rest_route(
			self::NAMESPACE,
			'/exceptions/audit/all',
			[
				'methods' => 'GET',
				'callback' => [$this, 'get_all_audit_log'],
				'permission_callback' => [$this, 'can_manage_exceptions'],
			]
		);

		// Calculate impact preview
		register_rest_route(
			self::NAMESPACE,
			'/exceptions/preview',
			[
				'methods' => 'POST',
				'callback' => [$this, 'preview_impact'],
				'permission_callback' => [$this, 'can_manage_exceptions'],
			]
		);

		// Get excepted findings for a scan item
		register_rest_route(
			self::NAMESPACE,
			'/scan-items/(?P<id>\d+)/exceptions',
			[
				'methods' => 'GET',
				'callback' => [$this, 'get_exception_findings'],
				'permission_callback' => [$this, 'can_manage_exceptions'],
			]
		);

		// Check if issue has an exception
		register_rest_route(
			self::NAMESPACE,
			'/violations/(?P<id>\d+)/exception-status',
			[
				'methods' => 'GET',
				'callback' => [$this, 'get_violation_exception_status'],
				'permission_callback' => [$this, 'can_manage_exceptions'],
			]
		);
	}

	/**
	 * Get exception rules list.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_exception_rules(\WP_REST_Request $request): \WP_REST_Response {
		$site_id = get_current_blog_id();
		$status = $request->get_param('status');
		$system_generated = $request->get_param('system_generated');
		$page = (int) $request->get_param('page') ?? 1;
		$per_page = (int) $request->get_param('per_page') ?? 20;
		$offset = ($page - 1) * $per_page;

		// Mark expired rules
		Exception_Rule_Repository::mark_expired($site_id);

		$args = [
			'limit' => $per_page,
			'offset' => $offset,
		];

		if ($status !== 'all') {
			$args['status'] = $status;
		}

		if (null !== $system_generated) {
			$args['system_generated'] = $system_generated;
		}

		$rules = Exception_Rule_Repository::get_by_site_id($site_id, $args);

		// Get counts for tabs
		$counts = [
			'active' => Exception_Rule_Repository::get_count_by_status($site_id, 'active'),
			'disabled' => Exception_Rule_Repository::get_count_by_status($site_id, 'disabled'),
			'expired' => Exception_Rule_Repository::get_count_by_status($site_id, 'expired'),
			'revoked' => Exception_Rule_Repository::get_count_by_status($site_id, 'revoked'),
			'all' => Exception_Rule_Repository::get_count_by_status($site_id, 'active')
				+ Exception_Rule_Repository::get_count_by_status($site_id, 'disabled')
				+ Exception_Rule_Repository::get_count_by_status($site_id, 'expired')
				+ Exception_Rule_Repository::get_count_by_status($site_id, 'revoked'),
		];
		$total = 'all' === $status ? $counts['all'] : ($counts[$status] ?? 0);

		return rest_ensure_response([
			'data' => array_map(fn($rule) => $rule->to_array(), $rules),
			'counts' => $counts,
			'total' => $total,
			'page' => $page,
			'per_page' => $per_page,
			'total_pages' => max(1, (int) ceil($total / $per_page)),
		]);
	}

	/**
	 * Get single exception rule.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_exception_rule(\WP_REST_Request $request) {
		$rule_id = $request->get_param('id');
		$rule = Exception_Rule_Repository::get_by_id($rule_id);

		if (!$rule) {
			return new \WP_Error('rule_not_found', 'Reviewed exception not found.', ['status' => 404]);
		}
		$rule_array = $rule->to_array();
		$rule_array['audit_log'] = array_map(
			fn($log) => $log->to_array(),
			Exception_Rule_Repository::get_audit_log($rule_id)
		);

		return rest_ensure_response($rule_array);
	}

	/**
	 * Create exception rule.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_exception_rule(\WP_REST_Request $request) {
		$request_params = (array) $request->get_json_params();
		if (empty($request_params['violation_id'])) {
			return new \WP_Error(
				'exception_occurrence_required',
				'Choose a current issue occurrence for this exception.',
				['status' => 400]
			);
		}
		$params = $this->validate_exception_params($request_params);
		if (is_wp_error($params)) {
			return $params;
		}

		// Generate UUID
		$rule_id = self::generate_uuid_v4();

		$rule = new Exception_Rule();
		$rule->id = $rule_id;
		$rule->site_id = get_current_blog_id();
		$rule->status = 'active';
		$rule->target_type = $params['target_type'];
		$rule->rule_ids = $params['rule_ids'] ?? [];
		$rule->element_match = $params['element_match'] ?? [];
		$rule->scope = $params['scope'];
		$rule->duration = $params['duration'];
		$rule->reason_category = $params['reason_category'] ?? null;
		$rule->note = $params['note'] ?? null;
		$rule->system_generated = $params['system_generated'] ?? false;
		$rule->created_by = get_current_user_id();
		$rule->created_at = current_time('mysql');

		$anchor_result = $this->anchor_occurrence_rule($rule, (int) ($params['violation_id'] ?? 0));
		if (is_wp_error($anchor_result)) {
			return $anchor_result;
		}

		$warning = $this->check_guardrails([
			'scope' => $rule->scope,
			'duration' => $rule->duration,
			'rule_ids' => $rule->rule_ids,
			'violation_id' => $params['violation_id'],
		]);

		// Set expiration if needed
		if (isset($params['duration']['duration_type'])) {
			$rule->expires_at = $this->calculate_expiration($params['duration']);
		}

		$insert_id = Exception_Rule_Repository::insert($rule);

		if (!$insert_id) {
			return new \WP_Error('insert_failed', 'Failed to create exception.', ['status' => 500]);
		}

		// Apply to existing violations
		$this->apply_exception_to_existing($rule);

		return rest_ensure_response([
			'id' => $rule_id,
			'message' => 'Reviewed exception created successfully.',
			'warning' => $warning,
			'rule' => $rule->to_array(),
		]);
	}

	/**
	 * Snooze an occurrence until its page is scanned again.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function snooze_occurrence(\WP_REST_Request $request) {
		$violation_id = (int) $request->get_param('violation_id');
		$user_id = get_current_user_id();

		// Get the violation
		$violation = Issue_Repository::get_by_id($violation_id);

		if (!$violation) {
			return new \WP_Error('violation_not_found', 'Issue not found.', ['status' => 404]);
		}

		$identity_error = $this->validate_occurrence_identity($violation);
		if (is_wp_error($identity_error)) {
			return $identity_error;
		}

		// Get scan item for URL
		global $wpdb;
		$scan_items_table = \ClearA11y\Database\Schema::get_table_name('scan_items');
		$scan_item = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$scan_items_table}` WHERE id = %d",
				$violation->scan_item_id
			)
		);

		if (!$scan_item) {
			return new \WP_Error('scan_item_not_found', 'Scan item not found.', ['status' => 404]);
		}

		$site_id = get_current_blog_id();
		$url = $scan_item->post_url ?? '';
		$selector = $violation->selector ?? '';

		// Refresh an existing snooze for the same occurrence.
		$existing = Exception_Rule_Repository::find_existing_snooze(
			$site_id,
			$violation->rule_id,
			$url,
			$selector
		);

		if ($existing) {
			$existing_match = Exception_Matcher_Service::matches_rule($violation, $existing);
			if (! $existing_match || 'suppressed' !== ($existing_match['action'] ?? '')) {
				$existing = null;
			}
		}

		if ($existing) {
			// Refresh expiration
			$existing->expires_at = null;
			Exception_Rule_Repository::update($existing);

			return rest_ensure_response([
				'message' => 'Temporary exception refreshed.',
				'rule' => $existing->to_array(),
			]);
		}

		// Generate element match data
		$fingerprints = Fingerprint_Service::generate_from_issue($violation);

		$rule_id = self::generate_uuid_v4();

		$rule = new Exception_Rule();
		$rule->id = $rule_id;
		$rule->site_id = $site_id;
		$rule->status = 'active';
		$rule->target_type = 'rule_on_element';
		$rule->rule_ids = [$violation->rule_id];
		$rule->element_match = [
			'css_selector' => $selector,
			'selector_fingerprint' => $fingerprints['selector'],
			'element_fingerprint' => $fingerprints['element'],
		];
		$rule->violation_identity_v2 = $violation->violation_identity_v2;
		$rule->element_identity_v2 = $violation->element_identity_v2;
		$rule->identity_signature_version = $violation->identity_signature_version;
		$rule->legacy_reanchor_status = ! empty($violation->violation_identity_v2)
			? 'anchored'
			: 'pending';
		$rule->scope = [
			'scope_type' => 'page',
			'url' => $url,
		];
		$rule->duration = [
			'duration_type' => 'until_next_scan',
		];
		$rule->reason_category = null; // A one-scan snooze is not a reviewed exception.
		$rule->note = null;
		$rule->system_generated = true;
		$rule->created_by = $user_id;
		$rule->created_at = current_time('mysql');
		$rule->expires_at = null;

		$insert_id = Exception_Rule_Repository::insert($rule);

		if (!$insert_id) {
			return new \WP_Error('insert_failed', 'Failed to snooze the issue.', ['status' => 500]);
		}

		// Create violation match
		Exception_Rule_Repository::create_match($violation_id, $rule_id, $site_id, 'exact', 'suppressed');

		return rest_ensure_response([
			'id' => $rule_id,
			'message' => 'Issue snoozed until the next scan.',
			'rule' => $rule->to_array(),
		]);
	}

	/**
	 * Undo a one-scan snooze.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function undo_snooze(\WP_REST_Request $request) {
		$rule_id = $request->get_param('id');

		$rule = Exception_Rule_Repository::get_by_id($rule_id);

		if (!$rule) {
			return new \WP_Error('rule_not_found', 'Reviewed exception not found.', ['status' => 404]);
		}
		if ('revoked' === $rule->status) {
			return new \WP_Error('exception_already_revoked', 'This exception is already revoked.', ['status' => 409]);
		}

		// Only allow undoing system-generated rules
		if (!$rule->system_generated) {
			return new \WP_Error('not_snooze', 'This exception is not a one-scan snooze.', ['status' => 400]);
		}

		// Delete the rule
		$result = Exception_Rule_Repository::revoke($rule_id);

		if ($result) {
			return rest_ensure_response([
				'message' => 'Snooze removed.',
			]);
		}

		return new \WP_Error('revoke_failed', 'Failed to remove the snooze.', ['status' => 500]);
	}

	/**
	 * Update exception rule.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_exception_rule(\WP_REST_Request $request) {
		$rule_id = $request->get_param('id');
		$params = (array) $request->get_json_params();

		$rule = Exception_Rule_Repository::get_by_id($rule_id);

		if (!$rule) {
			return new \WP_Error('rule_not_found', 'Reviewed exception not found.', ['status' => 404]);
		}
		if ('revoked' === $rule->status) {
			return new \WP_Error('exception_revoked', 'A revoked exception cannot be edited.', ['status' => 409]);
		}

		$params = $this->validate_exception_params(
			array_merge(
				$rule->to_array(),
				$params,
				['target_type' => $rule->target_type]
			)
		);
		if (is_wp_error($params)) {
			return $params;
		}

		// Update fields
		if (isset($params['rule_ids'])) {
			$rule->rule_ids = $params['rule_ids'];
		}
		if ('rule' === $rule->target_type && isset($params['element_match'])) {
			$rule->element_match = $params['element_match'];
		}
		if (isset($params['scope'])) {
			$rule->scope = $params['scope'];
		}
		if (isset($params['duration'])) {
			$rule->duration = $params['duration'];
			$rule->expires_at = $this->calculate_expiration($params['duration']);
		}
		if (isset($params['reason_category'])) {
			$rule->reason_category = $params['reason_category'];
		}
		if (isset($params['note'])) {
			$rule->note = $params['note'];
		}

		$result = Exception_Rule_Repository::update($rule);

		if ($result) {
			return rest_ensure_response([
				'message' => 'Reviewed exception updated successfully.',
				'rule' => $rule->to_array(),
			]);
		}

		return new \WP_Error('update_failed', 'Failed to update exception.', ['status' => 500]);
	}

	/**
	 * Delete exception rule.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function revoke_exception_rule(\WP_REST_Request $request) {
		$rule_id = $request->get_param('id');

		$rule = Exception_Rule_Repository::get_by_id($rule_id);

		if (!$rule) {
			return new \WP_Error('rule_not_found', 'Reviewed exception not found.', ['status' => 404]);
		}
		if ('revoked' === $rule->status) {
			return new \WP_Error('exception_already_revoked', 'This exception is already revoked.', ['status' => 409]);
		}

		$result = Exception_Rule_Repository::revoke($rule_id);

		if ($result) {
			return rest_ensure_response([
				'message' => 'Reviewed exception revoked successfully.',
			]);
		}

		return new \WP_Error('revoke_failed', 'Failed to revoke exception.', ['status' => 500]);
	}

	/**
	 * Toggle exception rule (enable/disable).
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function toggle_exception_rule(\WP_REST_Request $request) {
		$rule_id = $request->get_param('id');
		$action = $request->get_param('action');

		$rule = Exception_Rule_Repository::get_by_id($rule_id);

		if (!$rule) {
			return new \WP_Error('rule_not_found', 'Reviewed exception not found.', ['status' => 404]);
		}

		if ($action === 'enable') {
			if ('disabled' !== $rule->status) {
				return new \WP_Error('invalid_exception_transition', 'Only disabled exceptions can be enabled.', ['status' => 409]);
			}
			$result = Exception_Rule_Repository::enable($rule_id);
			$message = 'Reviewed exception enabled.';
		} else {
			if ('active' !== $rule->status) {
				return new \WP_Error('invalid_exception_transition', 'Only active exceptions can be disabled.', ['status' => 409]);
			}
			$result = Exception_Rule_Repository::disable($rule_id);
			$message = 'Reviewed exception disabled.';
		}

		if ($result) {
			return rest_ensure_response([
				'message' => $message,
			]);
		}

		return new \WP_Error('toggle_failed', 'Failed to toggle exception.', ['status' => 500]);
	}

	/**
	 * Get audit log for a rule.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_audit_log(\WP_REST_Request $request): \WP_REST_Response {
		$rule_id = $request->get_param('id');

		$audit_log = Exception_Rule_Repository::get_audit_log($rule_id);

		return rest_ensure_response([
			'data' => array_map(fn($log) => $log->to_array(), $audit_log),
		]);
	}

	/**
	 * Get all audit log entries for site.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_all_audit_log(\WP_REST_Request $request): \WP_REST_Response {
		$site_id = get_current_blog_id();

		$audit_log = Exception_Rule_Repository::get_all_audit_log($site_id);

		return rest_ensure_response([
			'data' => array_map(fn($log) => $log->to_array(), $audit_log),
		]);
	}

	/**
	 * Calculate impact preview for an exception rule.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function preview_impact(\WP_REST_Request $request) {
		$request_params = (array) $request->get_json_params();
		if (empty($request_params['violation_id'])) {
			return new \WP_Error(
				'exception_occurrence_required',
				'Choose a current issue occurrence for this exception.',
				['status' => 400]
			);
		}
		$params = $this->validate_exception_params($request_params);
		if (is_wp_error($params)) {
			return $params;
		}

		// Create temporary rule for preview
		$rule = new Exception_Rule();
		$rule->target_type = $params['target_type'];
		$rule->rule_ids = $params['rule_ids'];
		$rule->element_match = $params['element_match'];
		$rule->scope = $params['scope'];
		$anchor_result = $this->anchor_occurrence_rule($rule, (int) ($params['violation_id'] ?? 0));
		if (is_wp_error($anchor_result)) {
			return $anchor_result;
		}

		$site_id = get_current_blog_id();

		$impact = Exception_Matcher_Service::calculate_impact($rule, $site_id);

		return rest_ensure_response($impact);
	}

	/**
	 * Get excepted findings for a scan item.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_exception_findings(\WP_REST_Request $request): \WP_REST_Response {
		$scan_item_id = (int) $request->get_param('id');
		$site_id = get_current_blog_id();

		global $wpdb;
		$issues_table = \ClearA11y\Database\Schema::get_table_name('issues');
		$matches_table = Exception_Schema::get_table_name('issue_exception_matches');
		$rules_table = Exception_Schema::get_table_name('exception_rules');
		$scan_items_table = \ClearA11y\Database\Schema::get_table_name('scan_items');

		// Get violations that have exception matches
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT i.*, ir.id as exception_rule_id, ir.reason_category, ir.note, ir.created_by, ir.created_at as exception_applied_at
				FROM `{$issues_table}` i
				INNER JOIN `{$matches_table}` vm ON i.id = vm.violation_id AND vm.match_action = 'suppressed'
				INNER JOIN `{$rules_table}` ir ON vm.exception_rule_id = ir.id
				WHERE i.scan_item_id = %d
				ORDER BY i.severity DESC, i.id ASC",
				$scan_item_id
			)
		);

		$violations = [];
		foreach ($rows as $row) {
			$issue = \ClearA11y\Models\Issue::from_row($row);

			$violations[] = [
				'issue' => $issue->to_array(),
				'matching_exception' => [
					'rule_id' => $row->exception_rule_id,
					'reason_category' => $row->reason_category,
					'note' => $row->note,
					'created_by' => $row->created_by,
					'created_by_name' => $row->created_by ? get_userdata($row->created_by)->display_name : null,
					'exception_applied_at' => $row->exception_applied_at,
				],
			];
		}

		return rest_ensure_response([
			'data' => $violations,
			'total' => count($violations),
		]);
	}

	/**
	 * Check if a issue has an exception and return matching rules.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_violation_exception_status(\WP_REST_Request $request): \WP_REST_Response {
		$violation_id = (int) $request->get_param('id');
		$site_id = get_current_blog_id();

		$violation = Issue_Repository::get_by_id($violation_id);

		if (!$violation) {
			return new \WP_Error('violation_not_found', 'Issue not found.', ['status' => 404]);
		}

		$matches = Exception_Matcher_Service::find_matches($violation, $site_id, false);
		$suppressions = array_values(
			array_filter(
				$matches,
				static fn(array $match): bool => 'suppressed' === $match['action']
			)
		);
		$resemblances = array_values(
			array_filter(
				$matches,
				static fn(array $match): bool => 'resembles' === $match['action']
			)
		);

		return rest_ensure_response([
			'is_exception' => ! empty($suppressions),
			'resembles_exception' => ! empty($resemblances),
			'matching_rules' => array_map(function($match) {
				return [
					'rule_id' => $match['rule']->id,
					'rule_label' => $match['rule']->get_label(),
					'confidence' => $match['confidence'],
					'matched_by' => $match['matched_by'],
					'action' => $match['action'],
				];
			}, $matches),
		]);
	}

	/**
	 * Permission callback: Can manage ignores.
	 *
	 * @return bool True if user can manage ignores.
	 */
	public function can_manage_exceptions(): bool {
		/**
		 * Filter whether the current user can manage exception rules.
		 *
		 * @since 1.6.0
		 *
		 * @param bool $can_manage True if user can manage ignores.
		 */
		return apply_filters('cleara11y_manage_exceptions_permission', current_user_can('manage_options'));
	}

	/**
	 * Validate and normalize a reviewed exception payload.
	 *
	 * @param array $params Request parameters.
	 * @return array|\WP_Error Normalized parameters or validation error.
	 */
	private function validate_exception_params(array $params) {
		$target_type = sanitize_key((string) ($params['target_type'] ?? ''));
		$scope = is_array($params['scope'] ?? null) ? $params['scope'] : [];
		$duration = is_array($params['duration'] ?? null) ? $params['duration'] : [];
		$scope_type = sanitize_key((string) ($scope['scope_type'] ?? ''));
		$duration_type = sanitize_key((string) ($duration['duration_type'] ?? ''));
		$reason = sanitize_key((string) ($params['reason_category'] ?? ''));
		$note = sanitize_textarea_field((string) ($params['note'] ?? ''));

		if (
			! in_array($target_type, Exception_Rule::TARGET_TYPES, true)
			|| ! in_array($scope_type, Exception_Rule::SCOPE_TYPES, true)
			|| ! in_array($duration_type, Exception_Rule::DURATION_TYPES, true)
		) {
			return new \WP_Error(
				'invalid_exception',
				'Choose a valid exception target, scope, and duration.',
				['status' => 400]
			);
		}

		if (! in_array($reason, Exception_Rule::REASON_CATEGORIES, true) || '' === $note) {
			return new \WP_Error(
				'exception_reason_required',
				'Reviewed exceptions require both a reason and a note.',
				['status' => 400]
			);
		}

		$rule_ids = array_values(
			array_unique(
				array_filter(
					array_map('sanitize_key', (array) ($params['rule_ids'] ?? []))
				)
			)
		);
		if ('rule' === $target_type && empty($rule_ids) && empty($params['violation_id'])) {
			return new \WP_Error('exception_rule_required', 'Select at least one accessibility rule.', ['status' => 400]);
		}

		$normalized_scope = ['scope_type' => $scope_type];
		if ('page' === $scope_type) {
			$normalized_scope['url'] = esc_url_raw((string) ($scope['url'] ?? ''));
			if ('' === $normalized_scope['url'] && empty($params['violation_id'])) {
				return new \WP_Error('exception_page_required', 'Choose a page for this exception.', ['status' => 400]);
			}
		} elseif ('content_type' === $scope_type) {
			$normalized_scope['post_types'] = array_values(
				array_filter(array_map('sanitize_key', (array) ($scope['post_types'] ?? [])))
			);
			if (empty($normalized_scope['post_types'])) {
				return new \WP_Error('exception_content_type_required', 'Choose at least one content type.', ['status' => 400]);
			}
		} elseif ('url_pattern' === $scope_type) {
			$normalized_scope['patterns'] = array_slice(
				array_values(
					array_filter(
						array_map('sanitize_text_field', (array) ($scope['patterns'] ?? []))
					)
				),
				0,
				20
			);
			if (empty($normalized_scope['patterns'])) {
				return new \WP_Error('exception_pattern_required', 'Enter at least one URL pattern.', ['status' => 400]);
			}
		}

		$normalized_duration = ['duration_type' => $duration_type];
		if ('until_date' === $duration_type) {
			$timestamp = strtotime((string) ($duration['expires_at'] ?? ''));
			if (false === $timestamp || $timestamp <= time()) {
				return new \WP_Error('exception_date_invalid', 'Choose a future expiration date.', ['status' => 400]);
			}
			$normalized_duration['expires_at'] = gmdate('Y-m-d H:i:s', $timestamp);
		}

		$element_match = is_array($params['element_match'] ?? null) ? $params['element_match'] : [];
		$params['target_type'] = $target_type;
		$params['rule_ids'] = $rule_ids;
		$params['element_match'] = [
			'css_selector' => sanitize_text_field((string) ($element_match['css_selector'] ?? '')),
			'tag_name' => sanitize_key((string) ($element_match['tag_name'] ?? '')),
		];
		$params['scope'] = $normalized_scope;
		$params['duration'] = $normalized_duration;
		$params['reason_category'] = $reason;
		$params['note'] = $note;
		$params['system_generated'] = false;
		$params['violation_id'] = absint($params['violation_id'] ?? 0);

		return $params;
	}

	/**
	 * Bind an occurrence-specific exception to server-owned identity data.
	 *
	 * @param Exception_Rule $rule Rule being created.
	 * @param int            $violation_id Issue ID.
	 * @return true|\WP_Error
	 */
	private function anchor_occurrence_rule(Exception_Rule $rule, int $violation_id) {
		$violation = Issue_Repository::get_by_id($violation_id);
		if (! $violation) {
			return new \WP_Error(
				'exception_occurrence_required',
				'Choose a current issue occurrence for this exception.',
				['status' => 400]
			);
		}

		if ('rule' !== $rule->target_type) {
			$identity_error = $this->validate_occurrence_identity($violation);
			if (is_wp_error($identity_error)) {
				return $identity_error;
			}
		}

		global $wpdb;
		$scan_item = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT post_url FROM `' . \ClearA11y\Database\Schema::get_table_name('scan_items') . '` WHERE id = %d',
				$violation->scan_item_id
			)
		);
		if (! $scan_item) {
			return new \WP_Error('scan_item_not_found', 'Scan item not found.', ['status' => 404]);
		}

		$rule->rule_ids = 'element' === $rule->target_type ? [] : [$violation->rule_id];
		if ('rule' === $rule->target_type) {
			$rule->element_match = [];
			$rule->legacy_reanchor_status = 'not_required';
		} else {
			$rule->element_match = [
				'css_selector' => (string) $violation->selector,
			];
			$rule->violation_identity_v2 = $violation->violation_identity_v2;
			$rule->element_identity_v2 = $violation->element_identity_v2;
			$rule->identity_signature_version = $violation->identity_signature_version;
			$rule->legacy_reanchor_status = 'anchored';
		}
		if ('page' === ($rule->scope['scope_type'] ?? '')) {
			$rule->scope['url'] = (string) $scan_item->post_url;
		}

		return true;
	}

	/**
	 * Ensure an issue can safely anchor an occurrence-level exception.
	 *
	 * @param \ClearA11y\Models\Issue $violation Issue to validate.
	 * @return true|\WP_Error
	 */
	private function validate_occurrence_identity(\ClearA11y\Models\Issue $violation) {
		if (
			empty($violation->violation_identity_v2)
			|| empty($violation->element_identity_v2)
			|| empty($violation->identity_signature_version)
		) {
			return new \WP_Error(
				'exception_identity_unavailable',
				'This finding does not have a stable occurrence identity yet. Rescan it before creating an occurrence exception.',
				['status' => 409]
			);
		}

		$count = Exception_Rule_Repository::count_identity_observations(
			$violation->scan_item_id,
			$violation->violation_identity_v2,
			$violation->identity_signature_version
		);
		if (1 !== $count) {
			return new \WP_Error(
				'exception_identity_ambiguous',
				'This identity matches more than one element in the scan. Use an explicit page or rule exception instead.',
				['status' => 409, 'matching_occurrences' => $count]
			);
		}

		return true;
	}

	/**
	 * Check guardrails for exception rule creation.
	 *
	 * @param array $params Rule parameters.
	 * @return string|null Warning message or null.
	 */
	private function check_guardrails(array $params): ?string {
		$warnings = [];

		// Check for site-wide ignores
		if (isset($params['scope']['scope_type']) && $params['scope']['scope_type'] === 'site') {
			$warnings[] = 'This exception will apply across the entire site. Make sure this is intentional.';
		}

		// Check for permanent ignores
		if (isset($params['duration']['duration_type']) && $params['duration']['duration_type'] === 'permanent') {
			$warnings[] = 'This is a permanent exception. Consider using a temporary exception if the issue might be fixed later.';
		}

		// Check for critical issues using the server-owned source rule when available.
		$guardrail_rule_ids = $params['rule_ids'] ?? [];
		if (! empty($params['violation_id'])) {
			$violation = Issue_Repository::get_by_id((int) $params['violation_id']);
			if ($violation) {
				$guardrail_rule_ids = [$violation->rule_id];
			}
		}
		if (! empty($guardrail_rule_ids)) {
			global $wpdb;
			$issues_table = \ClearA11y\Database\Schema::get_table_name('issues');

			$placeholders = implode(',', array_fill(0, count($guardrail_rule_ids), '%s'));
			$has_critical = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM `{$issues_table}` WHERE rule_id IN ({$placeholders}) AND severity = 'critical' LIMIT 1",
					...$guardrail_rule_ids
				)
			);

			if ($has_critical) {
				$warnings[] = 'This exception may remove critical accessibility issues from active remediation counts. Marking an exception does not fix the underlying problem.';
			}
		}

		return !empty($warnings) ? implode("\n\n", $warnings) : null;
	}

	/**
	 * Calculate expiration date from duration config.
	 *
	 * @param array $duration Duration configuration.
	 * @return string|null Expiration date or null.
	 */
	private function calculate_expiration(array $duration): ?string {
		$duration_type = $duration['duration_type'] ?? '';

		switch ($duration_type) {
			case 'until_next_scan':
				return null;

			case 'until_date':
				return isset($duration['expires_at']) ? $duration['expires_at'] : null;

			case 'permanent':
				return null;

			case 'until_content_changes':
				// This is tracked separately, set to far future
				return date('Y-m-d H:i:s', time() + YEAR_IN_SECONDS);

			default:
				return null;
		}
	}

	/**
	 * Apply exception rule to existing violations.
	 *
	 * @param Exception_Rule $rule Exception rule to apply.
	 * @return void
	 */
	private function apply_exception_to_existing(Exception_Rule $rule): void {
		// Get all active violations for the site
		global $wpdb;
		$issues_table = \ClearA11y\Database\Schema::get_table_name('issues');
		$scan_items_table = \ClearA11y\Database\Schema::get_table_name('scan_items');

		// Build query based on rule criteria
		$where = ['1=1'];
		$where_params = [];

		// Filter by scope
		if ($rule->scope['scope_type'] === 'page') {
			$url = $rule->scope['url'] ?? '';
			$where[] = 'si.post_url = %s';
			$where_params[] = $url;
		}

		// Filter by target
		if ($rule->target_type === 'rule') {
			$rule_ids = $rule->rule_ids ?? [];
			if (!empty($rule_ids)) {
				$placeholders = implode(',', array_fill(0, count($rule_ids), '%s'));
				$where[] = "i.rule_id IN ({$placeholders})";
				$where_params = array_merge($where_params, $rule_ids);
			}
		}

		$where_clause = implode(' AND ', $where);

		// Get matching violations
		// @phpstan-ignore-next-line
		$violations = $wpdb->get_results(
			$wpdb->prepare(
			"SELECT i.* FROM `{$issues_table}` i
				INNER JOIN `{$scan_items_table}` si ON i.scan_item_id = si.id
				WHERE {$where_clause}",
				...$where_params
			)
		);

		// Create matches
		foreach ($violations as $violation_row) {
			$violation = \ClearA11y\Models\Issue::from_row($violation_row);
			$match = Exception_Matcher_Service::matches_rule($violation, $rule);
			if (! $match) {
				continue;
			}

			Exception_Rule_Repository::create_match(
				(int) $violation->id,
				$rule->id,
				$rule->site_id,
				$match['confidence'],
				$match['action'] ?? 'suppressed'
			);
		}
	}
}
