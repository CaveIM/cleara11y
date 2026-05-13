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
				// Check if issue's page URL matches
				$scan_item = self::get_scan_item_for_issue($issue);
				if (!$scan_item) {
					return false;
				}

				$issue_url = $scan_item->post_url ?? '';
				$rule_url = $scope['url'] ?? '';

				return self::urls_match($issue_url, $rule_url);

			case 'content_type':
				// Check if issue's post type is in the list
				$scan_item = self::get_scan_item_for_issue($issue);
				if (!$scan_item) {
					return false;
				}

				$post_types = $scope['post_types'] ?? [];
				return in_array($scan_item->post_type, $post_types, true);

			case 'url_pattern':
				// Check if issue's URL matches the pattern
				$scan_item = self::get_scan_item_for_issue($issue);
				if (!$scan_item) {
					return false;
				}

				$issue_url = $scan_item->post_url ?? '';
				$patterns = $scope['patterns'] ?? [];

				foreach ($patterns as $pattern) {
					if (self::url_matches_pattern($issue_url, $pattern)) {
						return true;
					}
				}

				return false;

			default:
				return false;
		}
	}

	/**
	 * Check if issue matches rule's target criteria.
	 *
	 * @param Issue      $issue Issue to check.
	 * @param Ignore_Rule $rule  Ignore rule.
	 * @return array|null Match result or null if no match.
	 */
	private static function matches_target(Issue $issue, Ignore_Rule $rule): ?array {
		$target_type = $rule->target_type;

		switch ($target_type) {
			case 'rule':
				return self::matches_rule_target($issue, $rule);

			case 'element':
				return self::matches_element_target($issue, $rule);

			case 'rule_on_element':
				return self::matches_rule_on_element_target($issue, $rule);

			default:
				return null;
		}
	}

	/**
	 * Check if issue matches rule target (ignore all instances of this rule).
	 *
	 * @param Issue      $issue Issue to check.
	 * @param Ignore_Rule $rule  Ignore rule.
	 * @return array|null Match result or null if no match.
	 */
	private static function matches_rule_target(Issue $issue, Ignore_Rule $rule): ?array {
		$rule_ids = $rule->rule_ids ?? [];

		if (in_array($issue->rule_id, $rule_ids, true)) {
			return [
				'confidence' => self::CONFIDENCE['EXACT'],
				'matched_by' => 'rule_id',
			];
		}

		return null;
	}

	/**
	 * Check if issue matches element target (ignore all issues on this element).
	 *
	 * @param Issue      $issue Issue to check.
	 * @param Ignore_Rule $rule  Ignore rule.
	 * @return array|null Match result or null if no match.
	 */
	private static function matches_element_target(Issue $issue, Ignore_Rule $rule): ?array {
		$element_match = $rule->element_match ?? [];

		// Try exact match first
		if (self::element_matches_exact($issue, $element_match)) {
			return [
				'confidence' => self::CONFIDENCE['EXACT'],
				'matched_by' => 'element_exact',
			];
		}

		// Try fingerprint match
		$element_fingerprint = $element_match['element_fingerprint'] ?? null;
		if ($element_fingerprint && self::element_matches_fingerprint($issue, $element_fingerprint)) {
			return [
				'confidence' => self::CONFIDENCE['HIGH'],
				'matched_by' => 'element_fingerprint',
			];
		}

		// Try semantic match
		$semantic_match = self::element_matches_semantic($issue, $element_match);
		if ($semantic_match) {
			return $semantic_match;
		}

		return null;
	}

	/**
	 * Check if issue matches rule_on_element target.
	 *
	 * @param Issue      $issue Issue to check.
	 * @param Ignore_Rule $rule  Ignore rule.
	 * @return array|null Match result or null if no match.
	 */
	private static function matches_rule_on_element_target(Issue $issue, Ignore_Rule $rule): ?array {
		// First check rule match
		$rule_ids = $rule->rule_ids ?? [];
		if (!in_array($issue->rule_id, $rule_ids, true)) {
			return null;
		}

		// Then check element match
		$element_match = $rule->element_match ?? [];

		// Try exact match first
		if (self::element_matches_exact($issue, $element_match)) {
			return [
				'confidence' => self::CONFIDENCE['EXACT'],
				'matched_by' => 'rule_on_element_exact',
			];
		}

		// Try fingerprint match
		$element_fingerprint = $element_match['element_fingerprint'] ?? null;
		if ($element_fingerprint && self::element_matches_fingerprint($issue, $element_fingerprint)) {
			return [
				'confidence' => self::CONFIDENCE['HIGH'],
				'matched_by' => 'rule_on_element_fingerprint',
			];
		}

		// Try selector match for quick ignores
		$selector = $element_match['css_selector'] ?? null;
		if ($selector && self::selectors_match($issue->selector ?? '', $selector)) {
			return [
				'confidence' => self::CONFIDENCE['HIGH'],
				'matched_by' => 'rule_on_element_selector',
			];
		}

		return null;
	}

	/**
	 * Check if element matches exactly (by selector or fingerprint).
	 *
	 * @param Issue $issue          Issue to check.
	 * @param array $element_match Element match criteria.
	 * @return bool True if exact match.
	 */
	private static function element_matches_exact(Issue $issue, array $element_match): bool {
		// Check selector
		$selector = $element_match['css_selector'] ?? null;
		if ($selector && self::selectors_match($issue->selector ?? '', $selector)) {
			return true;
		}

		// Check selector fingerprint
		$selector_fingerprint = $element_match['selector_fingerprint'] ?? null;
		if ($selector_fingerprint && $issue->fingerprint_strict === $selector_fingerprint) {
			return true;
		}

		return false;
	}

	/**
	 * Check if element matches by fingerprint.
	 *
	 * @param Issue  $issue              Issue to check.
	 * @param string $element_fingerprint Element fingerprint to match.
	 * @return bool True if fingerprint matches.
	 */
	private static function element_matches_fingerprint(Issue $issue, string $element_fingerprint): bool {
		// Check if we have the fingerprint stored
		if ($issue->fingerprint_loose === $element_fingerprint) {
			return true;
		}

		// Try generating from available data
		$fingerprints = Fingerprint_Service::generate_from_issue($issue);
		return in_array($element_fingerprint, $fingerprints, true);
	}

	/**
	 * Check if element matches semantically.
	 *
	 * @param Issue $issue          Issue to check.
	 * @param array $element_match Element match criteria.
	 * @return array|null Match result or null if no match.
	 */
	private static function element_matches_semantic(Issue $issue, array $element_match): ?array {
		$matches = 0;
		$total_checks = 0;

		// Check tag name
		if (!empty($element_match['tag_name'])) {
			$total_checks++;
			// Extract tag from selector
			if (preg_match('/^([a-z][a-z0-9]*)/i', $issue->selector ?? '', $matches)) {
				if (strtolower($matches[1]) === strtolower($element_match['tag_name'])) {
					$matches++;
				}
			}
		}

		// Check accessible name
		if (!empty($element_match['accessible_name']) && !empty($issue->accessible_name)) {
			$total_checks++;
			if (strtolower(trim($issue->accessible_name)) === strtolower(trim($element_match['accessible_name']))) {
				$matches++;
			}
		}

		// Check role
		if (!empty($element_match['role'])) {
			// We'd need to extract role from evidence
			// For now, skip this check
		}

		// Check class list
		if (!empty($element_match['class_list']) && !empty($issue->selector)) {
			$total_checks++;
			$rule_classes = $element_match['class_list'];
			$has_all_classes = true;

			foreach ($rule_classes as $class) {
				if (!strpos($issue->selector, '.' . $class)) {
					$has_all_classes = false;
					break;
				}
			}

			if ($has_all_classes && !empty($rule_classes)) {
				$matches++;
			}
		}

		// Check ancestor chain (partial match)
		if (!empty($element_match['ancestor_chain'])) {
			$total_checks++;
			$rule_ancestors = is_array($element_match['ancestor_chain'])
				? $element_match['ancestor_chain']
				: json_decode($element_match['ancestor_chain'], true) ?: [];

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
			}

			// For element matching in preview, we'll use selector if available
			$element_match = $rule->element_match ?? [];
			$selector = $element_match['css_selector'] ?? null;
			if ($selector) {
				$where[] = 'i.selector = %s';
				$where_params[] = $selector;
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
}
