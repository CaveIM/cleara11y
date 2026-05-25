<?php
/**
 * Frontend Highlighter
 *
 * Highlights accessibility issues on the frontend when requested.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Frontend
 */

namespace ClearA11y\Frontend;

/**
 * Frontend Highlighter Class
 */
class Highlighter {

	/**
	 * Query parameter for highlight mode.
	 *
	 * @var string
	 */
	private const HIGHLIGHT_PARAM = 'edac';

	/**
	 * Query parameter for scanning mode.
	 *
	 * @var string
	 */
	private const SCANNING_PARAM = 'cleara11y_scanning';

	/**
	 * Query parameter for scan token.
	 *
	 * @var string
	 */
	private const SCAN_TOKEN_PARAM = 'cleara11y_scan';

	/**
	 * Nonce parameter for security.
	 *
	 * @var string
	 */
	private const NONCE_PARAM = 'edac_nonce';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action('template_redirect', [$this, 'detect_highlight_request']);
		add_action('wp_footer', [$this, 'inject_highlighter_script']);
		add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
	}

	/**
	 * Enqueue frontend assets for issue panel.
	 *
	 * @return void
	 */
	public function enqueue_frontend_assets(): void {
		// Only load if frontend highlighting is enabled
		if (!$this->should_load_frontend_assets()) {
			return;
		}

		// Enqueue styles
		wp_enqueue_style(
			'cleara11y-frontend',
			CLEARA11Y_PLUGIN_URL . 'assets/css/frontend.css',
			[],
			CLEARA11Y_VERSION
		);

		// Enqueue script
		wp_enqueue_script(
			'cleara11y-frontend',
			CLEARA11Y_PLUGIN_URL . 'assets/js/frontend.js',
			[],
			CLEARA11Y_VERSION,
			true
		);

		// Localize issues data
		$this->localize_frontend_issues();
	}

	/**
	 * Check if frontend assets should be loaded.
	 *
	 * @return bool
	 */
	private function should_load_frontend_assets(): bool {
		// Don't load frontend assets when scanning (prevents panel elements from being flagged as violations)
		if (isset($_GET[self::SCANNING_PARAM]) || isset($_GET[self::SCAN_TOKEN_PARAM])) {
			return false;
		}

		// Only load if highlighting is enabled
		if (!get_option('cleara11y_enable_frontend_highlighting', true)) {
			return false;
		}

		// Only load if user is logged in and has edit permissions
		if (!is_user_logged_in()) {
			return false;
		}

		// Only load on singular pages
		if (!is_singular()) {
			return false;
		}

		global $post;

		if (!$post) {
			return false;
		}

		// Check if post type is enabled for scanning
		$enabled_post_types = get_option('cleara11y_scan_post_types', ['page', 'post']);

		if (!in_array($post->post_type, $enabled_post_types, true)) {
			return false;
		}

		// Check if user can edit this post
		$post_type_obj = get_post_type_object($post->post_type);

		return current_user_can($post_type_obj->cap->edit_post, $post->ID);
	}

	/**
	 * Localize issues data for frontend JavaScript.
	 *
	 * @return void
	 */
	private function localize_frontend_issues(): void {
		global $post;

		if (!$post) {
			return;
		}

		// Get all non-dismissed issues for this post
		$issues = \ClearA11y\Database\Issue_Repository::get_by_post_id($post->ID);

		// Get site ID for ignore matching
		$site_id = get_current_blog_id();

		// Prepare issues data for frontend (excluding ignored issues)
		$frontend_issues = [];

		foreach ($issues as $issue) {
			// Check if this issue is ignored by any active ignore rule
			$matches = \ClearA11y\Services\Ignore_Matcher_Service::find_matches($issue, $site_id);

			// Skip issues that have matching ignore rules
			if (!empty($matches)) {
				continue;
			}

			$frontend_issues[] = [
				'id' => $issue->id,
				'rule_id' => $issue->rule_id,
				'severity' => $issue->severity,
				'impact' => $issue->impact,
				'selector' => $issue->selector,
				'message' => $issue->message,
				'help_text' => $issue->help_text,
				'help_url' => $issue->help_url,
			];
		}

		wp_localize_script('cleara11y-frontend', 'cleara11yIssues', [
			'post_id' => $post->ID,
			'issues' => $frontend_issues,
		]);
	}

	/**
	 * Detect highlight request and set up highlighting.
	 *
	 * @return void
	 */
	public function detect_highlight_request(): void {
		// Check if highlight parameters are present
		if (!isset($_GET[self::HIGHLIGHT_PARAM], $_GET[self::NONCE_PARAM])) {
			return;
		}

		$issue_id = absint($_GET[self::HIGHLIGHT_PARAM]);
		$nonce = sanitize_text_field(wp_unslash($_GET[self::NONCE_PARAM]));

		// Verify nonce
		if (!wp_verify_nonce($nonce, 'edac_highlight')) {
			return;
		}

		// Check user permissions
		if (!current_user_can('edit_posts')) {
			return;
		}

		// Get issue details
		$issue = \ClearA11y\Database\Issue_Repository::get_by_id($issue_id);

		if (!$issue) {
			return;
		}

		// Store issue data for highlighting
		$this->setup_highlight_data($issue);

		add_action('wp_enqueue_scripts', [$this, 'enqueue_highlighter_scripts']);
	}

	/**
	 * Set up highlight data for JavaScript.
	 *
	 * @param \ClearA11y\Models\Issue $issue Issue to highlight.
	 * @return void
	 */
	private function setup_highlight_data(\ClearA11y\Models\Issue $issue): void {
		wp_localize_script('cleara11y-highlighter', 'cleara11yHighlightData', [
			'selector' => $issue->selector,
			'ruleId' => $issue->rule_id,
			'message' => $issue->message,
			'severity' => $issue->severity,
		]);
	}

	/**
	 * Enqueue highlighter scripts and styles.
	 *
	 * @return void
	 */
	public function enqueue_highlighter_scripts(): void {
		wp_enqueue_script(
			'cleara11y-highlighter',
			CLEARA11Y_PLUGIN_URL . 'assets/js/highlighter.js',
			[],
			CLEARA11Y_VERSION,
			true
		);

		wp_enqueue_style(
			'cleara11y-highlighter',
			CLEARA11Y_PLUGIN_URL . 'assets/css/highlighter.css',
			[],
			CLEARA11Y_VERSION
		);
	}

	/**
	 * Inject highlighter script inline.
	 *
	 * @return void
	 */
	public function inject_highlighter_script(): void {
		if (!isset($_GET[self::HIGHLIGHT_PARAM])) {
			return;
		}

		$issue_id = absint($_GET[self::HIGHLIGHT_PARAM]);

		// Get issue data
		$issue = \ClearA11y\Database\Issue_Repository::get_by_id($issue_id);

		if (!$issue || !$issue->selector) {
			return;
		}

		?>
		<script>
		document.addEventListener('DOMContentLoaded', function() {
			try {
				var element = document.querySelector(<?php echo wp_json_encode($issue->selector); ?>);

				if (element) {
					element.classList.add('cleara11y-highlight-issue');
					element.setAttribute('data-cleara11y-highlighted', 'true');
					element.scrollIntoView({ behavior: 'smooth', block: 'center' });

					// Add info panel
					var panel = document.createElement('div');
					panel.className = 'cleara11y-highlight-panel cleara11y-severity-<?php echo esc_attr($issue->severity); ?>';
					panel.setAttribute('data-cleara11y-plugin', 'true');
					panel.setAttribute('aria-hidden', 'true');
					panel.innerHTML = `
						<strong><?php echo esc_html($issue->rule_id); ?></strong><br>
						<?php echo esc_html($issue->message); ?>
						<button class="cleara11y-close-panel">&times;</button>
					`;
					document.body.appendChild(panel);

					// Close button handler
					panel.querySelector('.cleara11y-close-panel').addEventListener('click', function() {
						element.classList.remove('cleara11y-highlight-issue');
						panel.remove();
					});

					// Auto-hide after 10 seconds
					setTimeout(function() {
						element.classList.remove('cleara11y-highlight-issue');
						panel.remove();
					}, 10000);
				}
			} catch (e) {
				console.error('ClearA11y: Could not highlight element', e);
			}
		});
		</script>
		<style>
		.cleara11y-highlight-issue {
			outline: 3px solid #dc3232 !important;
			outline-offset: 2px;
			background-color: rgba(220, 50, 50, 0.1) !important;
		}
		.cleara11y-highlight-panel {
			position: fixed;
			top: 20px;
			right: 20px;
			max-width: 350px;
			padding: 15px;
			background: #fff;
			border-left: 5px solid;
			box-shadow: 0 4px 12px rgba(0,0,0,0.15);
			z-index: 999999;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
			font-size: 14px;
		}
		.cleara11y-highlight-panel.cleara11y-severity-critical {
			border-left-color: #dc3232;
		}
		.cleara11y-highlight-panel.cleara11y-severity-moderate {
			border-left-color: #f56e28;
		}
		.cleara11y-highlight-panel.cleara11y-severity-minor {
			border-left-color: #ffb900;
		}
		.cleara11y-close-panel {
			position: absolute;
			top: 10px;
			right: 10px;
			background: none;
			border: none;
			font-size: 20px;
			cursor: pointer;
			line-height: 1;
		}
		</style>
		<?php
	}

	/**
	 * Generate highlight URL for an issue.
	 *
	 * @param int $issue_id Issue ID.
	 * @param int $post_id  Post ID.
	 * @return string Highlight URL.
	 */
	public static function get_highlight_url(int $issue_id, int $post_id): string {
		return add_query_arg(
			[
				'edac' => $issue_id,
				'edac_nonce' => wp_create_nonce('edac_highlight'),
			],
			get_permalink($post_id)
		);
	}
}
