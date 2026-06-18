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
		add_action('admin_init', [$this, 'handle_automated_scan_settings']);
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
	 * Handle automated scan settings save.
	 */
	public function handle_automated_scan_settings(): void {
		// Check if the automated scan settings form was submitted
		if (!isset($_POST['cleara11y_save_automated_settings'])) {
			return;
		}

		// Verify nonce
		if (!check_admin_referer('cleara11y_automated_settings')) {
			wp_die(__('Security check failed.', 'cleara11y'));
		}

		// Check permissions
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have permission to perform this action.', 'cleara11y'));
		}

		// Get and sanitize settings
		$enabled = isset($_POST['cleara11y_automated_enabled']) ? 1 : 0;
		$frequency = isset($_POST['cleara11y_automated_frequency']) ? sanitize_text_field(wp_unslash($_POST['cleara11y_automated_frequency'])) : 'weekly';

		// Validate frequency
		$valid_frequencies = ['daily', 'weekly', 'monthly'];
		if (!in_array($frequency, $valid_frequencies, true)) {
			$frequency = 'weekly'; // Default to weekly if invalid
		}

		// Save settings
		update_option('cleara11y_automated_enabled', $enabled);
		update_option('cleara11y_automated_frequency', $frequency);

		// Schedule or unschedule the cron job
		if ($enabled) {
			$this->schedule_automated_scan($frequency);
		} else {
			$this->unschedule_automated_scan();
		}

		// Add success notice
		add_action('admin_notices', function() {
			echo '<div class="notice notice-success is-dismissible"><p>';
			echo '<strong>' . esc_html__('Success!', 'cleara11y') . '</strong> ';
			esc_html_e('Automated scan settings have been saved.', 'cleara11y');
			echo '</p></div>';
		});
	}

	/**
	 * Schedule automated scan cron job.
	 *
	 * @param string $frequency Scan frequency (daily, weekly, monthly).
	 */
	private function schedule_automated_scan(string $frequency): void {
		// Clear any existing scheduled event
		wp_clear_scheduled_hook('cleara11y_automated_scan');

		// Map frequency to custom schedule names
		$schedule_map = [
			'daily' => 'cleara11y_daily',
			'weekly' => 'cleara11y_weekly',
			'monthly' => 'cleara11y_monthly',
		];

		$schedule = $schedule_map[$frequency] ?? 'cleara11y_weekly';

		// Schedule with custom interval
		wp_schedule_event(time(), $schedule, 'cleara11y_automated_scan');
	}

	/**
	 * Unschedule automated scan cron job.
	 */
	private function unschedule_automated_scan(): void {
		$timestamp = wp_next_scheduled('cleara11y_automated_scan');
		if ($timestamp) {
			wp_unschedule_event($timestamp, 'cleara11y_automated_scan');
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
									<li><?php esc_html_e('All exceptions and audit history', 'cleara11y'); ?></li>
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

				<!-- Automated Scanning Section -->
				<div class="cleara11y-card cleara11y-settings-section">
					<h2>
						<span class="dashicons dashicons-clock"></span>
						<?php esc_html_e('Automated Scanning', 'cleara11y'); ?>
					</h2>

					<p class="description">
						<?php esc_html_e('Configure automated accessibility scans to run on a regular schedule. Automated scans run through WordPress cron and depend on site traffic or server cron triggering WP-Cron.', 'cleara11y'); ?>
					</p>

					<form method="post" id="cleara11y-automated-settings-form">
						<?php wp_nonce_field('cleara11y_automated_settings'); ?>

						<table class="form-table">
							<tr>
								<th scope="row">
									<label for="cleara11y_automated_enabled">
										<?php esc_html_e('Enable Automated Scans', 'cleara11y'); ?>
									</label>
								</th>
								<td>
									<input type="checkbox"
										name="cleara11y_automated_enabled"
										id="cleara11y_automated_enabled"
										value="1"
										<?php checked(get_option('cleara11y_automated_enabled', 0), 1); ?>>
									<label for="cleara11y_automated_enabled">
										<?php esc_html_e('Enable automated accessibility scans', 'cleara11y'); ?>
									</label>
									<p class="description">
										<?php esc_html_e('When enabled, scans will run automatically according to the schedule below.', 'cleara11y'); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="cleara11y_automated_frequency">
										<?php esc_html_e('Scan Frequency', 'cleara11y'); ?>
									</label>
								</th>
								<td>
									<select name="cleara11y_automated_frequency"
										id="cleara11y_automated_frequency">
										<option value="daily" <?php selected(get_option('cleara11y_automated_frequency', 'weekly'), 'daily'); ?>>
											<?php esc_html_e('Daily', 'cleara11y'); ?>
										</option>
										<option value="weekly" <?php selected(get_option('cleara11y_automated_frequency', 'weekly'), 'weekly'); ?>>
											<?php esc_html_e('Weekly', 'cleara11y'); ?>
										</option>
										<option value="monthly" <?php selected(get_option('cleara11y_automated_frequency', 'weekly'), 'monthly'); ?>>
											<?php esc_html_e('Monthly', 'cleara11y'); ?>
										</option>
									</select>
									<p class="description">
										<?php esc_html_e('How often to run automated accessibility scans.', 'cleara11y'); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<?php esc_html_e('Current Status', 'cleara11y'); ?>
								</th>
								<td>
									<?php
									$enabled = get_option('cleara11y_automated_enabled', 0);
									$next_scheduled = wp_next_scheduled('cleara11y_automated_scan');
									?>
									<?php if ($enabled) : ?>
										<div class="cleara11y-status-enabled">
											<span class="dashicons dashicons-yes-alt"></span>
											<strong><?php esc_html_e('Enabled', 'cleara11y'); ?></strong>
											<?php if ($next_scheduled) : ?>
												<br>
												<small>
													<?php
													echo esc_html(sprintf(
														__('Next scan: %s', 'cleara11y'),
														date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_scheduled)
													));
													?>
												</small>
											<?php endif; ?>
										</div>
									<?php else : ?>
										<div class="cleara11y-status-disabled">
											<span class="dashicons dashicons-no-alt"></span>
											<strong><?php esc_html_e('Disabled', 'cleara11y'); ?></strong>
											<br>
											<small><?php esc_html_e('Manual scans only', 'cleara11y'); ?></small>
										</div>
									<?php endif; ?>
								</td>
							</tr>
						</table>

						<?php submit_button(__('Save Automated Settings', 'cleara11y'), 'primary', 'cleara11y_save_automated_settings'); ?>
					</form>
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

		.cleara11y-status-enabled {
			color: #00a32a;
		}

		.cleara11y-status-enabled .dashicons {
			color: #00a32a;
		}

		.cleara11y-status-disabled {
			color: #646970;
		}

		.cleara11y-status-disabled .dashicons {
			color: #646970;
		}

		.cleara11y-status-enabled,
		.cleara11y-status-disabled {
			display: flex;
			align-items: center;
			gap: 8px;
		}
		</style>
		<?php
	}
}
