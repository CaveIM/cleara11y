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

					<label for="cleara11y-filter-status" style="font-weight: 600;">
						<?php esc_html_e('Status:', 'cleara11y'); ?>
					</label>
					<select id="cleara11y-filter-status" class="regular-text">
						<option value="active"><?php esc_html_e('Active Issues', 'cleara11y'); ?></option>
						<option value="exceptions"><?php esc_html_e('Exceptions', 'cleara11y'); ?></option>
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

				<!-- View Switcher -->
				<div class="cleara11y-view-switcher" style="margin: 20px 0; padding: 0; background: #fff; border: 1px solid #c3c4c7; border-bottom: none;">
					<div class="cleara11y-view-tabs" style="display: flex; gap: 0;">
						<button class="cleara11y-view-tab active" data-view="by-issue-type" style="padding: 12px 20px; background: none; border: none; border-bottom: 3px solid #2271b1; cursor: pointer; transition: all 0.2s; color: #2271b1; font-weight: 600;">
							<?php esc_html_e('By Issue Type', 'cleara11y'); ?>
						</button>
						<button class="cleara11y-view-tab" data-view="by-page" style="padding: 12px 20px; background: none; border: none; border-bottom: 3px solid transparent; cursor: pointer; transition: all 0.2s;">
							<?php esc_html_e('By Page', 'cleara11y'); ?>
						</button>
						<button class="cleara11y-view-tab" data-view="by-severity" style="padding: 12px 20px; background: none; border: none; border-bottom: 3px solid transparent; cursor: pointer; transition: all 0.2s;">
							<?php esc_html_e('By Severity', 'cleara11y'); ?>
						</button>
						<button class="cleara11y-view-tab" data-view="by-content-type" style="padding: 12px 20px; background: none; border: none; border-bottom: 3px solid transparent; cursor: pointer; transition: all 0.2s;">
							<?php esc_html_e('By Content Type', 'cleara11y'); ?>
						</button>
						<button class="cleara11y-view-tab" data-view="exceptions" style="padding: 12px 20px; background: none; border: none; border-bottom: 3px solid transparent; cursor: pointer; transition: all 0.2s;">
							<?php esc_html_e('Exceptions', 'cleara11y'); ?>
						</button>
					</div>
				</div>

				<!-- Advanced Filters (view-specific) -->
				<div class="cleara11y-advanced-filters" style="margin: 0 0 20px 0; padding: 15px; background: #fff; border: 1px solid #c3c4c7; border-top: none; box-shadow: 0 1px 1px rgba(0,0,0,.04); display: none;">
					<div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
						<!-- Severity filter (reused from main filters) -->
						<select id="cleara11y-filter-severity-adv" class="regular-text" style="display: none;">
							<option value=""><?php esc_html_e('All Severities', 'cleara11y'); ?></option>
							<option value="critical"><?php esc_html_e('Critical', 'cleara11y'); ?></option>
							<option value="moderate"><?php esc_html_e('Moderate', 'cleara11y'); ?></option>
							<option value="minor"><?php esc_html_e('Minor', 'cleara11y'); ?></option>
						</select>

						<!-- Post type filter (for By Page and By Content Type views) -->
						<select id="cleara11y-filter-post-type" class="regular-text" style="display: none;">
							<option value=""><?php esc_html_e('All Content Types', 'cleara11y'); ?></option>
							<option value="page"><?php esc_html_e('Pages', 'cleara11y'); ?></option>
							<option value="post"><?php esc_html_e('Posts', 'cleara11y'); ?></option>
							<option value="product"><?php esc_html_e('Products', 'cleara11y'); ?></option>
						</select>

						<!-- WCAG criterion filter (for By Issue Type view) -->
						<select id="cleara11y-filter-wcag" class="regular-text" style="display: none;">
							<option value=""><?php esc_html_e('All WCAG', 'cleara11y'); ?></option>
							<option value="1.1.1"><?php esc_html_e('1.1.1 Text Alternatives', 'cleara11y'); ?></option>
							<option value="1.4.3"><?php esc_html_e('1.4.3 Contrast', 'cleara11y'); ?></option>
							<option value="2.1.1"><?php esc_html_e('2.1.1 Keyboard', 'cleara11y'); ?></option>
							<option value="2.4.6"><?php esc_html_e('2.4.6 Headings', 'cleara11y'); ?></option>
							<option value="4.1.1"><?php esc_html_e('4.1.1 Parsing', 'cleara11y'); ?></option>
						</select>

						<!-- Content type grouping (for By Content Type view) -->
						<select id="cleara11y-filter-content-type-grouping" class="regular-text" style="display: none;">
							<option value="post_type"><?php esc_html_e('Group by Post Type', 'cleara11y'); ?></option>
							<option value="template"><?php esc_html_e('Group by Template', 'cleara11y'); ?></option>
						</select>

						<!-- Search -->
						<input type="text" id="cleara11y-search-adv" class="regular-text" placeholder="<?php esc_attr_e('Search...', 'cleara11y'); ?>" style="display: none;">

						<!-- Sort -->
						<select id="cleara11y-sort-by" class="regular-text">
							<option value=""><?php esc_html_e('Sort by...', 'cleara11y'); ?></option>
							<!-- Options populated dynamically based on view -->
						</select>

						<button type="button" class="button" id="cleara11y-reset-filters-adv">
							<?php esc_html_e('Reset', 'cleara11y'); ?>
						</button>
					</div>
				</div>

				<div class="cleara11y-issues-stats" style="margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
					<div style="display: flex; gap: 30px; flex-wrap: wrap;">
						<!-- Active Issues Section -->
						<div class="cleara11y-stats-section active" style="flex: 1; min-width: 200px; padding: 15px; background: #f6f7f7; border: 1px solid #c3c4c7; border-radius: 4px;">
							<h4 style="margin: 0 0 10px 0; font-size: 13px; color: #646970; text-transform: uppercase; letter-spacing: 0.5px;">
								<?php esc_html_e('Active Issues', 'cleara11y'); ?>
							</h4>
							<div style="display: flex; flex-direction: column; gap: 8px;">
								<div style="display: flex; justify-content: space-between; align-items: center;">
									<span class="cleara11y-stat-label" style="color: #646970; font-size: 13px;">
										<?php esc_html_e('Total', 'cleara11y'); ?>
									</span>
									<span class="cleara11y-stat-value" id="cleara11y-active-total cleara11y-total-issues" style="font-weight: 600; font-size: 18px;">-</span>
								</div>
								<div style="display: flex; justify-content: space-between; align-items: center;">
									<span class="cleara11y-stat-label" style="color: #d63638; font-size: 13px;">
										<?php esc_html_e('Critical', 'cleara11y'); ?>
									</span>
									<span class="cleara11y-stat-value" id="cleara11y-active-critical cleara11y-critical-issues" style="font-weight: 600; font-size: 18px; color: #d63638;">-</span>
								</div>
								<div style="display: flex; justify-content: space-between; align-items: center;">
									<span class="cleara11y-stat-label" style="color: #f56e28; font-size: 13px;">
										<?php esc_html_e('Moderate', 'cleara11y'); ?>
									</span>
									<span class="cleara11y-stat-value" id="cleara11y-active-moderate cleara11y-moderate-issues" style="font-weight: 600; font-size: 18px; color: #f56e28;">-</span>
								</div>
								<div style="display: flex; justify-content: space-between; align-items: center;">
									<span class="cleara11y-stat-label" style="color: #ffb900; font-size: 13px;">
										<?php esc_html_e('Minor', 'cleara11y'); ?>
									</span>
									<span class="cleara11y-stat-value" id="cleara11y-active-minor cleara11y-minor-issues" style="font-weight: 600; font-size: 18px; color: #ffb900;">-</span>
								</div>
							</div>
						</div>

						<!-- Exceptions Section -->
						<div class="cleara11y-stats-section exceptions" style="flex: 1; min-width: 200px; padding: 15px; background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #646970; border-radius: 4px;">
							<h4 style="margin: 0 0 10px 0; font-size: 13px; color: #646970; text-transform: uppercase; letter-spacing: 0.5px;">
								<?php esc_html_e('Exceptions', 'cleara11y'); ?>
							</h4>
							<div style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px;">
								<span class="cleara11y-stat-label" style="color: #646970; font-size: 13px;">
									<?php esc_html_e('Total Exceptions', 'cleara11y'); ?>
								</span>
								<span class="cleara11y-stat-value" id="cleara11y-exceptions-total cleara11y-ignored-issues" style="font-weight: 600; font-size: 18px; color: #646970;">-</span>
							</div>
						</div>
					</div>
				</div>

				<!-- Dynamic view content rendered via JavaScript -->
				<div id="cleara11y-view-content">
					<div class="cleara11y-loading-spinner" style="text-align: center; padding: 40px;">
						<span class="spinner is-active" style="float: none; margin: 0;"></span>
						<p style="margin-top: 15px;"><?php esc_html_e('Loading issues...', 'cleara11y'); ?></p>
					</div>
				</div>
			<!-- Old issues-list container (hidden, used for fallback) -->
			<div class="cleara11y-issues-container" style="margin-top: 20px; display: none;">
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
