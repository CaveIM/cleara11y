<?php
/**
 * Page Metabox
 *
 * Adds a metabox to the page/post edit screen showing accessibility issues.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Admin
 */

namespace ClearA11y\Admin;

use ClearA11y\Database\Issue_Repository;

/**
 * Metabox Class
 */
class Metabox {

	/**
	 * Single instance of the class.
	 *
	 * @var Metabox|null
	 */
	private static ?Metabox $instance = null;

	/**
	 * Get the single instance of the class.
	 *
	 * @return Metabox
	 */
	public static function get_instance(): Metabox {
		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action('add_meta_boxes', [$this, 'register_metabox']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
		add_action('wp_ajax_cleara11y_get_page_stats', [$this, 'ajax_get_page_stats']);
		add_action('wp_ajax_cleara11y_initiate_scan', [$this, 'ajax_initiate_scan']);
	}

	/**
	 * Register metabox for enabled post types.
	 *
	 * @return void
	 */
	public function register_metabox(): void {
		$enabled_post_types = get_option('cleara11y_scan_post_types', ['page', 'post']);

		foreach ($enabled_post_types as $post_type) {
			add_meta_box(
				'cleara11y-metabox',
				__('Accessibility', 'cleara11y'),
				[$this, 'render_metabox'],
				$post_type,
				'side',
				'high'
			);
		}
	}

	/**
	 * Render metabox content.
	 *
	 * @param \WP_Post $post Current post object.
	 * @return void
	 */
	public function render_metabox(\WP_Post $post): void {
		// Get issue counts for this post
		$counts = Issue_Repository::get_post_issue_counts($post->ID);

		// Calculate accessibility score
		$total_issues = $counts['total'];
		$score = 100;
		if ($total_issues > 0) {
			$score = max(0, 100 - ($counts['critical'] * 10) - ($counts['moderate'] * 5) - ($counts['minor'] * 2));
		}

		// Get latest scan date
		$scan_date = $this->get_latest_scan_date($post->ID);

		// Get the REST API URL
		$rest_url = rest_url('cleara11y/v1/');

		?>
		<div id="cleara11y-metabox" class="cleara11y-metabox">
			<!-- Accessibility Score -->
			<div class="cleara11y-score-section">
				<div class="cleara11y-score-circle" style="background: conic-gradient(<?php echo esc_attr($this->get_score_color($score)); ?> <?php echo esc_attr($score); ?>%, transparent 0);">
					<div class="cleara11y-score-inner">
						<span class="cleara11y-score-value"><?php echo esc_html($score); ?></span>
					</div>
				</div>
				<div class="cleara11y-score-label">Accessibility Score</div>
			</div>

			<!-- Issue Counts -->
			<div class="cleara11y-issue-counts">
				<div class="cleara11y-issue-count-item critical">
					<span class="cleara11y-issue-count"><?php echo esc_html($counts['critical']); ?></span>
					<span class="cleara11y-issue-label">Critical</span>
				</div>
				<div class="cleara11y-issue-count-item moderate">
					<span class="cleara11y-issue-count"><?php echo esc_html($counts['moderate']); ?></span>
					<span class="cleara11y-issue-label">Moderate</span>
				</div>
				<div class="cleara11y-issue-count-item minor">
					<span class="cleara11y-issue-count"><?php echo esc_html($counts['minor']); ?></span>
					<span class="cleara11y-issue-label">Minor</span>
				</div>
			</div>

			<!-- Scan Info -->
			<?php if ($scan_date): ?>
			<div class="cleara11y-scan-info">
				<span class="cleara11y-scan-date">
					<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
					<?php echo esc_html($scan_date); ?>
				</span>
			</div>
			<?php endif; ?>

			<!-- Actions -->
			<div class="cleara11y-metabox-actions">
				<button type="button" id="cleara11y-scan-page-btn" class="button button-secondary cleara11y-scan-btn">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
					<span>Scan Page</span>
				</button>
				<?php if ($total_issues > 0): ?>
				<a href="<?php echo esc_url($this->get_report_url($post->ID)); ?>" class="button button-primary">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
					<span>View Report</span>
				</a>
				<?php endif; ?>
			</div>

			<!-- Progress Indicator -->
			<div class="cleara11y-scan-progress" style="display: none;">
				<span class="spinner is-active"></span>
				<span>Scanning page...</span>
			</div>

			<!-- Complete Indicator -->
			<div class="cleara11y-scan-complete" style="display: none;">
				<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
				<span>Scan complete!</span>
			</div>

		</div>

		<script type="text/javascript">
			var cleara11yMetaboxData = {
				apiUrl: <?php echo wp_json_encode($rest_url); ?>,
				ajaxUrl: <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,
				nonce: <?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?>,
				ajaxNonce: <?php echo wp_json_encode(wp_create_nonce('cleara11y-nonce')); ?>,
				postId: <?php echo (int) $post->ID; ?>,
				postTitle: <?php echo wp_json_encode(get_the_title($post->ID)); ?>,
				pluginUrl: <?php echo wp_json_encode(CLEARA11Y_PLUGIN_URL); ?>
			};
		</script>
		<?php
	}

	/**
	 * Get score color based on value.
	 *
	 * @param int $score Score value.
	 * @return string Color hex code.
	 */
	private function get_score_color(int $score): string {
		if ($score >= 90) {
			return '#00a32a';
		} elseif ($score >= 70) {
			return '#ffb900';
		} elseif ($score >= 50) {
			return '#f56e28';
		}
		return '#dc2626';
	}

	/**
	 * Get latest scan date for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null Formatted date or null.
	 */
	private function get_latest_scan_date(int $post_id): ?string {
		global $wpdb;

		$scan_items_table = \ClearA11y\Database\Schema::get_table_name('scan_items');

		$date = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT scanned_at FROM `{$scan_items_table}`
				WHERE post_id = %d AND status = 'completed'
				ORDER BY scanned_at DESC
				LIMIT 1",
				$post_id
			)
		);

		if ($date) {
			$timestamp = strtotime($date);
			$now = current_time('timestamp');
			$diff = $now - $timestamp;

			if ($diff < HOUR_IN_SECONDS) {
				$minutes = floor($diff / MINUTE_IN_SECONDS);
				return sprintf('Scanned %d %s ago', $minutes, _n('minute', 'minutes', $minutes, 'cleara11y'));
			} elseif ($diff < DAY_IN_SECONDS) {
				$hours = floor($diff / HOUR_IN_SECONDS);
				return sprintf('Scanned %d %s ago', $hours, _n('hour', 'hours', $hours, 'cleara11y'));
			} elseif ($diff < WEEK_IN_SECONDS) {
				$days = floor($diff / DAY_IN_SECONDS);
				return sprintf('Scanned %d %s ago', $days, _n('day', 'days', $days, 'cleara11y'));
			} else {
				return sprintf('Scanned %s', date_i18n(get_option('date_format'), $timestamp));
			}
		}

		return null;
	}

	/**
	 * Get report URL for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string Report URL.
	 */
	private function get_report_url(int $post_id): string {
		// Build URL explicitly to avoid any issues with URL generation
		return admin_url('admin.php?page=cleara11y-page-report&post_id=' . $post_id);
	}

	/**
	 * AJAX handler to get page stats.
	 *
	 * @return void
	 */
	public function ajax_get_page_stats(): void {
		check_ajax_referer('cleara11y-nonce', 'nonce');

		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

		if (!$post_id) {
			wp_send_json_error(['message' => 'Invalid post ID']);
		}

		$counts = Issue_Repository::get_post_issue_counts($post_id);
		$total = $counts['total'];
		$score = 100;
		if ($total > 0) {
			$score = max(0, 100 - ($counts['critical'] * 10) - ($counts['moderate'] * 5) - ($counts['minor'] * 2));
		}

		$scan_date = $this->get_latest_scan_date($post_id);

		wp_send_json_success([
			'score' => $score,
			'counts' => $counts,
			'scan_date' => $scan_date,
			'scan_status' => 'completed',
		]);
	}

	/**
	 * AJAX handler to initiate scan.
	 *
	 * @return void
	 */
	public function ajax_initiate_scan(): void {
		check_ajax_referer('cleara11y-nonce', 'nonce');

		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

		if (!$post_id) {
			wp_send_json_error(['message' => 'Invalid post ID']);
		}

		// Check if tables exist
		if (!\ClearA11y\Database\Schema::tables_exist()) {
			\ClearA11y\Database\Schema::create_tables();
		}

		// Generate scan token
		$result = \ClearA11y\Services\Scan_Token_Manager::generate_token($post_id, 'individual');

		if (isset($result['error'])) {
			wp_send_json_error(['message' => $result['error']]);
		}

		// Build scan URL with frontend highlighting disabled during scan
		$scan_url = add_query_arg(
			[
				'cleara11y_scan' => $result['token'],
				'cleara11y_scanning' => '1',
			],
			get_permalink($post_id)
		);

		wp_send_json_success([
			'scan_url' => $scan_url,
			'token' => $result['token'],
		]);
	}

	/**
	 * AJAX handler to view page report.
	 *
	 * @return void
	 */
	public function ajax_view_page_report(): void {
		check_ajax_referer('cleara11y-nonce', 'nonce');

		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

		if (!$post_id) {
			wp_send_json_error(['message' => 'Invalid post ID']);
		}

		$report_url = $this->get_report_url($post_id);

		wp_send_json_success([
			'report_url' => $report_url,
		]);
	}

	/**
	 * Enqueue metabox assets.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue_assets(string $hook_suffix): void {
		// Only load on post edit screens
		if (!in_array($hook_suffix, ['post.php', 'post-new.php'], true)) {
			return;
		}

		// Don't load on the page report page itself
		if (strpos($hook_suffix, 'cleara11y-page-report') !== false) {
			return;
		}

		// Enqueue metabox styles
		wp_enqueue_style(
			'cleara11y-metabox',
			CLEARA11Y_PLUGIN_URL . 'assets/css/metabox.css',
			[],
			CLEARA11Y_VERSION
		);

		// Enqueue metabox script
		wp_enqueue_script(
			'cleara11y-metabox',
			CLEARA11Y_PLUGIN_URL . 'assets/js/page-metabox.js',
			[],
			CLEARA11Y_VERSION,
			true
		);
	}
}
