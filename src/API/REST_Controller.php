<?php
/**
 * REST API Controller
 *
 * Handles REST API endpoints for scan operations.
 *
 * @package ClearA11y
 * @namespace ClearA11y\API
 */

namespace ClearA11y\API;

use ClearA11y\Database\Issue_Repository;
use ClearA11y\Database\Job_Repository;
use ClearA11y\Database\Scan_Item_Repository;
use ClearA11y\Database\Scan_Repository;
use ClearA11y\Services\Scan_Results_Processor;
use ClearA11y\Services\Scan_Token_Manager;

/**
 * REST Controller Class
 */
class REST_Controller {

	/**
	 * API namespace.
	 *
	 * @var string
	 */
	private const NAMESPACE = 'cleara11y/v1';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action('rest_api_init', [$this, 'register_routes']);

		// Add error handling for REST API
		add_filter('rest_authentication_errors', [$this, 'handle_rest_errors'], 999);
	}

	/**
	 * Handle REST API errors gracefully.
	 */
	public function handle_rest_errors($result) {
		if (is_wp_error($result)) {
			// Log the actual error for debugging
			error_log('ClearA11y REST Error: ' . $result->get_error_message());
		}
		return $result;
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<id>\d+)/scan-token',
			[
				'methods' => 'POST',
				'callback' => [$this, 'generate_scan_token'],
				'permission_callback' => [$this, 'edit_post_permission'],
				'args' => [
					'id' => [
						'required' => true,
						'type' => 'integer',
						'description' => 'Post ID.',
					],
					'scan_type' => [
						'type' => 'string',
						'default' => 'individual',
						'enum' => ['individual', 'full'],
						'description' => 'Type of scan.',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/scan/results',
			[
				'methods' => 'POST',
				'callback' => [$this, 'submit_scan_results'],
				'permission_callback' => '__return_true',
				'args' => [
					'token' => [
						'required' => true,
						'type' => 'string',
						'description' => 'Scan token.',
					],
					'results' => [
						'required' => true,
						'type' => 'object',
						'description' => 'Axe-core scan results.',
					],
					'evidence' => [
						'required' => false,
						'type' => 'array',
						'description' => 'Evidence data extracted from violations.',
						'items' => ['type' => 'object'],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/scans',
			[
				[
					'methods' => 'GET',
					'callback' => [$this, 'get_scans'],
					'permission_callback' => [$this, 'manage_options_permission'],
					'args' => [
						'status' => [
							'type' => 'string',
							'description' => 'Filter by status.',
						],
						'scan_type' => [
							'type' => 'string',
							'description' => 'Filter by scan type.',
						],
						'per_page' => [
							'type' => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 100,
						],
						'page' => [
							'type' => 'integer',
							'default' => 1,
							'minimum' => 1,
						],
					],
				],
				[
					'methods' => 'POST',
					'callback' => [$this, 'create_scan'],
					'permission_callback' => [$this, 'manage_options_permission'],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/scans/(?P<id>\d+)',
			[
				'methods' => 'GET',
				'callback' => [$this, 'get_scan'],
				'permission_callback' => [$this, 'manage_options_permission'],
				'args' => [
					'id' => [
						'required' => true,
						'type' => 'integer',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/scans/(?P<id>\d+)/issues',
			[
				'methods' => 'GET',
				'callback' => [$this, 'get_scan_issues'],
				'permission_callback' => [$this, 'manage_options_permission'],
				'args' => [
					'id' => [
						'required' => true,
						'type' => 'integer',
					],
					'severity' => [
						'type' => 'string',
						'enum' => ['critical', 'moderate', 'minor'],
						'description' => 'Filter by severity.',
					],
					'dismissed' => [
						'type' => 'boolean',
						'description' => 'Filter by dismissed status.',
					],
				],
			]
		);

		// Get scan stats (job counts for a specific scan)
		register_rest_route(
			self::NAMESPACE,
			'/scans/(?P<id>\d+)/stats',
			[
				'methods' => 'GET',
				'callback' => [$this, 'get_scan_stats'],
				'permission_callback' => [$this, 'manage_options_permission'],
				'args' => [
					'id' => [
						'required' => true,
						'type' => 'integer',
						'description' => 'Scan ID',
					],
				],
			]
		);

		// Get active scan (for global scanner auto-resume)
		register_rest_route(
			self::NAMESPACE,
			'/scan/active',
			[
				'methods' => 'GET',
				'callback' => [$this, 'get_active_scan'],
				'permission_callback' => '__return_true', // Allow from wp-admin
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<id>\d+)/issues',
			[
				'methods' => 'GET',
				'callback' => [$this, 'get_post_issues'],
				'permission_callback' => [$this, 'edit_post_permission'],
				'args' => [
					'id' => [
						'required' => true,
						'type' => 'integer',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/scan-items/(?P<id>\d+)/issues',
			[
				'methods' => 'GET',
				'callback' => [$this, 'get_scan_item_issues'],
				'permission_callback' => [$this, 'manage_options_permission'],
				'args' => [
					'id' => [
						'required' => true,
						'type' => 'integer',
						'description' => 'Scan Item ID',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/scan-items/(?P<id>\d+)',
			[
				'methods' => 'GET',
				'callback' => [$this, 'get_scan_item'],
				'permission_callback' => [$this, 'manage_options_permission'],
				'args' => [
					'id' => [
						'required' => true,
						'type' => 'integer',
						'description' => 'Scan Item ID',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/scan-items/(?P<id>\d+)/fail',
			[
				'methods' => 'POST',
				'callback' => [$this, 'fail_scan_item'],
				'permission_callback' => [$this, 'manage_options_permission'],
				'args' => [
					'id' => [
						'required' => true,
						'type' => 'integer',
						'description' => 'Scan Item ID',
					],
					'error_message' => [
						'type' => 'string',
						'description' => 'Error message',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/issues/(?P<id>\d+)/dismiss',
			[
				'methods' => 'POST',
				'callback' => [$this, 'dismiss_issue'],
				'permission_callback' => [$this, 'can_dismiss_issues'],
				'args' => [
					'id' => [
						'required' => true,
						'type' => 'integer',
					],
					'comment' => [
						'type' => 'string',
						'description' => 'Dismissible comment.',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/issues/(?P<id>\d+)/undismiss',
			[
				'methods' => 'POST',
				'callback' => [$this, 'undismiss_issue'],
				'permission_callback' => [$this, 'can_dismiss_issues'],
				'args' => [
					'id' => [
						'required' => true,
						'type' => 'integer',
					],
				],
			]
		);

		// Bulk dismiss endpoint
		register_rest_route(
			self::NAMESPACE,
			'/issues/bulk-dismiss',
			[
				'methods' => 'POST',
				'callback' => [$this, 'bulk_dismiss_issues'],
				'permission_callback' => [$this, 'can_dismiss_issues'],
				'args' => [
					'issue_ids' => [
						'required' => true,
						'type' => 'array',
						'items' => ['type' => 'integer'],
						'description' => 'Array of issue IDs to dismiss.',
					],
					'comment' => [
						'type' => 'string',
						'description' => 'Dismissible comment.',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/stats/overview',
			[
				'methods' => 'GET',
				'callback' => [$this, 'get_stats_overview'],
				'permission_callback' => [$this, 'manage_options_permission'],
			]
		);

		// Queue routes
		register_rest_route(
			self::NAMESPACE,
			'/queue/add',
			[
				'methods' => 'POST',
				'callback' => [$this, 'add_to_queue'],
				'permission_callback' => [$this, 'manage_options_permission'],
				'args' => [
					'post_ids' => [
						'required' => true,
						'type' => 'array',
						'description' => 'Array of post IDs to scan.',
						'items' => ['type' => 'integer'],
					],
					'scan_name' => [
						'type' => 'string',
						'description' => 'Name for this scan batch.',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/queue/create-jobs',
			[
				'methods' => 'POST',
				'callback' => [$this, 'create_queue_jobs'],
				'permission_callback' => [$this, 'manage_options_permission'],
				'args' => [
					'post_ids' => [
						'required' => true,
						'type' => 'array',
						'description' => 'Array of post IDs to create jobs for.',
						'items' => ['type' => 'integer'],
					],
					'scan_id' => [
						'required' => true,
						'type' => 'integer',
						'description' => 'Scan ID to associate jobs with.',
					],
					'priority' => [
						'type' => 'integer',
						'default' => 10,
						'description' => 'Job priority (higher = earlier).',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/queue/status',
			[
				'methods' => 'GET',
				'callback' => [$this, 'get_queue_status'],
				'permission_callback' => [$this, 'manage_options_permission'],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/queue/next',
			[
				'methods' => 'POST',
				'callback' => [$this, 'get_next_queue_item'],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/queue/(?P<scan_id>\d+)/cancel',
			[
				'methods' => 'POST',
				'callback' => [$this, 'cancel_queue_scan'],
				'permission_callback' => [$this, 'manage_options_permission'],
				'args' => [
					'scan_id' => [
						'required' => true,
						'type' => 'integer',
					],
				],
			]
		);

		// Issues routes
		register_rest_route(
			self::NAMESPACE,
			'/issues/list',
			[
				'methods' => 'GET',
				'callback' => [$this, 'get_issues_list'],
				'permission_callback' => [$this, 'manage_options_permission'],
				'args' => [
					'severity' => [
						'type' => 'string',
						'enum' => ['critical', 'moderate', 'minor'],
						'description' => 'Filter by severity.',
					],
					'dismissed' => [
						'type' => 'string',
						'enum' => ['active', 'dismissed', 'all'],
						'default' => 'active',
						'description' => 'Filter by dismissed status.',
					],
					'search' => [
						'type' => 'string',
						'description' => 'Search term for rule_id, post_title, or post_url.',
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
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/issues/stats',
			[
				'methods' => 'GET',
				'callback' => [$this, 'get_issues_stats'],
				'permission_callback' => [$this, 'manage_options_permission'],
			]
		);

		// Issue Types routes
		register_rest_route(
			self::NAMESPACE,
			'/issue-types',
			[
				'methods' => 'GET',
				'callback' => [$this, 'get_issue_types'],
				'permission_callback' => [$this, 'manage_options_permission'],
				'args' => [
					'severity' => [
						'type' => 'string',
						'enum' => ['critical', 'moderate', 'minor'],
						'description' => 'Filter by severity.',
					],
					'status' => [
						'type' => 'string',
						'enum' => ['active', 'dismissed-global', 'all'],
						'default' => 'active',
						'description' => 'Filter by global ignore status.',
					],
					'search' => [
						'type' => 'string',
						'description' => 'Search term for rule_id or message.',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/issue-types/(?P<rule_id>[a-zA-Z0-9_-]+)/pages',
			[
				'methods' => 'GET',
				'callback' => [$this, 'get_issue_type_pages'],
				'permission_callback' => [$this, 'manage_options_permission'],
				'args' => [
					'rule_id' => [
						'required' => true,
						'type' => 'string',
						'description' => 'The rule ID to get pages for.',
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
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/issue-types/(?P<rule_id>[a-zA-Z0-9_-]+)/ignore-global',
			[
				'methods' => 'POST',
				'callback' => [$this, 'set_global_ignore'],
				'permission_callback' => [$this, 'manage_options_permission'],
				'args' => [
					'rule_id' => [
						'required' => true,
						'type' => 'string',
						'description' => 'The rule ID to globally ignore.',
					],
					'ignored' => [
						'required' => true,
						'type' => 'boolean',
						'description' => 'Whether to ignore or un-ignore.',
					],
					'comment' => [
						'type' => 'string',
						'description' => 'Optional comment explaining the ignore.',
					],
				],
			]
		);

		// Job routes for parallel scanning with leasing
		register_rest_route(
			self::NAMESPACE,
			'/jobs/lease',
			[
				'methods' => 'POST',
				'callback' => [$this, 'lease_jobs'],
				'permission_callback' => '__return_true', // Allow for wp-admin orchestrator
				'args' => [
					'workerId' => [
						'type' => 'string',
						'description' => 'Unique identifier for the worker instance.',
					],
					'limit' => [
						'type' => 'integer',
						'default' => 2,
						'minimum' => 1,
						'maximum' => 10,
						'description' => 'Maximum number of jobs to lease.',
					],
					'leaseSeconds' => [
						'type' => 'integer',
						'default' => 180,
						'minimum' => 15,
						'maximum' => 600,
						'description' => 'Lease duration in seconds.',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/heartbeat',
			[
				'methods' => 'POST',
				'callback' => [$this, 'job_heartbeat'],
				'permission_callback' => '__return_true', // Allow for wp-admin orchestrator
				'args' => [
					'jobId' => [
						'required' => true,
						'type' => 'integer',
						'description' => 'Job ID to renew lease for.',
					],
					'leaseToken' => [
						'required' => true,
						'type' => 'string',
						'description' => 'Lease token for authentication.',
					],
					'leaseSeconds' => [
						'type' => 'integer',
						'default' => 180,
						'minimum' => 15,
						'maximum' => 600,
						'description' => 'Lease extension duration in seconds.',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/complete',
			[
				'methods' => 'POST',
				'callback' => [$this, 'complete_job'],
				'permission_callback' => '__return_true', // Allow for wp-admin orchestrator
				'args' => [
					'jobId' => [
						'required' => true,
						'type' => 'integer',
						'description' => 'Job ID to complete.',
					],
					'leaseToken' => [
						'required' => true,
						'type' => 'string',
						'description' => 'Lease token for authentication.',
					],
					'status' => [
						'required' => true,
						'type' => 'string',
						'enum' => ['done', 'failed'],
						'description' => 'Job completion status.',
					],
					'resultJson' => [
						'type' => 'string',
						'description' => 'JSON-encoded scan results (for done status).',
					],
					'error' => [
						'type' => 'string',
						'description' => 'Error message (for failed status).',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/stats',
			[
				'methods' => 'GET',
				'callback' => [$this, 'get_job_stats'],
				'permission_callback' => [$this, 'manage_options_permission'],
				'args' => [
					'scan_id' => [
						'type' => 'integer',
						'description' => 'Filter stats by scan ID.',
						'required' => false,
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/expire',
			[
				'methods' => 'POST',
				'callback' => [$this, 'expire_jobs'],
				'permission_callback' => [$this, 'manage_options_permission'],
				'args' => [
					'force' => [
						'type' => 'boolean',
						'default' => false,
						'description' => 'If true, expire ALL active jobs regardless of lease time.',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/cleanup',
			[
				'methods' => 'POST',
				'callback' => [$this, 'cleanup_jobs'],
				'permission_callback' => [$this, 'manage_options_permission'],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/stats/overview',
			[
				'methods' => 'GET',
				'callback' => [$this, 'get_overview_stats'],
				'permission_callback' => [$this, 'manage_options_permission'],
			]
		);

			// Pages routes
			register_rest_route(
				self::NAMESPACE,
				'/pages/list',
				[
					'methods' => 'GET',
					'callback' => [$this, 'get_pages_list'],
					'permission_callback' => [$this, 'manage_options_permission'],
					'args' => [
						'post_type' => [
							'type' => 'string',
							'default' => 'page',
							'description' => 'Filter by post type.',
						],
						'status' => [
							'type' => 'string',
							'enum' => ['scanned', 'unscanned', 'all'],
							'default' => 'all',
							'description' => 'Filter by scan status.',
						],
						'severity' => [
							'type' => 'string',
							'enum' => ['critical', 'moderate', 'minor'],
							'description' => 'Filter by minimum severity.',
						],
						'search' => [
							'type' => 'string',
							'description' => 'Search term for post title or URL.',
						],
						'orderby' => [
							'type' => 'string',
							'enum' => ['title', 'issues', 'score', 'scanned_date'],
							'default' => 'scanned_date',
							'description' => 'Sort order.',
						],
						'order' => [
							'type' => 'string',
							'enum' => ['asc', 'desc'],
							'default' => 'desc',
							'description' => 'Sort direction.',
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
				]
			);
	}

	/**
	 * Generate scan token for a post.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function generate_scan_token(\WP_REST_Request $request) {
		// Check if tables exist, if not try to create them
		if (!\ClearA11y\Database\Schema::tables_exist()) {
			\ClearA11y\Database\Schema::create_tables();
		}

		$post_id = (int) $request->get_param('id');
		$scan_type = $request->get_param('scan_type') ?? 'individual';

		// Validate post exists
		$post = get_post($post_id);
		if (!$post || $post->post_status !== 'publish') {
			return new \WP_Error('invalid_post', 'Post not found or not published.', ['status' => 404]);
		}

		try {
			$result = Scan_Token_Manager::generate_token($post_id, $scan_type);

			if (isset($result['error'])) {
				return new \WP_Error('scan_error', $result['error'], ['status' => 400]);
			}

			return rest_ensure_response($result);
		} catch (\Exception $e) {
			return new \WP_Error('scan_exception', $e->getMessage(), ['status' => 500]);
		}
	}

	/**
	 * Submit scan results from client-side scanner.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function submit_scan_results(\WP_REST_Request $request): \WP_REST_Response {
		$token = $request->get_param('token');
		$results = $request->get_param('results');
		$evidence = $request->get_param('evidence');

		// Debug: Log received evidence data
		error_log('ClearA11y REST: Received evidence type: ' . gettype($evidence));
		error_log('ClearA11y REST: Received evidence count: ' . (is_array($evidence) ? count($evidence) : 'N/A'));
		error_log('ClearA11y REST: Received evidence data: ' . wp_json_encode($evidence));
		error_log('ClearA11y REST: Raw body: ' . $request->get_body());

		// Validate token
		$token_data = Scan_Token_Manager::validate_token($token);

		if (!$token_data) {
			return rest_ensure_response(
				new \WP_Error('invalid_token', 'Invalid or expired scan token.', ['status' => 403])
			);
		}

		// Ensure evidence is an array
		if (!is_array($evidence)) {
			error_log('ClearA11y REST: Evidence is not an array, converting to empty array');
			$evidence = [];
		}

		// Process results with evidence
		$result = Scan_Results_Processor::process_results(
			$token_data['scan_item_id'],
			$results,
			$evidence
		);

		return rest_ensure_response($result);
	}

	/**
	 * Get scans list.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_scans(\WP_REST_Request $request): \WP_REST_Response {
		$status = $request->get_param('status');
		$scan_type = $request->get_param('scan_type');
		$per_page = $request->get_param('per_page') ?? 20;
		$page = $request->get_param('page') ?? 1;
		$offset = ($page - 1) * $per_page;

		$args = [
			'status' => $status,
			'scan_type' => $scan_type,
			'limit' => $per_page,
			'offset' => $offset,
		];

		$scans = Scan_Repository::get_all($args);
		$total = Scan_Repository::get_count($status ?? null);

		return rest_ensure_response([
			'data' => $scans,
			'total' => $total,
			'pages' => ceil($total / $per_page),
			'current_page' => $page,
		]);
	}

	/**
	 * Create a new scan.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function create_scan(\WP_REST_Request $request): \WP_REST_Response {
		// Check if there's already an active scan
		if (\ClearA11y\Services\Scan_Orchestrator::has_active_scan()) {
			return rest_ensure_response(
				new \WP_Error('scan_already_active',
					'Another scan is already in progress. Please wait for it to complete before starting a new scan.',
					['status' => 409]
				)
			);
		}

		$params = $request->get_json_params();

		$scan = new \ClearA11y\Models\Scan();
		$scan->scan_type = $params['scan_type'] ?? 'full';
		$scan->scan_name = $params['scan_name'] ?? null;
		$scan->status = 'pending';
		$scan->total_items = 0;
		$scan->created_at = current_time('mysql');

		$scan_id = Scan_Repository::insert($scan);

		if (!$scan_id) {
			return rest_ensure_response(
				new \WP_Error('scan_error', 'Failed to create scan.', ['status' => 500])
			);
		}

		return rest_ensure_response([
			'id' => $scan_id,
			'message' => 'Scan created successfully.',
		]);
	}

	/**
	 * Get single scan details.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_scan(\WP_REST_Request $request): \WP_REST_Response {
		$scan_id = (int) $request->get_param('id');
		$scan = Scan_Repository::get_by_id($scan_id);

		if (!$scan) {
			return rest_ensure_response(
				new \WP_Error('scan_not_found', 'Scan not found.', ['status' => 404])
			);
		}

		// Get scan items
		$scan_items = Scan_Item_Repository::get_by_scan_id($scan_id);

		return rest_ensure_response([
			'scan' => $scan,
			'items' => $scan_items,
		]);
	}

	/**
	 * Get issues for a scan.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_scan_issues(\WP_REST_Request $request): \WP_REST_Response {
		$scan_id = (int) $request->get_param('id');
		$severity = $request->get_param('severity');
		$dismissed = $request->get_param('dismissed');

		$args = [
			'severity' => $severity,
			'dismissed' => $dismissed,
		];

		$issues = Issue_Repository::get_by_scan_id($scan_id, $args);

		return rest_ensure_response([
			'data' => $issues,
			'total' => count($issues),
		]);
	}

	/**
	 * Get issues for a post.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_post_issues(\WP_REST_Request $request): \WP_REST_Response {
		$post_id = (int) $request->get_param('id');
		$issues = Issue_Repository::get_by_post_id($post_id);
		$counts = Issue_Repository::get_post_issue_counts($post_id);

		return rest_ensure_response([
			'counts' => $counts,
			'issues' => $issues,
		]);
	}

	/**
	 * Get issues for a specific scan item.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_scan_item_issues(\WP_REST_Request $request): \WP_REST_Response {
		$scan_item_id = (int) $request->get_param('id');

		// Get scan item
		$scan_item = Scan_Item_Repository::get_by_id($scan_item_id);

		if (!$scan_item) {
			return rest_ensure_response(
				new \WP_Error('scan_item_not_found', 'Scan item not found.', ['status' => 404])
			);
		}

		// Get issues for this scan item
		$issues = Issue_Repository::get_by_scan_item_id($scan_item_id);

		return rest_ensure_response([
			'scan_item' => $scan_item,
			'issues' => $issues,
		]);
	}

	/**
	 * Get a single scan item.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_scan_item(\WP_REST_Request $request): \WP_REST_Response {
		$scan_item_id = (int) $request->get_param('id');

		// Get scan item
		$scan_item = Scan_Item_Repository::get_by_id($scan_item_id);

		if (!$scan_item) {
			return rest_ensure_response(
				new \WP_Error('scan_item_not_found', 'Scan item not found.', ['status' => 404])
			);
		}

		return rest_ensure_response([
			'scan_item' => $scan_item,
		]);
	}

	/**
	 * Mark a scan item as failed.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function fail_scan_item(\WP_REST_Request $request): \WP_REST_Response {
		$scan_item_id = (int) $request->get_param('id');
		$error_message = $request->get_param('error_message') ?? 'Scan timed out or failed to complete';

		// Get scan item
		$scan_item = Scan_Item_Repository::get_by_id($scan_item_id);

		if (!$scan_item) {
			return rest_ensure_response(
				new \WP_Error('scan_item_not_found', 'Scan item not found.', ['status' => 404])
			);
		}

		// Only fail if it's currently in_progress
		if ($scan_item->status !== 'in_progress') {
			return rest_ensure_response([
				'success' => false,
				'message' => 'Scan item is not in progress.',
				'current_status' => $scan_item->status,
			]);
		}

		// Update status to failed
		$scan_item->status = 'failed';
		$scan_item->error_message = $error_message;
		Scan_Item_Repository::update($scan_item);

		// Check if parent scan should also be marked as failed
		$scan = Scan_Repository::get_by_id($scan_item->scan_id);
		if ($scan && $scan->status === 'in_progress') {
			// Check if there are any other pending or in_progress items
			$items = Scan_Item_Repository::get_by_scan_id($scan_item->scan_id);
			$active_items = array_filter($items, fn($i) => in_array($i->status, ['pending', 'in_progress'], true));

			if (empty($active_items)) {
				// All items are done - mark scan as failed
				$scan->status = 'failed';
				Scan_Repository::update($scan);
			}
		}

		return rest_ensure_response([
			'success' => true,
			'message' => 'Scan item marked as failed.',
		]);
	}

	/**
	 * Dismiss an issue.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function dismiss_issue(\WP_REST_Request $request): \WP_REST_Response {
		$issue_id = (int) $request->get_param('id');
		$comment = $request->get_param('comment') ?? '';
		$user_id = get_current_user_id();

		$result = Issue_Repository::dismiss($issue_id, $user_id, $comment);

		if ($result) {
			$updated_issue = Issue_Repository::get_by_id($issue_id);
			return rest_ensure_response([
				'message' => 'Issue dismissed successfully.',
				'issue' => $updated_issue ? $updated_issue->to_array() : null,
			]);
		}

		return rest_ensure_response(
			new \WP_Error('dismiss_failed', 'Failed to dismiss issue.', ['status' => 500])
		);
	}

	/**
	 * Undismiss an issue.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function undismiss_issue(\WP_REST_Request $request): \WP_REST_Response {
		$issue_id = (int) $request->get_param('id');

		$result = Issue_Repository::undismiss($issue_id);

		if ($result) {
			$updated_issue = Issue_Repository::get_by_id($issue_id);
			return rest_ensure_response([
				'message' => 'Issue undismissed successfully.',
				'issue' => $updated_issue ? $updated_issue->to_array() : null,
			]);
		}

		return rest_ensure_response(
			new \WP_Error('undismiss_failed', 'Failed to undismiss issue.', ['status' => 500])
		);
	}

	/**
	 * Bulk dismiss multiple issues.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function bulk_dismiss_issues(\WP_REST_Request $request): \WP_REST_Response {
		$issue_ids = $request->get_param('issue_ids');
		$comment = $request->get_param('comment') ?? '';
		$user_id = get_current_user_id();

		if (empty($issue_ids) || !is_array($issue_ids)) {
			return rest_ensure_response(
				new \WP_Error('invalid_params', 'Issue IDs array is required.', ['status' => 400])
			);
		}

		$dismissed_count = 0;
		$failed_count = 0;

		foreach ($issue_ids as $issue_id) {
			$result = Issue_Repository::dismiss((int) $issue_id, $user_id, $comment);
			if ($result) {
				$dismissed_count++;
			} else {
				$failed_count++;
			}
		}

		return rest_ensure_response([
			'message' => sprintf(
				'Dismissed %d issue(s).',
				$dismissed_count
			),
			'dismissed_count' => $dismissed_count,
			'failed_count' => $failed_count,
		]);
	}

	/**
	 * Get overview statistics.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_stats_overview(\WP_REST_Request $request): \WP_REST_Response {
		global $wpdb;

		$latest_scan = Scan_Repository::get_latest_completed();
		$issues_table = \ClearA11y\Database\Schema::get_table_name('issues');
		$scan_items_table = \ClearA11y\Database\Schema::get_table_name('scan_items');

		// Get scan counts (status only)
		$stats = [
			'total_scans' => Scan_Repository::get_count(),
			'completed_scans' => Scan_Repository::get_count('completed'),
			'pending_scans' => Scan_Repository::get_count('pending'),
			'in_progress_scans' => Scan_Repository::get_count('in_progress'),
		];

		if ($latest_scan) {
			// Calculate actual issue counts from the issues table
			// This accounts for dismissals that happened after the scan
			$issue_counts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT severity, COUNT(*) as count
					 FROM `{$issues_table}`
					 WHERE scan_id = %d AND dismissed = 0 AND dismissed_global = 0
					 GROUP BY severity",
					$latest_scan->id
				),
				ARRAY_A
			);

			$counts = [
				'total' => 0,
				'critical' => 0,
				'moderate' => 0,
				'minor' => 0,
			];

			foreach ($issue_counts as $row) {
				$counts[$row['severity']] = (int) $row['count'];
				$counts['total'] += (int) $row['count'];
			}

			// Get the count of unique pages scanned
			$scanned_pages = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT post_id)
					 FROM `{$scan_items_table}`
					 WHERE scan_id = %d AND status = 'completed'",
					$latest_scan->id
				)
			);

			$stats['latest_scan'] = [
				'id' => $latest_scan->id,
				'scan_type' => $latest_scan->scan_type,
				'scanned_pages' => (int) $scanned_pages,
				'total_issues' => $counts['total'],
				'critical_issues' => $counts['critical'],
				'moderate_issues' => $counts['moderate'],
				'minor_issues' => $counts['minor'],
				'completed_at' => $latest_scan->completed_at,
			];
		}

		return rest_ensure_response($stats);
	}

	/**
	 * Permission callback: Can edit post.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return bool
	 */
	public function edit_post_permission(\WP_REST_Request $request): bool {
		$post_id = (int) $request->get_param('id');
		return current_user_can('edit_post', $post_id);
	}

	/**
	 * Permission callback: Can manage options.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return bool
	 */
	public function manage_options_permission(\WP_REST_Request $request): bool {
		return current_user_can('manage_options');
	}

	/**
	 * Check if current user can dismiss issues.
	 *
	 * @return bool True if user can dismiss issues.
	 */
	public function can_dismiss_issues(): bool {
		/**
		 * Filter whether the current user can dismiss issues.
		 *
		 * @since 1.5.0
		 *
		 * @param bool $can_dismiss True if user can dismiss issues.
		 */
		return apply_filters('cleara11y_dismiss_permission', current_user_can('manage_options'));
	}

	/**
	 * Add posts to scan queue.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function add_to_queue(\WP_REST_Request $request): \WP_REST_Response {
		$post_ids = $request->get_param('post_ids');
		$scan_name = $request->get_param('scan_name') ?? null;

		if (empty($post_ids) || !is_array($post_ids)) {
			return rest_ensure_response(
				new \WP_Error('invalid_posts', 'Invalid post IDs.', ['status' => 400])
			);
		}

		// Check if tables exist
		if (!\ClearA11y\Database\Schema::tables_exist()) {
			\ClearA11y\Database\Schema::create_tables();
		}

		// Create a new scan
		$scan = new \ClearA11y\Models\Scan();
		$scan->scan_type = 'full';
		$scan->scan_name = $scan_name ?? 'Batch Scan - ' . count($post_ids) . ' pages';
		$scan->status = 'pending';
		$scan->total_items = count($post_ids);
		$scan->scanned_items = 0;
		$scan->created_at = current_time('mysql');

		$scan_id = Scan_Repository::insert($scan);

		if (!$scan_id) {
			return rest_ensure_response(
				new \WP_Error('scan_error', 'Failed to create scan.', ['status' => 500])
			);
		}

		// Create scan items for all posts
		foreach ($post_ids as $post_id) {
			$post = get_post($post_id);

			if (!$post || $post->post_status !== 'publish') {
				continue;
			}

			$scan_item = new \ClearA11y\Models\Scan_Item();
			$scan_item->scan_id = $scan_id;
			$scan_item->post_id = $post_id;
			$scan_item->post_type = $post->post_type;
			$scan_item->post_title = $post->post_title;
			$scan_item->post_url = get_permalink($post_id);
			$scan_item->status = 'pending';
			$scan_item->scan_method = 'client';
			$scan_item->created_at = current_time('mysql');

			Scan_Item_Repository::insert($scan_item);
		}

		return rest_ensure_response([
			'scan_id' => $scan_id,
			'total_items' => count($post_ids),
			'message' => 'Scan queue created successfully.',
		]);
	}

	/**
	 * Create jobs in scan_jobs table for parallel processing.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function create_queue_jobs(\WP_REST_Request $request): \WP_REST_Response {
		global $wpdb;

		$post_ids = $request->get_param('post_ids');
		$scan_id = (int) $request->get_param('scan_id');
		$priority = (int) $request->get_param('priority') ?? 10;

		error_log(sprintf('[ClearA11y] create_queue_jobs called: scan_id=%d, posts=%d',
			$scan_id, count($post_ids ?? [])));

		if (empty($post_ids) || !is_array($post_ids)) {
			return rest_ensure_response(
				new \WP_Error('invalid_posts', 'Invalid post IDs.', ['status' => 400])
			);
		}

		// Verify scan exists
		$scan = Scan_Repository::get_by_id($scan_id);
		if (!$scan) {
			error_log('[ClearA11y] create_queue_jobs: Scan not found: ' . $scan_id);
			return rest_ensure_response(
				new \WP_Error('invalid_scan', 'Scan not found.', ['status' => 404])
			);
		}

		error_log(sprintf('[ClearA11y] create_queue_jobs: Scan found: %s (status=%s)',
			$scan->scan_name, $scan->status));

		$jobs_table = \ClearA11y\Database\Schema::get_table_name('scan_jobs');
		$scans_table = \ClearA11y\Database\Schema::get_table_name('scans');
		$created = 0;

		foreach ($post_ids as $post_id) {
			$post = get_post($post_id);

			if (!$post || $post->post_status !== 'publish') {
				continue;
			}

			$url = get_permalink($post_id);

			// Check if job already exists for this post/scan
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM `{$jobs_table}` WHERE post_id = %d AND scan_id = %d",
					$post_id,
					$scan_id
				)
			);

			if ($existing) {
				continue; // Skip existing jobs
			}

			// Insert new job
			$result = $wpdb->insert(
				$jobs_table,
				[
					'site_id' => get_current_blog_id(),
					'url' => $url,
					'post_id' => $post_id,
					'scan_id' => $scan_id,
					'status' => 'pending',
					'priority' => $priority,
					'created_at' => current_time('mysql'),
				],
				['%d', '%s', '%d', '%d', '%s', '%d', '%s']
			);

			if ($result) {
				$created++;
			}
		}

		error_log(sprintf('[ClearA11y] create_queue_jobs: Created %d new jobs', $created));

		// Update scan status to 'in_progress' and set started_at
		if ($created > 0) {
			$updated = $wpdb->update(
				$scans_table,
				[
					'status' => 'in_progress',
					'started_at' => current_time('mysql'),
				],
				['id' => $scan_id],
				['%s', '%s'],
				['%d']
			);

			error_log(sprintf('[ClearA11y] create_queue_jobs: Updated scan %d status to in_progress (result=%d)',
				$scan_id, $updated));
		}

		return rest_ensure_response([
			'scan_id' => $scan_id,
			'jobs_created' => $created,
			'scan_status' => 'in_progress',
			'message' => sprintf('%d jobs created successfully.', $created),
		]);
	}

	/**
	 * Get scan queue status.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_queue_status(\WP_REST_Request $request): \WP_REST_Response {
		// Get active scans (pending or in_progress)
		$active_scans = Scan_Repository::get_all([
			'status' => null,
			'limit' => 10,
			'orderby' => 'created_at',
			'order' => 'DESC',
		]);

		$queue = [];
		foreach ($active_scans as $scan) {
			if (in_array($scan->status, ['pending', 'in_progress'], true)) {
				$items = Scan_Item_Repository::get_by_scan_id($scan->id);
				$pending = count(array_filter($items, fn($i) => $i->status === 'pending'));
				$completed = count(array_filter($items, fn($i) => $i->status === 'completed'));
				$failed = count(array_filter($items, fn($i) => $i->status === 'failed'));

				$queue[] = [
					'scan_id' => $scan->id,
					'scan_name' => $scan->scan_name,
					'status' => $scan->status,
					'total_items' => $scan->total_items,
					'scanned_items' => $scan->scanned_items,
					'pending' => $pending,
					'completed' => $completed,
					'failed' => $failed,
					'created_at' => $scan->created_at,
				];
			}
		}

		return rest_ensure_response([
			'queue' => $queue,
			'active_count' => count($queue),
		]);
	}

	/**
	 * Get next pending item from queue for background scanning.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_next_queue_item(\WP_REST_Request $request): \WP_REST_Response {
		global $wpdb;
		$table = \ClearA11y\Database\Schema::get_table_name('scan_items');

		// First, auto-reset any items stuck in "in_progress" for more than 5 minutes
		$stuck_timeout = 5; // minutes
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET status = 'pending', error_message = 'Auto-reset due to timeout'
				WHERE status = 'in_progress'
				AND created_at < DATE_SUB(NOW(), INTERVAL %d MINUTE)",
				$stuck_timeout
			)
		);

		// Find first pending scan item
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE status = 'pending' ORDER BY created_at ASC LIMIT 1",
			)
		);

		if (!$row) {
			return rest_ensure_response([
				'item' => null,
				'message' => 'No pending items in queue.',
			]);
		}

		// Mark as in_progress
		$wpdb->update(
			$table,
			['status' => 'in_progress'],
			['id' => $row->id],
			['%s'],
			['%d']
		);

		// Validate post exists and is published
		$post = \get_post($row->post_id);
		if (!$post || $post->post_status !== 'publish') {
			// Post doesn't exist or isn't published - mark as failed
			$wpdb->update(
				$table,
				[
					'status' => 'failed',
					'error_message' => $post ? 'Post is not published' : 'Post no longer exists'
				],
				['id' => $row->id],
				['%s', '%s'],
				['%d']
			);

			// Update scan status if this was the last item
			$scan_items_table = \ClearA11y\Database\Schema::get_table_name('scan_items');
			$pending_count = $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(*) FROM `{$scan_items_table}` WHERE scan_id = %d AND status = 'pending'",
				$row->scan_id
			));

			if ($pending_count == 0) {
				$scans_table = \ClearA11y\Database\Schema::get_table_name('scans');
				$wpdb->update(
					$scans_table,
					['status' => 'failed'],
					['id' => $row->scan_id],
					['%s'],
					['%d']
				);
			}

			return rest_ensure_response([
				'item' => null,
				'message' => 'Skipped invalid post: ' . ($post ? 'not published' : 'does not exist'),
				'skipped' => true,
			]);
		}

		// Generate a token for this item
		$token = \wp_generate_password(32, false);
		$expiry_seconds = (int) \get_option('cleara11y_scan_token_expiry', 300);
		$expires_at = date('Y-m-d H:i:s', time() + $expiry_seconds);

		// Store token data
		$token_data = [
			'scan_id' => $row->scan_id,
			'scan_item_id' => $row->id,
			'post_id' => $row->post_id,
			'created_at' => \current_time('mysql'),
			'expires_at' => $expires_at,
		];

		\update_option(
			'cleara11y_scan_token_' . $token,
			$token_data,
			false
		);

		// Get scan URL (add parameter to disable frontend highlighting during scan)
		$scan_url = \add_query_arg(
			['cleara11y_scanning' => '1'],
			\get_permalink($row->post_id)
		);

		return rest_ensure_response([
			'item' => [
				'id' => $row->id,
				'scan_id' => $row->scan_id,
				'post_id' => $row->post_id,
				'post_title' => $row->post_title,
				'post_url' => $row->post_url,
			],
			'token' => $token,
			'scan_url' => $scan_url,
			'expires_at' => $expires_at,
		]);
	}

	/**
	 * Cancel a queued scan.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function cancel_queue_scan(\WP_REST_Request $request): \WP_REST_Response {
		$scan_id = (int) $request->get_param('scan_id');

		$scan = Scan_Repository::get_by_id($scan_id);

		if (!$scan) {
			return rest_ensure_response(
				new \WP_Error('scan_not_found', 'Scan not found.', ['status' => 404])
			);
		}

		// Only allow cancelling pending or in_progress scans
		if (!in_array($scan->status, ['pending', 'in_progress'], true)) {
			return rest_ensure_response(
				new \WP_Error('invalid_status', 'Scan cannot be cancelled.', ['status' => 400])
			);
		}

		// Update scan status to cancelled
		Scan_Repository::update_status($scan_id, 'cancelled');

		// Also cancel all pending items
		global $wpdb;
		$table = \ClearA11y\Database\Schema::get_table_name('scan_items');
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET status = 'cancelled' WHERE scan_id = %d AND status = 'pending'",
				$scan_id
			)
		);

		return rest_ensure_response([
			'message' => 'Scan cancelled successfully.',
		]);
	}

	/**
	 * Get issues list with filters and pagination.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_issues_list(\WP_REST_Request $request): \WP_REST_Response {
		global $wpdb;

		$severity = $request->get_param('severity');
		$dismissed = $request->get_param('dismissed') ?? 'active';
		$search = $request->get_param('search');
		$page = (int) $request->get_param('page') ?? 1;
		$per_page = (int) $request->get_param('per_page') ?? 20;
		$offset = ($page - 1) * $per_page;

		$issues_table = \ClearA11y\Database\Schema::get_table_name('issues');
		$scan_items_table = \ClearA11y\Database\Schema::get_table_name('scan_items');
		$ignore_matches_table = \ClearA11y\Database\Ignore_Schema::get_table_name('violation_ignore_matches');
		$ignore_rules_table = \ClearA11y\Database\Ignore_Schema::get_table_name('ignore_rules');

		// Build WHERE clause
		$where = ['1=1'];
		$where_params = [];

		if (!empty($severity)) {
			$where[] = 'i.severity = %s';
			$where_params[] = $severity;
		}

		if ($dismissed === 'active') {
			$where[] = 'i.dismissed = %d';
			$where_params[] = 0;
		} elseif ($dismissed === 'dismissed') {
			$where[] = 'i.dismissed = %d';
			$where_params[] = 1;
		}

		if (!empty($search)) {
			$where[] = '(i.rule_id LIKE %s OR i.message LIKE %s OR si.post_title LIKE %s OR si.post_url LIKE %s)';
			$search_term = '%' . $wpdb->esc_like($search) . '%';
			$where_params[] = $search_term;
			$where_params[] = $search_term;
			$where_params[] = $search_term;
			$where_params[] = $search_term;
		}

		// Exclude issues that have active ignore matches
		$where[] = 'vim.id IS NULL';

		$where_clause = implode(' AND ', $where);

		// Get total count
		$count_query = "SELECT COUNT(DISTINCT i.id) FROM `{$issues_table}` i
					   INNER JOIN `{$scan_items_table}` si ON i.scan_item_id = si.id
					   LEFT JOIN `{$ignore_matches_table}` vim ON i.id = vim.violation_id
					   LEFT JOIN `{$ignore_rules_table}` ir ON vim.ignore_rule_id = ir.id AND ir.status = 'active'
						   AND (ir.expires_at IS NULL OR ir.expires_at > NOW())
					   WHERE {$where_clause}";
		// @phpstan-ignore-next-line
		$total = (int) $wpdb->get_var($wpdb->prepare($count_query, ...$where_params));

		// Get issues
		$query = "SELECT i.*, si.post_title, si.post_url
				 FROM `{$issues_table}` i
				 INNER JOIN `{$scan_items_table}` si ON i.scan_item_id = si.id
				 LEFT JOIN `{$ignore_matches_table}` vim ON i.id = vim.violation_id
				 LEFT JOIN `{$ignore_rules_table}` ir ON vim.ignore_rule_id = ir.id AND ir.status = 'active'
					 AND (ir.expires_at IS NULL OR ir.expires_at > NOW())
				 WHERE {$where_clause}
				 ORDER BY i.dismissed ASC, FIELD(i.severity, 'critical', 'moderate', 'minor'), i.id DESC
				 LIMIT %d OFFSET %d";

		$where_params[] = $per_page;
		$where_params[] = $offset;

		// @phpstan-ignore-next-line
		$issues = $wpdb->get_results($wpdb->prepare($query, ...$where_params));

		return rest_ensure_response([
			'data' => $issues,
			'total' => $total,
			'page' => $page,
			'per_page' => $per_page,
			'total_pages' => ceil($total / $per_page),
		]);
	}

	/**
	 * Get issues statistics.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_issues_stats(\WP_REST_Request $request): \WP_REST_Response {
		global $wpdb;

		$issues_table = \ClearA11y\Database\Schema::get_table_name('issues');
		$ignore_matches_table = \ClearA11y\Database\Ignore_Schema::get_table_name('violation_ignore_matches');
		$ignore_rules_table = \ClearA11y\Database\Ignore_Schema::get_table_name('ignore_rules');

		// Get counts by severity (active only, excluding ignored)
		$counts = $wpdb->get_results(
			"SELECT
				SUM(CASE WHEN i.severity = 'critical' AND i.dismissed = 0 AND i.dismissed_global = 0 AND vim.id IS NULL THEN 1 ELSE 0 END) as critical,
				SUM(CASE WHEN i.severity = 'moderate' AND i.dismissed = 0 AND i.dismissed_global = 0 AND vim.id IS NULL THEN 1 ELSE 0 END) as moderate,
				SUM(CASE WHEN i.severity = 'minor' AND i.dismissed = 0 AND i.dismissed_global = 0 AND vim.id IS NULL THEN 1 ELSE 0 END) as minor,
				SUM(CASE WHEN i.dismissed = 0 AND i.dismissed_global = 0 AND vim.id IS NULL THEN 1 ELSE 0 END) as active,
				SUM(CASE WHEN i.dismissed = 1 OR i.dismissed_global = 1 THEN 1 ELSE 0 END) as dismissed,
				COUNT(*) as total
			FROM `{$issues_table}` i
			LEFT JOIN `{$ignore_matches_table}` vim ON i.id = vim.violation_id
			LEFT JOIN `{$ignore_rules_table}` ir ON vim.ignore_rule_id = ir.id AND ir.status = 'active'
				AND (ir.expires_at IS NULL OR ir.expires_at > NOW())",
			ARRAY_A
		);

		$stats = $counts[0] ?? [
			'critical' => 0,
			'moderate' => 0,
			'minor' => 0,
			'active' => 0,
			'dismissed' => 0,
			'total' => 0,
		];

		// Convert to integers and restructure response
		$result = [
			'active' => [
				'critical' => (int) $stats['critical'],
				'moderate' => (int) $stats['moderate'],
				'minor' => (int) $stats['minor'],
				'total' => (int) $stats['active'],
			],
			'dismissed' => (int) $stats['dismissed'],
		];

		return rest_ensure_response($result);
	}

	/**
	 * Get pages list with accessibility scores and issues.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_pages_list(\WP_REST_Request $request): \WP_REST_Response {
		global $wpdb;

		$post_type = $request->get_param('post_type') ?? 'page';
		$status = $request->get_param('status') ?? 'all';
		$severity = $request->get_param('severity');
		$search = $request->get_param('search');
		$orderby = $request->get_param('orderby') ?? 'scanned_date';
		$order = $request->get_param('order') ?? 'desc';
		$page = (int) $request->get_param('page') ?? 1;
		$per_page = (int) $request->get_param('per_page') ?? 20;
		$offset = ($page - 1) * $per_page;

		$scan_items_table = \ClearA11y\Database\Schema::get_table_name('scan_items');
		$scans_table = \ClearA11y\Database\Schema::get_table_name('scans');
		$issues_table = \ClearA11y\Database\Schema::get_table_name('issues');
		$posts_table = $wpdb->posts;

		// Build WHERE clause
		$where = ["p.post_type = '{$post_type}'", "p.post_status = 'publish'"];
		$where_params = [];

		// Filter by scan status
		if ($status === 'scanned') {
			$where[] = 'si.status = "completed"';
		} elseif ($status === 'unscanned') {
			$where[] = 'si.id IS NULL';
		}

		// Search by title or URL
		if (!empty($search)) {
			$where[] = '(p.post_title LIKE %s OR si.post_url LIKE %s)';
			$search_term = '%' . $wpdb->esc_like($search) . '%';
			$where_params[] = $search_term;
			$where_params[] = $search_term;
		}

		$where_clause = implode(' AND ', $where);

		// Get total count
		$count_query = "SELECT COUNT(DISTINCT p.ID)
					   FROM `{$posts_table}` p
					   LEFT JOIN `{$scan_items_table}` si ON p.ID = si.post_id AND si.status = 'completed'
					   LEFT JOIN `{$scans_table}` s ON si.scan_id = s.id AND s.status = 'completed'
					   WHERE {$where_clause}";

		if (!empty($where_params)) {
			$total = (int) $wpdb->get_var($wpdb->prepare($count_query, ...$where_params));
		} else {
			$total = (int) $wpdb->get_var($count_query);
		}

		// Get pages with latest scan_item info
		$pages_query = "SELECT p.ID as post_id,
						p.post_title,
						p.post_type,
						si.id as scan_item_id,
						si.status as scan_status,
						si.post_url,
						si.scanned_at,
						si.error_message
					 FROM `{$posts_table}` p
					 LEFT JOIN `{$scan_items_table}` si ON p.ID = si.post_id AND si.status = 'completed'
					 LEFT JOIN `{$scans_table}` s ON si.scan_id = s.id AND s.status = 'completed'
					 WHERE {$where_clause}
					 ORDER BY p.post_title ASC
					 LIMIT %d OFFSET %d";

		$params = [...$where_params, $per_page, $offset];

		if (!empty($params)) {
			$pages = $wpdb->get_results($wpdb->prepare($pages_query, ...$params));
		} else {
			$pages = $wpdb->get_results($pages_query);
		}

		// Calculate score and format results
		$formatted_pages = [];
		foreach ($pages as $page) {
			// Get actual issue counts from issues table (accounts for dismissals)
			$counts = [
				'total' => 0,
				'critical' => 0,
				'moderate' => 0,
				'minor' => 0,
			];

			if ($page->scan_item_id && $page->scan_status === 'completed') {
				// Count issues from the latest scan_item, excluding dismissed ones
				error_log('ClearA11y Pages List: Querying issues for post_id: ' . $page->post_id . ', scan_item_id: ' . $page->scan_item_id);
				$issue_counts = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT severity, COUNT(*) as count
						 FROM `{$issues_table}`
						 WHERE scan_item_id = %d AND dismissed = 0 AND dismissed_global = 0
						 GROUP BY severity",
						$page->scan_item_id
					),
					ARRAY_A
				);

				foreach ($issue_counts as $row) {
					$counts[$row['severity']] = (int) $row['count'];
					$counts['total'] += (int) $row['count'];
			error_log(sprintf('ClearA11y Pages List: post_id %d, scan_item_id %d, counts: total=%d critical=%d moderate=%d minor=%d',
				$page->post_id, $page->scan_item_id, $counts['total'], $counts['critical'], $counts['moderate'], $counts['minor']));
				}
			}

			$score = 100;
			if ($counts['total'] > 0) {
				$score = max(0, 100 - ($counts['critical'] * 10) - ($counts['moderate'] * 5) - ($counts['minor'] * 2));
			}

			$formatted_pages[] = [
				'post_id' => (int) $page->post_id,
				'post_title' => $page->post_title,
				'post_type' => $page->post_type,
				'post_url' => $page->post_url ?: get_permalink($page->post_id),
				'scan_status' => $page->scan_status ?: 'unscanned',
				'score' => $score,
				'issues' => [
					'total' => $counts['total'],
					'critical' => $counts['critical'],
					'moderate' => $counts['moderate'],
					'minor' => $counts['minor'],
				],
				'scanned_at' => $page->scanned_at,
				'error_message' => $page->error_message,
			];
		}

		// Sort after we have all the data
		$formatted_pages = $this->sort_pages_list($formatted_pages, $orderby, $order);

		return rest_ensure_response([
			'data' => $formatted_pages,
			'total' => $total,
			'page' => $page,
			'per_page' => $per_page,
			'total_pages' => ceil($total / $per_page),
		]);
	}


	/**
	 * Sort pages list by specified criteria.
	 *
	 * @param array  $pages  Pages array to sort.
	 * @param string $orderby Field to order by.
	 * @param string $order   Sort direction (asc/desc).
	 * @return array Sorted pages.
	 */
	private function sort_pages_list(array $pages, string $orderby, string $order): array {
		usort($pages, function($a, $b) use ($orderby, $order) {
			$a_val = null;
			$b_val = null;

			switch ($orderby) {
				case 'title':
					$a_val = $a['post_title'] ?? '';
					$b_val = $b['post_title'] ?? '';
					break;
				case 'score':
					$a_val = $a['score'] ?? 100;
					$b_val = $b['score'] ?? 100;
					break;
				case 'issues':
					$a_val = $a['issues']['total'] ?? 0;
					$b_val = $b['issues']['total'] ?? 0;
					break;
				case 'scanned_date':
				default:
					$a_val = $a['scanned_at'] ?? '1970-01-01';
					$b_val = $b['scanned_at'] ?? '1970-01-01';
					break;
			}

			$direction = strtolower($order) === 'asc' ? 1 : -1;

			if ($a_val === $b_val) {
				// Secondary sort by title
				return strnatcasecmp($a['post_title'] ?? '', $b['post_title'] ?? '') * $direction;
			}

			if (is_string($a_val)) {
				return strcmp($a_val, $b_val) * $direction;
			}

			// Compare numbers without spaceship operator (PHP 5.x compatible)
			if ($a_val < $b_val) {
				return -1 * $direction;
			} elseif ($a_val > $b_val) {
				return 1 * $direction;
			}
			return 0;
		});

		return $pages;
	}

		/**
		 * Get issue types grouped by rule.
		 *
		 * @param \WP_REST_Request $request REST request object.
		 * @return \WP_REST_Response
		 */
	public function get_issue_types(\WP_REST_Request $request): \WP_REST_Response {
		global $wpdb;

		$severity = $request->get_param('severity');
		$status = $request->get_param('status') ?? 'active';
		$search = $request->get_param('search');

		$issues_table = \ClearA11y\Database\Schema::get_table_name('issues');

		// Build WHERE clause
		$where = ['1=1'];
		$where_params = [];

		// Filter by severity
		if (!empty($severity)) {
			$where[] = 'severity = %s';
			$where_params[] = $severity;
		}

		// Filter by status
		if ('dismissed-global' === $status) {
			$where[] = 'dismissed_global = 1';
		} elseif ('active' === $status) {
			$where[] = 'dismissed = 0 AND dismissed_global = 0';
		}
		// 'all' doesn't filter

		// Search
		if (!empty($search)) {
			$where[] = '(rule_id LIKE %s OR message LIKE %s)';
			$where_params[] = '%' . $wpdb->esc_like($search) . '%';
			$where_params[] = '%' . $wpdb->esc_like($search) . '%';
		}

		$where_clause = 'WHERE ' . implode(' AND ', $where);

		// Get issue types grouped by rule
		$query = $wpdb->prepare(
			"SELECT
				rule_id,
				rule_type,
				severity,
				message,
				help_url,
				COUNT(*) as issue_count,
				COUNT(DISTINCT post_id) as page_count,
				SUM(CASE WHEN dismissed_global = 1 THEN 1 ELSE 0 END) as globally_ignored,
				MAX(created_at) as last_found
			FROM `{$issues_table}`
			{$where_clause}
			GROUP BY rule_id, rule_type, severity, message
			ORDER BY FIELD(severity, 'critical', 'moderate', 'minor'), issue_count DESC",
			$where_params
		);

		$issue_types = $wpdb->get_results($query);

		// Build WHERE clause for counts (without status filter - counts should always be global)
		$count_where = ['1=1'];
		$count_params = [];

		// Apply severity filter to counts
		if (!empty($severity)) {
			$count_where[] = 'severity = %s';
			$count_params[] = $severity;
		}

		// Apply search filter to counts
		if (!empty($search)) {
			$count_where[] = '(rule_id LIKE %s OR message LIKE %s)';
			$count_params[] = '%' . $wpdb->esc_like($search) . '%';
			$count_params[] = '%' . $wpdb->esc_like($search) . '%';
		}

		$count_where_clause = 'WHERE ' . implode(' AND ', $count_where);

		// Get counts for status tabs (global counts, not filtered by current status tab)
		$count_query = $wpdb->prepare(
			"SELECT
				SUM(CASE WHEN dismissed = 0 AND dismissed_global = 0 THEN 1 ELSE 0 END) as active_issues,
				SUM(CASE WHEN dismissed_global = 1 THEN 1 ELSE 0 END) as globally_ignored_issues,
				COUNT(*) as total_issues
			FROM `{$issues_table}`
			{$count_where_clause}",
			$count_params
		);

		$counts = $wpdb->get_row($count_query, ARRAY_A);

		return rest_ensure_response([
			'issue_types' => $issue_types,
			'counts' => [
				'active' => (int) ($counts['active_issues'] ?? 0),
				'dismissed-global' => (int) ($counts['globally_ignored_issues'] ?? 0),
				'all' => (int) ($counts['total_issues'] ?? 0),
			],
		]);
	}

	/**
	 * Get pages that have a specific issue type.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_issue_type_pages(\WP_REST_Request $request): \WP_REST_Response {
		global $wpdb;

		$rule_id = $request->get_param('rule_id');
		$page = (int) $request->get_param('page') ?? 1;
		$per_page = (int) $request->get_param('per_page') ?? 20;
		$offset = ($page - 1) * $per_page;

		$issues_table = \ClearA11y\Database\Schema::get_table_name('issues');
		$scan_items_table = \ClearA11y\Database\Schema::get_table_name('scan_items');

		// Get total count
		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT i.post_id)
				FROM `{$issues_table}` i
				WHERE i.rule_id = %s",
				$rule_id
			)
		);

		// Get pages with this issue
		$pages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					DISTINCT i.post_id,
					si.post_title,
					si.post_url,
					COUNT(*) as issue_count,
					SUM(CASE WHEN i.dismissed = 0 AND i.dismissed_global = 0 THEN 1 ELSE 0 END) as active_count
				FROM `{$issues_table}` i
				INNER JOIN `{$scan_items_table}` si ON i.scan_item_id = si.id
				WHERE i.rule_id = %s
				GROUP BY i.post_id, si.post_title, si.post_url
				ORDER BY active_count DESC
				LIMIT %d OFFSET %d",
				$rule_id,
				$per_page,
				$offset
			)
		);

		return rest_ensure_response([
			'pages' => $pages,
			'total' => (int) $total,
			'page' => $page,
			'per_page' => $per_page,
			'total_pages' => ceil($total / $per_page),
		]);
	}

	/**
	 * Set global ignore status for a rule.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function set_global_ignore(\WP_REST_Request $request): \WP_REST_Response {
		global $wpdb;

		$rule_id = $request->get_param('rule_id');
		$ignored = (bool) $request->get_param('ignored');
		$comment = $request->get_param('comment') ?? '';

		$issues_table = \ClearA11y\Database\Schema::get_table_name('issues');
		$user_id = get_current_user_id();

		// Debug logging
		error_log('[ClearA11y Debug] Global ignore request - Rule ID: ' . $rule_id);
		error_log('[ClearA11y Debug] Global ignore request - Ignored: ' . ($ignored ? 'true' : 'false'));
		error_log('[ClearA11y Debug] Global ignore request - Comment: ' . $comment);

		if ($ignored) {
			// Set global ignore
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$issues_table}`
					SET dismissed_global = 1,
						dismissed_global_by = %d,
						dismissed_global_at = NOW(),
						dismissed_global_comment = %s
					WHERE rule_id = %s",
					$user_id,
					$comment,
					$rule_id
				)
			);

			$affected = $wpdb->rows_affected;
			error_log('[ClearA11y Debug] Global ignore - Affected rows: ' . $affected);

			return rest_ensure_response([
				'success' => true,
				'message' => sprintf('Globally ignored %d issues.', $affected),
				'affected' => $affected,
				'rule_id' => $rule_id,
			]);
		} else {
			// Remove global ignore
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$issues_table}`
					SET dismissed_global = 0,
						dismissed_global_by = NULL,
						dismissed_global_at = NULL,
						dismissed_global_comment = NULL
					WHERE rule_id = %s",
					$rule_id
				)
			);

			$affected = $wpdb->rows_affected;
			error_log('[ClearA11y Debug] Global unignore - Affected rows: ' . $affected);

			return rest_ensure_response([
				'success' => true,
				'message' => sprintf('Restored %d issues from global ignore.', $affected),
				'affected' => $affected,
				'rule_id' => $rule_id,
			]);
		}
	}

	/**
	 * Lease jobs for parallel processing.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function lease_jobs(\WP_REST_Request $request): \WP_REST_Response {
		$worker_id = $request->get_param('workerId') ?? wp_generate_uuid_v4();
		$limit = (int) ($request->get_param('limit') ?? 2);
		$lease_seconds = (int) ($request->get_param('leaseSeconds') ?? 180);
		$site_id = get_current_blog_id();

		error_log(sprintf('[ClearA11y] lease_jobs called: worker=%s, limit=%d, site=%d',
			$worker_id, $limit, $site_id));

		// Expire stuck jobs first
		$expired = Job_Repository::expire_stuck_jobs();
		if ($expired > 0) {
			error_log('[ClearA11y] lease_jobs: Expired ' . $expired . ' stuck jobs');
		}

		// Lease pending jobs
		$jobs = Job_Repository::lease_jobs($limit, $site_id, $worker_id, $lease_seconds);

		error_log(sprintf('[ClearA11y] lease_jobs: Leased %d jobs for worker %s',
			count($jobs), $worker_id));

		return rest_ensure_response([
			'workerId' => $worker_id,
			'jobs' => $jobs,
			'leased' => count($jobs),
		]);
	}

	/**
	 * Extend lease for a job (heartbeat).
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function job_heartbeat(\WP_REST_Request $request): \WP_REST_Response {
		$job_id = (int) $request->get_param('jobId');
		$lease_token = $request->get_param('leaseToken');
		$lease_seconds = (int) ($request->get_param('leaseSeconds') ?? 180);

		$result = Job_Repository::heartbeat($job_id, $lease_token, $lease_seconds);

		if ($result === false) {
			return rest_ensure_response(
				new \WP_Error('heartbeat_failed', 'Invalid job ID, lease token, or job not active.', ['status' => 403])
			);
		}

		return rest_ensure_response($result);
	}

	/**
	 * Complete a job (success or failure).
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function complete_job(\WP_REST_Request $request): \WP_REST_Response {
		global $wpdb;

		$job_id = (int) $request->get_param('jobId');
		$lease_token = $request->get_param('leaseToken');
		$status = $request->get_param('status'); // 'done' or 'failed'
		$result_json = $request->get_param('resultJson');
		$error = $request->get_param('error');

		error_log(sprintf('[ClearA11y] complete_job called - jobId: %d, status: %s, resultJson length: %d',
			$job_id, $status, strlen($result_json ?? '')));

		// Get job first for additional processing
		$job = Job_Repository::get_by_id($job_id);

		if (!$job || $job->lease_token !== $lease_token) {
			return rest_ensure_response(
				new \WP_Error('job_not_found', 'Invalid job ID or lease token.', ['status' => 404])
			);
		}

		// Complete the job using repository
		$completed = Job_Repository::complete($job_id, $lease_token, $status, $result_json, $error);

		if (!$completed) {
			return rest_ensure_response(
				new \WP_Error('complete_failed', 'Failed to complete job.', ['status' => 500])
			);
		}

		// Additional processing for successful jobs
		if ($status === 'done' && $result_json) {
			$results = json_decode($result_json, true);

			if ($results && isset($results['violations'])) {
				// Get scan_item for this job
				$scan_items_table = \ClearA11y\Database\Schema::get_table_name('scan_items');
				$scan_item = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM `{$scan_items_table}` WHERE post_id = %d AND scan_id = %d LIMIT 1",
						$job->post_id,
						$job->scan_id
					),
					ARRAY_A
				);

				if ($scan_item) {
					// Store results via Scan_Results_Processor
					$results_processor = new Scan_Results_Processor();
					$results_processor->process_results(
						$scan_item['id'],
						$results,
						$results['evidence'] ?? []
					);
				}
			}
		}

		// CRITICAL: Check if scan is complete and update status
		$this->check_and_complete_scan($job->scan_id);

		return rest_ensure_response([
			'ok' => true,
		]);
	}

	/**
	 * Check if all jobs for a scan are complete and update scan status.
	 *
	 * @param int $scan_id Scan ID.
	 * @return void
	 */
	private function check_and_complete_scan(int $scan_id): void {
		global $wpdb;

		$jobs_table = \ClearA11y\Database\Schema::get_table_name('scan_jobs');
		$scans_table = \ClearA11y\Database\Schema::get_table_name('scans');

		// Check if there are any pending or active jobs
		$pending_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$jobs_table}`
				WHERE scan_id = %d
				AND status IN ('pending', 'active')",
				$scan_id
			)
		);

		if ($pending_count == 0) {
			// All jobs are complete - update scan status
			$updated = $wpdb->update(
				$scans_table,
				[
					'status' => 'completed',
					'completed_at' => current_time('mysql'),
				],
				[
					'id' => $scan_id,
					'status' => 'in_progress',
				],
				['%s', '%s'],
				['%d', '%s']
			);

			if ($updated) {
				error_log(sprintf('[ClearA11y] Scan %d marked as completed', $scan_id));
			}
		}
	}

	/**
	 * Update parent scan progress after job completion.
	 *
	 * @param int $scan_id Scan ID.
	 * @return void
	 */
	private function update_scan_progress(int $scan_id): void {
		global $wpdb;

		$scans_table = \ClearA11y\Database\Schema::get_table_name('scans');
		$scan_items_table = \ClearA11y\Database\Schema::get_table_name('scan_items');

		// Get scan item counts
		$counts = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) as total,
					SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
					SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
					SUM(total_issues) as total_issues,
					SUM(critical_issues) as critical_issues,
					SUM(moderate_issues) as moderate_issues,
					SUM(minor_issues) as minor_issues
				FROM `{$scan_items_table}`
				WHERE scan_id = %d",
				$scan_id
			),
			ARRAY_A
		);

		if ($counts) {
			$total = (int) $counts['total'];
			$completed = (int) $counts['completed'];
			$failed = (int) $counts['failed'];
			$finished = $completed + $failed;

			// Update scan with progress
			$wpdb->update(
				$scans_table,
				[
					'scanned_items' => $finished,
					'total_issues' => (int) $counts['total_issues'],
					'critical_issues' => (int) $counts['critical_issues'],
					'moderate_issues' => (int) $counts['moderate_issues'],
					'minor_issues' => (int) $counts['minor_issues'],
					'status' => ($finished >= $total) ? 'completed' : 'in_progress',
					'completed_at' => ($finished >= $total) ? current_time('mysql') : null,
				],
				['id' => $scan_id],
				['%d', '%d', '%d', '%d', '%d', '%s', '%s'],
				['%d']
			);
		}
	}

	/**
	 * Get job queue statistics.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_job_stats(\WP_REST_Request $request): \WP_REST_Response {
		// Check if we should filter by current scan
		$scan_id = $request->get_param('scan_id');

		if ($scan_id) {
			$stats = Job_Repository::get_stats_by_scan((int) $scan_id);
		} else {
			$stats = Job_Repository::get_stats();
		}

		// Add total for backward compatibility
		$stats['total'] = $stats['pending'] + $stats['active'] + $stats['completed'] + $stats['failed'];

		return rest_ensure_response($stats);
	}

	/**
	 * Get scan stats (job counts for a specific scan).
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_scan_stats(\WP_REST_Request $request): \WP_REST_Response {
		$scan_id = (int) $request->get_param('id');

		if (!$scan_id) {
			return new \WP_Error('invalid_scan', 'Invalid scan ID', ['status' => 400]);
		}

		$stats = Job_Repository::get_stats_by_scan($scan_id);

		// Add total for convenience
		$stats['total'] = $stats['pending'] + $stats['active'] + $stats['completed'] + $stats['failed'];

		return rest_ensure_response($stats);
	}

	/**
	 * Get active scan (for global scanner auto-resume).
	 * Returns the most recent scan with 'in_progress' status.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_active_scan(\WP_REST_Request $request): \WP_REST_Response {
		global $wpdb;
		$scans_table = \ClearA11y\Database\Schema::get_table_name('scans');
		$jobs_table = \ClearA11y\Database\Schema::get_table_name('scan_jobs');

		// Log the request for debugging
		error_log('[ClearA11y] get_active_scan called');

		// Get the most recent scan with 'in_progress' status
		// This is more reliable - just check scan status, not job status
		$scan = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT s.* FROM `{$scans_table}` s
				WHERE s.status = 'in_progress'
				ORDER BY s.id DESC
				LIMIT 1"
			)
		);

		if (!$scan) {
			error_log('[ClearA11y] get_active_scan: No active scan found');
			return rest_ensure_response([
				'active' => false,
				'scan_id' => null,
			]);
		}

		// Get job statistics for this scan
		$stats = Job_Repository::get_stats_by_scan((int) $scan->id);

		error_log(sprintf('[ClearA11y] get_active_scan: Found scan %d (%s) - jobs: pending=%d, active=%d, done=%d',
			$scan->id,
			$scan->scan_name,
			$stats['pending'],
			$stats['active'],
			$stats['completed']
		));

		return rest_ensure_response([
			'active' => true,
			'scan_id' => (int) $scan->id,
			'scan_name' => $scan->scan_name,
			'status' => $scan->status,
			'total_items' => (int) $scan->total_items,
			'scanned_items' => (int) $scan->scanned_items,
			'stats' => $stats,
		]);
	}

	/**
	 * Expire stuck jobs (reset active jobs with expired leases to pending).
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function expire_jobs(\WP_REST_Request $request): \WP_REST_Response {
		$force = $request->get_param('force') === true;
		$expired = Job_Repository::expire_stuck_jobs($force);

		return rest_ensure_response([
			'ok' => true,
			'expired' => $expired,
			'forced' => $force,
		]);
	}

	/**
	 * Clean up completed jobs from completed scans.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function cleanup_jobs(\WP_REST_Request $request): \WP_REST_Response {
		$cleaned = Job_Repository::cleanup_completed_jobs();

		return rest_ensure_response([
			'ok' => true,
			'cleaned' => $cleaned,
		]);
	}

	/**
	 * Get overview statistics for the dashboard.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_overview_stats(\WP_REST_Request $request): \WP_REST_Response {
		$latest_scan = \ClearA11y\Database\Scan_Repository::get_latest_completed();

		$stats = [
			'total_critical' => 0,
			'total_moderate' => 0,
			'total_minor' => 0,
			'total_pages' => 0,
		];

		if ($latest_scan) {
			$stats['total_critical'] = $latest_scan->critical_issues;
			$stats['total_moderate'] = $latest_scan->moderate_issues;
			$stats['total_minor'] = $latest_scan->minor_issues;
			$stats['total_pages'] = $latest_scan->scanned_items;
		}

		return rest_ensure_response($stats);
	}
}
