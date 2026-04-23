<?php
/**
 * Dashboard Page
 *
 * Renders the admin dashboard page.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Admin
 */

namespace ClearA11y\Admin;

/**
 * Dashboard Page Class
 */
class Dashboard_Page {

	/**
	 * Single instance of the class.
	 *
	 * @var Dashboard_Page|null
	 */
	private static ?Dashboard_Page $instance = null;

	/**
	 * Get the single instance of the class.
	 *
	 * @return Dashboard_Page
	 */
	public static function get_instance(): Dashboard_Page {
		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		// Hooks are registered in Admin class
	}

	/**
	 * Render the dashboard page.
	 */
	public static function render(): void {
		$stats = self::get_stats();
		$recent_scans = self::get_recent_scans();
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<meta charset="UTF-8">
			<title><?php esc_html_e('ClearA11y Dashboard', 'cleara11y'); ?></title>
		</head>
		<body>
		<div class="wrap cleara11y-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e('ClearA11y Dashboard', 'cleara11y'); ?></h1>
			<hr class="wp-header-end">

			<div class="cleara11y-dashboard-grid">

				<!-- Stats Overview -->
				<div class="cleara11y-card cleara11y-stats-overview">
					<h2><?php esc_html_e('Site Health', 'cleara11y'); ?></h2>
					<div class="cleara11y-stats-grid">
						<div class="cleara11y-stat-box">
							<div class="cleara11y-stat-value cleara11y-stat-critical" id="cleara11y-total-critical"><?php echo esc_html($stats['total_critical']); ?></div>
							<div class="cleara11y-stat-label"><?php esc_html_e('Critical Issues', 'cleara11y'); ?></div>
						</div>
						<div class="cleara11y-stat-box">
							<div class="cleara11y-stat-value cleara11y-stat-moderate" id="cleara11y-total-moderate"><?php echo esc_html($stats['total_moderate']); ?></div>
							<div class="cleara11y-stat-label"><?php esc_html_e('Moderate Issues', 'cleara11y'); ?></div>
						</div>
						<div class="cleara11y-stat-box">
							<div class="cleara11y-stat-value cleara11y-stat-minor" id="cleara11y-total-minor"><?php echo esc_html($stats['total_minor']); ?></div>
							<div class="cleara11y-stat-label"><?php esc_html_e('Minor Issues', 'cleara11y'); ?></div>
						</div>
						<div class="cleara11y-stat-box">
							<div class="cleara11y-stat-value" id="cleara11y-total-pages"><?php echo esc_html($stats['total_pages']); ?></div>
							<div class="cleara11y-stat-label"><?php esc_html_e('Pages Scanned', 'cleara11y'); ?></div>
						</div>
					</div>
				</div>

				<!-- Quick Actions -->
				<div class="cleara11y-card cleara11y-quick-actions">
					<h2><?php esc_html_e('Quick Actions', 'cleara11y'); ?></h2>
					<div class="cleara11y-actions-grid">
						<button type="button" class="button button-primary button-large cleara11y-start-scan">
							<span class="dashicons dashicons-search"></span>
							<?php esc_html_e('Run Full Site Scan', 'cleara11y'); ?>
						</button>
						<a href="<?php echo esc_url(admin_url('edit.php?post_type=page')); ?>" class="button button-large">
							<span class="dashicons dashicons-admin-page"></span>
							<?php esc_html_e('View All Pages', 'cleara11y'); ?>
						</a>
					</div>
				</div>

				<!-- Recent Scans -->
				<div class="cleara11y-card cleara11y-recent-scans">
					<h2><?php esc_html_e('Recent Scans', 'cleara11y'); ?></h2>
					<?php if (empty($recent_scans)) : ?>
						<p class="cleara11y-empty-state">
							<?php esc_html_e('No scans yet. Start by scanning a page.', 'cleara11y'); ?>
						</p>
					<?php else : ?>
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th><?php esc_html_e('Type', 'cleara11y'); ?></th>
									<th><?php esc_html_e('Status', 'cleara11y'); ?></th>
									<th><?php esc_html_e('Issues', 'cleara11y'); ?></th>
									<th><?php esc_html_e('Date', 'cleara11y'); ?></th>
									<th></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($recent_scans as $scan) : ?>
									<tr>
										<td><?php echo esc_html(ucfirst($scan->scan_type)); ?></td>
										<td>
											<span class="cleara11y-status-badge cleara11y-status-<?php echo esc_attr($scan->status); ?>">
												<?php echo esc_html(ucfirst(str_replace('_', ' ', $scan->status))); ?>
											</span>
										</td>
										<td>
											<?php if ($scan->total_issues > 0) : ?>
												<span class="cleara11y-issue-count">
													<?php echo esc_html($scan->total_issues); ?>
												</span>
											<?php else : ?>
												<span class="cleara11y-no-issues"><?php esc_html_e('None', 'cleara11y'); ?></span>
											<?php endif; ?>
										</td>
										<td><?php echo esc_html(date('M j, Y', strtotime($scan->created_at))); ?></td>
										<td>
											<button type="button" class="button button-small cleara11y-view-scan" data-scan-id="<?php echo esc_attr($scan->id); ?>">
												<?php esc_html_e('View Details', 'cleara11y'); ?>
											</button>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>

				<!-- Pages List -->
				<div class="cleara11y-card cleara11y-pages-list">
					<h2><?php esc_html_e('Pages to Scan', 'cleara11y'); ?></h2>
					<div class="cleara11y-pages-filter">
						<select id="cleara11y-post-type-filter" class="regular-text">
							<option value="page"><?php esc_html_e('Pages', 'cleara11y'); ?></option>
							<option value="post"><?php esc_html_e('Posts', 'cleara11y'); ?></option>
						</select>
					</div>
					<div id="cleara11y-pages-container" class="cleara11y-pages-container" data-loading="false">
						<div class="cleara11y-loading-spinner">
							<span class="spinner is-active"></span>
							<?php esc_html_e('Loading pages...', 'cleara11y'); ?>
						</div>
					</div>
				</div>

			</div>
		</div>
		<?php
	}

	/**
	 * Get dashboard statistics.
	 *
	 * @return array
	 */
	private static function get_stats(): array {
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

		return $stats;
	}

	/**
	 * Get recent scans.
	 *
	 * @return array
	 */
	private static function get_recent_scans(): array {
		return \ClearA11y\Database\Scan_Repository::get_all([
			'limit' => 10,
			'orderby' => 'created_at',
			'order' => 'DESC',
		]);
	}
}
