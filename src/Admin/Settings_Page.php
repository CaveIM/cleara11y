<?php
/**
 * Settings Page
 *
 * Renders the admin settings page.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Admin
 */

namespace ClearA11y\Admin;

/**
 * Settings Page Class
 */
class Settings_Page {

	/**
	 * Single instance of the class.
	 *
	 * @var Settings_Page|null
	 */
	private static ?Settings_Page $instance = null;

	/**
	 * Get the single instance of the class.
	 *
	 * @return Settings_Page
	 */
	public static function get_instance(): Settings_Page {
		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action('admin_init', [$this, 'handle_database_clear']);
	}

	/**
	 * Handle database clear action.
	 */
	public function handle_database_clear(): void {
		// Check if the clear database form was submitted
		if (!isset($_POST['cleara11y_clear_database'])) {
			return;
		}

		// Verify nonce
		if (!check_admin_referer('cleara11y_clear_database')) {
			wp_die(__('Security check failed.', 'cleara11y'));
		}

		// Check permissions
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have permission to perform this action.', 'cleara11y'));
		}

		// Clear the database by dropping and recreating tables
		$result = \ClearA11y\Database\Schema::recreate_tables();

		if ($result) {
			// Add success notice
			add_action('admin_notices', function() {
				echo '<div class="notice notice-success is-dismissible"><p>';
				echo '<strong>' . esc_html__('Success!', 'cleara11y') . '</strong> ';
				esc_html_e('All scan data has been cleared from the database.', 'cleara11y');
				echo '</p></div>';
			});
		} else {
			// Add error notice
			add_action('admin_notices', function() {
				echo '<div class="notice notice-error is-dismissible"><p>';
				echo '<strong>' . esc_html__('Error!', 'cleara11y') . '</strong> ';
				esc_html_e('Failed to clear the database. Please check error logs.', 'cleara11y');
				echo '</p></div>';
			});
		}
	}

	/**
	 * Get database statistics.
	 *
	 * @return array Database statistics.
	 */
	private function get_database_stats(): array {
		global $wpdb;

		$scans_table = \ClearA11y\Database\Schema::get_table_name('scans');
		$scan_items_table = \ClearA11y\Database\Schema::get_table_name('scan_items');
		$issues_table = \ClearA11y\Database\Schema::get_table_name('issues');
		$schedules_table = \ClearA11y\Database\Schema::get_table_name('schedules');
		$scan_jobs_table = \ClearA11y\Database\Schema::get_table_name('scan_jobs');

		return [
			'scans' => (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$scans_table}`"),
			'scan_items' => (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$scan_items_table}`"),
			'issues' => (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$issues_table}`"),
			'schedules' => (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$schedules_table}`"),
			'scan_jobs' => (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$scan_jobs_table}`"),
		];
	}

	/**
	 * Render the settings page.
	 */
	public static function render(): void {
		$instance = self::get_instance();
		$stats = $instance->get_database_stats();

		$total_records = $stats['scans'] + $stats['scan_items'] + $stats['issues'] + $stats['schedules'] + $stats['scan_jobs'];
		?>
		<div class="wrap cleara11y-settings-wrap">
			<h1><?php esc_html_e('ClearA11y Settings', 'cleara11y'); ?></h1>
			<hr class="wp-header-end">

			<div class="cleara11y-settings-container">

				<!-- Database Management Section -->
				<div class="cleara11y-card cleara11y-settings-section">
					<h2>
						<span class="dashicons dashicons-database"></span>
						<?php esc_html_e('Database Management', 'cleara11y'); ?>
					</h2>

					<p class="description">
						<?php esc_html_e('This section allows you to manage the scan data stored in the database. Clearing the database will remove all scan results, issues, and scan history.', 'cleara11y'); ?>
					</p>

					<!-- Database Statistics -->
					<div class="cleara11y-database-stats">
						<h3><?php esc_html_e('Current Database Contents', 'cleara11y'); ?></h3>
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th><?php esc_html_e('Table', 'cleara11y'); ?></th>
									<th><?php esc_html_e('Records', 'cleara11y'); ?></th>
								</tr>
							</thead>
							<tbody>
								<tr>
									<td><code><?php echo esc_html(\ClearA11y\Database\Schema::get_table_name('scans')); ?></code></td>
									<td><?php echo esc_html(number_format($stats['scans'])); ?></td>
								</tr>
								<tr>
									<td><code><?php echo esc_html(\ClearA11y\Database\Schema::get_table_name('scan_items')); ?></code></td>
									<td><?php echo esc_html(number_format($stats['scan_items'])); ?></td>
								</tr>
								<tr>
									<td><code><?php echo esc_html(\ClearA11y\Database\Schema::get_table_name('issues')); ?></code></td>
									<td><?php echo esc_html(number_format($stats['issues'])); ?></td>
								</tr>
								<tr>
									<td><code><?php echo esc_html(\ClearA11y\Database\Schema::get_table_name('schedules')); ?></code></td>
									<td><?php echo esc_html(number_format($stats['schedules'])); ?></td>
								</tr>
								<tr>
									<td><code><?php echo esc_html(\ClearA11y\Database\Schema::get_table_name('scan_jobs')); ?></code></td>
									<td><?php echo esc_html(number_format($stats['scan_jobs'])); ?></td>
								</tr>
							</tbody>
							<tfoot>
								<tr class="cleara11y-total-row">
									<td><strong><?php esc_html_e('Total Records', 'cleara11y'); ?></strong></td>
									<td><strong><?php echo esc_html(number_format($total_records)); ?></strong></td>
								</tr>
							</tfoot>
						</table>
					</div>

					<!-- Clear Database Form -->
					<div class="cleara11y-clear-database-section">
						<h3><?php esc_html_e('Clear Scan Database', 'cleara11y'); ?></h3>

						<div class="cleara11y-warning-box">
							<span class="dashicons dashicons-warning"></span>
							<div class="cleara11y-warning-content">
								<h4><?php esc_html_e('Warning: This action cannot be undone!', 'cleara11y'); ?></h4>
								<p>
									<?php esc_html_e('Clearing the database will permanently delete all scan data including:', 'cleara11y'); ?>
								</p>
								<ul>
									<li><?php esc_html_e('All scan history and records', 'cleara11y'); ?></li>
									<li><?php esc_html_e('All accessibility issues found', 'cleara11y'); ?></li>
									<li><?php esc_html_e('All dismissed issues', 'cleara11y'); ?></li>
									<li><?php esc_html_e('All scheduled scan configurations', 'cleara11y'); ?></li>
								</ul>
							</div>
						</div>

						<form method="post" id="cleara11y-clear-database-form">
							<?php wp_nonce_field('cleara11y_clear_database'); ?>
							<input type="submit"
								name="cleara11y_clear_database"
								id="cleara11y-clear-database-btn"
								class="button button-secondary"
								value="<?php esc_attr_e('Clear Scan Database', 'cleara11y'); ?>">
						</form>
					</div>
				</div>

			</div>
		</div>

		<script>
		jQuery(document).ready(function($) {
			$('#cleara11y-clear-database-btn').on('click', function(e) {
				e.preventDefault();

				var message = '<?php esc_html_e('Are you sure you want to clear the scan database? This action cannot be undone and will delete all scan data.', 'cleara11y'); ?>';

				if (!confirm(message)) {
					return;
				}

				if (!confirm('<?php esc_html_e('This is your last chance! Click OK to permanently delete all scan data, or Cancel to keep your data.', 'cleara11y'); ?>')) {
					return;
				}

				$('#cleara11y-clear-database-form').submit();
			});
		});
		</script>

		<style>
		.cleara11y-settings-wrap {
			max-width: 1200px;
		}

		.cleara11y-settings-container {
			margin-top: 20px;
		}

		.cleara11y-card {
			background: #fff;
			border: 1px solid #c3c4c7;
			padding: 20px;
			margin-bottom: 20px;
			box-shadow: 0 1px 1px rgba(0,0,0,.04);
		}

		.cleara11y-card h2 {
			margin-top: 0;
			padding-bottom: 10px;
			border-bottom: 1px solid #ddd;
			display: flex;
			align-items: center;
			gap: 10px;
		}

		.cleara11y-card h2 .dashicons {
			color: #2271b1;
		}

		.cleara11y-database-stats {
			margin: 20px 0;
		}

		.cleara11y-database-stats h3 {
			margin-bottom: 10px;
		}

		.cleara11y-database-stats code {
			background: #f0f0f1;
			padding: 2px 6px;
			border-radius: 3px;
			font-size: 13px;
		}

		.cleara11y-total-row {
			background: #f6f7f7;
		}

		.cleara11y-clear-database-section {
			margin-top: 30px;
			padding-top: 20px;
			border-top: 1px solid #ddd;
		}

		.cleara11y-clear-database-section h3 {
			color: #d63638;
			margin-bottom: 15px;
		}

		.cleara11y-warning-box {
			background: #fff;
			border-left: 4px solid #d63638;
			padding: 15px;
			margin-bottom: 20px;
			display: flex;
			gap: 15px;
		}

		.cleara11y-warning-box .dashicons {
			color: #d63638;
			font-size: 24px;
			flex-shrink: 0;
		}

		.cleara11y-warning-content h4 {
			margin: 0 0 10px 0;
			color: #d63638;
		}

		.cleara11y-warning-content p {
			margin: 0 0 10px 0;
		}

		.cleara11y-warning-content ul {
			margin: 0;
			padding-left: 20px;
		}

		.cleara11y-warning-content li {
			margin-bottom: 5px;
		}
		</style>
		<?php
	}
}
