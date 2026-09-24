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

if (! defined('ABSPATH')) {
	exit;
}

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
		<?php
	}
}
