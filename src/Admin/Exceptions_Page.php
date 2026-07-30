<?php
/**
 * Exceptions Management Page
 *
 * Renders the admin page for managing exception rules.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Admin
 */

namespace ClearA11y\Admin;

/**
 * Exceptions Page Class
 */
class Exceptions_Page {

	/**
	 * Single instance of the class.
	 *
	 * @var Exceptions_Page|null
	 */
	private static ?Exceptions_Page $instance = null;

	/**
	 * Get the single instance of the class.
	 *
	 * @return Exceptions_Page
	 */
	public static function get_instance(): Exceptions_Page {
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
	 * Render the exceptions management page.
	 */
	public static function render(): void {
		?>
		<div class="wrap cleara11y-exceptions-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e('Exceptions', 'cleara11y'); ?></h1>
			<a href="#" class="page-title-action" id="cleara11y-create-exception">
				<?php esc_html_e('Create Exception', 'cleara11y'); ?>
			</a>
			<hr class="wp-header-end">

			<!-- Tabs Navigation -->
			<nav class="nav-tab-wrapper wp-clearfix" style="margin: 20px 0;">
				<a href="#" class="nav-tab nav-tab-active" data-tab="active">
					<?php esc_html_e('Active', 'cleara11y'); ?>
					<span class="count" id="cleara11y-active-count">(0)</span>
				</a>
				<a href="#" class="nav-tab" data-tab="expired">
					<?php esc_html_e('Expired', 'cleara11y'); ?>
					<span class="count" id="cleara11y-expired-count">(0)</span>
				</a>
				<a href="#" class="nav-tab" data-tab="disabled">
					<?php esc_html_e('Disabled', 'cleara11y'); ?>
					<span class="count" id="cleara11y-disabled-count">(0)</span>
				</a>
				<a href="#" class="nav-tab" data-tab="revoked">
					<?php esc_html_e('Revoked', 'cleara11y'); ?>
					<span class="count" id="cleara11y-revoked-count">(0)</span>
				</a>
				<a href="#" class="nav-tab" data-tab="audit">
					<?php esc_html_e('Audit Log', 'cleara11y'); ?>
				</a>
			</nav>

			<!-- Tab Content -->
			<div class="cleara11y-tab-content">
				<!-- Rules Table -->
				<div id="tab-rules" class="tab-panel active">
					<div class="cleara11y-exceptions-filters" style="margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
						<div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
							<label>
								<input type="checkbox" id="cleara11y-hide-system-exceptions">
								<?php esc_html_e('Hide temporary exceptions', 'cleara11y'); ?>
							</label>
							<button type="button" class="button" id="cleara11y-refresh-exceptions">
								<span class="dashicons dashicons-update" style="margin-top: 3px;"></span>
								<?php esc_html_e('Refresh', 'cleara11y'); ?>
							</button>
						</div>
					</div>

					<table class="wp-list-table widefat fixed striped" style="margin-top: 20px;">
						<thead>
							<tr>
								<th scope="col" style="width: 30%;">
									<?php esc_html_e('Exception Rule', 'cleara11y'); ?>
								</th>
								<th scope="col" style="width: 15%;">
									<?php esc_html_e('Target', 'cleara11y'); ?>
								</th>
								<th scope="col" style="width: 15%;">
									<?php esc_html_e('Scope', 'cleara11y'); ?>
								</th>
								<th scope="col" style="width: 10%;">
									<?php esc_html_e('Duration', 'cleara11y'); ?>
								</th>
								<th scope="col" style="width: 10%;">
									<?php esc_html_e('Reason', 'cleara11y'); ?>
								</th>
								<th scope="col" style="width: 10%;">
									<?php esc_html_e('Created By', 'cleara11y'); ?>
								</th>
								<th scope="col" style="width: 10%;">
									<?php esc_html_e('Actions', 'cleara11y'); ?>
								</th>
							</tr>
						</thead>
						<tbody id="cleara11y-exceptions-table-body">
							<tr>
								<td colspan="7" style="text-align: center; padding: 40px;">
									<span class="spinner is-active" style="float: none; margin: 0;"></span>
									<?php esc_html_e('Loading exceptions...', 'cleara11y'); ?>
								</td>
							</tr>
						</tbody>
					</table>

					<!-- Pagination -->
					<div class="tablenav bottom" id="cleara11y-exceptions-pagination" style="display: none;">
						<div class="tablenav-pages">
							<span class="displaying-num" id="cleara11y-exceptions-displaying-num"></span>
							<span class="pagination-links">
								<button type="button" class="button-page" id="cleara11y-exceptions-first-page" aria-label="First page">&laquo;</button>
								<button type="button" class="button-page" id="cleara11y-exceptions-prev-page" aria-label="Previous page">&lsaquo;</button>
								<span class="paging-input">
									<label for="cleara11y-exceptions-current-page" class="screen-reader-text">Current Page</label>
									<input type="text" id="cleara11y-exceptions-current-page" class="current-page" value="1" size="1" aria-describedby="table-paging">
									<span id="table-paging" class="tablenav-paging-text">of <span class="total-pages" id="cleara11y-exceptions-total-pages">1</span></span>
								</span>
								<button type="button" class="button-page" id="cleara11y-exceptions-next-page" aria-label="Next page">&rsaquo;</button>
								<button type="button" class="button-page" id="cleara11y-exceptions-last-page" aria-label="Last page">&raquo;</button>
							</span>
						</div>
					</div>
				</div>

				<!-- Audit Log Panel -->
				<div id="tab-audit" class="tab-panel" style="display: none;">
					<div class="cleara11y-audit-log-filters" style="margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
						<div style="display: flex; gap: 15px; align-items: center;">
							<button type="button" class="button" id="cleara11y-refresh-audit">
								<span class="dashicons dashicons-update" style="margin-top: 3px;"></span>
								<?php esc_html_e('Refresh', 'cleara11y'); ?>
							</button>
						</div>
					</div>

					<table class="wp-list-table widefat fixed striped" style="margin-top: 20px;">
						<thead>
							<tr>
								<th scope="col" style="width: 20%;">
									<?php esc_html_e('Event', 'cleara11y'); ?>
								</th>
								<th scope="col" style="width: 25%;">
									<?php esc_html_e('Exception Rule', 'cleara11y'); ?>
								</th>
								<th scope="col" style="width: 15%;">
									<?php esc_html_e('Actor', 'cleara11y'); ?>
								</th>
								<th scope="col" style="width: 20%;">
									<?php esc_html_e('Timestamp', 'cleara11y'); ?>
								</th>
								<th scope="col" style="width: 20%;">
									<?php esc_html_e('Details', 'cleara11y'); ?>
								</th>
							</tr>
						</thead>
						<tbody id="cleara11y-audit-table-body">
							<tr>
								<td colspan="5" style="text-align: center; padding: 40px;">
									<span class="spinner is-active" style="float: none; margin: 0;"></span>
									<?php esc_html_e('Loading audit log...', 'cleara11y'); ?>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>

			<!-- Empty States -->
			<template id="cleara11y-empty-state-template">
				<tr>
					<td colspan="7" style="text-align: center; padding: 40px;">
						<div style="color: #646970;">
							<p style="font-size: 16px; margin: 0 0 10px;">
								<span class="dashicons dashicons-dismiss" style="font-size: 48px; width: 48px; height: 48px; display: block; margin: 0 auto 10px;"></span>
								<strong data-empty-message></strong>
							</p>
						</div>
					</td>
				</tr>
			</template>
		</div>

		<!-- Exception Rule Detail Modal -->
		<div id="cleara11y-exception-detail-modal" style="display: none;">
			<div class="cleara11y-modal-backdrop"></div>
			<div class="cleara11y-modal-content" role="dialog" aria-modal="true" aria-labelledby="cleara11y-exception-detail-title" style="max-width: 600px;">
				<div class="cleara11y-modal-header">
					<h2 id="cleara11y-exception-detail-title"><?php esc_html_e('Exception Rule Details', 'cleara11y'); ?></h2>
					<button type="button" class="cleara11y-modal-close" aria-label="<?php esc_attr_e('Close exception details', 'cleara11y'); ?>">
						<span class="dashicons dashicons-no-alt"></span>
					</button>
				</div>
				<div class="cleara11y-modal-body" id="cleara11y-exception-detail-body">
					<!-- Content loaded dynamically -->
				</div>
				<div class="cleara11y-modal-footer">
					<button type="button" class="button button-secondary cleara11y-modal-close">
						<?php esc_html_e('Close', 'cleara11y'); ?>
					</button>
					<button type="button" class="button button-primary cleara11y-edit-exception" id="cleara11y-edit-exception">
						<?php esc_html_e('Edit Rule', 'cleara11y'); ?>
					</button>
				</div>
			</div>
		</div>

		<?php
		// Enqueue scripts and styles
		self::enqueue_assets();
	}

	/**
	 * Get localized strings for the exceptions page script.
	 *
	 * @return array<string, string>
	 */
	public static function get_script_strings(): array {
		return [
			'confirmDelete' => __('Revoke this exception? Existing findings will return to active remediation, while the review record remains in the audit log.', 'cleara11y'),
			'confirmDisable' => __('Are you sure you want to disable this exception?', 'cleara11y'),
			'undoSuccess' => __('Exception removed.', 'cleara11y'),
			'deleteSuccess' => __('Exception revoked.', 'cleara11y'),
			'error' => __('An error occurred. Please try again.', 'cleara11y'),
			'createWizardTitle' => __('Create Exception', 'cleara11y'),
			'cancel' => __('Cancel', 'cleara11y'),
			'next' => __('Next', 'cleara11y'),
			'createRule' => __('Create Exception', 'cleara11y'),
			'creating' => __('Creating...', 'cleara11y'),
			'createSuccess' => __('Exception created successfully!', 'cleara11y'),
			'createFailed' => __('Failed to create exception.', 'cleara11y'),
			'step1Title' => __('What should become an exception?', 'cleara11y'),
			'step1Desc' => __('Choose the detected issue pattern this review decision should apply to: a specific rule, a specific element, or both.', 'cleara11y'),
			'ruleOnly' => __('Rule Only', 'cleara11y'),
			'ruleOnlyDesc' => __('Mark all detected issues for a specific accessibility rule as exceptions (e.g. color-contrast).', 'cleara11y'),
			'elementOnly' => __('Element Only', 'cleara11y'),
			'elementOnlyDesc' => __('Mark detected issues on a specific element as exceptions, regardless of rule.', 'cleara11y'),
			'ruleOnElement' => __('Rule on Element', 'cleara11y'),
			'ruleOnElementDesc' => __('Mark a specific accessibility rule on a specific element as an exception (most precise).', 'cleara11y'),
			'step2Title' => __('Where should this apply?', 'cleara11y'),
			'step2Desc' => __('Choose the scope for this exception.', 'cleara11y'),
			'singlePage' => __('Single Page', 'cleara11y'),
			'singlePageDesc' => __('Apply to a specific page only.', 'cleara11y'),
			'entireSite' => __('Entire Site', 'cleara11y'),
			'entireSiteDesc' => __('Apply to all pages on the site.', 'cleara11y'),
			'contentTypes' => __('Content Types', 'cleara11y'),
			'contentTypesDesc' => __('Apply to specific post types (e.g. pages, posts).', 'cleara11y'),
			'urlPattern' => __('URL Pattern', 'cleara11y'),
			'urlPatternDesc' => __('Apply to pages matching a URL pattern (use * as wildcard).', 'cleara11y'),
			'step3Title' => __('How long should this apply?', 'cleara11y'),
			'step3Desc' => __('Choose the duration for this exception.', 'cleara11y'),
			'untilNextScan' => __('Until Next Scan', 'cleara11y'),
			'untilNextScanDesc' => __('Exclude from active issue counts until the next accessibility scan runs (recommended for testing).', 'cleara11y'),
			'permanent' => __('Permanent', 'cleara11y'),
			'permanentDesc' => __('Never expire. Use with caution and document why.', 'cleara11y'),
			'untilDate' => __('Until Specific Date', 'cleara11y'),
			'untilDateDesc' => __('Exclude from active issue counts until a specific date.', 'cleara11y'),
			'untilContentChanges' => __('Until Content Changes', 'cleara11y'),
			'untilContentChangesDesc' => __('Exclude from active issue counts until the element is significantly modified.', 'cleara11y'),
			'step4Title' => __('Why is this an exception?', 'cleara11y'),
			'step4Desc' => __('Help your team understand why this detected issue does not currently require remediation.', 'cleara11y'),
			'step5Title' => __('Review and Confirm', 'cleara11y'),
			'step5Desc' => __('Review your exception rule before creating it.', 'cleara11y'),
			'target' => __('Target', 'cleara11y'),
			'scope' => __('Scope', 'cleara11y'),
			'duration' => __('Duration', 'cleara11y'),
			'reason' => __('Reason', 'cleara11y'),
			'impactPreview' => __('Impact Preview', 'cleara11y'),
			'impactPreviewDesc' => __('This shows how many existing issues will be affected by this exception.', 'cleara11y'),
			'calculatingImpact' => __('Calculating impact...', 'cleara11y'),
			'issuesExcepted' => __('issue(s) will be marked as exceptions', 'cleara11y'),
			'pagesAffected' => __('page(s) affected', 'cleara11y'),
			'noIssuesMatch' => __('No existing issues match this exception.', 'cleara11y'),
			'impactWarning' => __('Warning:', 'cleara11y'),
			'impactWarningDesc' => __('This rule will mark many detected issues as exceptions. Please double-check your configuration.', 'cleara11y'),
			'failedToCalculate' => __('Failed to calculate impact. You can still create the rule.', 'cleara11y'),
		];
	}

	/**
	 * Enqueue scripts and styles for the exceptions page.
	 */
	private static function enqueue_assets(): void {
		wp_enqueue_style(
			'cleara11y-exceptions-page',
			CLEARA11Y_PLUGIN_URL . 'assets/css/exceptions-page.css',
			[],
			CLEARA11Y_VERSION
		);

		wp_enqueue_script(
			'cleara11y-exceptions-page',
			CLEARA11Y_PLUGIN_URL . 'assets/js/exceptions-page.js',
			['jquery', 'wp-api'],
			CLEARA11Y_VERSION,
			true
		);

		wp_localize_script('cleara11y-exceptions-page', 'cleara11yExceptions', [
			'apiUrl' => rest_url('cleara11y/v1/exceptions'),
			'nonce' => wp_create_nonce('wp_rest'),
			'strings' => self::get_script_strings(),
		]);
	}
}
