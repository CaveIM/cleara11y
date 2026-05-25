<?php
/**
 * Admin Menu
 *
 * Handles admin menu registration.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Admin
 */

namespace ClearA11y\Admin;

/**
 * Admin Class
 */
class Admin {

	/**
	 * Single instance of the class.
	 *
	 * @var Admin|null
	 */
	private static ?Admin $instance = null;

	/**
	 * Get the single instance of the class.
	 *
	 * @return Admin
	 */
	public static function get_instance(): Admin {
		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action('admin_menu', [$this, 'register_menu']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
		add_action('admin_bar_menu', [$this, 'add_toolbar_indicator'], 999);

		// AJAX handlers for pages list (fallback if REST API fails)
		add_action('wp_ajax_cleara11y_get_posts', [$this, 'ajax_get_posts']);
		add_action('wp_ajax_cleara11y_get_post_issues', [$this, 'ajax_get_post_issues']);

		// AJAX handlers for global scanner state management
		add_action('wp_ajax_cleara11y_get_scan_state', [$this, 'ajax_get_scan_state']);
		add_action('wp_ajax_cleara11y_save_scan_result', [$this, 'ajax_save_scan_result']);
		add_action('wp_ajax_cleara11y_advance_scan', [$this, 'ajax_advance_scan']);
		add_action('wp_ajax_cleara11y_stop_scan', [$this, 'ajax_stop_scan']);
	}

	/**
	 * AJAX handler to get posts list (fallback).
	 */
	public function ajax_get_posts(): void {
		check_ajax_referer('cleara11y-nonce', 'nonce');

		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$post_type = isset($_POST['post_type']) ? sanitize_text_field(wp_unslash($_POST['post_type'])) : 'page';
		$page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
		$per_page = 20;

		$args = [
			'post_type' => $post_type,
			'post_status' => 'publish',
			'posts_per_page' => $per_page,
			'paged' => $page,
			'fields' => 'ids',
		];

		$query = new \WP_Query($args);
		$post_ids = $query->posts;

		$posts = [];
		foreach ($post_ids as $post_id) {
			$post = get_post($post_id);
			$posts[] = [
				'id' => $post_id,
				'title' => [
					'rendered' => $post->post_title,
				],
				'link' => get_permalink($post_id),
			];
		}

		wp_send_json_success([
			'posts' => $posts,
			'total_pages' => $query->max_num_pages,
		]);
	}

	/**
	 * AJAX handler to get post issues (fallback).
	 */
	public function ajax_get_post_issues(): void {
		check_ajax_referer('cleara11y-nonce', 'nonce');

		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

		if (!$post_id) {
			wp_send_json_error(['message' => 'Invalid post ID']);
		}

		$counts = \ClearA11y\Database\Issue_Repository::get_post_issue_counts($post_id);

		wp_send_json_success([
			'counts' => $counts,
		]);
	}

	/**
	 * AJAX handler to get current scan state (for global scanner).
	 */
	public function ajax_get_scan_state(): void {
		check_ajax_referer('cleara11y-nonce', 'nonce');

		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		global $wpdb;
		$scans_table = \ClearA11y\Database\Schema::get_table_name('scans');
		$jobs_table = \ClearA11y\Database\Schema::get_table_name('scan_jobs');

		// Get the most recent active or pending scan
		$scan = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$scans_table}`
				WHERE status IN ('pending', 'in_progress')
				ORDER BY id DESC
				LIMIT 1"
			)
		);

		if (!$scan) {
			wp_send_json_success([
				'active' => false,
				'scan_id' => null,
			]);
		}

		// Get job statistics
		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
					SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
					SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) as completed,
					SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
				FROM `{$jobs_table}`
				WHERE scan_id = %d",
				$scan->id
			),
			ARRAY_A
		);

		wp_send_json_success([
			'active' => true,
			'scan_id' => $scan->id,
			'scan_name' => $scan->scan_name,
			'status' => $scan->status,
			'stats' => [
				'pending' => (int) ($stats['pending'] ?? 0),
				'active' => (int) ($stats['active'] ?? 0),
				'completed' => (int) ($stats['completed'] ?? 0),
				'failed' => (int) ($stats['failed'] ?? 0),
			],
		]);
	}

	/**
	 * AJAX handler to save scan result (for global scanner).
	 */
	public function ajax_save_scan_result(): void {
		check_ajax_referer('cleara11y-nonce', 'nonce');

		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$job_id = isset($_POST['job_id']) ? intval($_POST['job_id']) : 0;
		$result_json = isset($_POST['result_json']) ? wp_unslash($_POST['result_json']) : '';
		$error = isset($_POST['error']) ? sanitize_text_field(wp_unslash($_POST['error'])) : '';

		if (!$job_id) {
			wp_send_json_error(['message' => 'Invalid job ID']);
		}

		$job = \ClearA11y\Database\Job_Repository::get_by_id($job_id);

		if (!$job) {
			wp_send_json_error(['message' => 'Job not found']);
		}

		// Complete the job
		if (empty($error)) {
			$success = \ClearA11y\Database\Job_Repository::complete(
				$job_id,
				$job->lease_token ?: '',
				'done',
				$result_json
			);

			// Process results into scan items and issues
			if ($success && !empty($result_json)) {
				$results = json_decode($result_json, true);
				if ($results) {
					\ClearA11y\Services\Scan_Results_Processor::process_results(
						$job->scan_id,
						$job->post_id,
						$results
					);
				}
			}
		} else {
			$success = \ClearA11y\Database\Job_Repository::complete(
				$job_id,
				$job->lease_token ?: '',
				'failed',
				null,
				$error
			);
		}

		if ($success) {
			wp_send_json_success(['ok' => true]);
		} else {
			wp_send_json_error(['message' => 'Failed to save result']);
		}
	}

	/**
	 * AJAX handler to advance scan (get next job).
	 */
	public function ajax_advance_scan(): void {
		check_ajax_referer('cleara11y-nonce', 'nonce');

		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$limit = isset($_POST['limit']) ? max(1, intval($_POST['limit'])) : 1;
		$worker_id = isset($_POST['worker_id']) ? sanitize_text_field(wp_unslash($_POST['worker_id'])) : '';

		$jobs = \ClearA11y\Database\Job_Repository::lease_jobs(
			$limit,
			get_current_blog_id(),
			$worker_id,
			180
		);

		wp_send_json_success([
			'jobs' => $jobs,
		]);
	}

	/**
	 * AJAX handler to stop scan.
	 */
	public function ajax_stop_scan(): void {
		check_ajax_referer('cleara11y-nonce', 'nonce');

		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$scan_id = isset($_POST['scan_id']) ? intval($_POST['scan_id']) : 0;

		if (!$scan_id) {
			wp_send_json_error(['message' => 'Invalid scan ID']);
		}

		// Reset active jobs to pending
		global $wpdb;
		$jobs_table = \ClearA11y\Database\Schema::get_table_name('scan_jobs');

		$updated = $wpdb->update(
			$jobs_table,
			[
				'status' => 'pending',
				'lease_token' => null,
				'lease_expires_at' => null,
			],
			[
				'scan_id' => $scan_id,
				'status' => 'active',
			],
			['%s', '%s', '%s'],
			['%d', '%s']
		);

		wp_send_json_success([
			'ok' => true,
			'reset_jobs' => $updated,
		]);
	}

	/**
	 * Enqueue global scanner script on all admin pages.
	 * This allows scanning to continue across page navigation.
	 */
	private function enqueue_global_scanner(): void {
		// Get the correct REST API base URL
		$rest_url = get_rest_url();

		// Localize scripts with shared data (do this first before enqueueing)
		$data = [
			'apiUrl' => $rest_url . 'cleara11y/v1/',
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('wp_rest'),
			'ajaxNonce' => wp_create_nonce('cleara11y-nonce'),
			'pluginUrl' => CLEARA11Y_PLUGIN_URL,
			'workerId' => sanitize_text_field($_COOKIE['cleara11y_worker_id'] ?? ''),
		];

		// Enqueue toolbar styles
		wp_enqueue_style(
			'cleara11y-toolbar',
			CLEARA11Y_PLUGIN_URL . 'assets/css/toolbar.css',
			[],
			CLEARA11Y_VERSION
		);

		// Enqueue global scanner FIRST (it initializes cleara11yData)
		$cache_buster = 'v4_' . time();
		wp_enqueue_script(
			'cleara11y-global-scanner',
			CLEARA11Y_PLUGIN_URL . 'assets/js/global-admin-scanner.js?' . $cache_buster . '=1',
			[],
			CLEARA11Y_VERSION,
			true
		);
		wp_localize_script('cleara11y-global-scanner', 'cleara11yData', $data);

		// Enqueue scan indicator SECOND (uses existing cleara11yData)
		wp_enqueue_script(
			'cleara11y-scan-indicator',
			CLEARA11Y_PLUGIN_URL . 'assets/js/scan-indicator.js',
			['cleara11y-global-scanner'], // Depend on global scanner
			CLEARA11Y_VERSION,
			true
		);
		// Don't re-localize - indicator uses the same cleara11yData
	}

	/**
	 * Add scan progress indicator to admin toolbar.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar WordPress admin bar instance.
	 */
	public function add_toolbar_indicator(\WP_Admin_Bar $wp_admin_bar): void {
		// Skip if we're currently scanning (admin bar is hidden)
		if (\ClearA11y\Frontend\Scanner::is_scanning()) {
			return;
		}

		// Only show for users with manage_options capability
		if (!current_user_can('manage_options')) {
			return;
		}

		// Get active scan
		global $wpdb;
		$scans_table = \ClearA11y\Database\Schema::get_table_name('scans');
		$jobs_table = \ClearA11y\Database\Schema::get_table_name('scan_jobs');

		$scan = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$scans_table}`
				WHERE status = 'in_progress'
				ORDER BY id DESC
				LIMIT 1"
			)
		);

		if (!$scan) {
			// No active scan, show a simple "ClearA11y" menu item
			$wp_admin_bar->add_node([
				'id'    => 'cleara11y-toolbar',
				'title' => '<span class="ab-icon" aria-hidden="true"></span><span class="ab-label">ClearA11y</span>',
				'href'  => admin_url('admin.php?page=cleara11y'),
				'meta'  => [
					'title' => __('Accessibility Scanner', 'cleara11y'),
				],
			]);
			return;
		}

		// Get job statistics for this scan
		$stats = \ClearA11y\Database\Job_Repository::get_stats_by_scan((int) $scan->id);
		$total = $stats['pending'] + $stats['active'] + $stats['completed'] + $stats['failed'];
		$completed = $stats['completed'];
		$progress = $total > 0 ? round(($completed / $total) * 100) : 0;

		// Build title with progress indicator
		$title = sprintf(
			'<span class="ab-icon" aria-hidden="true"></span>
			<span class="ab-label">ClearA11y</span>
				<span class="cleara11y-progress-text">%d/%d</span>
			</span>',
			esc_attr($scan->id),
			esc_attr($progress),
			esc_attr($progress),
			esc_html($completed),
			esc_html($total),
			esc_html($progress)
		);

		// Add toolbar menu with scan progress
		$wp_admin_bar->add_node([
			'id'    => 'cleara11y-toolbar',
			'title' => $title,
			'href'  => admin_url('admin.php?page=cleara11y'),
			'meta'  => [
				'title' => sprintf(__('Accessibility Scan: %d%% complete', 'cleara11y'), $progress),
				'class' => 'cleara11y-scanning',
			],
		]);
	}

	/**
	 * Register admin menu.
	 */
	public function register_menu(): void {
		add_menu_page(
			__('ClearA11y', 'cleara11y'),
			__('ClearA11y', 'cleara11y'),
			'manage_options',
			'cleara11y',
			[Dashboard_Page::class, 'render'],
			'dashicons-universal-access-alt',
			30
		);

		add_submenu_page(
			'cleara11y',
			__('Dashboard', 'cleara11y'),
			__('Dashboard', 'cleara11y'),
			'manage_options',
			'cleara11y',
			[Dashboard_Page::class, 'render']
		);

		// Add Issues List submenu page
		add_submenu_page(
			'cleara11y',
			__('Issues', 'cleara11y'),
			__('Issues', 'cleara11y'),
			'manage_options',
			'cleara11y-issues',
			[Issues_List_Page::class, 'render']
		);

		// Add Ignores submenu page
		add_submenu_page(
			'cleara11y',
			'Ignores',
			'Ignores',
			'manage_options',
			'cleara11y-ignores',
			[Ignores_Page::class, 'render']
		);

		// // Add Issue Types submenu page
		// add_submenu_page(
		// 	'cleara11y',
		// 	__('Issue Types', 'cleara11y'),
		// 	__('Issue Types', 'cleara11y'),
		// 	'manage_options',
		// 	'cleara11y-issue-types',
		// 	[Issue_Types_Page::class, 'render_page']
		// );

		// Add Issue Reference submenu page
		add_submenu_page(
			'cleara11y',
			__('Issue Reference', 'cleara11y'),
			__('Issue Reference', 'cleara11y'),
			'manage_options',
			'cleara11y-issue-reference',
			[Issue_Reference_Page::class, 'render_page']
		);

		// Add Settings submenu page
		add_submenu_page(
			'cleara11y',
			__('Settings', 'cleara11y'),
			__('Settings', 'cleara11y'),
			'manage_options',
			'cleara11y-settings',
			[Settings_Page::class, 'render']
		);

		// Add Debug Tools submenu page
		add_submenu_page(
			'cleara11y',
			__('Debug Tools', 'cleara11y'),
			__('Debug Tools', 'cleara11y'),
			'manage_options',
			'cleara11y-debug',
			[$this, 'render_debug_page']
		);
	}

	/**
	 * Render debug tools page.
	 */
	public function render_debug_page(): void {
		// Handle test scan request - redirect to page with scan token
		if (isset($_POST['cleara11y_test_scan']) && check_admin_referer('cleara11y_test_scan')) {
			$post_id = intval($_POST['test_post_id'] ?? 0);
			if ($post_id > 0) {
				// Generate a temporary scan token
				$token = \ClearA11y\Services\Scan_Token_Manager::generate_token($post_id, 'test');
				if (isset($token['error'])) {
					echo '<div class="notice notice-error"><p>Error: ' . esc_html($token['error']) . '</p></div>';
				} else {
					// Redirect to the page with the scan token (in foreground mode for testing)
					$scan_url = add_query_arg(
						['cleara11y_scan' => $token['token']],
						get_permalink($post_id)
					);
					echo '<p>Redirecting to scan page... <a href="' . esc_url($scan_url) . '">Click here if not redirected</a></p>';
					echo '<script>window.location.href = ' . wp_json_encode($scan_url) . ';</script>';
					return;
				}
			}
		}

		// Check if reset form was submitted
		if (isset($_POST['cleara11y_reset_stuck']) && check_admin_referer('cleara11y_reset_stuck')) {
			global $wpdb;
			$items_table = \ClearA11y\Database\Schema::get_table_name('scan_items');
			$scans_table = \ClearA11y\Database\Schema::get_table_name('scans');

			$updated_items = $wpdb->query(
				"UPDATE `{$items_table}` SET status = 'failed' WHERE status = 'in_progress'"
			);
			$updated_scans = $wpdb->query(
				"UPDATE `{$scans_table}` SET status = 'failed' WHERE status = 'in_progress'"
			);

			echo '<div class="notice notice-success is-dismissible"><p>';
			echo sprintf('Reset %d stuck scan items and %d stuck scans.', $updated_items, $updated_scans);
			echo '</p></div>';
		}

		// Get list of published pages/posts
		$post_types = get_post_types(['public' => true], 'objects');

		?>
		<div class="wrap">
			<h1><?php esc_html_e('ClearA11y Debug Tools', 'cleara11y'); ?></h1>

			<h2>Available Pages for Scanning</h2>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th>ID</th>
						<th>Title</th>
						<th>Type</th>
						<th>URL</th>
						<th>Status</th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ($post_types as $post_type) {
						$args = [
							'post_type' => $post_type->name,
							'post_status' => 'publish',
							'numberposts' => 50,
							'orderby' => 'title',
							'order' => 'ASC'
						];

						$posts = get_posts($args);

						foreach ($posts as $post) {
							$url = get_permalink($post->ID);
							$status = get_post_status($post->ID);
							?>
							<tr>
								<td><?php echo esc_html($post->ID); ?></td>
								<td><?php echo esc_html($post->post_title); ?></td>
								<td><?php echo esc_html($post_type->labels->singular_name); ?></td>
								<td><a href="<?php echo esc_url($url); ?>" target="_blank"><?php echo esc_html($url); ?></a></td>
								<td><?php echo esc_html($status); ?></td>
							</tr>
							<?php
						}
					}
					?>
				</tbody>
			</table>

			<h2>Reset Stuck Scans</h2>
			<p>Use this tool to reset scans that are stuck in "in_progress" status. This can happen if a scan was interrupted.</p>
			<form method="post">
				<?php wp_nonce_field('cleara11y_reset_stuck'); ?>
				<input type="submit" name="cleara11y_reset_stuck" class="button button-primary" value="Reset Stuck Scans">
			</form>

			<h2>Test Scanner</h2>
			<p>Generate a test scan token to manually test the scanner. The page will open in a new tab with the scanner enabled.</p>
			<form method="post" target="_blank">
				<?php wp_nonce_field('cleara11y_test_scan'); ?>
				<select name="test_post_id" class="regular-text">
					<option value="">Select a page to scan...</option>
					<?php
					foreach ($post_types as $post_type) {
						$posts = get_posts([
							'post_type' => $post_type->name,
							'post_status' => 'publish',
							'numberposts' => 20,
							'orderby' => 'title',
							'order' => 'ASC'
						]);
						foreach ($posts as $post) {
							echo '<option value="' . esc_attr($post->ID) . '">' . esc_html($post_type->labels->singular_name . ': ' . $post->post_title) . '</option>';
						}
					}
					?>
				</select>
				<input type="submit" name="cleara11y_test_scan" class="button" value="Test Scan in New Tab">
				<span style="margin-left: 10px; color: #646970;">(Look for the scanner overlay when page loads)</span>
			</form>

			<h2>Queue Status</h2>
			<?php
			global $wpdb;
			$items_table = \ClearA11y\Database\Schema::get_table_name('scan_items');
			$scans_table = \ClearA11y\Database\Schema::get_table_name('scans');

			$pending = $wpdb->get_var("SELECT COUNT(*) FROM `{$items_table}` WHERE status = 'pending'");
			$in_progress = $wpdb->get_var("SELECT COUNT(*) FROM `{$items_table}` WHERE status = 'in_progress'");
			$completed = $wpdb->get_var("SELECT COUNT(*) FROM `{$items_table}` WHERE status = 'completed'");
			$failed = $wpdb->get_var("SELECT COUNT(*) FROM `{$items_table}` WHERE status = 'failed'");

			echo '<ul>';
			echo '<li>Pending: <strong>' . intval($pending) . '</strong></li>';
			echo '<li>In Progress: <strong>' . intval($in_progress) . '</strong></li>';
			echo '<li>Completed: <strong>' . intval($completed) . '</strong></li>';
			echo '<li>Failed: <strong>' . intval($failed) . '</strong></li>';
			echo '</ul>';

			// Show recent scan items with details
			$recent_items = $wpdb->get_results(
				"SELECT id, post_title, status, created_at, error_message FROM `{$items_table}` ORDER BY created_at DESC LIMIT 5"
			);
			if ($recent_items) {
				echo '<h4>Recent Scan Items:</h4>';
				echo '<table class="wp-list-table widefat fixed striped" style="margin-top: 10px;">';
				echo '<thead><tr><th>ID</th><th>Page</th><th>Status</th><th>Error</th><th>Created</th></tr></thead>';
				echo '<tbody>';
				foreach ($recent_items as $item) {
					echo '<tr>';
					echo '<td>' . esc_html($item->id) . '</td>';
					echo '<td>' . esc_html($item->post_title ?: 'N/A') . '</td>';
					echo '<td>' . esc_html($item->status) . '</td>';
					echo '<td>' . esc_html($item->error_message ?: '') . '</td>';
					echo '<td>' . esc_html($item->created_at) . '</td>';
					echo '</tr>';
				}
				echo '</tbody></table>';
			}
			?>
		</div>
		<?php
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_assets(string $hook_suffix): void {
		// Always load global scanner on ALL admin pages for scan continuation
		$this->enqueue_global_scanner();

		// Only load page-specific assets on our pages
		if (strpos($hook_suffix, 'cleara11y') === false) {
			return;
		}

		// Enqueue dashboard CSS
		wp_enqueue_style(
			'cleara11y-dashboard',
			CLEARA11Y_PLUGIN_URL . 'assets/css/dashboard.css',
			[],
			CLEARA11Y_VERSION
		);

		// Get the correct REST API base URL
		$rest_url = get_rest_url();

		// Check which page we're on
		$is_issues_page = ($hook_suffix === 'cleara11y_page_cleara11y-issues');
		$is_issue_types_page = ($hook_suffix === 'cleara11y_page_cleara11y-issue-types');
		$is_issue_reference_page = ($hook_suffix === 'cleara11y_page_cleara11y-issue-reference');
		$is_ignores_page = ($hook_suffix === 'cleara11y_page_cleara11y-ignores');

		// Enqueue the appropriate JavaScript
		if ($is_issues_page) {
			// Enqueue toast CSS for notifications
			wp_enqueue_style(
				'cleara11y-toast',
				CLEARA11Y_PLUGIN_URL . 'assets/css/toast.css',
				[],
				CLEARA11Y_VERSION
			);

			// Enqueue ignores page CSS for wizard
			wp_enqueue_style(
				'cleara11y-ignores-page',
				CLEARA11Y_PLUGIN_URL . 'assets/css/ignores-page.css',
				[],
				CLEARA11Y_VERSION
			);

			// Enqueue jQuery (required for ignores page wizard)
			wp_enqueue_script('jquery');

			// Enqueue ignores page script for wizard functionality
			wp_enqueue_script(
				'cleara11y-ignores-page',
				CLEARA11Y_PLUGIN_URL . 'assets/js/ignores-page.js',
				['jquery'],
				rand(),
				true
			);

			// Localize ignores page script
			wp_localize_script('cleara11y-ignores-page', 'cleara11yIgnores', [
				'apiUrl' => $rest_url . 'cleara11y/v1/ignores',
				'nonce' => wp_create_nonce('wp_rest'),
				'strings' => [
					'createWizardTitle' => __('Create Ignore Rule', 'cleara11y'),
					'cancel' => __('Cancel', 'cleara11y'),
					'next' => __('Next', 'cleara11y'),
					'createRule' => __('Create Ignore Rule', 'cleara11y'),
					'target' => __('Target', 'cleara11y'),
					'scope' => __('Scope', 'cleara11y'),
					'duration' => __('Duration', 'cleara11y'),
					'reason' => __('Reason', 'cleara11y'),
					'step5Title' => __('Review & Confirm', 'cleara11y'),
					'createSuccess' => __('Ignore rule created successfully.', 'cleara11y'),
					'confirmDelete' => __('Are you sure you want to delete this ignore rule? This action cannot be undone.', 'cleara11y'),
					'confirmDisable' => __('Are you sure you want to disable this ignore rule?', 'cleara11y'),
					'error' => __('An error occurred. Please try again.', 'cleara11y'),
				],
			]);

			// Enqueue issues list JavaScript
			wp_enqueue_script(
				'cleara11y-issues-list',
				CLEARA11Y_PLUGIN_URL . 'assets/js/issues-list.js',
				[],
				rand(),
				true
			);

			// Localize issues list script
			wp_localize_script('cleara11y-issues-list', 'cleara11yData', [
				'apiUrl' => $rest_url . 'cleara11y/v1/',
				'nonce' => wp_create_nonce('wp_rest'),
				'pluginUrl' => CLEARA11Y_PLUGIN_URL,
				'strings' => [
					'loading' => __('Loading...', 'cleara11y'),
					'noIssues' => __('No issues found.', 'cleara11y'),
					'error' => __('Error loading issues.', 'cleara11y'),
					'confirmDismiss' => __('Are you sure you want to dismiss this issue?', 'cleara11y'),
					'confirmUndismiss' => __('Are you sure you want to undismiss this issue?', 'cleara11y'),
				],
			]);
		} elseif ($is_issue_types_page) {
			// Enqueue issue types JavaScript
			wp_enqueue_script(
				'cleara11y-issue-types',
				CLEARA11Y_PLUGIN_URL . 'assets/js/issue-types.js',
				[],
				rand(),
				true
			);

			// Localize issue types script
			wp_localize_script('cleara11y-issue-types', 'cleara11yData', [
				'apiUrl' => $rest_url . 'cleara11y/v1/',
				'nonce' => wp_create_nonce('wp_rest'),
				'pluginUrl' => CLEARA11Y_PLUGIN_URL,
				'strings' => [
					'loading' => __('Loading...', 'cleara11y'),
					'noIssues' => __('No issues found.', 'cleara11y'),
					'error' => __('Error loading issue types.', 'cleara11y'),
					'confirmGlobalIgnore' => __('Are you sure you want to globally ignore this issue type?', 'cleara11y'),
					'confirmGlobalUnignore' => __('Are you sure you want to un-ignore this issue type globally?', 'cleara11y'),
				],
			]);
		} elseif ($is_issue_reference_page) {
			// Enqueue issue reference JavaScript
			wp_enqueue_script(
				'cleara11y-issue-reference',
				CLEARA11Y_PLUGIN_URL . 'assets/js/issue-reference.js',
				[],
				rand(),
				true
			);

			// Localize issue reference script
			wp_localize_script('cleara11y-issue-reference', 'cleara11yData', [
				'apiUrl' => $rest_url . 'cleara11y/v1/',
				'nonce' => wp_create_nonce('wp_rest'),
				'pluginUrl' => CLEARA11Y_PLUGIN_URL,
				'strings' => [
					'loading' => __('Loading...', 'cleara11y'),
					'noIssues' => __('No rules found.', 'cleara11y'),
					'error' => __('Error loading rules.', 'cleara11y'),
				],
				// Include severity mapping for all axe-core rules
				'severityMap' => \ClearA11y\Services\Rule_Severity_Map::get_severity_map(),
			]);
		} elseif ($is_ignores_page) {
			// Enqueue ignores page JavaScript
			wp_enqueue_script(
				'cleara11y-ignores-page',
				CLEARA11Y_PLUGIN_URL . 'assets/js/ignores-page.js',
				['jquery', 'wp-api'],
				rand(),
				true
			);

			// Localize ignores page script
			wp_localize_script('cleara11y-ignores-page', 'cleara11yIgnores', [
				'apiUrl' => $rest_url . 'cleara11y/v1/ignores',
				'nonce' => wp_create_nonce('wp_rest'),
				'strings' => [
					'confirmDelete' => __('Are you sure you want to delete this ignore rule? This action cannot be undone.', 'cleara11y'),
					'confirmDisable' => __('Are you sure you want to disable this ignore rule?', 'cleara11y'),
					'undoSuccess' => __('Ignore rule removed.', 'cleara11y'),
					'deleteSuccess' => __('Ignore rule deleted.', 'cleara11y'),
					'error' => __('An error occurred. Please try again.', 'cleara11y'),
				],
			]);
		} else {
			// Enqueue scanner orchestrator first (loaded but not executed directly)
			wp_enqueue_script(
				'cleara11y-scanner-orchestrator',
				CLEARA11Y_PLUGIN_URL . 'assets/js/scanner-orchestrator.js',
				[],
				rand(),
				true
			);

			// Enqueue dashboard JavaScript (depends on orchestrator)
			wp_enqueue_script(
				'cleara11y-dashboard',
				CLEARA11Y_PLUGIN_URL . 'assets/js/dashboard.js',
				['cleara11y-scanner-orchestrator'],
				rand(),
				true
			);

			// Localize dashboard script
			wp_localize_script('cleara11y-dashboard', 'cleara11yData', [
			'apiUrl' => $rest_url . 'cleara11y/v1/',
			'wpApiUrl' => $rest_url . 'wp/v2/',
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('wp_rest'),
			'ajaxNonce' => wp_create_nonce('cleara11y-nonce'),
			'pluginUrl' => CLEARA11Y_PLUGIN_URL,
			'strings' => [
				'scanInProgress' => __('Scan in progress...', 'cleara11y'),
				'scanComplete' => __('Scan complete!', 'cleara11y'),
				'scanFailed' => __('Scan failed.', 'cleara11y'),
				'loading' => __('Loading...', 'cleara11y'),
				'noIssues' => __('No accessibility issues found!', 'cleara11y'),
			],
			// Debug info
			'debug' => [
				'restUrl' => $rest_url,
				'siteUrl' => site_url(),
				'homeUrl' => home_url(),
			],
		]);
		}
	}
}
