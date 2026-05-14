<?php
/**
 * Ignore Matcher Service
 *
 * Handles flexible matching logic for ignore rules against violations.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Services
 */

namespace ClearA11y\Services;

use ClearA11y\Models\Ignore_Rule;
use ClearA11y\Models\Issue;
use ClearA11y\Database\Ignore_Rule_Repository;

/**
 * Ignore Matcher Service Class
 */
class Ignore_Matcher_Service {

	/**
	 * Match confidence levels.
	 *
	 * @var array
	 */
	public const CONFIDENCE = [
		'EXACT' => 'exact',
		'HIGH' => 'high',
		'PARTIAL' => 'partial',
		'LOW' => 'low',
	];

	/**
	 * Find matching ignore rules for a violation.
	 *
	 * @param Issue $issue Issue to check.
	 * @param int   $site_id Site ID.
	 * @return array Array of matching rules with confidence.
	 */
	public static function find_matches(Issue $issue, int $site_id): array {
		// Get active rules for site
		$rules = Ignore_Rule_Repository::get_active($site_id);

		$matches = [];

		foreach ($rules as $rule) {
			$match = self::matches_rule($issue, $rule);
			if ($match) {
				$matches[] = [
					'rule' => $rule,
					'confidence' => $match['confidence'],
					'matched_by' => $match['matched_by'],
				];
			}
		}

		return $matches;
	}

	/**
	 * Check if an issue matches an ignore rule.
	 *
	 * @param Issue      $issue Issue to check.
	 * @param Ignore_Rule $rule  Ignore rule to match against.
	 * @return array|null Match result or null if no match.
	 */
	public static function matches_rule(Issue $issue, Ignore_Rule $rule): ?array {
		// Check scope first (fastest filter)
		if (!self::matches_scope($issue, $rule)) {
			return null;
		}

		// Check target match
		$target_match = self::matches_target($issue, $rule);

		if (!$target_match) {
			return null;
		}

		return $target_match;
	}

	/**
	 * Check if issue matches rule's scope.
	 *
	 * @param Issue      $issue Issue to check.
	 * @param Ignore_Rule $rule  Ignore rule.
	 * @return bool True if matches scope.
	 */
	private static function matches_scope(Issue $issue, Ignore_Rule $rule): bool {
		$scope = $rule->scope;
		$scope_type = $scope['scope_type'] ?? '';

		switch ($scope_type) {
			case 'site':
				// All issues on site match
				return true;

			case 'page':
				// Must match specific page URL
				$scan_item = self::get_scan_item_for_issue($issue);
				if (!$scan_item) {
					return false;
				}
				return self::urls_match($scan_item->post_url ?? '', $scope['url'] ?? '');

			case 'content_type':
				// Must match post type
				$scan_item = self::get_scan_item_for_issue($issue);
				if (!$scan_item) {
					return false;
				}
				$post_types = $scope['post_types'] ?? [];
				return in_array($scan_item->post_type ?? '', $post_types, true);

			case 'url_pattern':
				// Must match URL pattern
				$scan_item = self::get_scan_item_for_issue($issue);
				if (!$scan_item) {
					return false;
				}
				$patterns = $scope['patterns'] ?? [];
				foreach ($patterns as $pattern) {
					if (self::url_matches_pattern($scan_item->post_url ?? '', $pattern)) {
						return true;
					}
				}
				return false;

			default:
				return false;
		}
	}

	/**
	 * Check if issue matches rule's target.
	 *
	 * @param Issue      $issue Issue to check.
	 * @param Ignore_Rule $rule  Ignore rule.
	 * @return array|null Match result or null if no match.
	 */
	private static function matches_target(Issue $issue, Ignore_Rule $rule): ?array {
		$target_type = $rule->target_type;

		switch ($target_type) {
			case 'rule':
				return self::matches_rule_only($issue, $rule);

			case 'rule_on_element':
				return self::matches_rule_on_element($issue, $rule);

			case 'element':
				return self::matches_element_only($issue, $rule);

			default:
				return null;
		}
	}

	/**
	 * Match: Rule only (any element with this rule).
	 *
	 * @param Issue      $issue Issue to check.
	 * @param Ignore_Rule $rule  Ignore rule.
	 * @return array|null Match result or null.
	 */
	private static function matches_rule_only(Issue $issue, Ignore_Rule $rule): ?array {
		$rule_ids = $rule->rule_ids ?? [];

		if (empty($rule_ids)) {
			return null;
		}

		if (!in_array($issue->rule_id, $rule_ids, true)) {
			return null;
		}

		return [
			'confidence' => self::CONFIDENCE['HIGH'],
			'matched_by' => 'rule_only',
		];
	}

	/**
	 * Match: Rule on specific element.
	 *
	 * @param Issue      $issue Issue to check.
	 * @param Ignore_Rule $rule  Ignore rule.
	 * @return array|null Match result or null.
	 */
	private static function matches_rule_on_element(Issue $issue, Ignore_Rule $rule): ?array {
		$rule_ids = $rule->rule_ids ?? [];

		if (empty($rule_ids) || !in_array($issue->rule_id, $rule_ids, true)) {
			return null;
		}

		$element_match = $rule->element_match ?? [];

		if (empty($element_match)) {
			return null;
		}

		return self::matches_element_data($issue, $element_match);
	}

	/**
	 * Match: Element only (all rules on this element).
	 *
	 * @param Issue      $issue Issue to check.
	 * @param Ignore_Rule $rule  Ignore rule.
	 * @return array|null Match result or null.
	 */
	private static function matches_element_only(Issue $issue, Ignore_Rule $rule): ?array {
		$element_match = $rule->element_match ?? [];

		if (empty($element_match)) {
			return null;
		}

		return self::matches_element_data($issue, $element_match);
	}

	/**
	 * Match issue against element data.
	 *
	 * @param Issue $issue Issue to check.
	 * @param array $element_match Element match data from rule.
	 * @return array|null Match result or null.
	 */
	private static function matches_element_data(Issue $issue, array $element_match): ?array {
		$matches = 0;
		$total_checks = 0;

		// Check selector (direct match or fingerprint)
		if (!empty($element_match['css_selector'])) {
			$total_checks++;

			if (self::selectors_match($issue->selector, $element_match['css_selector'])) {
				$matches++;
			}
		}

		// Check selector fingerprint
		if (!empty($element_match['selector_fingerprint'])) {
			$total_checks++;

			$issue_fp = \ClearA11y\Services\Fingerprint_Service::generate_selector_fingerprint($issue->selector ?? '');
			if ($issue_fp === $element_match['selector_fingerprint']) {
				$matches++;
			}
		}

		// Check element fingerprint
		if (!empty($element_match['element_fingerprint'])) {
			$total_checks++;

			$issue_element_fp = self::get_issue_element_fingerprint($issue);
			if ($issue_element_fp === $element_match['element_fingerprint']) {
				$matches++;
			}
		}

		// Check class list
		if (!empty($element_match['class_list'])) {
			$total_checks++;

			$rule_classes = $element_match['class_list'];
			$issue_classes = self::extract_classes_from_selector($issue->selector ?? '');

			// Check if all rule classes are present in issue
			if (array_intersect($rule_classes, $issue_classes) === $rule_classes) {
				$matches++;
			}
		}

		// Check tag name
		if (!empty($element_match['tag_name'])) {
			$total_checks++;

			$issue_tag = self::extract_tag_from_selector($issue->selector ?? '');
			if (strtolower($issue_tag) === strtolower($element_match['tag_name'])) {
				$matches++;
			}
		}

		// Check accessible name
		if (!empty($element_match['accessible_name'])) {
			$total_checks++;

			$issue_name = $issue->accessible_name ?? '';
			if (strtolower($issue_name) === strtolower($element_match['accessible_name'])) {
				$matches++;
			}
		}

		// Check ancestor chain (partial match)
		if (!empty($element_match['ancestor_chain'])) {
			$total_checks++;
			$rule_ancestors = is_array($element_match['ancestor_chain'])
				? $element_match['ancestor_chain']
				: (json_decode($element_match['ancestor_chain'], true) ?: []);

			if (!empty($rule_ancestors)) {
				$issue_ancestors = $issue->ancestor_chain ? json_decode($issue->ancestor_chain, true) : [];
				// Check if first few ancestors match
				$matched_ancestors = 0;
				$check_count = min(3, count($rule_ancestors));

				for ($i = 0; $i < $check_count; $i++) {
					if (isset($rule_ancestors[$i], $issue_ancestors[$i])) {
						$rule_tag = is_array($rule_ancestors[$i]) ? ($rule_ancestors[$i]['tag'] ?? '') : $rule_ancestors[$i];
						$issue_tag = is_array($issue_ancestors[$i]) ? ($issue_ancestors[$i]['tag'] ?? '') : $issue_ancestors[$i];

						if (strtolower($rule_tag) === strtolower($issue_tag)) {
							$matched_ancestors++;
						}
					}
				}

				if ($matched_ancestors >= 2) {
					$matches++;
				}
			}
		}

		// Calculate confidence
		if ($total_checks > 0 && $matches >= $total_checks * 0.7) {
			// 70% match threshold
			$confidence = $matches === $total_checks
				? self::CONFIDENCE['HIGH']
				: self::CONFIDENCE['PARTIAL'];

			return [
				'confidence' => $confidence,
				'matched_by' => 'element_semantic',
			];
		}

		return null;
	}

	/**
	 * Check if two selectors match.
	 *
	 * @param string|null $selector1 First selector.
	 * @param string      $selector2 Second selector.
	 * @return bool True if selectors match.
	 */
	private static function selectors_match(?string $selector1, string $selector2): bool {
		if ($selector1 === null) {
			return false;
		}

		// Normalize both selectors
		$normalized1 = strtolower(trim($selector1));
		$normalized2 = strtolower(trim($selector2));

		return $normalized1 === $normalized2;
	}

	/**
	 * Check if two URLs match.
	 *
	 * @param string $url1 First URL.
	 * @param string $url2 Second URL.
	 * @return bool True if URLs match.
	 */
	private static function urls_match(string $url1, string $url2): bool {
		$normalized1 = Fingerprint_Service::normalize_url($url1);
		$normalized2 = Fingerprint_Service::normalize_url($url2);

		return $normalized1 === $normalized2;
	}

	/**
	 * Check if URL matches a pattern with wildcard.
	 *
	 * @param string $url     URL to check.
	 * @param string $pattern Pattern (supports * wildcard).
	 * @return bool True if matches.
	 */
	private static function url_matches_pattern(string $url, string $pattern): bool {
		// Normalize URL
		$normalized_url = Fingerprint_Service::normalize_url($url);

		// Convert wildcard pattern to regex
		$regex_pattern = '#^' . str_replace(
			['\\*', '\\?'],
			['.*', '.'],
			preg_quote($pattern, '#')
		) . '$#i';

		return (bool) preg_match($regex_pattern, $normalized_url);
	}

	/**
	 * Get scan item for an issue.
	 *
	 * @param Issue $issue Issue.
	 * @return object|null Scan item or null.
	 */
	private static function get_scan_item_for_issue(Issue $issue): ?object {
		static $cache = [];

		if (!isset($cache[$issue->scan_item_id])) {
			global $wpdb;

			$table = \ClearA11y\Database\Schema::get_table_name('scan_items');
			$cache[$issue->scan_item_id] = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM `{$table}` WHERE id = %d",
					$issue->scan_item_id
				)
			);
		}

		return $cache[$issue->scan_item_id];
	}

	/**
	 * Calculate impact preview for an ignore rule.
	 *
	 * Returns the number of issues and pages that would be affected.
	 *
	 * @param Ignore_Rule $rule  Ignore rule to preview.
	 * @param int         $site_id Site ID.
	 * @return array Impact data with issues and pages count.
	 */
	public static function calculate_impact(Ignore_Rule $rule, int $site_id): array {
		global $wpdb;

		$issues_table = \ClearA11y\Database\Schema::get_table_name('issues');
		$scan_items_table = \ClearA11y\Database\Schema::get_table_name('scan_items');

		// Build WHERE clause based on rule criteria
		$where = ['1=1'];
		$where_params = [];

		// Filter by scope (page)
		if ($rule->scope['scope_type'] === 'page') {
			$url = $rule->scope['url'] ?? '';
			$where[] = 'si.post_url = %s';
			$where_params[] = $url;
		}

		// Filter by scope (content_type)
		if ($rule->scope['scope_type'] === 'content_type') {
			$post_types = $rule->scope['post_types'] ?? [];
			$placeholders = implode(',', array_fill(0, count($post_types), '%s'));
			$where[] = "si.post_type IN ({$placeholders})";
			$where_params = array_merge($where_params, $post_types);
		}

		// Filter by target (rule)
		if ($rule->target_type === 'rule') {
			$rule_ids = $rule->rule_ids ?? [];
			if (!empty($rule_ids)) {
				$placeholders = implode(',', array_fill(0, count($rule_ids), '%s'));
				$where[] = "i.rule_id IN ({$placeholders})";
				$where_params = array_merge($where_params, $rule_ids);
			}
		}

		// Filter by target (rule_on_element)
		if ($rule->target_type === 'rule_on_element') {
			$rule_ids = $rule->rule_ids ?? [];
			if (!empty($rule_ids)) {
				$placeholders = implode(',', array_fill(0, count($rule_ids), '%s'));
				$where[] = "i.rule_id IN ({$placeholders})";
				$where_params = array_merge($where_params, $rule_ids);

				// For element matching in preview, we'll use selector if available
				$element_match = $rule->element_match ?? [];
				$selector = $element_match['css_selector'] ?? null;
				if ($selector) {
					$where[] = 'i.selector = %s';
					$where_params[] = $selector;
				}
			}
		}

		// Filter by target (element) - use selector
		if ($rule->target_type === 'element') {
			$element_match = $rule->element_match ?? [];
			$selector = $element_match['css_selector'] ?? null;
			if ($selector) {
				$where[] = 'i.selector = %s';
				$where_params[] = $selector;
			}
		}

		$where_clause = implode(' AND ', $where);

		// Count matching issues
		// @phpstan-ignore-next-line
		$issue_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT i.id) FROM `{$issues_table}` i
				INNER JOIN `{$scan_items_table}` si ON i.scan_item_id = si.id
				WHERE {$where_clause}",
				...$where_params
			)
		);

		// Count unique pages
		// @phpstan-ignore-next-line
		$page_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT si.post_id) FROM `{$issues_table}` i
				INNER JOIN `{$scan_items_table}` si ON i.scan_item_id = si.id
				WHERE {$where_clause}",
				...$where_params
			)
		);

		return [
			'issues' => $issue_count,
			'pages' => $page_count,
		];
	}

	/**
	 * Get element fingerprint for an issue.
	 *
	 * @param Issue $issue Issue.
	 * @return string Element fingerprint.
	 */
	private static function get_issue_element_fingerprint(Issue $issue): string {
		// Try to get fingerprint from existing data
		if ($issue->node_evidence) {
			$evidence = json_decode($issue->node_evidence, true);
			$node_data = $evidence['node_evidence'] ?? [];

			if (!empty($node_data)) {
				return \ClearA11y\Services\Fingerprint_Service::generate_element_fingerprint($node_data);
			}
		}

		// Generate from issue fields
		$node_data = [
			'tag_name' => self::extract_tag_from_selector($issue->selector ?? ''),
			'role' => $issue->role ?? '',
			'accessible_name' => $issue->accessible_name ?? '',
			'inner_text_snippet' => $issue->inner_text_snippet ?? '',
			'ancestor_chain' => $issue->ancestor_chain ? json_decode($issue->ancestor_chain, true) : [],
			'class_list' => self::extract_classes_from_selector($issue->selector ?? ''),
			'xpath' => $issue->xpath ?? '',
		];

		return \ClearA11y\Services\Fingerprint_Service::generate_element_fingerprint($node_data);
	}

	/**
	 * Extract classes from a CSS selector.
	 *
	 * @param string $selector CSS selector.
	 * @return array Array of class names.
	 */
	private static function extract_classes_from_selector(string $selector): array {
		preg_match_all('/\.([\w-]+)/', $selector, $matches);
		return $matches[1] ?? [];
	}

	/**
	 * Extract tag name from a CSS selector.
	 *
	 * @param string $selector CSS selector.
	 * @return string Tag name or empty string.
	 */
	private static function extract_tag_from_selector(string $selector): string {
		if (preg_match('/^[a-z][a-z0-9]*/i', $selector, $matches)) {
			return $matches[0];
		}
		return '';
	}
}
