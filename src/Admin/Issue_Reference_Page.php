<?php
/**
 * Issue Reference Page
 *
 * Reference page for all possible accessibility issues from axe-core.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Admin
 */

namespace ClearA11y\Admin;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Issue Reference Page Class
 */
class Issue_Reference_Page {

	/**
	 * Get instance of class.
	 *
	 * @return Issue_Reference_Page
	 */
	public static function get_instance(): Issue_Reference_Page {
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
			__('Issue Reference', 'cleara11y'),
			__('Issue Reference', 'cleara11y'),
			'manage_options',
			'cleara11y-issue-reference',
			[$this, 'render_page']
		);
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		?>
		<div class="wrap cleara11y-issue-reference-wrap">
			<h1><?php esc_html_e('Accessibility Issue Reference', 'cleara11y'); ?></h1>

			<div id="cleara11y-issue-reference-app">
				<!-- Introduction -->
				<div class="cleara11y-intro">
					<p><?php esc_html_e('This page lists all accessibility checks that ClearA11y performs, based on the axe-core library. Use this as a reference to understand what issues we scan for and how to fix them.', 'cleara11y'); ?></p>
				</div>

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

						<select id="cleara11y-category-filter">
							<option value=""><?php esc_html_e('All Categories', 'cleara11y'); ?></option>
							<option value="cat.aria"><?php esc_html_e('ARIA', 'cleara11y'); ?></option>
							<option value="cat.color"><?php esc_html_e('Color', 'cleara11y'); ?></option>
							<option value="cat.forms"><?php esc_html_e('Forms', 'cleara11y'); ?></option>
							<option value="cat.keyboard"><?php esc_html_e('Keyboard', 'cleara11y'); ?></option>
							<option value="cat.language"><?php esc_html_e('Language', 'cleara11y'); ?></option>
							<option value="cat.lists"><?php esc_html_e('Lists', 'cleara11y'); ?></option>
							<option value="cat.media"><?php esc_html_e('Media', 'cleara11y'); ?></option>
							<option value="cat.semantics"><?php esc_html_e('Semantics', 'cleara11y'); ?></option>
							<option value="cat.structure"><?php esc_html_e('Structure', 'cleara11y'); ?></option>
							<option value="cat.tables"><?php esc_html_e('Tables', 'cleara11y'); ?></option>
							<option value="cat.text-alternatives"><?php esc_html_e('Text Alternatives', 'cleara11y'); ?></option>
						</select>

						<select id="cleara11y-wcag-filter">
							<option value=""><?php esc_html_e('All WCAG Levels', 'cleara11y'); ?></option>
							<option value="wcag2a"><?php esc_html_e('WCAG 2.0 Level A', 'cleara11y'); ?></option>
							<option value="wcag2aa"><?php esc_html_e('WCAG 2.0 Level AA', 'cleara11y'); ?></option>
							<option value="wcag2aaa"><?php esc_html_e('WCAG 2.0 Level AAA', 'cleara11y'); ?></option>
							<option value="wcag21a"><?php esc_html_e('WCAG 2.1 Level A', 'cleara11y'); ?></option>
							<option value="wcag21aa"><?php esc_html_e('WCAG 2.1 Level AA', 'cleara11y'); ?></option>
						</select>

						<input type="search" id="cleara11y-issue-search" placeholder="<?php esc_attr_e('Search issues...', 'cleara11y'); ?>" />
					</div>

					<div class="cleara11y-stats" id="cleara11y-stats">
						<span class="cleara11y-stat-item">
							<strong id="cleara11y-total-rules">0</strong>
							<?php esc_html_e('Total Rules', 'cleara11y'); ?>
						</span>
						<span class="cleara11y-stat-item">
							<strong id="cleara11y-filtered-rules">0</strong>
							<?php esc_html_e('Showing', 'cleara11y'); ?>
						</span>
					</div>
				</div>

				<!-- Issue Reference List -->
				<div class="cleara11y-issue-reference-list" id="cleara11y-issue-reference-list">
					<div class="cleara11y-loading">
						<span class="spinner is-active"></span>
						<?php esc_html_e('Loading accessibility rules...', 'cleara11y'); ?>
					</div>
				</div>
			</div>

			<!-- Detail Modal -->
			<div id="cleara11y-detail-modal" class="cleara11y-modal" role="dialog" aria-modal="true" aria-labelledby="cleara11y-modal-title" style="display: none;">
				<div class="cleara11y-modal-content">
					<div class="cleara11y-modal-header">
						<h2 id="cleara11y-modal-title" tabindex="-1"></h2>
						<button type="button" class="cleara11y-modal-close" aria-label="<?php esc_attr_e('Close modal', 'cleara11y'); ?>">&times;</button>
					</div>
					<div class="cleara11y-modal-body" id="cleara11y-modal-body">
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
