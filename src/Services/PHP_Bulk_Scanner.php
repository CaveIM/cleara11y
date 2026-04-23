<?php
/**
 * PHP Bulk Scanner
 *
 * Server-side accessibility scanner using DOMDocument.
 * Used for background/bulk scanning when client-side scanning is not practical.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Services
 */

namespace ClearA11y\Services;

use ClearA11y\Database\Issue_Repository;
use ClearA11y\Database\Scan_Item_Repository;
use ClearA11y\Models\Issue;

/**
 * PHP Bulk Scanner Class
 */
class PHP_Bulk_Scanner {

	/**
	 * Accessibility rules to check.
	 *
	 * @var array
	 */
	private static array $rules = [];

	/**
	 * Initialize rules.
	 */
	private static function init_rules(): void {
		if (!empty(self::$rules)) {
			return;
		}

		self::$rules = [
			// Image alt text rules
			'img-alt-missing' => [
				'severity' => 'critical',
				'rule_type' => 'error',
				'message' => 'Images must have an alt attribute.',
				'help_text' => 'All images must have an alt attribute that describes the image content or is left empty if decorative.',
				'help_url' => 'https://www.w3.org/WAI/tutorials/images/',
				'wcag_criterion' => '1.1.1',
				'check' => [self::class, 'check_img_alt'],
			],
			// Heading structure rules
			'heading-empty' => [
				'severity' => 'moderate',
				'rule_type' => 'error',
				'message' => 'Headings must not be empty.',
				'help_text' => 'Headings should provide descriptive labels for content sections.',
				'help_url' => 'https://www.w3.org/WAI/tutorials/page-structure/',
				'wcag_criterion' => '2.4.6',
				'check' => [self::class, 'check_heading_empty'],
			],
			'heading-skip-level' => [
				'severity' => 'moderate',
				'rule_type' => 'warning',
				'message' => 'Heading levels should not be skipped.',
				'help_text' => 'Headings should follow a logical hierarchy (h1, h2, h3, etc.) without skipping levels.',
				'help_url' => 'https://www.w3.org/WAI/tutorials/page-structure/',
				'wcag_criterion' => '2.4.6',
				'check' => [self::class, 'check_heading_levels'],
			],
			// Link rules
			'link-empty' => [
				'severity' => 'moderate',
				'rule_type' => 'error',
				'message' => 'Links must have discernible text.',
				'help_text' => 'Links should have descriptive text that indicates the destination or purpose.',
				'help_url' => 'https://www.w3.org/WAI/tutorials/links/',
				'wcag_criterion' => '2.4.4',
				'check' => [self::class, 'check_link_empty'],
			],
			'link-blank' => [
				'severity' => 'moderate',
				'rule_type' => 'warning',
				'message' => 'Links that open in a new window should warn the user.',
				'help_text' => 'When a link opens in a new window, indicate this in the link text.',
				'help_url' => 'https://www.w3.org/WAI/WCAG21/Techniques/html/H83.html',
				'wcag_criterion' => '3.3.1',
				'check' => [self::class, 'check_link_blank'],
			],
			// Form rules
			'form-label-missing' => [
				'severity' => 'critical',
				'rule_type' => 'error',
				'message' => 'Form fields must have labels.',
				'help_text' => 'All form inputs should have associated labels that describe their purpose.',
				'help_url' => 'https://www.w3.org/WAI/tutorials/forms/',
				'wcag_criterion' => '1.3.1',
				'check' => [self::class, 'check_form_labels'],
			],
			// Table rules
			'table-th-missing' => [
				'severity' => 'critical',
				'rule_type' => 'error',
				'message' => 'Tables must have header cells (th).',
				'help_text' => 'Data tables should use th elements for headers with proper scope attributes.',
				'help_url' => 'https://www.w3.org/WAI/tutorials/tables/',
				'wcag_criterion' => '1.3.1',
				'check' => [self::class, 'check_table_headers'],
			],
			// ARIA rules
			'aria-label-missing' => [
				'severity' => 'critical',
				'rule_type' => 'error',
				'message' => 'Elements with aria-label or aria-labelledby must have valid references.',
				'help_text' => 'ARIA labels must reference existing elements or provide descriptive text.',
				'help_url' => 'https://www.w3.org/WAI/standards-guidelines/aria/',
				'wcag_criterion' => '2.4.4',
				'check' => [self::class, 'check_aria_labels'],
			],
		];

		// Allow filtering rules
		self::$rules = apply_filters('cleara11y_php_scanner_rules', self::$rules);
	}

	/**
	 * Scan a post for accessibility issues.
	 *
	 * @param int $post_id  Post ID.
	 * @param int $scan_id  Scan ID.
	 * @param int $scan_item_id Scan Item ID.
	 * @return array Scan result.
	 */
	public static function scan_post(int $post_id, int $scan_id, int $scan_item_id): array {
		$post = get_post($post_id);

		if (!$post || $post->post_status !== 'publish') {
			return [
				'success' => false,
				'message' => 'Post not found or not published.',
			];
		}

		// Get the rendered HTML content
		$html = self::get_rendered_html($post_id);

		if (empty($html)) {
			return [
				'success' => false,
				'message' => 'Could not get rendered HTML.',
			];
		}

		// Initialize rules
		self::init_rules();

		// Delete existing issues for this scan item
		Issue_Repository::delete_by_scan_item_id($scan_item_id);

		// Parse HTML and run checks
		$issues = self::scan_html($html, $scan_id, $scan_item_id, $post_id);

		// Update scan item with results
		$scan_item = Scan_Item_Repository::get_by_id($scan_item_id);
		if ($scan_item) {
			$scan_item->status = 'completed';
			$scan_item->scan_method = 'server';
			$scan_item->total_issues = count($issues);
			$scan_item->critical_issues = 0;
			$scan_item->moderate_issues = 0;
			$scan_item->minor_issues = 0;
			$scan_item->scanned_at = current_time('mysql');

			foreach ($issues as $issue) {
				if ($issue->severity === 'critical') {
					$scan_item->critical_issues++;
				} elseif ($issue->severity === 'moderate') {
					$scan_item->moderate_issues++;
				} else {
					$scan_item->minor_issues++;
				}
			}

			Scan_Item_Repository::update($scan_item);
		}

		return [
			'success' => true,
			'issues_found' => count($issues),
			'message' => sprintf('Scan complete. Found %d issues.', count($issues)),
		];
	}

	/**
	 * Get rendered HTML for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string Rendered HTML.
	 */
	private static function get_rendered_html(int $post_id): string {
		// Get the post URL
		$url = get_permalink($post_id);

		if (!$url) {
			return '';
		}

		// Fetch the rendered HTML
		$response = wp_remote_get($url, [
			'timeout' => 30,
			'sslverify' => false,
		]);

		if (is_wp_error($response)) {
			return '';
		}

		$html = wp_remote_retrieve_body($response);

		// Extract body content if available
		if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $matches)) {
			return $matches[1];
		}

		return $html;
	}

	/**
	 * Scan HTML for accessibility issues.
	 *
	 * @param string $html         HTML content.
	 * @param int    $scan_id      Scan ID.
	 * @param int    $scan_item_id Scan Item ID.
	 * @param int    $post_id      Post ID.
	 * @return Issue[] Array of issues found.
	 */
	private static function scan_html(string $html, int $scan_id, int $scan_item_id, int $post_id): array {
		libxml_use_internal_errors(true);
		$dom = new \DOMDocument();
		$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
		libxml_clear_errors();

		$xpath = new \DOMXPath($dom);
		$issues = [];

		foreach (self::$rules as $rule_id => $rule) {
			if (!isset($rule['check']) || !is_callable($rule['check'])) {
				continue;
			}

			$rule_issues = call_user_func($rule['check'], $xpath, $dom, $rule_id);

			foreach ($rule_issues as $issue_data) {
				$issue = new Issue();
				$issue->scan_id = $scan_id;
				$issue->scan_item_id = $scan_item_id;
				$issue->post_id = $post_id;
				$issue->rule_id = $rule_id;
				$issue->rule_type = $rule['rule_type'];
				$issue->severity = $rule['severity'];
				$issue->selector = $issue_data['selector'] ?? '';
				$issue->html = $issue_data['html'] ?? '';
				$issue->message = $rule['message'];
				$issue->help_text = $rule['help_text'];
				$issue->help_url = $rule['help_url'];
				$issue->wcag_criterion = $rule['wcag_criterion'];
				$issue->dismissed = false;
				$issue->created_at = current_time('mysql');

				if (Issue_Repository::insert($issue)) {
					$issues[] = $issue;
				}
			}
		}

		return $issues;
	}

	/**
	 * Check for missing image alt attributes.
	 *
	 * @param \DOMXPath $xpath    DOMXPath object.
	 * @param \DOMDocument $dom   DOMDocument object.
	 * @param string $rule_id     Rule ID.
	 * @return array Array of issue data.
	 */
	public static function check_img_alt(\DOMXPath $xpath, \DOMDocument $dom, string $rule_id): array {
		$issues = [];
		$nodes = $xpath->query('//img[not(@alt)]');

		foreach ($nodes as $node) {
			$issues[] = [
				'selector' => self::get_node_selector($node),
				'html' => $node->C14N(),
			];
		}

		// Check for empty alt that might need content
		$nodes = $xpath->query('//img[@alt=""]');
		foreach ($nodes as $node) {
			// Skip if decorative (could be improved with more checks)
			$src = $node->getAttribute('src');
			if ($src && !self::is_decorative_image($node)) {
				$issues[] = [
					'selector' => self::get_node_selector($node),
					'html' => $node->C14N(),
				];
			}
		}

		return $issues;
	}

	/**
	 * Check for empty headings.
	 *
	 * @param \DOMXPath $xpath    DOMXPath object.
	 * @param \DOMDocument $dom   DOMDocument object.
	 * @param string $rule_id     Rule ID.
	 * @return array Array of issue data.
	 */
	public static function check_heading_empty(\DOMXPath $xpath, \DOMDocument $dom, string $rule_id): array {
		$issues = [];
		$nodes = $xpath->query('//h1 | //h2 | //h3 | //h4 | //h5 | //h6');

		foreach ($nodes as $node) {
			$text = trim($node->textContent);
			if (empty($text)) {
				$issues[] = [
					'selector' => self::get_node_selector($node),
					'html' => $node->C14N(),
				];
			}
		}

		return $issues;
	}

	/**
	 * Check for skipped heading levels.
	 *
	 * @param \DOMXPath $xpath    DOMXPath object.
	 * @param \DOMDocument $dom   DOMDocument object.
	 * @param string $rule_id     Rule ID.
	 * @return array Array of issue data.
	 */
	public static function check_heading_levels(\DOMXPath $xpath, \DOMDocument $dom, string $rule_id): array {
		$issues = [];
		$nodes = $xpath->query('//h1 | //h2 | //h3 | //h4 | //h5 | //h6');

		$previous_level = 0;
		foreach ($nodes as $node) {
			$current_level = (int) substr($node->nodeName, 1);

			// Allow h1 as first heading
			if ($previous_level === 0 && $current_level === 1) {
				$previous_level = $current_level;
				continue;
			}

			// Check if level was skipped
			if ($current_level > $previous_level + 1) {
				$issues[] = [
					'selector' => self::get_node_selector($node),
					'html' => $node->C14N(),
				];
			}

			$previous_level = $current_level;
		}

		return $issues;
	}

	/**
	 * Check for empty links.
	 *
	 * @param \DOMXPath $xpath    DOMXPath object.
	 * @param \DOMDocument $dom   DOMDocument object.
	 * @param string $rule_id     Rule ID.
	 * @return array Array of issue data.
	 */
	public static function check_link_empty(\DOMXPath $xpath, \DOMDocument $dom, string $rule_id): array {
		$issues = [];
		$nodes = $xpath->query('//a');

		foreach ($nodes as $node) {
			$text = trim($node->textContent);
			$has_img = $xpath->query('.//img', $node)->length > 0;

			// Skip if has image with alt
			if ($has_img) {
				$img = $xpath->query('.//img', $node)->item(0);
				if ($img && $img->getAttribute('alt') !== '') {
					continue;
				}
			}

			if (empty($text) && !$has_img) {
				$issues[] = [
					'selector' => self::get_node_selector($node),
					'html' => $node->C14N(),
				];
			}
		}

		return $issues;
	}

	/**
	 * Check for links that open in new window without warning.
	 *
	 * @param \DOMXPath $xpath    DOMXPath object.
	 * @param \DOMDocument $dom   DOMDocument object.
	 * @param string $rule_id     Rule ID.
	 * @return array Array of issue data.
	 */
	public static function check_link_blank(\DOMXPath $xpath, \DOMDocument $dom, string $rule_id): array {
		$issues = [];
		$nodes = $xpath->query('//a[@target="_blank"]');

		foreach ($nodes as $node) {
			$text = strtolower($node->textContent);
			$aria_label = strtolower($node->getAttribute('aria-label') ?? '');

			// Check if there's a warning in the link text or aria-label
			$warnings = ['opens in a new window', 'opens in new tab', 'new window', 'new tab'];
			$has_warning = false;

			foreach ($warnings as $warning) {
				if (strpos($text, $warning) !== false || strpos($aria_label, $warning) !== false) {
					$has_warning = true;
					break;
				}
			}

			if (!$has_warning) {
				$issues[] = [
					'selector' => self::get_node_selector($node),
					'html' => $node->C14N(),
				];
			}
		}

		return $issues;
	}

	/**
	 * Check for form inputs without labels.
	 *
	 * @param \DOMXPath $xpath    DOMXPath object.
	 * @param \DOMDocument $dom   DOMDocument object.
	 * @param string $rule_id     Rule ID.
	 * @return array Array of issue data.
	 */
	public static function check_form_labels(\DOMXPath $xpath, \DOMDocument $dom, string $rule_id): array {
		$issues = [];
		$nodes = $xpath->query('//input | //textarea | //select');

		foreach ($nodes as $node) {
			$type = $node->getAttribute('type');

			// Skip submit, button, hidden
			if (in_array($type, ['submit', 'button', 'hidden', 'image'], true)) {
				continue;
			}

			// Check for explicit label
			$id = $node->getAttribute('id');
			$has_label = false;

			if ($id) {
				$label = $xpath->query('//label[@for="' . $id . '"]')->length > 0;
				if ($label) {
					$has_label = true;
				}
			}

			// Check for implicit label
			$parent = $node->parentNode;
			if ($parent && $parent->nodeName === 'label') {
				$has_label = true;
			}

			// Check for aria-label or aria-labelledby
			if ($node->hasAttribute('aria-label') || $node->hasAttribute('aria-labelledby')) {
				$has_label = true;
			}

			if (!$has_label) {
				$issues[] = [
					'selector' => self::get_node_selector($node),
					'html' => $node->C14N(),
				];
			}
		}

		return $issues;
	}

	/**
	 * Check for tables without headers.
	 *
	 * @param \DOMXPath $xpath    DOMXPath object.
	 * @param \DOMDocument $dom   DOMDocument object.
	 * @param string $rule_id     Rule ID.
	 * @return array Array of issue data.
	 */
	public static function check_table_headers(\DOMXPath $xpath, \DOMDocument $dom, string $rule_id): array {
		$issues = [];
		$nodes = $xpath->query('//table');

		foreach ($nodes as $node) {
			$has_th = $xpath->query('.//th', $node)->length > 0;

			if (!$has_th) {
				$issues[] = [
					'selector' => self::get_node_selector($node),
					'html' => substr($node->C14N(), 0, 500),
				];
			}
		}

		return $issues;
	}

	/**
	 * Check for invalid ARIA label references.
	 *
	 * @param \DOMXPath $xpath    DOMXPath object.
	 * @param \DOMDocument $dom   DOMDocument object.
	 * @param string $rule_id     Rule ID.
	 * @return array Array of issue data.
	 */
	public static function check_aria_labels(\DOMXPath $xpath, \DOMDocument $dom, string $rule_id): array {
		$issues = [];

		// Check aria-labelledby
		$nodes = $xpath->query('//*[@aria-labelledby]');
		foreach ($nodes as $node) {
			$labelledby = $node->getAttribute('aria-labelledby');
			$ids = explode(' ', trim($labelledby));

			foreach ($ids as $id) {
				if (empty($id)) {
					continue;
				}

				// Check if referenced element exists
				$ref = $xpath->query('//*[@id="' . $id . '"]')->length;
				if ($ref === 0) {
					$issues[] = [
						'selector' => self::get_node_selector($node),
						'html' => $node->C14N(),
					];
					break;
				}
			}
		}

		return $issues;
	}

	/**
	 * Get CSS selector for a DOM node.
	 *
	 * @param \DOMNode $node DOM node.
	 * @return string CSS selector.
	 */
	private static function get_node_selector(\DOMNode $node): string {
		$selector = $node->nodeName;

		if ($node instanceof \DOMElement) {
			$id = $node->getAttribute('id');
			if ($id) {
				$selector .= '#' . preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
				return $selector;
			}

			$classes = $node->getAttribute('class');
			if ($classes) {
				$class_list = preg_split('/\s+/', trim($classes));
				if (!empty($class_list[0])) {
					$selector .= '.' . preg_replace('/[^a-zA-Z0-9_-]/', '', $class_list[0]);
				}
			}
		}

		return $selector;
	}

	/**
	 * Check if an image is decorative.
	 *
	 * @param \DOMNode $node Image node.
	 * @return bool True if likely decorative.
	 */
	private static function is_decorative_image(\DOMNode $node): bool {
		if (!$node instanceof \DOMElement) {
			return false;
		}

		$role = $node->getAttribute('role');
		if ($role === 'presentation' || $role === 'none') {
			return true;
		}

		return false;
	}
}
