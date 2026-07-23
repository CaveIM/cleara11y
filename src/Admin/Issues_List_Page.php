<?php
/**
 * Issues Explorer Page.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Admin
 */

namespace ClearA11y\Admin;

/**
 * Issues Explorer Page Class.
 */
class Issues_List_Page {

	/**
	 * Build an explorer URL from a meaningful scope.
	 *
	 * @param array $scope Explorer query arguments.
	 * @return string Explorer URL.
	 */
	public static function get_url(array $scope = []): string {
		return add_query_arg(
			array_merge(['page' => 'cleara11y-issues'], $scope),
			admin_url('admin.php')
		);
	}

	/**
	 * Render the issues explorer shell.
	 *
	 * @return void
	 */
	public static function render(): void {
		?>
		<div class="wrap cleara11y-explorer" id="cleara11y-issues-explorer">
			<nav class="cleara11y-explorer__breadcrumbs" aria-label="<?php esc_attr_e('Breadcrumb', 'cleara11y'); ?>">
				<a href="<?php echo esc_url(admin_url('admin.php?page=cleara11y-issues')); ?>">
					<?php esc_html_e('Accessibility Issues', 'cleara11y'); ?>
				</a>
				<span aria-hidden="true">/</span>
				<span id="cleara11y-breadcrumb-current"><?php esc_html_e('Active issues', 'cleara11y'); ?></span>
			</nav>

			<div class="cleara11y-explorer__header">
				<h1 id="cleara11y-explorer-title"><?php esc_html_e('Active accessibility issues', 'cleara11y'); ?></h1>
				<p id="cleara11y-explorer-summary" class="description">
					<?php esc_html_e('Loading issue occurrences…', 'cleara11y'); ?>
				</p>
			</div>

			<div id="cleara11y-snapshot-banner" class="cleara11y-snapshot-banner" hidden></div>

			<section class="cleara11y-filter-bar" aria-labelledby="cleara11y-filter-heading">
				<h2 id="cleara11y-filter-heading" class="screen-reader-text"><?php esc_html_e('Filter issues', 'cleara11y'); ?></h2>
				<div class="cleara11y-filter-bar__primary">
					<label>
						<span><?php esc_html_e('Status', 'cleara11y'); ?></span>
						<select id="cleara11y-filter-status">
							<option value="active"><?php esc_html_e('Active', 'cleara11y'); ?></option>
							<option value="ignored"><?php esc_html_e('Exceptions', 'cleara11y'); ?></option>
							<option value="all"><?php esc_html_e('All workflow states', 'cleara11y'); ?></option>
						</select>
					</label>
					<label>
						<span><?php esc_html_e('Severity', 'cleara11y'); ?></span>
						<select id="cleara11y-filter-severity">
							<option value=""><?php esc_html_e('All severities', 'cleara11y'); ?></option>
							<option value="critical"><?php esc_html_e('Critical', 'cleara11y'); ?></option>
							<option value="moderate"><?php esc_html_e('Moderate', 'cleara11y'); ?></option>
							<option value="minor"><?php esc_html_e('Minor', 'cleara11y'); ?></option>
						</select>
					</label>
					<label class="cleara11y-filter-bar__search">
						<span><?php esc_html_e('Search', 'cleara11y'); ?></span>
						<input type="search" id="cleara11y-search-issues" placeholder="<?php esc_attr_e('Rule, page, selector, or text', 'cleara11y'); ?>">
					</label>
					<label>
						<span><?php esc_html_e('Group by', 'cleara11y'); ?></span>
						<select id="cleara11y-group-by">
							<option value="page"><?php esc_html_e('Page', 'cleara11y'); ?></option>
							<option value="rule"><?php esc_html_e('Rule', 'cleara11y'); ?></option>
							<option value="none"><?php esc_html_e('No grouping', 'cleara11y'); ?></option>
						</select>
					</label>
					<label>
						<span><?php esc_html_e('Sort', 'cleara11y'); ?></span>
						<select id="cleara11y-sort">
							<option value="severity"><?php esc_html_e('Severity', 'cleara11y'); ?></option>
							<option value="newest"><?php esc_html_e('Newest observation', 'cleara11y'); ?></option>
							<option value="page"><?php esc_html_e('Page', 'cleara11y'); ?></option>
							<option value="rule"><?php esc_html_e('Rule', 'cleara11y'); ?></option>
						</select>
					</label>
					<button type="button" class="button" id="cleara11y-clear-filters"><?php esc_html_e('Clear filters', 'cleara11y'); ?></button>
				</div>
				<details class="cleara11y-more-filters">
					<summary><?php esc_html_e('More filters', 'cleara11y'); ?></summary>
					<div class="cleara11y-more-filters__grid">
						<?php self::render_entity_filter('rule', __('Rule', 'cleara11y')); ?>
						<?php self::render_entity_filter('page', __('Page', 'cleara11y')); ?>
						<?php self::render_entity_filter('scan', __('Scan', 'cleara11y')); ?>
					</div>
				</details>
			</section>

			<p id="cleara11y-results-announcer" class="screen-reader-text" aria-live="polite" aria-atomic="true"></p>

			<div class="cleara11y-master-detail">
				<section id="cleara11y-results-region" class="cleara11y-results" aria-labelledby="cleara11y-results-heading" tabindex="-1">
					<h2 id="cleara11y-results-heading"><?php esc_html_e('Issue occurrences', 'cleara11y'); ?></h2>
					<div id="cleara11y-issues-container" aria-busy="true">
						<?php self::render_loading_state(); ?>
					</div>
					<nav id="cleara11y-pagination" class="cleara11y-pagination" aria-label="<?php esc_attr_e('Issue results pages', 'cleara11y'); ?>" hidden>
						<button type="button" class="button" id="cleara11y-prev-page"><?php esc_html_e('Previous', 'cleara11y'); ?></button>
						<span id="cleara11y-page-info"></span>
						<button type="button" class="button" id="cleara11y-next-page"><?php esc_html_e('Next', 'cleara11y'); ?></button>
					</nav>
				</section>

				<aside id="cleara11y-detail-panel" class="cleara11y-detail" aria-labelledby="cleara11y-detail-title" hidden>
					<div id="cleara11y-detail-content"></div>
				</aside>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a server-backed entity filter.
	 *
	 * @param string $type Filter type.
	 * @param string $label Filter label.
	 * @return void
	 */
	private static function render_entity_filter(string $type, string $label): void {
		?>
		<div class="cleara11y-entity-filter" data-filter-type="<?php echo esc_attr($type); ?>">
			<label for="cleara11y-<?php echo esc_attr($type); ?>-filter"><?php echo esc_html($label); ?></label>
			<input
				type="search"
				id="cleara11y-<?php echo esc_attr($type); ?>-filter"
				autocomplete="off"
				role="combobox"
				aria-autocomplete="list"
				aria-expanded="false"
				aria-controls="cleara11y-<?php echo esc_attr($type); ?>-options"
				placeholder="<?php echo esc_attr(sprintf(__('Find a %s…', 'cleara11y'), strtolower($label))); ?>"
			>
			<ul id="cleara11y-<?php echo esc_attr($type); ?>-options" class="cleara11y-entity-options" role="listbox" hidden></ul>
		</div>
		<?php
	}

	/**
	 * Render stable loading markup.
	 *
	 * @return void
	 */
	private static function render_loading_state(): void {
		?>
		<div class="cleara11y-loading-state" role="status">
			<span class="spinner is-active" aria-hidden="true"></span>
			<span><?php esc_html_e('Loading issue occurrences…', 'cleara11y'); ?></span>
		</div>
		<?php
	}
}
