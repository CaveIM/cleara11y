<?php
/**
 * Scans admin page.
 *
 * Lists accessibility scans in a WordPress-style admin table.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Admin
 */

namespace ClearA11y\Admin;

use ClearA11y\Database\Scan_Repository;
use ClearA11y\Models\Scan;

/**
 * Scans Page Class.
 */
class Scans_Page {

	/**
	 * Render the scans list page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to view scans.', 'cleara11y'));
		}

		$filters = self::get_filters();
		$page = isset($_GET['paged']) ? max(1, absint(wp_unslash($_GET['paged']))) : 1;
		$per_page = 20;
		$total = Scan_Repository::count_filtered($filters);
		$total_pages = max(1, (int) ceil($total / $per_page));
		$page = min($page, $total_pages);

		$scans = Scan_Repository::get_filtered(
			array_merge(
				$filters,
				[
					'limit' => $per_page,
					'offset' => ($page - 1) * $per_page,
				]
			)
		);

		?>
		<div class="wrap cleara11y-scans-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e('Scans', 'cleara11y'); ?></h1>
			<hr class="wp-header-end">

			<form method="get" class="cleara11y-scans-filters" style="margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
				<input type="hidden" name="page" value="cleara11y-scans">
				<div style="display: flex; gap: 12px; align-items: end; flex-wrap: wrap;">
					<label>
						<span style="display: block; font-weight: 600; margin-bottom: 4px;"><?php esc_html_e('Status', 'cleara11y'); ?></span>
						<select name="scan_status">
							<option value=""><?php esc_html_e('All statuses', 'cleara11y'); ?></option>
							<?php foreach (Scan::STATUSES as $status) : ?>
								<option value="<?php echo esc_attr($status); ?>" <?php selected($filters['status'], $status); ?>>
									<?php echo esc_html(self::format_label($status)); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>

					<label>
						<span style="display: block; font-weight: 600; margin-bottom: 4px;"><?php esc_html_e('Type', 'cleara11y'); ?></span>
						<select name="scan_type">
							<option value=""><?php esc_html_e('All types', 'cleara11y'); ?></option>
							<?php foreach (Scan::SCAN_TYPES as $type) : ?>
								<option value="<?php echo esc_attr($type); ?>" <?php selected($filters['scan_type'], $type); ?>>
									<?php echo esc_html(self::format_label($type)); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>

					<label>
						<span style="display: block; font-weight: 600; margin-bottom: 4px;"><?php esc_html_e('From', 'cleara11y'); ?></span>
						<input type="date" name="date_from" value="<?php echo esc_attr($filters['date_from']); ?>">
					</label>

					<label>
						<span style="display: block; font-weight: 600; margin-bottom: 4px;"><?php esc_html_e('To', 'cleara11y'); ?></span>
						<input type="date" name="date_to" value="<?php echo esc_attr($filters['date_to']); ?>">
					</label>

					<label>
						<span style="display: block; font-weight: 600; margin-bottom: 4px;"><?php esc_html_e('Search', 'cleara11y'); ?></span>
						<input type="search" name="s" value="<?php echo esc_attr($filters['search']); ?>" placeholder="<?php esc_attr_e('Scan name, page title, or URL', 'cleara11y'); ?>" class="regular-text">
					</label>

					<button type="submit" class="button button-primary"><?php esc_html_e('Filter', 'cleara11y'); ?></button>
					<a href="<?php echo esc_url(admin_url('admin.php?page=cleara11y-scans')); ?>" class="button"><?php esc_html_e('Reset', 'cleara11y'); ?></a>
				</div>
			</form>

			<div class="tablenav top">
				<div class="tablenav-pages">
					<span class="displaying-num">
						<?php echo esc_html(sprintf(_n('%d scan', '%d scans', $total, 'cleara11y'), $total)); ?>
					</span>
					<?php self::render_pagination($page, $total_pages, $filters); ?>
				</div>
			</div>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col"><?php echo wp_kses_post(self::sort_link(__('ID', 'cleara11y'), 'id', $filters)); ?></th>
						<th scope="col"><?php echo wp_kses_post(self::sort_link(__('Name', 'cleara11y'), 'scan_name', $filters)); ?></th>
						<th scope="col"><?php echo wp_kses_post(self::sort_link(__('Type', 'cleara11y'), 'scan_type', $filters)); ?></th>
						<th scope="col"><?php echo wp_kses_post(self::sort_link(__('Status', 'cleara11y'), 'status', $filters)); ?></th>
						<th scope="col"><?php echo wp_kses_post(self::sort_link(__('Pages', 'cleara11y'), 'scanned_items', $filters)); ?></th>
						<th scope="col"><?php echo wp_kses_post(self::sort_link(__('Issues', 'cleara11y'), 'total_issues', $filters)); ?></th>
						<th scope="col"><?php echo wp_kses_post(self::sort_link(__('Created', 'cleara11y'), 'created_at', $filters)); ?></th>
						<th scope="col"><?php echo wp_kses_post(self::sort_link(__('Completed', 'cleara11y'), 'completed_at', $filters)); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if (empty($scans)) : ?>
						<tr>
							<td colspan="8" style="padding: 30px; text-align: center;">
								<?php esc_html_e('No scans found.', 'cleara11y'); ?>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ($scans as $scan) : ?>
							<?php self::render_scan_row($scan); ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<div class="tablenav bottom">
				<div class="tablenav-pages">
					<?php self::render_pagination($page, $total_pages, $filters); ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Get scan detail URL.
	 *
	 * @param int $scan_id Scan ID.
	 * @return string
	 */
	public static function get_detail_url(int $scan_id): string {
		return admin_url('admin.php?page=cleara11y-scan-detail&scan_id=' . absint($scan_id));
	}

	/**
	 * Get sanitized filters from the request.
	 *
	 * @return array
	 */
	private static function get_filters(): array {
		$status = isset($_GET['scan_status']) ? sanitize_key(wp_unslash($_GET['scan_status'])) : '';
		$type = isset($_GET['scan_type']) ? sanitize_key(wp_unslash($_GET['scan_type'])) : '';
		$orderby = isset($_GET['orderby']) ? sanitize_key(wp_unslash($_GET['orderby'])) : 'created_at';
		$order = isset($_GET['order']) ? sanitize_key(wp_unslash($_GET['order'])) : 'desc';

		return [
			'status' => in_array($status, Scan::STATUSES, true) ? $status : '',
			'scan_type' => in_array($type, Scan::SCAN_TYPES, true) ? $type : '',
			'search' => isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '',
			'date_from' => self::sanitize_date($_GET['date_from'] ?? ''),
			'date_to' => self::sanitize_date($_GET['date_to'] ?? ''),
			'orderby' => $orderby,
			'order' => strtolower($order) === 'asc' ? 'ASC' : 'DESC',
		];
	}

	/**
	 * Render a scan row.
	 *
	 * @param Scan $scan Scan record.
	 * @return void
	 */
	private static function render_scan_row(Scan $scan): void {
		$name = $scan->scan_name ?: sprintf(__('Scan #%d', 'cleara11y'), $scan->id);
		$progress = $scan->total_items > 0 ? sprintf('%d / %d', $scan->scanned_items, $scan->total_items) : (string) $scan->scanned_items;
		?>
		<tr>
			<td><?php echo esc_html((string) $scan->id); ?></td>
			<td>
				<strong><a href="<?php echo esc_url(self::get_detail_url($scan->id)); ?>"><?php echo esc_html($name); ?></a></strong>
				<div class="row-actions">
					<span class="view"><a href="<?php echo esc_url(self::get_detail_url($scan->id)); ?>"><?php esc_html_e('View details', 'cleara11y'); ?></a></span>
				</div>
			</td>
			<td><?php echo esc_html(self::format_label($scan->scan_type)); ?></td>
			<td><span class="cleara11y-status-badge cleara11y-status-<?php echo esc_attr($scan->status); ?>"><?php echo esc_html(self::format_label($scan->status)); ?></span></td>
			<td><?php echo esc_html($progress); ?></td>
			<td><?php echo esc_html(self::format_issue_summary($scan)); ?></td>
			<td><?php echo esc_html(self::format_date($scan->created_at)); ?></td>
			<td><?php echo esc_html($scan->completed_at ? self::format_date($scan->completed_at) : '-'); ?></td>
		</tr>
		<?php
	}

	/**
	 * Render pagination controls.
	 *
	 * @param int   $page Current page.
	 * @param int   $total_pages Total pages.
	 * @param array $filters Current filters.
	 * @return void
	 */
	private static function render_pagination(int $page, int $total_pages, array $filters): void {
		if ($total_pages <= 1) {
			return;
		}

		$base_args = self::query_args($filters);
		$first_url = add_query_arg(array_merge($base_args, ['paged' => 1]), admin_url('admin.php'));
		$prev_url = add_query_arg(array_merge($base_args, ['paged' => max(1, $page - 1)]), admin_url('admin.php'));
		$next_url = add_query_arg(array_merge($base_args, ['paged' => min($total_pages, $page + 1)]), admin_url('admin.php'));
		$last_url = add_query_arg(array_merge($base_args, ['paged' => $total_pages]), admin_url('admin.php'));
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
	 * Build sortable column link.
	 *
	 * @param string $label Link label.
	 * @param string $orderby Orderby field.
	 * @param array  $filters Current filters.
	 * @return string HTML link.
	 */
	private static function sort_link(string $label, string $orderby, array $filters): string {
		$current = $filters['orderby'] === $orderby;
		$next_order = $current && strtoupper($filters['order']) === 'ASC' ? 'desc' : 'asc';
		$url = add_query_arg(
			array_merge(
				self::query_args($filters),
				[
					'orderby' => $orderby,
					'order' => $next_order,
					'paged' => 1,
				]
			),
			admin_url('admin.php')
		);
		$indicator = $current ? (strtoupper($filters['order']) === 'ASC' ? ' ↑' : ' ↓') : '';

		return '<a href="' . esc_url($url) . '"><span>' . esc_html($label . $indicator) . '</span></a>';
	}

	/**
	 * Build query args from filters.
	 *
	 * @param array $filters Current filters.
	 * @return array
	 */
	private static function query_args(array $filters): array {
		$args = ['page' => 'cleara11y-scans'];

		if (!empty($filters['status'])) {
			$args['scan_status'] = $filters['status'];
		}
		if (!empty($filters['scan_type'])) {
			$args['scan_type'] = $filters['scan_type'];
		}
		if (!empty($filters['search'])) {
			$args['s'] = $filters['search'];
		}
		if (!empty($filters['date_from'])) {
			$args['date_from'] = $filters['date_from'];
		}
		if (!empty($filters['date_to'])) {
			$args['date_to'] = $filters['date_to'];
		}
		if (!empty($filters['orderby'])) {
			$args['orderby'] = $filters['orderby'];
			$args['order'] = strtolower((string) $filters['order']);
		}

		return $args;
	}

	/**
	 * Format a status/type label.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function format_label(string $value): string {
		return ucwords(str_replace('_', ' ', $value));
	}

	/**
	 * Format a date for display.
	 *
	 * @param string $date Date string.
	 * @return string
	 */
	public static function format_date(string $date): string {
		if (empty($date)) {
			return '-';
		}

		return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($date));
	}

	/**
	 * Format issue count summary.
	 *
	 * @param Scan $scan Scan record.
	 * @return string
	 */
	public static function format_issue_summary(Scan $scan): string {
		if ($scan->total_issues <= 0) {
			return __('None', 'cleara11y');
		}

		return sprintf(
			/* translators: 1: total issues, 2: critical, 3: moderate, 4: minor */
			__('%1$d total (%2$d critical, %3$d moderate, %4$d minor)', 'cleara11y'),
			$scan->total_issues,
			$scan->critical_issues,
			$scan->moderate_issues,
			$scan->minor_issues
		);
	}

	/**
	 * Sanitize an ISO date value.
	 *
	 * @param mixed $value Date value.
	 * @return string
	 */
	private static function sanitize_date(mixed $value): string {
		$value = sanitize_text_field(wp_unslash((string) $value));
		return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
	}
}
