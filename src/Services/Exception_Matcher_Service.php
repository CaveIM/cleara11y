<?php
/**
 * Exception Matcher Service
 *
 * Handles flexible matching logic for exception rules against violations.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Services
 */

namespace ClearA11y\Services;

if (! defined('ABSPATH')) {
	exit;
}

use ClearA11y\Models\Exception_Rule;
use ClearA11y\Models\Issue;
use ClearA11y\Database\Exception_Rule_Repository;

/**
 * Exception Matcher Service Class
 */
class Exception_Matcher_Service {

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
	 * Find matching exception rules for a violation.
	 *
	 * @param Issue $issue Issue to check.
	 * @param int   $site_id Site ID.
	 * @param bool  $allow_legacy_reanchor Whether a legacy match may persist a v2 anchor.
	 * @return array Array of matching rules with confidence.
	 */
	public static function find_matches(Issue $issue, int $site_id, bool $allow_legacy_reanchor = true): array {
		// Get active rules for site
		$rules = Exception_Rule_Repository::get_active($site_id);

		$matches = [];

		foreach ($rules as $rule) {
			$match = self::matches_rule($issue, $rule, $allow_legacy_reanchor);
			if ($match) {
				$matches[] = [
					'rule' => $rule,
					'confidence' => $match['confidence'],
					'matched_by' => $match['matched_by'],
					'action' => $match['action'] ?? 'suppressed',
				];
			}
		}

		return $matches;
	}

	/**
	 * Check if an issue matches an exception rule.
	 *
	 * @param Issue      $issue Issue to check.
	 * @param Exception_Rule $rule  Exception rule to match against.
	 * @param bool        $allow_legacy_reanchor Whether the legacy bridge may write.
	 * @return array|null Match result or null if no match.
	 */
	public static function matches_rule(
		Issue $issue,
		Exception_Rule $rule,
		bool $allow_legacy_reanchor = true
	): ?array {
		if (! self::matches_scope($issue, $rule)) {
			return null;
		}

		// Rule-level exceptions are explicit scoped declarations and retain
		// their existing page/site/content-type/URL-pattern behavior.
		if ('rule' === $rule->target_type) {
			return self::matches_rule_only($issue, $rule);
		}

		// An explicit all-rules target uses element identity within its scope.
		// Still reject ambiguous observations of the current rule on that element.
		if (
			'element' === $rule->target_type
			&& ! empty($rule->element_identity_v2)
			&& ! empty($issue->element_identity_v2)
			&& ! empty($issue->violation_identity_v2)
			&& $rule->identity_signature_version === $issue->identity_signature_version
			&& hash_equals($rule->element_identity_v2, $issue->element_identity_v2)
		) {
			$collision = Exception_Rule_Repository::count_identity_observations(
				$issue->scan_item_id,
				$issue->violation_identity_v2,
				$issue->identity_signature_version
			) > 1;
			return [
				'confidence' => $collision ? self::CONFIDENCE['PARTIAL'] : self::CONFIDENCE['EXACT'],
				'matched_by' => $collision ? 'violation_identity_collision' : 'element_identity_v2',
				'action' => $collision ? 'resembles' : 'suppressed',
			];
		}

		// Rule-on-element exceptions require an exact v2 violation
		// identity. Matching only the element is useful context, but must never
		// hide a finding.
		if (! empty($rule->violation_identity_v2)) {
			if (
				! empty($issue->violation_identity_v2)
				&& $rule->identity_signature_version === $issue->identity_signature_version
				&& hash_equals($rule->violation_identity_v2, $issue->violation_identity_v2)
			) {
				$identity_count = Exception_Rule_Repository::count_identity_observations(
					$issue->scan_item_id,
					$issue->violation_identity_v2,
					$issue->identity_signature_version
				);
				if ($identity_count > 1) {
					return [
						'confidence' => self::CONFIDENCE['PARTIAL'],
						'matched_by' => 'violation_identity_collision',
						'action' => 'resembles',
					];
				}

				return [
					'confidence' => self::CONFIDENCE['EXACT'],
					'matched_by' => 'violation_identity_v2',
					'action' => 'suppressed',
				];
			}

			if (
				! empty($rule->element_identity_v2)
				&& ! empty($issue->element_identity_v2)
				&& $rule->identity_signature_version === $issue->identity_signature_version
				&& hash_equals($rule->element_identity_v2, $issue->element_identity_v2)
			) {
				return [
					'confidence' => self::CONFIDENCE['HIGH'],
					'matched_by' => 'element_identity_v2',
					'action' => 'resembles',
				];
			}

			return null;
		}

		// Pre-launch data is disposable, so selector-only occurrence rules are
		// deliberately retired instead of being allowed one probabilistic match.
		return null;
	}

	/**
	 * Match legacy scope while tolerating a page slug change.
	 *
	 * A pre-v2 page exception stored only a mutable URL. Its prior matched
	 * observation still has the stable WordPress post ID, so use that as the
	 * one-time bridge when available.
	 *
	 * @param Issue       $issue Current issue.
	 * @param Exception_Rule $rule Legacy rule.
	 * @return bool True when the scan covers the intended scope.
	 */
	private static function matches_legacy_reanchor_scope(Issue $issue, Exception_Rule $rule): bool {
		if ('page' === ($rule->scope['scope_type'] ?? '') && $issue->post_id > 0) {
			$anchor_post_ids = Exception_Rule_Repository::get_legacy_anchor_post_ids($rule->id);
			if (in_array($issue->post_id, $anchor_post_ids, true)) {
				return true;
			}
		}

		return self::matches_scope($issue, $rule);
	}

	/**
	 * Check if issue matches rule's scope.
	 *
	 * @param Issue      $issue Issue to check.
	 * @param Exception_Rule $rule  Exception rule.
	 * @return bool True if matches scope.
	 */
	private static function matches_scope(Issue $issue, Exception_Rule $rule): bool {
		if ('site' === ($rule->scope['scope_type'] ?? '')) {
			return true;
		}
		$scan_item = self::get_scan_item_for_issue($issue);
		return $scan_item && self::matches_page_scope(
			$rule,
			(string) ($scan_item->post_url ?? ''),
			(string) ($scan_item->post_type ?? '')
		);
	}

	/**
	 * Match a scanned page even when it has no remaining findings.
	 *
	 * @param Exception_Rule $rule Exception rule.
	 * @param string         $page_url Scanned URL.
	 * @param string         $post_type Scanned content type.
	 * @return bool Whether the page is in scope.
	 */
	public static function matches_page_scope(Exception_Rule $rule, string $page_url, string $post_type): bool {
		$scope = $rule->scope;
		switch ($scope['scope_type'] ?? '') {
			case 'site':
				return true;
			case 'page':
				return self::urls_match($page_url, $scope['url'] ?? '');
			case 'content_type':
				return in_array($post_type, $scope['post_types'] ?? [], true);
			case 'url_pattern':
				foreach ($scope['patterns'] ?? [] as $pattern) {
					if (self::url_matches_pattern($page_url, $pattern)) {
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
	 * @param Exception_Rule $rule  Exception rule.
	 * @return array|null Match result or null if no match.
	 */
	private static function matches_target(Issue $issue, Exception_Rule $rule): ?array {
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
	 * @param Exception_Rule $rule  Exception rule.
	 * @return array|null Match result or null.
	 */
	private static function matches_rule_only(Issue $issue, Exception_Rule $rule): ?array {
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
			'action' => 'suppressed',
		];
	}

	/**
	 * Match: Rule on specific element.
	 *
	 * @param Issue      $issue Issue to check.
	 * @param Exception_Rule $rule  Exception rule.
	 * @return array|null Match result or null.
	 */
	private static function matches_rule_on_element(Issue $issue, Exception_Rule $rule): ?array {
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
	 * @param Exception_Rule $rule  Exception rule.
	 * @return array|null Match result or null.
	 */
	private static function matches_element_only(Issue $issue, Exception_Rule $rule): ?array {
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

		// Legacy-only confidence heuristic. This threshold predates the v2
		// identity work and no validation corpus or documented derivation exists.
		// It may re-anchor one old selector-based exception, but its result is
		// never used as the durable suppression identity.
		if ($total_checks > 0 && $matches >= $total_checks * 0.7) {
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
			// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live scan/exception state in custom tables; caching can return stale worker or suppression state; Schema-owned identifiers and fixed SQL fragments; variable values are prepared separately.
			$cache[$issue->scan_item_id] = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM `{$table}` WHERE id = %d",
					$issue->scan_item_id
				)
			);
			// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return $cache[$issue->scan_item_id];
	}

	/**
	 * Calculate impact preview for an exception rule.
	 *
	 * Returns the number of issues and pages that would be affected.
	 *
	 * @param Exception_Rule $rule  Exception rule to preview.
	 * @param int         $site_id Site ID.
	 * @return array Impact data with issues and pages count.
	 */
	public static function calculate_impact(Exception_Rule $rule, int $site_id): array {
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

		$issue_query = "SELECT COUNT(DISTINCT i.id) FROM `{$issues_table}` i
			INNER JOIN `{$scan_items_table}` si ON i.scan_item_id = si.id
			WHERE {$where_clause}";
		$page_query = "SELECT COUNT(DISTINCT si.post_id) FROM `{$issues_table}` i
			INNER JOIN `{$scan_items_table}` si ON i.scan_item_id = si.id
			WHERE {$where_clause}";

		// Site-wide impact can have no filters; prepare only when values exist.
		if (! empty($where_params)) {
			$issue_query = $wpdb->prepare($issue_query, ...$where_params); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is built from fixed predicates; all filter values are in the parameter list.
			$page_query = $wpdb->prepare($page_query, ...$where_params); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is built from fixed predicates; all filter values are in the parameter list.
		}
		$issue_count = (int) $wpdb->get_var($issue_query); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Live scan/exception state in custom tables; caching can return stale worker or suppression state.
		$page_count = (int) $wpdb->get_var($page_query); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Live scan/exception state in custom tables; caching can return stale worker or suppression state.

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
