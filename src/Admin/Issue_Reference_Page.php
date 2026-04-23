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
			<div id="cleara11y-detail-modal" class="cleara11y-modal" style="display: none;">
				<div class="cleara11y-modal-content">
					<div class="cleara11y-modal-header">
						<h2 id="cleara11y-modal-title"></h2>
						<button class="cleara11y-modal-close" aria-label="<?php esc_attr_e('Close modal', 'cleara11y'); ?>">&times;</button>
					</div>
					<div class="cleara11y-modal-body" id="cleara11y-modal-body">
					</div>
				</div>
			</div>
		</div>

		<style>
			.cleara11y-issue-reference-wrap {
				max-width: 1400px;
			}

			.cleara11y-intro {
				background: #fff;
				padding: 20px;
				border: 1px solid #c3c4c7;
				border-radius: 4px;
				margin-bottom: 20px;
			}

			.cleara11y-filters {
				background: #fff;
				padding: 20px;
				border: 1px solid #c3c4c7;
				border-radius: 4px;
				margin-bottom: 20px;
			}

			.cleara11y-filter-controls {
				display: flex;
				gap: 10px;
				flex-wrap: wrap;
				margin-bottom: 15px;
			}

			.cleara11y-filter-controls select,
			.cleara11y-filter-controls input {
				padding: 8px 12px;
				border: 1px solid #c3c4c7;
				border-radius: 4px;
				font-size: 14px;
			}

			.cleara11y-filter-controls input[type="search"] {
				flex: 1;
				min-width: 200px;
			}

			.cleara11y-stats {
				display: flex;
				gap: 20px;
				padding-top: 15px;
				border-top: 1px solid #c3c4c7;
			}

			.cleara11y-stat-item {
				font-size: 14px;
			}

			.cleara11y-stat-item strong {
				font-size: 18px;
				margin-right: 5px;
			}

			.cleara11y-issue-reference-list {
				background: #fff;
				border: 1px solid #c3c4c7;
				border-radius: 4px;
			}

			.cleara11y-reference-item {
				display: grid;
				grid-template-columns: 120px 1fr auto;
				gap: 20px;
				padding: 20px;
				border-bottom: 1px solid #c3c4c7;
				align-items: start;
			}

			.cleara11y-reference-item:last-child {
				border-bottom: none;
			}

			.cleara11y-reference-item:hover {
				background: #f6f7f7;
			}

			.cleara11y-rule-meta {
				display: flex;
				flex-direction: column;
				gap: 5px;
			}

			.cleara11y-rule-impact {
				font-weight: 600;
				text-transform: uppercase;
				font-size: 11px;
				padding: 4px 8px;
				border-radius: 3px;
				text-align: center;
				width: fit-content;
			}

			.cleara11y-rule-impact.critical {
				background: #f66565;
				color: #fff;
			}

			.cleara11y-rule-impact.serious {
				background: #f5a623;
				color: #fff;
			}

			.cleara11y-rule-impact.moderate {
				background: #6dd4b6;
				color: #fff;
			}

			.cleara11y-rule-impact.minor {
				background: #a0aec0;
				color: #fff;
			}

			.cleara11y-rule-tags {
				display: flex;
				flex-wrap: wrap;
				gap: 4px;
			}

			.cleara11y-rule-tag {
				font-size: 10px;
				padding: 2px 6px;
				background: #e5e5e6;
				border-radius: 3px;
				color: #646970;
			}

			.cleara11y-rule-info h3 {
				margin: 0 0 5px 0;
				font-size: 16px;
			}

			.cleara11y-rule-id {
				color: #646970;
				font-size: 12px;
				font-family: monospace;
				margin-bottom: 10px;
			}

			.cleara11y-rule-description {
				color: #1d2327;
				margin: 10px 0;
				line-height: 1.5;
			}

			.cleara11y-rule-actions {
				display: flex;
				flex-direction: column;
				gap: 8px;
				align-items: end;
			}

			.cleara11y-view-details-btn {
				padding: 6px 12px;
				font-size: 13px;
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

			.cleara11y-loading {
				text-align: center;
				padding: 40px;
			}

			.cleara11y-loading .spinner {
				float: none;
				margin: 0 auto 10px;
			}

			.cleara11y-empty-state {
				text-align: center;
				padding: 60px 20px;
				color: #646970;
			}

			.cleara11y-detail-section {
				margin-bottom: 25px;
			}

			.cleara11y-detail-section h4 {
				margin: 0 0 10px 0;
				font-size: 16px;
				color: #1d2327;
			}

			.cleara11y-detail-section p,
			.cleara11y-detail-section ul {
				margin: 0;
				line-height: 1.6;
			}

			.cleara11y-detail-section ul {
				padding-left: 20px;
			}

			.cleara11y-detail-section li {
				margin-bottom: 5px;
			}

			.cleara11y-help-url {
				display: inline-block;
				margin-top: 10px;
				padding: 8px 12px;
				background: #2271b1;
				color: #fff;
				text-decoration: none;
				border-radius: 4px;
				font-size: 13px;
			}

			.cleara11y-help-url:hover {
				background: #135e96;
				color: #fff;
			}

			.cleara11y-wcag-tags {
				display: flex;
				flex-wrap: wrap;
				gap: 8px;
				margin-top: 10px;
			}

			.cleara11y-wcag-tag {
				padding: 4px 10px;
				background: #e5e5e6;
				border-radius: 3px;
				font-size: 12px;
			}

			.cleara11y-wcag-tag.wcag2a,
			.cleara11y-wcag-tag.wcag21a {
				background: #d6e7f8;
				color: #0964b0;
			}

			.cleara11y-wcag-tag.wcag2aa,
			.cleara11y-wcag-tag.wcag21aa {
				background: #c7e0c7;
				color: #2c5c2c;
			}

			.cleara11y-wcag-tag.wcag2aaa {
				background: #f5e6e6;
				color: #8b2c2c;
			}
		</style>
		<?php
	}
}
