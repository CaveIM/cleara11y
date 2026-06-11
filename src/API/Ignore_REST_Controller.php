<?php
/**
 * Ignore REST API Controller
 *
 * Handles REST API endpoints for ignore rule operations.
 *
 * @package ClearA11y
 * @namespace ClearA11y\API
 */

namespace ClearA11y\API;

use ClearA11y\Database\Ignore_Rule_Repository;
use ClearA11y\Database\Ignore_Schema;
use ClearA11y\Database\Issue_Repository;
use ClearA11y\Models\Ignore_Rule;
use ClearA11y\Services\Ignore_Matcher_Service;
use ClearA11y\Services\Fingerprint_Service;

/**
 * Ignore REST Controller Class
 */
class Ignore_REST_Controller {

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
			if (!Ignore_Schema::tables_exist()) {
				Ignore_Schema::create_tables();
			}
		}, 5);

		// Get ignore rules list
		register_rest_route(
			self::NAMESPACE,
			'/ignores',
			[
				[
					'methods' => 'GET',
					'callback' => [$this, 'get_ignore_rules'],
					'permission_callback' => [$this, 'can_manage_ignores'],
					'args' => [
						'status' => [
							'type' => 'string',
							'enum' => ['active', 'disabled', 'expired', 'all'],
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
					'callback' => [$this, 'create_ignore_rule'],
					'permission_callback' => [$this, 'can_manage_ignores'],
				],
			]
		);

		// Get single ignore rule
		register_rest_route(
			self::NAMESPACE,
			'/ignores/(?P<id>[a-zA-Z0-9-]+)',
			[
				[
					'methods' => 'GET',
					'callback' => [$this, 'get_ignore_rule'],
					'permission_callback' => [$this, 'can_manage_ignores'],
				],
				[
					'methods' => 'PUT',
					'callback' => [$this, 'update_ignore_rule'],
					'permission_callback' => [$this, 'can_manage_ignores'],
				],
				[
					'methods' => 'DELETE',
					'callback' => [$this, 'delete_ignore_rule'],
					'permission_callback' => [$this, 'can_manage_ignores'],
				],
			]
		);

		// Quick ignore endpoint
		register_rest_route(
			self::NAMESPACE,
			'/ignores/quick',
			[
				'methods' => 'POST',
				'callback' => [$this, 'quick_ignore'],
				'permission_callback' => [$this, 'can_manage_ignores'],
				'args' => [
					'violation_id' => [
						'required' => true,
						'type' => 'integer',
						'description' => 'Issue ID to mark as an exception.',
					],
				],
			]
		);

		// Undo quick ignore
		register_rest_route(
			self::NAMESPACE,
			'/ignores/(?P<id>[a-zA-Z0-9-]+)/undo',
			[
				'methods' => 'POST',
				'callback' => [$this, 'undo_quick_ignore'],
				'permission_callback' => [$this, 'can_manage_ignores'],
			]
		);

		// Enable/disable ignore rule
		register_rest_route(
			self::NAMESPACE,
			'/ignores/(?P<id>[a-zA-Z0-9-]+)/(?P<action>enable|disable)',
			[
				'methods' => 'POST',
				'callback' => [$this, 'toggle_ignore_rule'],
				'permission_callback' => [$this, 'can_manage_ignores'],
			]
		);

		// Get audit log
		register_rest_route(
			self::NAMESPACE,
			'/ignores/(?P<id>[a-zA-Z0-9-]+)/audit',
			[
				'methods' => 'GET',
				'callback' => [$this, 'get_audit_log'],
				'permission_callback' => [$this, 'can_manage_ignores'],
			]
		);

		// Get site-wide audit log
		register_rest_route(
			self::NAMESPACE,
			'/ignores/audit/all',
			[
				'methods' => 'GET',
				'callback' => [$this, 'get_all_audit_log'],
				'permission_callback' => [$this, 'can_manage_ignores'],
			]
		);

		// Calculate impact preview
		register_rest_route(
			self::NAMESPACE,
			'/ignores/preview',
			[
				'methods' => 'POST',
				'callback' => [$this, 'preview_impact'],
				'permission_callback' => [$this, 'can_manage_ignores'],
			]
		);

		// Get ignored violations for a scan item
		register_rest_route(
			self::NAMESPACE,
			'/scan-items/(?P<id>\d+)/ignored',
			[
				'methods' => 'GET',
				'callback' => [$this, 'get_ignored_violations'],
				'permission_callback' => [$this, 'can_manage_ignores'],
			]
		);

		// Check if violation is ignored
		register_rest_route(
			self::NAMESPACE,
			'/violations/(?P<id>\d+)/ignore-status',
			[
				'methods' => 'GET',
				'callback' => [$this, 'get_violation_ignore_status'],
				'permission_callback' => [$this, 'can_manage_ignores'],
			]
		);
	}

	/**
	 * Get ignore rules list.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_ignore_rules(\WP_REST_Request $request): \WP_REST_Response {
		$site_id = get_current_blog_id();
		$status = $request->get_param('status');
		$system_generated = $request->get_param('system_generated');
		$page = (int) $request->get_param('page') ?? 1;
		$per_page = (int) $request->get_param('per_page') ?? 20;
		$offset = ($page - 1) * $per_page;

		// Mark expired rules
		Ignore_Rule_Repository::mark_expired($site_id);

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

		$rules = Ignore_Rule_Repository::get_by_site_id($site_id, $args);

		// Get counts for tabs
		$counts = [
			'active' => Ignore_Rule_Repository::get_count_by_status($site_id, 'active'),
			'disabled' => Ignore_Rule_Repository::get_count_by_status($site_id, 'disabled'),
			'expired' => Ignore_Rule_Repository::get_count_by_status($site_id, 'expired'),
			'all' => Ignore_Rule_Repository::get_count_by_status($site_id, 'active')
				+ Ignore_Rule_Repository::get_count_by_status($site_id, 'disabled')
				+ Ignore_Rule_Repository::get_count_by_status($site_id, 'expired'),
		];

		return rest_ensure_response([
			'data' => array_map(fn($rule) => $rule->to_array(), $rules),
			'counts' => $counts,
			'total' => $counts['all'],
			'page' => $page,
			'per_page' => $per_page,
			'total_pages' => ceil($counts['all'] / $per_page),
		]);
	}

	/**
	 * Get single ignore rule.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_ignore_rule(\WP_REST_Request $request) {
		$rule_id = $request->get_param('id');
		$rule = Ignore_Rule_Repository::get_by_id($rule_id);

		if (!$rule) {
			return new \WP_Error('rule_not_found', 'Reviewed exception not found.', ['status' => 404]);
		}

		$rule_array = $rule->to_array();
		$rule_array['audit_log'] = array_map(
			fn($log) => $log->to_array(),
			Ignore_Rule_Repository::get_audit_log($rule_id)
		);

		return rest_ensure_response($rule_array);
	}

	/**
	 * Create ignore rule.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_ignore_rule(\WP_REST_Request $request) {
		$params = $request->get_json_params();

		// Validate required fields
		if (empty($params['target_type']) || empty($params['scope']) || empty($params['duration'])) {
			return new \WP_Error('missing_params', 'Missing required parameters.', ['status' => 400]);
		}

		// Check for guardrails
		$warning = $this->check_guardrails($params);
		if (is_wp_error($warning)) {
			return $warning;
		}

		// Generate UUID
		$rule_id = self::generate_uuid_v4();

		$rule = new Ignore_Rule();
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

		// Set expiration if needed
		if (isset($params['duration']['duration_type'])) {
			$rule->expires_at = $this->calculate_expiration($params['duration']);
		}

		$insert_id = Ignore_Rule_Repository::insert($rule);

		if (!$insert_id) {
			return new \WP_Error('insert_failed', 'Failed to create exception.', ['status' => 500]);
		}

		// Apply to existing violations
		$this->apply_rule_to_existing($rule);

		return rest_ensure_response([
			'id' => $rule_id,
			'message' => 'Reviewed exception created successfully.',
			'warning' => $warning,
			'rule' => $rule->to_array(),
		]);
	}

	/**
	 * Quick ignore - create temporary ignore for a violation.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function quick_ignore(\WP_REST_Request $request) {
		$violation_id = (int) $request->get_param('violation_id');
		$user_id = get_current_user_id();

		// Get the violation
		$violation = Issue_Repository::get_by_id($violation_id);

		if (!$violation) {
			return new \WP_Error('violation_not_found', 'Issue not found.', ['status' => 404]);
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

		// Check for existing quick ignore
		$existing = Ignore_Rule_Repository::find_existing_quick_ignore(
			$site_id,
			$violation->rule_id,
			$url,
			$selector
		);

		if ($existing) {
			// Refresh expiration
			$existing->expires_at = date('Y-m-d H:i:s', time() + DAY_IN_SECONDS);
			Ignore_Rule_Repository::update($existing);

			return rest_ensure_response([
				'message' => 'Temporary exception refreshed.',
				'rule' => $existing->to_array(),
			]);
		}

		// Generate element match data
		$fingerprints = Fingerprint_Service::generate_from_issue($violation);

		$rule_id = self::generate_uuid_v4();

		$rule = new Ignore_Rule();
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
		$rule->scope = [
			'scope_type' => 'page',
			'url' => $url,
		];
		$rule->duration = [
			'duration_type' => 'until_next_scan',
		];
		$rule->reason_category = null; // Quick ignores don't require reasons
		$rule->note = null;
		$rule->system_generated = true;
		$rule->created_by = $user_id;
		$rule->created_at = current_time('mysql');
		$rule->expires_at = date('Y-m-d H:i:s', time() + DAY_IN_SECONDS);

		$insert_id = Ignore_Rule_Repository::insert($rule);

		if (!$insert_id) {
			return new \WP_Error('insert_failed', 'Failed to create temporary exception.', ['status' => 500]);
		}

		// Create violation match
		Ignore_Rule_Repository::create_match($violation_id, $rule_id, $site_id, 'high');

		return rest_ensure_response([
			'id' => $rule_id,
			'message' => 'Issue marked as a temporary exception until next scan.',
			'rule' => $rule->to_array(),
		]);
	}

	/**
	 * Undo quick ignore.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function undo_quick_ignore(\WP_REST_Request $request) {
		$rule_id = $request->get_param('id');

		$rule = Ignore_Rule_Repository::get_by_id($rule_id);

		if (!$rule) {
			return new \WP_Error('rule_not_found', 'Reviewed exception not found.', ['status' => 404]);
		}

		// Only allow undoing system-generated rules
		if (!$rule->system_generated) {
			return new \WP_Error('not_quick_ignore', 'This is not a temporary exception.', ['status' => 400]);
		}

		// Delete the rule
		$result = Ignore_Rule_Repository::delete($rule_id);

		if ($result) {
			return rest_ensure_response([
				'message' => 'Temporary exception removed.',
			]);
		}

		return new \WP_Error('delete_failed', 'Failed to remove temporary exception.', ['status' => 500]);
	}

	/**
	 * Update ignore rule.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_ignore_rule(\WP_REST_Request $request) {
		$rule_id = $request->get_param('id');
		$params = $request->get_json_params();

		$rule = Ignore_Rule_Repository::get_by_id($rule_id);

		if (!$rule) {
			return new \WP_Error('rule_not_found', 'Reviewed exception not found.', ['status' => 404]);
		}

		// Update fields
		if (isset($params['rule_ids'])) {
			$rule->rule_ids = $params['rule_ids'];
		}
		if (isset($params['element_match'])) {
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

		$result = Ignore_Rule_Repository::update($rule);

		if ($result) {
			return rest_ensure_response([
				'message' => 'Reviewed exception updated successfully.',
				'rule' => $rule->to_array(),
			]);
		}

		return new \WP_Error('update_failed', 'Failed to update exception.', ['status' => 500]);
	}

	/**
	 * Delete ignore rule.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_ignore_rule(\WP_REST_Request $request) {
		$rule_id = $request->get_param('id');

		$rule = Ignore_Rule_Repository::get_by_id($rule_id);

		if (!$rule) {
			return new \WP_Error('rule_not_found', 'Reviewed exception not found.', ['status' => 404]);
		}

		$result = Ignore_Rule_Repository::delete($rule_id);

		if ($result) {
			return rest_ensure_response([
				'message' => 'Reviewed exception deleted successfully.',
			]);
		}

		return new \WP_Error('delete_failed', 'Failed to delete exception.', ['status' => 500]);
	}

	/**
	 * Toggle ignore rule (enable/disable).
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function toggle_ignore_rule(\WP_REST_Request $request) {
		$rule_id = $request->get_param('id');
		$action = $request->get_param('action');

		$rule = Ignore_Rule_Repository::get_by_id($rule_id);

		if (!$rule) {
			return new \WP_Error('rule_not_found', 'Reviewed exception not found.', ['status' => 404]);
		}

		if ($action === 'enable') {
			$result = Ignore_Rule_Repository::enable($rule_id);
			$message = 'Reviewed exception enabled.';
		} else {
			$result = Ignore_Rule_Repository::disable($rule_id);
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

		$audit_log = Ignore_Rule_Repository::get_audit_log($rule_id);

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

		$audit_log = Ignore_Rule_Repository::get_all_audit_log($site_id);

		return rest_ensure_response([
			'data' => array_map(fn($log) => $log->to_array(), $audit_log),
		]);
	}

	/**
	 * Calculate impact preview for an ignore rule.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function preview_impact(\WP_REST_Request $request): \WP_REST_Response {
		$params = $request->get_json_params();

		// Create temporary rule for preview
		$rule = new Ignore_Rule();
		$rule->target_type = $params['target_type'] ?? '';
		$rule->rule_ids = $params['rule_ids'] ?? [];
		$rule->element_match = $params['element_match'] ?? [];
		$rule->scope = $params['scope'] ?? [];

		$site_id = get_current_blog_id();

		$impact = Ignore_Matcher_Service::calculate_impact($rule, $site_id);

		return rest_ensure_response($impact);
	}

	/**
	 * Get ignored violations for a scan item.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_ignored_violations(\WP_REST_Request $request): \WP_REST_Response {
		$scan_item_id = (int) $request->get_param('id');
		$site_id = get_current_blog_id();

		global $wpdb;
		$issues_table = \ClearA11y\Database\Schema::get_table_name('issues');
		$matches_table = Ignore_Schema::get_table_name('violation_ignore_matches');
		$rules_table = Ignore_Schema::get_table_name('ignore_rules');
		$scan_items_table = \ClearA11y\Database\Schema::get_table_name('scan_items');

		// Get violations that have ignore matches
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT i.*, ir.id as ignore_rule_id, ir.reason_category, ir.note, ir.created_by, ir.created_at as ignored_at
				FROM `{$issues_table}` i
				INNER JOIN `{$matches_table}` vm ON i.id = vm.violation_id
				INNER JOIN `{$rules_table}` ir ON vm.ignore_rule_id = ir.id
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
				'matching_ignore' => [
					'rule_id' => $row->ignore_rule_id,
					'reason_category' => $row->reason_category,
					'note' => $row->note,
					'created_by' => $row->created_by,
					'created_by_name' => $row->created_by ? get_userdata($row->created_by)->display_name : null,
					'ignored_at' => $row->ignored_at,
				],
			];
		}

		return rest_ensure_response([
			'data' => $violations,
			'total' => count($violations),
		]);
	}

	/**
	 * Check if a violation is ignored and return matching rules.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_violation_ignore_status(\WP_REST_Request $request): \WP_REST_Response {
		$violation_id = (int) $request->get_param('id');
		$site_id = get_current_blog_id();

		$violation = Issue_Repository::get_by_id($violation_id);

		if (!$violation) {
			return new \WP_Error('violation_not_found', 'Issue not found.', ['status' => 404]);
		}

		$matches = Ignore_Matcher_Service::find_matches($violation, $site_id);

		return rest_ensure_response([
			'is_ignored' => !empty($matches),
			'matching_rules' => array_map(function($match) {
				return [
					'rule_id' => $match['rule']->id,
					'rule_label' => $match['rule']->get_label(),
					'confidence' => $match['confidence'],
					'matched_by' => $match['matched_by'],
				];
			}, $matches),
		]);
	}

	/**
	 * Permission callback: Can manage ignores.
	 *
	 * @return bool True if user can manage ignores.
	 */
	public function can_manage_ignores(): bool {
		/**
		 * Filter whether the current user can manage ignore rules.
		 *
		 * @since 1.6.0
		 *
		 * @param bool $can_manage True if user can manage ignores.
		 */
		return apply_filters('cleara11y_manage_ignores_permission', current_user_can('manage_options'));
	}

	/**
	 * Check guardrails for ignore rule creation.
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

		// Check for critical issues
		if (!empty($params['rule_ids'])) {
			global $wpdb;
			$issues_table = \ClearA11y\Database\Schema::get_table_name('issues');

			$placeholders = implode(',', array_fill(0, count($params['rule_ids']), '%s'));
			$has_critical = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM `{$issues_table}` WHERE rule_id IN ({$placeholders}) AND severity = 'critical' LIMIT 1",
					...$params['rule_ids']
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
				return date('Y-m-d H:i:s', time() + DAY_IN_SECONDS);

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
	 * Apply ignore rule to existing violations.
	 *
	 * @param Ignore_Rule $rule Ignore rule to apply.
	 * @return void
	 */
	private function apply_rule_to_existing(Ignore_Rule $rule): void {
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
				"SELECT i.id FROM `{$issues_table}` i
				INNER JOIN `{$scan_items_table}` si ON i.scan_item_id = si.id
				WHERE {$where_clause}",
				...$where_params
			)
		);

		// Create matches
		foreach ($violations as $violation) {
			Ignore_Rule_Repository::create_match(
				(int) $violation->id,
				$rule->id,
				$rule->site_id,
				'high'
			);
		}
	}
}
