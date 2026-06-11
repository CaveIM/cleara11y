<?php
/**
 * Page Report
 *
 * Renders a dedicated report page for individual pages/posts.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Admin
 */

namespace ClearA11y\Admin;

use ClearA11y\Database\Issue_Repository;

/**
 * Page Report Class
 */
class Page_Report {

	/**
	 * Single instance of the class.
	 *
	 * @var Page_Report|null
	 */
	private static ?Page_Report $instance = null;

	/**
	 * Get the single instance of the class.
	 *
	 * @return Page_Report
	 */
	public static function get_instance(): Page_Report {
		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action('admin_menu', [$this, 'register_page'], 20);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
	}

	/**
	 * Register the page report admin page.
	 *
	 * @return void
	 */
	public function register_page(): void {
		add_submenu_page(
			null,
			__('Page Accessibility Report', 'cleara11y'),
			__('Page Accessibility Report', 'cleara11y'),
			'edit_posts',
			'cleara11y-page-report',
			[$this, 'render_page']
		);
	}

	/**
	 * Render the page report.
	 *
	 * @return void
	 */
	public function render_page(): void {
		$post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;

		if (!$post_id) {
			wp_die(__('Invalid post ID.', 'cleara11y'));
		}

		$post = get_post($post_id);

		if (!$post) {
			wp_die(__('Post not found.', 'cleara11y'));
		}

		if (!current_user_can('edit_post', $post_id)) {
			wp_die(__('You do not have permission to view this report.', 'cleara11y'));
		}

		// Get issues for this post
		$issues = Issue_Repository::get_by_post_id($post_id);
		$counts = Issue_Repository::get_post_issue_counts($post_id);

		// Calculate score
		$total_issues = $counts['total'];
		$score = 100;
		if ($total_issues > 0) {
			$score = max(0, 100 - ($counts['critical'] * 10) - ($counts['moderate'] * 5) - ($counts['minor'] * 2));
		}

		// Get latest scan date
		$scan_date = $this->get_latest_scan_date($post_id);

		?>
		<div class="wrap cleara11y-page-report-wrap">

			<h1 class="wp-heading-inline">
				<?php printf(__('Accessibility Report: %s', 'cleara11y'), esc_html($post->post_title)); ?>
			</h1>

			<?php if ($scan_date): ?>
			<p class="cleara11y-scan-date">
				<span class="dashicons dashicons-clock"></span>
				<?php echo esc_html($scan_date); ?>
			</p>
			<?php endif; ?>

			<hr class="wp-header-end">

			<div class="cleara11y-report-grid">
				<!-- Sidebar -->
				<div class="cleara11y-report-sidebar">
					<div class="cleara11y-score-card">
						<div class="cleara11y-score-circle" style="background: conic-gradient(<?php echo esc_attr($this->get_score_color($score)); ?> <?php echo esc_attr($score); ?>%, transparent 0);">
							<div class="cleara11y-score-inner">
								<span class="cleara11y-score-value"><?php echo esc_html($score); ?></span>
							</div>
						</div>
						<div class="cleara11y-score-label"><?php esc_html_e('Accessibility Score', 'cleara11y'); ?></div>
					</div>

					<div class="cleara11y-issue-summary">
						<div class="cleara11y-summary-item critical">
							<span class="cleara11y-summary-count"><?php echo esc_html($counts['critical']); ?></span>
							<span class="cleara11y-summary-label"><?php esc_html_e('Critical', 'cleara11y'); ?></span>
						</div>
						<div class="cleara11y-summary-item moderate">
							<span class="cleara11y-summary-count"><?php echo esc_html($counts['moderate']); ?></span>
							<span class="cleara11y-summary-label"><?php esc_html_e('Moderate', 'cleara11y'); ?></span>
						</div>
						<div class="cleara11y-summary-item minor">
							<span class="cleara11y-summary-count"><?php echo esc_html($counts['minor']); ?></span>
							<span class="cleara11y-summary-label"><?php esc_html_e('Minor', 'cleara11y'); ?></span>
						</div>
					</div>

					<div class="cleara11y-report-actions-card">
						<h3><?php esc_html_e('Quick Actions', 'cleara11y'); ?></h3>
						<a href="<?php echo esc_url(get_permalink($post_id)); ?>" target="_blank" class="button button-secondary button-large">
							<span class="dashicons dashicons-visibility"></span>
							<?php esc_html_e('View on Page', 'cleara11y'); ?>
						</a>
						<a href="<?php echo esc_url(get_edit_post_link($post_id)); ?>#cleara11y-metabox" class="button button-primary button-large">
							<span class="dashicons dashicons-edit"></span>
							<?php esc_html_e('Edit Page', 'cleara11y'); ?>
						</a>
						<a href="<?php echo esc_url(admin_url('admin.php?page=cleara11y')); ?>" class="button button-large">
							<span class="dashicons dashicons-dashboard"></span>
							<?php esc_html_e('Dashboard', 'cleara11y'); ?>
						</a>
					</div>
				</div>

				<!-- Content -->
				<div class="cleara11y-report-content">
					<div class="cleara11y-report-header-card">
						<h2>
							<?php
							if ($total_issues > 0) {
								printf(_n('%d Issue Found', '%d Issues Found', $total_issues, 'cleara11y'), $total_issues);
							} else {
								esc_html_e('No Issues Found', 'cleara11y');
							}
							?>
						</h2>
					</div>


					<?php if ($total_issues > 0): ?>
						<div class="cleara11y-issues-list">
							<?php foreach ($issues as $issue): ?>
								<div class="cleara11y-issue-card severity-<?php echo esc_attr($issue->severity); ?>">
									<div class="cleara11y-issue-card-header">
										<div class="cleara11y-issue-title-row">
											<h3 class="cleara11y-issue-title"><?php echo esc_html($issue->rule_id); ?></h3>
											<span class="cleara11y-issue-severity-badge"><?php echo esc_html($issue->severity); ?></span>
										</div>
										<?php if ($issue->selector): ?>
										<div class="cleara11y-issue-selector">
											<span class="dashicons dashicons-admin-code"></span>
											<code><?php echo esc_html($this->truncate_selector($issue->selector)); ?></code>
										</div>
										<?php endif; ?>
									</div>

									<div class="cleara11y-issue-message">
										<?php echo esc_html($issue->message ?: $issue->help_text); ?>
									</div>

									<div class="cleara11y-issue-card-footer">
										<?php if ($issue->help_url): ?>
										<a href="<?php echo esc_url($issue->help_url); ?>" target="_blank" rel="noopener" class="cleara11y-issue-help-link">
											<span class="dashicons dashicons-info"></span>
											<?php esc_html_e('Learn more about this rule', 'cleara11y'); ?>
										</a>
										<?php endif; ?>

										<?php if ($issue->wcag_criterion): ?>
										<span class="cleara11y-issue-wcag">
											<strong>WCAG:</strong> <?php echo esc_html($issue->wcag_criterion); ?>
										</span>
										<?php endif; ?>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					<?php else: ?>
						<div class="cleara11y-no-issues">
							<div class="cleara11y-no-issues-icon">
								<span class="dashicons dashicons-yes-alt"></span>
							</div>
							<h2><?php esc_html_e('Great Job!', 'cleara11y'); ?></h2>
							<p><?php esc_html_e('No accessibility issues were detected on this page.', 'cleara11y'); ?></p>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
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
			return sprintf(__('Scanned on %s', 'cleara11y'), date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($date)));
		}

		return null;
	}

	/**
	 * Truncate selector for display.
	 *
	 * @param string $selector Selector string.
	 * @return string Truncated selector.
	 */
	private function truncate_selector(string $selector): string {
		if (strlen($selector) <= 60) {
			return $selector;
		}
		return substr($selector, 0, 60) . '...';
	}

	/**
	 * Enqueue assets for the page report.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue_assets(string $hook_suffix): void {
		if (! in_array($hook_suffix, ['cleara11y_page_cleara11y-page-report', 'admin_page_cleara11y-page-report'], true)) {
			return;
		}

		wp_enqueue_style('cleara11y-page-report', CLEARA11Y_PLUGIN_URL . 'assets/css/page-report.css', [], CLEARA11Y_VERSION);
	}
}
