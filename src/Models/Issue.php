<?php
/**
 * Issue Model
 *
 * Represents a single accessibility issue found during a scan.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Models
 */

namespace ClearA11y\Models;

use ClearA11y\Services\Rule_Severity_Map;

// Force OPcache to reload this file
if (function_exists('opcache_invalidate')) {
	opcache_invalidate(__FILE__, true);
}

/**
 * Issue Model Class
 */
class Issue {

	/**
	 * Issue ID.
	 *
	 * @var int
	 */
	public int $id = 0;

	/**
	 * Parent Scan ID.
	 *
	 * @var int
	 */
	public int $scan_id = 0;

	/**
	 * Parent Scan Item ID.
	 *
	 * @var int
	 */
	public int $scan_item_id = 0;

	/**
	 * Post ID.
	 *
	 * @var int
	 */
	public int $post_id = 0;

	/**
	 * Rule ID (axe-core rule slug).
	 *
	 * @var string
	 */
	public string $rule_id = '';

	/**
	 * Rule type (error, warning).
	 *
	 * @var string
	 */
	public string $rule_type = 'error';

	/**
	 * Issue severity.
	 *
	 * @var string
	 */
	public string $severity = 'moderate';

	/**
	 * Issue impact.
	 *
	 * @var string|null
	 */
	public ?string $impact = null;

	/**
	 * CSS selector for the element.
	 *
	 * @var string|null
	 */
	public ?string $selector = null;

	/**
	 * HTML snippet of the element.
	 *
	 * @var string|null
	 */
	public ?string $html = null;

	/**
	 * Issue message.
	 *
	 * @var string|null
	 */
	public ?string $message = null;

	/**
	 * Help text describing the issue.
	 *
	 * @var string|null
	 */
	public ?string $help_text = null;

	/**
	 * Help URL for more information.
	 *
	 * @var string|null
	 */
	public ?string $help_url = null;

	/**
	 * WCAG criterion code.
	 *
	 * @var string|null
	 */
	public ?string $wcag_criterion = null;

	/**
	 * Whether issue is dismissed.
	 *
	 * @var bool
	 */
	public bool $dismissed = false;

	/**
	 * User ID who dismissed.
	 *
	 * @var int|null
	 */
	public ?int $dismissed_by = null;

	/**
	 * Dismissal timestamp.
	 *
	 * @var string|null
	 */
	public ?string $dismissed_at = null;

	/**
	 * Dismissal comment.
	 *
	 * @var string|null
	 */
	public ?string $dismissal_comment = null;

	/**
	 * Created timestamp.
	 *
	 * @var string
	 */
	public string $created_at = '';

	/**
	 * Selector quality score (0-100).
	 *
	 * @var int|null
	 */
	public ?int $selector_score = null;

	/**
	 * Number of elements matching the selector.
	 *
	 * @var int|null
	 */
	public ?int $selector_match_count = null;

	/**
	 * XPath to the element.
	 *
	 * @var string|null
	 */
	public ?string $xpath = null;

	/**
	 * DOM path as JSON.
	 *
	 * @var string|null
	 */
	public ?string $dom_path = null;

	/**
	 * Ancestor chain as JSON.
	 *
	 * @var string|null
	 */
	public ?string $ancestor_chain = null;

	/**
	 * Accessible name of the element.
	 *
	 * @var string|null
	 */
	public ?string $accessible_name = null;

	/**
	 * Inner text snippet.
	 *
	 * @var string|null
	 */
	public ?string $inner_text_snippet = null;

	/**
	 * Bounding box as JSON.
	 *
	 * @var string|null
	 */
	public ?string $bounding_box = null;

	/**
	 * Computed styles as JSON.
	 *
	 * @var string|null
	 */
	public ?string $computed_style = null;

	/**
	 * Strict fingerprint hash.
	 *
	 * @var string|null
	 */
	public ?string $fingerprint_strict = null;

	/**
	 * Loose fingerprint hash.
	 *
	 * @var string|null
	 */
	public ?string $fingerprint_loose = null;

	/**
	 * Signature version.
	 *
	 * @var int|null
	 */
	public ?int $signature_version = null;

	/**
	 * Full node evidence as JSON.
	 *
	 * @var string|null
	 */
	public ?string $node_evidence = null;

	/**
	 * Valid severities.
	 *
	 * @var array
	 */
	public const SEVERITIES = ['critical', 'moderate', 'minor'];

	/**
	 * Valid rule types.
	 *
	 * @var array
	 */
	public const RULE_TYPES = ['error', 'warning'];

	/**
	 * WCAG impact to severity mapping.
	 *
	 * @var array
	 */
	public const IMPACT_SEVERITY_MAP = [
		'critical' => 'critical',
		'serious' => 'critical',
		'moderate' => 'moderate',
		'minor' => 'minor',
	];

	/**
	 * Create Issue from database row.
	 *
	 * @param object $row Database row object.
	 * @return self
	 */
	public static function from_row(object $row): self {
		$issue = new self();

		$issue->id = (int) $row->id;
		$issue->scan_id = (int) $row->scan_id;
		$issue->scan_item_id = (int) $row->scan_item_id;
		$issue->post_id = (int) $row->post_id;
		$issue->rule_id = $row->rule_id ?? '';
		$issue->rule_type = $row->rule_type ?? 'error';
		$issue->severity = $row->severity ?? 'moderate';
		$issue->impact = $row->impact ?? null;
		$issue->selector = $row->selector ?? null;
		$issue->html = $row->html ?? null;
		$issue->message = $row->message ?? null;
		$issue->help_text = $row->help_text ?? null;
		$issue->help_url = $row->help_url ?? null;
		$issue->wcag_criterion = $row->wcag_criterion ?? null;
		$issue->dismissed = (bool) $row->dismissed;
		$issue->dismissed_by = $row->dismissed_by ? (int) $row->dismissed_by : null;
		$issue->dismissed_at = $row->dismissed_at ?? null;
		$issue->dismissal_comment = $row->dismissal_comment ?? null;
		$issue->created_at = $row->created_at ?? current_time('mysql');

		// Evidence fields
		$issue->selector_score = isset($row->selector_score) ? (int) $row->selector_score : null;
		$issue->selector_match_count = isset($row->selector_match_count) ? (int) $row->selector_match_count : null;
		$issue->xpath = $row->xpath ?? null;
		$issue->dom_path = $row->dom_path ?? null;
		$issue->ancestor_chain = $row->ancestor_chain ?? null;
		$issue->accessible_name = $row->accessible_name ?? null;
		$issue->inner_text_snippet = $row->inner_text_snippet ?? null;
		$issue->bounding_box = $row->bounding_box ?? null;
		$issue->computed_style = $row->computed_style ?? null;
		$issue->fingerprint_strict = $row->fingerprint_strict ?? null;
		$issue->fingerprint_loose = $row->fingerprint_loose ?? null;
		$issue->signature_version = isset($row->signature_version) ? (int) $row->signature_version : null;
		$issue->node_evidence = $row->node_evidence ?? null;

		return $issue;
	}

	/**
	 * Create Issue from axe-core result.
	 *
	 * @param array  $result   Axe-core result node.
	 * @param int    $scan_id  Scan ID.
	 * @param int    $scan_item_id Scan Item ID.
	 * @param int    $post_id  Post ID.
	 * @param array  $evidence Optional evidence data from evidence extractor.
	 * @return self
	 */
	public static function from_axe_result(array $result, int $scan_id, int $scan_item_id, int $post_id, array $evidence = []): self {
		$issue = new self();

		$issue->scan_id = $scan_id;
		$issue->scan_item_id = $scan_item_id;
		$issue->post_id = $post_id;
		$issue->rule_id = $result['id'] ?? '';
		$issue->rule_type = isset($result['tags']) && in_array('wcag2aa', $result['tags'], true) ? 'error' : 'warning';
		$issue->impact = $result['impact'] ?? null;
		$issue->severity = self::calculate_severity($result);
		$issue->message = $result['description'] ?? null;
		$issue->help_text = $result['help'] ?? null;
		$issue->help_url = $result['helpUrl'] ?? null;

		// Extract WCAG tags
		$issue->wcag_criterion = self::extract_wcag_criterion($result);

		// Extract selector and HTML from first node
		if (!empty($result['nodes'][0])) {
			$node = $result['nodes'][0];
			$issue->selector = $node['target'][0] ?? null;
			$issue->html = $node['html'] ?? null;
		}

		$issue->dismissed = false;
		$issue->created_at = current_time('mysql');

		// Apply evidence data if provided
		if (!empty($evidence)) {
			$issue->selector_score = $evidence['selector_score']['score'] ?? null;
			$issue->selector_match_count = $evidence['selector_match_count'] ?? null;

			if (!empty($evidence['node_evidence'])) {
				$node_ev = $evidence['node_evidence'];
				$issue->xpath = $node_ev['xpath'] ?? null;
				$issue->dom_path = isset($node_ev['dom_path']) ? wp_json_encode($node_ev['dom_path']) : null;
				$issue->ancestor_chain = isset($node_ev['ancestor_chain']) ? wp_json_encode($node_ev['ancestor_chain']) : null;
				$issue->accessible_name = $node_ev['accessible_name'] ?? null;
				$issue->inner_text_snippet = $node_ev['inner_text_snippet'] ?? null;
				$issue->bounding_box = isset($node_ev['bounding_box']) ? wp_json_encode($node_ev['bounding_box']) : null;
				$issue->computed_style = isset($node_ev['computed_style']) ? wp_json_encode($node_ev['computed_style']) : null;
				$issue->fingerprint_strict = $node_ev['fingerprint_strict'] ?? null;
				$issue->fingerprint_loose = $node_ev['fingerprint_loose'] ?? null;
				$issue->signature_version = $node_ev['signature_version'] ?? 1;
			}

			// Store full evidence record as JSON
			$issue->node_evidence = wp_json_encode($evidence);
		}

		return $issue;
	}

	/**
	 * Calculate severity from axe-core result.
	 *
	 * Uses rule-based severity mapping following accessibility-checker standard.
	 *
	 * @param array $result Axe-core result node.
	 * @return string Severity category (critical, moderate, minor).
	 */
	public static function calculate_severity(array $result): string {
		$rule_id = $result['id'] ?? '';

		// Use rule-based severity mapping
		if (!empty($rule_id)) {
			$numeric_severity = Rule_Severity_Map::get_severity($rule_id);
			return Rule_Severity_Map::severity_to_category($numeric_severity);
		}

		// Fallback to impact-based mapping if rule not found
		$impact = $result['impact'] ?? null;

		// Map impact to severity
		if (isset(self::IMPACT_SEVERITY_MAP[$impact])) {
			return self::IMPACT_SEVERITY_MAP[$impact];
		}

		// Check for WCAG AA tags as fallback
		if (isset($result['tags']) && in_array('wcag2aa', $result['tags'], true)) {
			return 'moderate';
		}

		return 'minor';
	}

	/**
	 * Extract WCAG criterion from tags.
	 *
	 * @param array $result Axe-core result node.
	 * @return string|null
	 */
	public static function extract_wcag_criterion(array $result): ?string {
		if (empty($result['tags'])) {
			return null;
		}

		foreach ($result['tags'] as $tag) {
			if (preg_match('/wcag2[0-9]+a([0-9]+)/', $tag, $matches)) {
				return $matches[1] . '.1.' . ($matches[2] ?? '1');
			}
		}

		return null;
	}

	/**
	 * Check if issue is dismissed.
	 *
	 * @return bool
	 */
	public function is_dismissed(): bool {
		return $this->dismissed;
	}

	/**
	 * Get dismissal info as array.
	 *
	 * @return array
	 */
	public function get_dismissal_info(): array {
		if (!$this->dismissed) {
			return [];
		}

		$user = $this->dismissed_by ? get_userdata($this->dismissed_by) : null;

		return [
			'dismissed_by' => $user ? $user->display_name : null,
			'dismissed_at' => $this->dismissed_at,
			'comment' => $this->dismissal_comment,
		];
	}
}
