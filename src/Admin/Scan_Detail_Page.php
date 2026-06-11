<?php
/**
 * Scan detail admin page.
 *
 * Renders a dedicated backend page for one scan record.
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

		$page = isset($_GET['items_page']) ? max(1, absint(wp_unslash($_GET['items_page']))) : 1;
		$per_page = 50;
		$total_items = Scan_Item_Repository::get_count($scan_id);
		$total_pages = max(1, (int) ceil($total_items / $per_page));
		$page = min($page, $total_pages);
		$items = Scan_Item_Repository::get_by_scan_id(
			$scan_id,
			[
				'orderby' => 'post_title',
				'order' => 'ASC',
				'limit' => $per_page,
				'offset' => ($page - 1) * $per_page,
			]
		);

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
			<?php self::render_items_table($scan, $items, $page, $total_pages, $total_items); ?>
		</div>
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

	/**
	 * Render scan items table.
	 *
	 * @param Scan        $scan Scan record.
	 * @param Scan_Item[] $items Scan items.
	 * @param int         $page Current page.
	 * @param int         $total_pages Total pages.
	 * @param int         $total_items Total items.
	 * @return void
	 */
	private static function render_items_table(Scan $scan, array $items, int $page, int $total_pages, int $total_items): void {
		?>
		<h2><?php esc_html_e('Pages in This Scan', 'cleara11y'); ?></h2>
		<div class="tablenav top">
			<div class="tablenav-pages">
				<span class="displaying-num"><?php echo esc_html(sprintf(_n('%d page', '%d pages', $total_items, 'cleara11y'), $total_items)); ?></span>
				<?php self::render_pagination($scan->id, $page, $total_pages); ?>
			</div>
		</div>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e('Page', 'cleara11y'); ?></th>
					<th scope="col"><?php esc_html_e('Status', 'cleara11y'); ?></th>
					<th scope="col"><?php esc_html_e('Method', 'cleara11y'); ?></th>
					<th scope="col"><?php esc_html_e('Issues', 'cleara11y'); ?></th>
					<th scope="col"><?php esc_html_e('Scanned', 'cleara11y'); ?></th>
					<th scope="col"><?php esc_html_e('Actions', 'cleara11y'); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if (empty($items)) : ?>
					<tr>
						<td colspan="6" style="padding: 30px; text-align: center;"><?php esc_html_e('No pages found for this scan.', 'cleara11y'); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ($items as $item) : ?>
						<?php self::render_item_row($item); ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<?php self::render_pagination($scan->id, $page, $total_pages); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render one scan item row.
	 *
	 * @param Scan_Item $item Scan item.
	 * @return void
	 */
	private static function render_item_row(Scan_Item $item): void {
		?>
		<tr>
			<td>
				<strong><?php echo esc_html($item->post_title ?: __('Untitled', 'cleara11y')); ?></strong>
				<?php if ($item->post_url) : ?>
					<br><small><a href="<?php echo esc_url($item->post_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($item->post_url); ?></a></small>
				<?php endif; ?>
				<?php if ($item->error_message) : ?>
					<br><small style="color: #d63638;"><?php echo esc_html($item->error_message); ?></small>
				<?php endif; ?>
			</td>
			<td><span class="cleara11y-status-badge cleara11y-status-<?php echo esc_attr($item->status); ?>"><?php echo esc_html(Scans_Page::format_label($item->status)); ?></span></td>
			<td><?php echo esc_html(Scans_Page::format_label($item->scan_method)); ?></td>
			<td><?php echo esc_html(self::format_item_issues($item)); ?></td>
			<td><?php echo esc_html($item->scanned_at ? Scans_Page::format_date($item->scanned_at) : '-'); ?></td>
			<td>
				<?php if ($item->post_id > 0) : ?>
					<a href="<?php echo esc_url(admin_url('admin.php?page=cleara11y-page-report&post_id=' . absint($item->post_id))); ?>" class="button button-small"><?php esc_html_e('Page Report', 'cleara11y'); ?></a>
					<a href="<?php echo esc_url(get_edit_post_link($item->post_id)); ?>" class="button button-small"><?php esc_html_e('Edit', 'cleara11y'); ?></a>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render pagination controls.
	 *
	 * @param int $scan_id Scan ID.
	 * @param int $page Current page.
	 * @param int $total_pages Total pages.
	 * @return void
	 */
	private static function render_pagination(int $scan_id, int $page, int $total_pages): void {
		if ($total_pages <= 1) {
			return;
		}

		$base = ['page' => 'cleara11y-scan-detail', 'scan_id' => $scan_id];
		$first_url = add_query_arg(array_merge($base, ['items_page' => 1]), admin_url('admin.php'));
		$prev_url = add_query_arg(array_merge($base, ['items_page' => max(1, $page - 1)]), admin_url('admin.php'));
		$next_url = add_query_arg(array_merge($base, ['items_page' => min($total_pages, $page + 1)]), admin_url('admin.php'));
		$last_url = add_query_arg(array_merge($base, ['items_page' => $total_pages]), admin_url('admin.php'));
		?>
		<span class="pagination-links">
			<a class="first-page button<?php echo $page <= 1 ? ' disabled' : ''; ?>" href="<?php echo esc_url($first_url); ?>">&laquo;</a>
			<a class="prev-page button<?php echo $page <= 1 ? ' disabled' : ''; ?>" href="<?php echo esc_url($prev_url); ?>">&lsaquo;</a>
			<span class="paging-input"><?php echo esc_html(sprintf(__('Page %1$d of %2$d', 'cleara11y'), $page, $total_pages)); ?></span>
			<a class="next-page button<?php echo $page >= $total_pages ? ' disabled' : ''; ?>" href="<?php echo esc_url($next_url); ?>">&rsaquo;</a>
			<a class="last-page button<?php echo $page >= $total_pages ? ' disabled' : ''; ?>" href="<?php echo esc_url($last_url); ?>">&raquo;</a>
		</span>
		<?php
	}

	/**
	 * Format scan item issue summary.
	 *
	 * @param Scan_Item $item Scan item.
	 * @return string
	 */
	private static function format_item_issues(Scan_Item $item): string {
		if ($item->total_issues <= 0) {
			return __('None', 'cleara11y');
		}

		return sprintf(
			/* translators: 1: total issues, 2: critical, 3: moderate, 4: minor */
			__('%1$d total (%2$d critical, %3$d moderate, %4$d minor)', 'cleara11y'),
			$item->total_issues,
			$item->critical_issues,
			$item->moderate_issues,
			$item->minor_issues
		);
	}
}
