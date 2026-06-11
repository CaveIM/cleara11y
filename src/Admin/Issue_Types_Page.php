<?php
/**
 * Issue Types Page
 *
 * Page for viewing and managing individual issue types (grouped by rule).
 *
 * @package ClearA11y
 * @namespace ClearA11y\Admin
 */

namespace ClearA11y\Admin;

use ClearA11y\Database\Schema;

/**
 * Issue Types Page Class
 */
class Issue_Types_Page {

	/**
	 * Get instance of class.
	 *
	 * @return Issue_Types_Page
	 */
	public static function get_instance(): Issue_Types_Page {
		static $instance = null;
		if (null === $instance) {
			$instance = new self();
		}
		return $instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		// Actions will be registered in Admin class
	}

	/**
	 * Register the page.
	 *
	 * @return void
	 */
	public function register_page(): void {
		add_submenu_page(
			'cleara11y',
			__('Issue Types', 'cleara11y'),
			__('Issue Types', 'cleara11y'),
			'manage_options',
			'cleara11y-issue-types',
			[$this, 'render_page']
		);
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		// Ensure tables exist
		if (!Schema::tables_exist()) {
			echo '<div class="wrap">';
			echo '<h1>' . esc_html__('Accessibility Issue Types', 'cleara11y') . '</h1>';
			echo '<div class="notice notice-warning"><p>';
			esc_html_e('Database tables not found. Please scan a page first.', 'cleara11y');
			echo '</p></div>';
			echo '</div>';
			return;
		}

		?>
		<div class="wrap cleara11y-issue-types-wrap">
			<h1><?php esc_html_e('Accessibility Issue Types', 'cleara11y'); ?></h1>

			<div id="cleara11y-issue-types-app">
				<!-- Filters -->
				<div class="cleara11y-filters">
					<h2 class="screen-reader-text"><?php esc_html_e('Filter Issues', 'cleara11y'); ?></h2>

					<div class="cleara11y-filter-controls">
						<select id="cleara11y-severity-filter">
							<option value=""><?php esc_html_e('All Severities', 'cleara11y'); ?></option>
							<option value="critical"><?php esc_html_e('Critical', 'cleara11y'); ?></option>
							<option value="moderate"><?php esc_html_e('Moderate', 'cleara11y'); ?></option>
							<option value="minor"><?php esc_html_e('Minor', 'cleara11y'); ?></option>
						</select>

						<input type="search" id="cleara11y-issue-search" placeholder="<?php esc_attr_e('Search issues...', 'cleara11y'); ?>" />
					</div>
				</div>

				<!-- Stats -->
				<div class="cleara11y-stats-grid" id="cleara11y-stats-grid"></div>

				<!-- Issue Types List -->
				<div class="cleara11y-issue-types-list" id="cleara11y-issue-types-list">
					<div class="cleara11y-loading">
						<span class="spinner is-active"></span>
						<?php esc_html_e('Loading issue types...', 'cleara11y'); ?>
					</div>
				</div>
			</div>

			<!-- Modal for Issue Pages -->
			<div id="cleara11y-pages-modal" class="cleara11y-modal" style="display: none;">
				<div class="cleara11y-modal-content">
					<div class="cleara11y-modal-header">
						<h2 id="cleara11y-modal-title"></h2>
						<button class="cleara11y-modal-close" aria-label="<?php esc_attr_e('Close modal', 'cleara11y'); ?>">&times;</button>
					</div>
					<div class="cleara11y-modal-body" id="cleara11y-modal-body">
						<div class="cleara11y-loading">
							<span class="spinner is-active"></span>
							<?php esc_html_e('Loading pages...', 'cleara11y'); ?>
						</div>
					</div>
				</div>
			</div>

		</div>

		<style>
			.cleara11y-issue-types-wrap {
				max-width: 1400px;
				margin: 0 auto;
			}

			.cleara11y-filters {
				background: #fff;
				padding: 20px;
				border: 1px solid #c3c4c7;
				border-radius: 4px;
				margin-bottom: 20px;
			}

			.cleara11y-filter-tabs {
				display: flex;
				gap: 10px;
				margin-bottom: 15px;
			}

			.cleara11y-filter-tab {
				padding: 8px 16px;
				background: #f0f0f1;
				border: 1px solid #c3c4c7;
				border-radius: 4px;
				cursor: pointer;
				display: flex;
				align-items: center;
				gap: 8px;
			}

			.cleara11y-filter-tab:hover {
				background: #e5e5e6;
			}

			.cleara11y-filter-tab.active {
				background: #2271b1;
				color: #fff;
				border-color: #2271b1;
			}

			.cleara11y-filter-tab .count {
				background: rgba(0, 0, 0, 0.1);
				padding: 2px 6px;
				border-radius: 10px;
				font-size: 12px;
			}

			.cleara11y-filter-tab.active .count {
				background: rgba(255, 255, 255, 0.2);
			}

			.cleara11y-filter-controls {
				display: flex;
				gap: 10px;
				flex-wrap: wrap;
			}

			.cleara11y-filter-controls select,
			.cleara11y-filter-controls input {
				padding: 8px 12px;
				border: 1px solid #c3c4c7;
				border-radius: 4px;
			}

			.cleara11y-filter-controls input[type="search"] {
				flex: 1;
				min-width: 200px;
			}

			.cleara11y-stats-grid {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
				gap: 15px;
				margin-bottom: 20px;
			}

			.cleara11y-stat-card {
				background: #fff;
				padding: 15px;
				border: 1px solid #c3c4c7;
				border-radius: 4px;
				text-align: center;
			}

			.cleara11y-stat-card .stat-label {
				font-size: 14px;
				color: #646970;
				margin-bottom: 5px;
			}

			.cleara11y-stat-card .stat-value {
				font-size: 28px;
				font-weight: 600;
			}

			.cleara11y-issue-types-list {
				background: #fff;
				border: 1px solid #c3c4c7;
				border-radius: 4px;
			}

			.cleara11y-issue-type-item {
				display: grid;
				grid-template-columns: 80px 120px 1fr auto;
				gap: 20px;
				padding: 20px;
				border-bottom: 1px solid #c3c4c7;
				align-items: center;
			}

			.cleara11y-issue-type-item:last-child {
				border-bottom: none;
			}

			.cleara11y-issue-type-item:hover {
				background: #f6f7f7;
			}

			.cleara11y-issue-severity {
				font-weight: 600;
				text-transform: uppercase;
				font-size: 12px;
				padding: 4px 8px;
				border-radius: 3px;
				text-align: center;
			}

			.cleara11y-issue-severity.critical {
				background: #f66565;
				color: #fff;
			}

			.cleara11y-issue-severity.moderate {
				background: #f5a623;
				color: #fff;
			}

			.cleara11y-issue-severity.minor {
				background: #6dd4b6;
				color: #fff;
			}

			.cleara11y-issue-count {
				text-align: center;
			}

			.cleara11y-issue-count .number {
				font-size: 24px;
				font-weight: 600;
			}

			.cleara11y-issue-count .label {
				font-size: 12px;
				color: #646970;
			}

			.cleara11y-issue-info h3 {
				margin: 0 0 5px 0;
				font-size: 16px;
			}

			.cleara11y-issue-info .rule-id {
				color: #646970;
				font-size: 13px;
				font-family: monospace;
			}

			.cleara11y-issue-info .message {
				margin: 10px 0;
				color: #1d2327;
			}

			.cleara11y-issue-actions {
				display: flex;
				gap: 10px;
			}

			.cleara11y-modal {
				position: fixed;
				top: 0;
				left: 0;
				width: 100%;
				height: 100%;
				background: rgba(0, 0, 0, 0.5);
				z-index: 100000;
				display: flex;
				align-items: center;
				justify-content: center;
			}

			.cleara11y-modal-content {
				background: #fff;
				border-radius: 4px;
				width: 90%;
				max-width: 800px;
				max-height: 90vh;
				overflow: hidden;
				display: flex;
				flex-direction: column;
			}

			.cleara11y-modal-small {
				max-width: 500px;
			}

			.cleara11y-modal-header {
				padding: 20px;
				border-bottom: 1px solid #c3c4c7;
				display: flex;
				justify-content: space-between;
				align-items: center;
			}

			.cleara11y-modal-header h2 {
				margin: 0;
				font-size: 20px;
			}

			.cleara11y-modal-close {
				background: none;
				border: none;
				font-size: 24px;
				cursor: pointer;
				padding: 0;
				width: 30px;
				height: 30px;
				line-height: 1;
			}

			.cleara11y-modal-body {
				padding: 20px;
				overflow-y: auto;
			}

			.cleara11y-modal-footer {
				padding: 20px;
				border-top: 1px solid #c3c4c7;
				display: flex;
				justify-content: flex-end;
				gap: 10px;
			}

			.cleara11y-pages-list {
				list-style: none;
				margin: 0;
				padding: 0;
			}

			.cleara11y-page-item {
				padding: 15px;
				border: 1px solid #c3c4c7;
				border-radius: 4px;
				margin-bottom: 10px;
				display: flex;
				justify-content: space-between;
				align-items: center;
			}

			.cleara11y-page-info {
				flex: 1;
			}

			.cleara11y-page-title {
				font-weight: 600;
				margin-bottom: 5px;
			}

			.cleara11y-page-url {
				color: #2271b1;
				font-size: 13px;
			}

			.cleara11y-loading {
				text-align: center;
				padding: 40px;
			}

			.cleara11y-loading .spinner {
				float: none;
				margin: 0 auto 10px;
			}

			.cleara11y-ignore-modal textarea {
				width: 100%;
				padding: 10px;
				border: 1px solid #c3c4c7;
				border-radius: 4px;
				margin-top: 10px;
			}

			.cleara11y-empty-state {
				text-align: center;
				padding: 60px 20px;
				color: #646970;
			}

			.cleara11y-empty-state .dashicons {
				font-size: 64px;
				width: 64px;
				height: 64px;
				margin-bottom: 20px;
			}
		</style>
		<?php
	}
}
