<?php
/**
 * Scan detail admin page.
 *
 * Renders a dedicated backend page for one scan record with comprehensive issue analysis views.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Admin
 */

namespace ClearA11y\Admin;

use ClearA11y\Database\Scan_Item_Repository;
use ClearA11y\Database\Scan_Repository;
use ClearA11y\Models\Scan;
use ClearA11y\Models\Scan_Item;

/**
 * Scan Detail Page Class.
 */
class Scan_Detail_Page {

	/**
	 * Render the scan detail page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to view scan details.', 'cleara11y'));
		}

		$scan_id = isset($_GET['scan_id']) ? absint(wp_unslash($_GET['scan_id'])) : 0;
		if (!$scan_id) {
			wp_die(esc_html__('Invalid scan ID.', 'cleara11y'));
		}

		$scan = Scan_Repository::get_by_id($scan_id);
		if (!$scan) {
			wp_die(esc_html__('Scan not found.', 'cleara11y'));
		}

		?>
		<div class="wrap cleara11y-scan-detail-wrap">
			<h1 class="wp-heading-inline">
				<?php echo esc_html(sprintf(__('Scan #%d', 'cleara11y'), $scan->id)); ?>
			</h1>
			<a href="<?php echo esc_url(admin_url('admin.php?page=cleara11y-scans')); ?>" class="page-title-action">
				<?php esc_html_e('Back to Scans', 'cleara11y'); ?>
			</a>
			<hr class="wp-header-end">

			<?php self::render_summary($scan); ?>

			<!-- Issues Views Section -->
			<h2><?php esc_html_e('Issues Analysis', 'cleara11y'); ?></h2>

			<!-- View Switcher -->
			<div class="cleara11y-view-switcher" style="margin: 20px 0; padding: 0; background: #fff; border: 1px solid #c3c4c7; border-bottom: none;">
				<div class="cleara11y-view-tabs" style="display: flex; gap: 0;">
					<button class="cleara11y-view-tab active" data-view="by-page" style="padding: 12px 20px; background: none; border: none; border-bottom: 3px solid #2271b1; cursor: pointer; transition: all 0.2s; color: #2271b1; font-weight: 600;">
						<?php esc_html_e('By Page', 'cleara11y'); ?>
					</button>
					<button class="cleara11y-view-tab" data-view="by-issue-type" style="padding: 12px 20px; background: none; border: none; border-bottom: 3px solid transparent; cursor: pointer; transition: all 0.2s;">
						<?php esc_html_e('By Issue Type', 'cleara11y'); ?>
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
					<select id="cleara11y-filter-severity-adv" class="regular-text" style="display: none;">
						<option value=""><?php esc_html_e('All Severities', 'cleara11y'); ?></option>
						<option value="critical"><?php esc_html_e('Critical', 'cleara11y'); ?></option>
						<option value="moderate"><?php esc_html_e('Moderate', 'cleara11y'); ?></option>
						<option value="minor"><?php esc_html_e('Minor', 'cleara11y'); ?></option>
					</select>

					<select id="cleara11y-filter-post-type" class="regular-text" style="display: none;">
						<option value=""><?php esc_html_e('All Content Types', 'cleara11y'); ?></option>
						<option value="page"><?php esc_html_e('Pages', 'cleara11y'); ?></option>
						<option value="post"><?php esc_html_e('Posts', 'cleara11y'); ?></option>
						<option value="product"><?php esc_html_e('Products', 'cleara11y'); ?></option>
					</select>

					<select id="cleara11y-filter-wcag" class="regular-text" style="display: none;">
						<option value=""><?php esc_html_e('All WCAG', 'cleara11y'); ?></option>
						<option value="1.1.1"><?php esc_html_e('1.1.1 Text Alternatives', 'cleara11y'); ?></option>
						<option value="1.4.3"><?php esc_html_e('1.4.3 Contrast', 'cleara11y'); ?></option>
						<option value="2.1.1"><?php esc_html_e('2.1.1 Keyboard', 'cleara11y'); ?></option>
						<option value="2.4.6"><?php esc_html_e('2.4.6 Headings', 'cleara11y'); ?></option>
						<option value="4.1.1"><?php esc_html_e('4.1.1 Parsing', 'cleara11y'); ?></option>
					</select>

					<select id="cleara11y-filter-content-type-grouping" class="regular-text" style="display: none;">
						<option value="post_type"><?php esc_html_e('Group by Post Type', 'cleara11y'); ?></option>
						<option value="template"><?php esc_html_e('Group by Template', 'cleara11y'); ?></option>
					</select>

					<input type="text" id="cleara11y-search-adv" class="regular-text" placeholder="<?php esc_attr_e('Search...', 'cleara11y'); ?>" style="display: none;">

					<select id="cleara11y-sort-by" class="regular-text">
						<option value=""><?php esc_html_e('Sort by...', 'cleara11y'); ?></option>
					</select>

					<button type="button" class="button" id="cleara11y-reset-filters-adv">
						<?php esc_html_e('Reset', 'cleara11y'); ?>
					</button>
				</div>
			</div>

			<!-- Stats Display -->
			<div class="cleara11y-issues-stats" style="margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
				<div style="display: flex; gap: 30px; flex-wrap: wrap;">
					<!-- Active Issues Section -->
					<div class="cleara11y-stats-section active" style="flex: 1; min-width: 200px; padding: 15px; background: #f6f7f7; border: 1px solid #c3c4c7; border-radius: 4px;">
						<h4 style="margin: 0 0 10px 0; font-size: 13px; color: #646970; text-transform: uppercase; letter-spacing: 0.5px;">
							<?php esc_html_e('Active Issues (This Scan)', 'cleara11y'); ?>
						</h4>
						<div style="display: flex; flex-direction: column; gap: 8px;">
							<div style="display: flex; justify-content: space-between; align-items: center;">
								<span class="cleara11y-stat-label" style="color: #646970; font-size: 13px;">
									<?php esc_html_e('Total', 'cleara11y'); ?>
								</span>
								<span class="cleara11y-stat-value" id="cleara11y-active-total" style="font-weight: 600; font-size: 18px;">-</span>
							</div>
							<div style="display: flex; justify-content: space-between; align-items: center;">
								<span class="cleara11y-stat-label" style="color: #d63638; font-size: 13px;">
									<?php esc_html_e('Critical', 'cleara11y'); ?>
								</span>
								<span class="cleara11y-stat-value" id="cleara11y-active-critical" style="font-weight: 600; font-size: 18px; color: #d63638;">-</span>
							</div>
							<div style="display: flex; justify-content: space-between; align-items: center;">
								<span class="cleara11y-stat-label" style="color: #f56e28; font-size: 13px;">
									<?php esc_html_e('Moderate', 'cleara11y'); ?>
								</span>
								<span class="cleara11y-stat-value" id="cleara11y-active-moderate" style="font-weight: 600; font-size: 18px; color: #f56e28;">-</span>
							</div>
							<div style="display: flex; justify-content: space-between; align-items: center;">
								<span class="cleara11y-stat-label" style="color: #ffb900; font-size: 13px;">
									<?php esc_html_e('Minor', 'cleara11y'); ?>
								</span>
								<span class="cleara11y-stat-value" id="cleara11y-active-minor" style="font-weight: 600; font-size: 18px; color: #ffb900;">-</span>
							</div>
						</div>
					</div>

					<!-- Exceptions Section -->
					<div class="cleara11y-stats-section exceptions" style="flex: 1; min-width: 200px; padding: 15px; background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #646970; border-radius: 4px;">
						<h4 style="margin: 0 0 10px 0; font-size: 13px; color: #646970; text-transform: uppercase; letter-spacing: 0.5px;">
							<?php esc_html_e('Exceptions (This Scan)', 'cleara11y'); ?>
						</h4>
						<div style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px;">
							<span class="cleara11y-stat-label" style="color: #646970; font-size: 13px;">
								<?php esc_html_e('Total Exceptions', 'cleara11y'); ?>
							</span>
							<span class="cleara11y-stat-value" id="cleara11y-exceptions-total" style="font-weight: 600; font-size: 18px; color: #646970;">-</span>
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

			<!-- Scan Scope Note -->
			<p style="margin: 30px 0 20px; color: #646970; font-style: italic;">
				<?php esc_html_e('Note: Views show only issues from this scan. For all site issues, see the', 'cleara11y'); ?>
				<a href="<?php echo esc_url(admin_url('admin.php?page=cleara11y-issues')); ?>"><?php esc_html_e('Issues page', 'cleara11y'); ?></a>.
			</p>
		</div>

		<!-- Pass scan data to JavaScript -->
		<script>
			if (typeof cleara11yScanData === 'undefined') {
				var cleara11yScanData = {};
			}
			cleara11yScanData.scanId = <?php echo absint($scan_id); ?>;
			cleara11yScanData.apiUrl = '<?php echo esc_url(rest_url('cleara11y/v1/')); ?>';
			cleara11yScanData.nonce = '<?php echo wp_create_nonce('wp_rest'); ?>';
		</script>
		<?php
	}

	/**
	 * Render scan summary cards and metadata.
	 *
	 * @param Scan $scan Scan record.
	 * @return void
	 */
	private static function render_summary(Scan $scan): void {
		$progress = $scan->get_progress();
		?>
		<div class="cleara11y-scan-summary" style="margin: 20px 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px;">
			<?php self::render_card(__('Status', 'cleara11y'), Scans_Page::format_label($scan->status)); ?>
			<?php self::render_card(__('Type', 'cleara11y'), Scans_Page::format_label($scan->scan_type)); ?>
			<?php self::render_card(__('Progress', 'cleara11y'), sprintf('%s%%', number_format_i18n($progress, 2))); ?>
			<?php self::render_card(__('Pages', 'cleara11y'), sprintf('%d / %d', $scan->scanned_items, $scan->total_items)); ?>
			<?php self::render_card(__('Issues', 'cleara11y'), (string) $scan->total_issues); ?>
		</div>

		<div class="cleara11y-scan-metadata" style="margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
			<h2 style="margin-top: 0;"><?php esc_html_e('Scan Details', 'cleara11y'); ?></h2>
			<table class="widefat striped" style="max-width: 900px;">
				<tbody>
					<tr>
						<th scope="row" style="width: 180px;"><?php esc_html_e('Name', 'cleara11y'); ?></th>
						<td><?php echo esc_html($scan->scan_name ?: sprintf(__('Scan #%d', 'cleara11y'), $scan->id)); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Created', 'cleara11y'); ?></th>
						<td><?php echo esc_html(Scans_Page::format_date($scan->created_at)); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Started', 'cleara11y'); ?></th>
						<td><?php echo esc_html($scan->started_at ? Scans_Page::format_date($scan->started_at) : '-'); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Completed', 'cleara11y'); ?></th>
						<td><?php echo esc_html($scan->completed_at ? Scans_Page::format_date($scan->completed_at) : '-'); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Severity Totals', 'cleara11y'); ?></th>
						<td><?php echo esc_html(Scans_Page::format_issue_summary($scan)); ?></td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render a summary card.
	 *
	 * @param string $label Card label.
	 * @param string $value Card value.
	 * @return void
	 */
	private static function render_card(string $label, string $value): void {
		?>
		<div class="cleara11y-scan-card" style="padding: 18px; background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
			<div style="font-size: 22px; font-weight: 600; line-height: 1.2;"><?php echo esc_html($value); ?></div>
			<div style="color: #646970; margin-top: 4px;"><?php echo esc_html($label); ?></div>
		</div>
		<?php
	}
}
