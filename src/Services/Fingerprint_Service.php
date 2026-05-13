<?php
/**
 * Fingerprint Service
 *
 * Generates deterministic normalized hashes for violation and element identity.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Services
 */

namespace ClearA11y\Services;

/**
 * Fingerprint Service Class
 */
class Fingerprint_Service {

	/**
	 * Generate violation fingerprint.
	 *
	 * Purpose: Identify a specific violation occurrence.
	 * Generated from: rule_id, normalized URL, normalized element identity, wcag criteria
	 *
	 * @param array $violation_data Violation data from axe-core.
	 * @param string $url Page URL.
	 * @return string SHA-256 hash.
	 */
	public static function generate_violation_fingerprint(array $violation_data, string $url): string {
		$normalized = [
			'rule_id' => $violation_data['id'] ?? '',
			'url' => self::normalize_url($url),
			'selector' => self::normalize_selector($violation_data['nodes'][0]['target'][0] ?? ''),
			'wcag' => self::extract_wcag_tags($violation_data['tags'] ?? []),
		];

		return self::hash($normalized);
	}

	/**
	 * Generate element fingerprint (primary stable identity).
	 *
	 * Purpose: Stable identity for the element itself.
	 * Should NOT rely solely on selectors.
	 *
	 * Weighted inputs: tag_name, accessible_name, role, nearby text, ancestor chain, stable attributes
	 *
	 * @param array $node_evidence Node evidence data.
	 * @return string SHA-256 hash.
	 */
	public static function generate_element_fingerprint(array $node_evidence): string {
		$normalized = [
			'tag' => strtolower($node_evidence['tag_name'] ?? ''),
			'role' => strtolower($node_evidence['role'] ?? ''),
			'accessible_name' => self::normalize_text($node_evidence['accessible_name'] ?? ''),
			'nearby_text' => self::normalize_text($node_evidence['inner_text_snippet'] ?? ''),
			'ancestor_chain' => self::normalize_ancestor_chain($node_evidence['ancestor_chain'] ?? []),
			'classes' => self::normalize_classes($node_evidence),
		];

		return self::hash($normalized);
	}

	/**
	 * Generate structural fingerprint.
	 *
	 * Purpose: Detect major DOM structure changes.
	 * Generated from: subtree structure, child count, parent hierarchy, sibling positioning
	 *
	 * @param array $node_evidence Node evidence data.
	 * @return string SHA-256 hash.
	 */
	public static function generate_structural_fingerprint(array $node_evidence): string {
		$dom_path = isset($node_evidence['dom_path']) ? json_decode($node_evidence['dom_path'], true) : [];

		$normalized = [
			'dom_path' => array_slice($dom_path, 0, 10), // First 10 levels
			'child_count' => count($dom_path),
			'sibling_position' => $node_evidence['xpath'] ?? '',
		];

		return self::hash($normalized);
	}

	/**
	 * Generate selector fingerprint (fallback exact matching).
	 *
	 * Purpose: Fallback exact matching using CSS selector.
	 * Least stable fingerprint - should not be primary identity.
	 *
	 * @param string $selector CSS selector.
	 * @return string SHA-256 hash.
	 */
	public static function generate_selector_fingerprint(string $selector): string {
		$normalized = [
			'selector' => self::normalize_selector($selector),
		];

		return self::hash($normalized);
	}

	/**
	 * Normalize URL for consistent hashing.
	 *
	 * - Lowercase
	 * - Remove protocol
	 * - Remove tracking parameters
	 * - Remove fragment
	 *
	 * @param string $url URL to normalize.
	 * @return string Normalized URL.
	 */
	private static function normalize_url(string $url): string {
		$url = strtolower($url);

		// Remove protocol
		$url = preg_replace('#^https?://#', '', $url);

		// Remove www prefix if present
		$url = preg_replace('#^www\.#', '', $url);

		// Remove fragment
		$url = strtok($url, '#');

		// Remove common tracking parameters
		$tracking_params = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid'];
		$parsed = parse_url('http://' . $url);

		if (isset($parsed['query'])) {
			parse_str($parsed['query'], $params);
			foreach ($tracking_params as $param) {
				unset($params[$param]);
			}

			if (empty($params)) {
				unset($parsed['query']);
			} else {
				$parsed['query'] = http_build_query($params);
			}

			// Reconstruct URL
			$url = $parsed['host'] . ($parsed['path'] ?? '');
			if (isset($parsed['query'])) {
				$url .= '?' . $parsed['query'];
			}
		}

		return rtrim($url, '/');
	}

	/**
	 * Normalize CSS selector.
	 *
	 * @param string $selector CSS selector.
	 * @return string Normalized selector.
	 */
	private static function normalize_selector(string $selector): string {
		// Remove whitespace
		$selector = trim($selector);

		// Normalize spaces
		$selector = preg_replace('/\s+/', ' ', $selector);

		// Lowercase
		return strtolower($selector);
	}

	/**
	 * Normalize text content.
	 *
	 * @param string $text Text to normalize.
	 * @return string Normalized text.
	 */
	private static function normalize_text(string $text): string {
		// Trim and normalize whitespace
		$text = trim($text);
		$text = preg_replace('/\s+/', ' ', $text);

		// Lowercase for comparison
		return strtolower($text);
	}

	/**
	 * Normalize ancestor chain.
	 *
	 * @param array|string $ancestor_chain Ancestor chain data.
	 * @return array Normalized ancestor chain.
	 */
	private static function normalize_ancestor_chain($ancestor_chain): array {
		if (is_string($ancestor_chain)) {
			$ancestor_chain = json_decode($ancestor_chain, true) ?: [];
		}

		// Take first 5 levels only
		$ancestor_chain = array_slice($ancestor_chain, 0, 5);

		// Normalize each level
		return array_map(function($level) {
			if (is_string($level)) {
				return strtolower($level);
			}
			if (is_array($level)) {
				return isset($level['tag']) ? strtolower($level['tag']) : '';
			}
			return '';
		}, $ancestor_chain);
	}

	/**
	 * Normalize class list from node evidence.
	 *
	 * Ignore dynamic/generated classes.
	 *
	 * @param array $node_evidence Node evidence data.
	 * @return array Normalized class list.
	 */
	private static function normalize_classes(array $node_evidence): array {
		if (isset($node_evidence['class_list']) && is_array($node_evidence['class_list'])) {
			$classes = $node_evidence['class_list'];

			// Filter out dynamic classes (common patterns)
			$dynamic_patterns = [
				'/^css-/', // Emotion
				'/^_/', // Styled Components
				'/^[a-f0-9]{6,}$/i', // Hash-like
				'/\[[a-f0-9]{8,}\]/i', // React/Vue generated
			];

			$classes = array_filter($classes, function($class) use ($dynamic_patterns) {
				$class = trim($class);
				foreach ($dynamic_patterns as $pattern) {
					if (preg_match($pattern, $class)) {
						return false;
					}
				}
				return true;
			});

			// Sort and lowercase
			$classes = array_map('strtolower', $classes);
			sort($classes);

			return array_values($classes);
		}

		return [];
	}

	/**
	 * Extract WCAG tags from violation tags.
	 *
	 * @param array $tags Violation tags.
	 * @return array WCAG criteria codes.
	 */
	private static function extract_wcag_tags(array $tags): array {
		$wcag_tags = [];

		foreach ($tags as $tag) {
			if (preg_match('/wcag2[0-9]+a/', $tag)) {
				$wcag_tags[] = $tag;
			}
		}

		sort($wcag_tags);
		return $wcag_tags;
	}

	/**
	 * Generate SHA-256 hash from normalized data.
	 *
	 * @param array $data Normalized data to hash.
	 * @return string Hex digest of hash.
	 */
	private static function hash(array $data): string {
		// Canonical JSON: sort keys, no extra whitespace
		$json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		return hash('sha256', $json);
	}

	/**
	 * Generate a quick fingerprint from issue data for impact preview.
	 *
	 * Simplified version that doesn't require full evidence data.
	 *
	 * @param \ClearA11y\Models\Issue $issue Issue model.
	 * @return array Fingerprints.
	 */
	public static function generate_from_issue(\ClearA11y\Models\Issue $issue): array {
		$node_evidence = [];
		if ($issue->node_evidence) {
			$evidence = json_decode($issue->node_evidence, true);
			$node_evidence = $evidence['node_evidence'] ?? [];
		}

		// Build node evidence from issue fields if not available
		if (empty($node_evidence)) {
			$node_evidence = [
				'tag_name' => '',
				'role' => '',
				'accessible_name' => $issue->accessible_name ?? '',
				'inner_text_snippet' => $issue->inner_text_snippet ?? '',
				'ancestor_chain' => $issue->ancestor_chain ? json_decode($issue->ancestor_chain, true) : [],
				'class_list' => [],
				'xpath' => $issue->xpath ?? '',
			];

			// Extract class names from selector
			if ($issue->selector) {
				preg_match_all('/\.([\w-]+)/', $issue->selector, $matches);
				$node_evidence['class_list'] = $matches[1] ?? [];

				// Try to extract tag name
				if (preg_match('/^([a-z][a-z0-9]*)/i', $issue->selector, $tag_match)) {
					$node_evidence['tag_name'] = $tag_match[1];
				}
			}
		}

		return [
			'violation' => self::generate_violation_fingerprint(
				['id' => $issue->rule_id, 'nodes' => [['target' => [$issue->selector ?? '']]], 'tags' => []],
				'' // URL not needed for this use case
			),
			'element' => self::generate_element_fingerprint($node_evidence),
			'selector' => self::generate_selector_fingerprint($issue->selector ?? ''),
		];
	}
}
