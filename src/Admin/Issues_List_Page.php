<?php
/**
 * Issues List Page
 *
 * Renders the admin page for listing all accessibility issues.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Admin
 */

namespace ClearA11y\Admin;

/**
 * Issues List Page Class
 */
class Issues_List_Page {

	/**
	 * Single instance of the class.
	 *
	 * @var Issues_List_Page|null
	 */
	private static ?Issues_List_Page $instance = null;

	/**
	 * Get the single instance of the class.
	 *
	 * @return Issues_List_Page
	 */
	public static function get_instance(): Issues_List_Page {
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
	 * Render the issues list page.
	 */
	public static function render(): void {
		?>
		<div class="wrap cleara11y-issues-list-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e('Accessibility Issues', 'cleara11y'); ?></h1>
			<hr class="wp-header-end">

			<!-- Bulk Actions Bar -->
			<div class="cleara11y-bulk-actions" style="display: none; margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 1px 1px rgba(0,0,0,.04); align-items: center; gap: 15px;">
				<strong><?php esc_html_e('Bulk Actions:', 'cleara11y'); ?></strong>
				<button type="button" class="button button-primary" id="cleara11y-bulk-dismiss">
					<span class="dashicons dashicons-hidden" style="margin-top: 3px;"></span>
					<?php esc_html_e('Dismiss Selected', 'cleara11y'); ?>
				</button>
				<span class="cleara11y-selected-count" style="color: #646970;"></span>
			</div>

			<div class="cleara11y-issues-filters" style="margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
				<div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
					<label for="cleara11y-filter-severity" style="font-weight: 600;">
						<?php esc_html_e('Severity:', 'cleara11y'); ?>
					</label>
					<select id="cleara11y-filter-severity" class="regular-text">
						<option value=""><?php esc_html_e('All Severities', 'cleara11y'); ?></option>
						<option value="critical"><?php esc_html_e('Critical', 'cleara11y'); ?></option>
						<option value="moderate"><?php esc_html_e('Moderate', 'cleara11y'); ?></option>
						<option value="minor"><?php esc_html_e('Minor', 'cleara11y'); ?></option>
					</select>

					<label for="cleara11y-filter-dismissed" style="font-weight: 600;">
						<?php esc_html_e('Status:', 'cleara11y'); ?>
					</label>
					<select id="cleara11y-filter-dismissed" class="regular-text">
						<option value="active"><?php esc_html_e('Active', 'cleara11y'); ?></option>
						<option value="dismissed"><?php esc_html_e('Dismissed', 'cleara11y'); ?></option>
						<option value="all"><?php esc_html_e('All', 'cleara11y'); ?></option>
					</select>

					<label for="cleara11y-search-issues" style="font-weight: 600;">
						<?php esc_html_e('Search:', 'cleara11y'); ?>
					</label>
					<input type="text" id="cleara11y-search-issues" class="regular-text" placeholder="<?php esc_attr_e('Search by rule, page, or URL...', 'cleara11y'); ?>">

					<button type="button" class="button" id="cleara11y-reset-filters">
						<?php esc_html_e('Reset', 'cleara11y'); ?>
					</button>
				</div>
			</div>

			<div class="cleara11y-issues-stats" style="margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
				<div style="display: flex; gap: 30px;">
					<div>
						<span class="cleara11y-stat-label" style="color: #646970;"><?php esc_html_e('Total Issues:', 'cleara11y'); ?></span>
						<span class="cleara11y-stat-value" id="cleara11y-total-issues" style="font-weight: 600; margin-left: 5px;">-</span>
					</div>
					<div>
						<span class="cleara11y-stat-label" style="color: #d63638;"><?php esc_html_e('Critical:', 'cleara11y'); ?></span>
						<span class="cleara11y-stat-value" id="cleara11y-critical-issues" style="font-weight: 600; margin-left: 5px;">-</span>
					</div>
					<div>
						<span class="cleara11y-stat-label" style="color: #f56e28;"><?php esc_html_e('Moderate:', 'cleara11y'); ?></span>
						<span class="cleara11y-stat-value" id="cleara11y-moderate-issues" style="font-weight: 600; margin-left: 5px;">-</span>
					</div>
					<div>
						<span class="cleara11y-stat-label" style="color: #ffb900;"><?php esc_html_e('Minor:', 'cleara11y'); ?></span>
						<span class="cleara11y-stat-value" id="cleara11y-minor-issues" style="font-weight: 600; margin-left: 5px;">-</span>
					</div>
					<div>
						<span class="cleara11y-stat-label" style="color: #646970;"><?php esc_html_e('Dismissed:', 'cleara11y'); ?></span>
						<span class="cleara11y-stat-value" id="cleara11y-dismissed-issues" style="font-weight: 600; margin-left: 5px; color: #646970;">-</span>
					</div>
				</div>
			</div>

			<div class="cleara11y-issues-container" style="margin-top: 20px;">
				<div class="cleara11y-loading-spinner" style="text-align: center; padding: 40px;">
					<span class="spinner is-active" style="float: none; margin: 0;"></span>
					<p style="margin-top: 15px;"><?php esc_html_e('Loading issues...', 'cleara11y'); ?></p>
				</div>
			</div>

			<!-- Pagination -->
			<div class="cleara11y-pagination" style="margin: 20px 0; display: none; justify-content: center; align-items: center; gap: 15px;">
				<button type="button" class="button" id="cleara11y-prev-page" disabled>
					<span class="dashicons dashicons-arrow-left-alt2"></span>
					<?php esc_html_e('Previous', 'cleara11y'); ?>
				</button>
				<span id="cleara11y-page-info" style="font-weight: 600;">Page 1 of 1</span>
				<button type="button" class="button" id="cleara11y-next-page" disabled>
					<?php esc_html_e('Next', 'cleara11y'); ?>
					<span class="dashicons dashicons-arrow-right-alt2"></span>
				</button>
			</div>
		</div>

		<!-- Issue Detail Modal -->
		<div id="cleara11y-issue-modal" class="cleara11y-modal-overlay" style="display: none;">
			<div class="cleara11y-modal" style="max-width: 700px;">
				<div class="cleara11y-modal-header">
					<h3 class="cleara11y-modal-title">Issue Details</h3>
					<button type="button" class="cleara11y-modal-close">×</button>
				</div>
				<div class="cleara11y-modal-body" style="max-height: 70vh; overflow-y: auto;">
					<div class="cleara11y-issue-detail-content"></div>
				</div>
				<div class="cleara11y-modal-footer">
					<button type="button" class="button cleara11y-modal-close-btn"><?php esc_html_e('Close', 'cleara11y'); ?></button>
				</div>
			</div>
		</div>
		<?php
	}
}
