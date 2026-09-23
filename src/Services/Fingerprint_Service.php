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

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Fingerprint Service Class
 */
class Fingerprint_Service {

	/**
	 * Signature version for the separated identity model.
	 */
	public const IDENTITY_SIGNATURE_VERSION = 3;

	/**
	 * Create versioned element identity from the exact v2 input contract.
	 *
	 * @param array  $node_evidence Browser-derived node evidence.
	 * @param string $source_key Stable source key from template attribution.
	 * @param string $page_url Scanned page URL, used to resolve relative links.
	 * @return array Hash, raw inputs, and signature version.
	 */
	public static function create_element_identity_v2(
		array $node_evidence,
		string $source_key,
		string $page_url = ''
	): array {
		$attributes = isset($node_evidence['attributes']) && is_array($node_evidence['attributes'])
			? $node_evidence['attributes']
			: [];
		$ancestor_roles = $node_evidence['ancestor_role_chain'] ?? [];

		if (! is_array($ancestor_roles)) {
			$ancestor_roles = [];
		}

		$ancestor_roles = array_values(
			array_filter(
				array_map(
					static fn($role): string => strtolower(trim((string) $role)),
					array_slice($ancestor_roles, 0, 5)
				)
			)
		);

		$inputs = [
			'tag_name' => strtolower(trim((string) ($node_evidence['tag_name'] ?? ''))),
			'computed_role' => strtolower(
				trim((string) ($node_evidence['computed_role'] ?? $attributes['role'] ?? ''))
			),
			'input_type' => strtolower(trim((string) ($node_evidence['input_type'] ?? ''))),
			'href_path' => self::normalize_href_target(
				(string) ($attributes['href'] ?? ''),
				(string) ($node_evidence['href_path'] ?? ''),
				$page_url
			),
			'ancestor_role_chain' => $ancestor_roles,
			'source_key' => preg_match('/^[a-f0-9]{64}$/', $source_key) ? $source_key : '',
		];

		return [
			'hash' => self::hash($inputs),
			'inputs' => $inputs,
			'signature_version' => self::IDENTITY_SIGNATURE_VERSION,
		];
	}

	/**
	 * Create versioned violation identity from the exact v2 input contract.
	 *
	 * @param string $rule_id Axe rule ID.
	 * @param string $page_object_key Stable WordPress page object key.
	 * @param string $element_identity_v2 Element identity digest.
	 * @return array Hash, raw inputs, and signature version.
	 */
	public static function create_violation_identity_v2(
		string $rule_id,
		string $page_object_key,
		string $element_identity_v2
	): array {
		$inputs = [
			'rule_id' => sanitize_key($rule_id),
			'page_object_key' => $page_object_key,
			'element_identity_v2' => $element_identity_v2,
		];

		return [
			'hash' => self::hash($inputs),
			'inputs' => $inputs,
			'signature_version' => self::IDENTITY_SIGNATURE_VERSION,
		];
	}

	/**
	 * Resolve a scanned URL to a stable WordPress object key.
	 *
	 * @param string $url Scanned URL.
	 * @param int    $post_id Known WordPress post ID, when available.
	 * @return string Stable object key.
	 */
	public static function resolve_page_object_key(string $url, int $post_id = 0): string {
		if ($post_id > 0 && get_post($post_id)) {
			return 'post:' . $post_id;
		}

		$resolved_post_id = $url ? url_to_postid($url) : 0;
		if ($resolved_post_id > 0) {
			return 'post:' . $resolved_post_id;
		}

		$normalized_url = self::normalize_url($url);
		if ($normalized_url === self::normalize_url(home_url('/'))) {
			return 'front';
		}

		$query = wp_parse_url($url, PHP_URL_QUERY);
		if (is_string($query)) {
			parse_str($query, $query_args);
			if (isset($query_args['s'])) {
				return 'search';
			}
		}

		foreach (get_post_types(['has_archive' => true], 'names') as $post_type) {
			$archive_url = get_post_type_archive_link($post_type);
			if ($archive_url && $normalized_url === self::normalize_url($archive_url)) {
				return 'archive:' . sanitize_key($post_type);
			}
		}

		return 'url:' . $normalized_url;
	}

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
	public static function normalize_url(string $url): string {
		$url = strtolower($url);

		// Remove protocol
		$url = preg_replace('#^https?://#', '', $url);

		// Remove www prefix if present
		$url = preg_replace('#^www\.#', '', $url);

		// Remove fragment
		$url = strtok($url, '#');

		// Remove common tracking parameters
		$tracking_params = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid'];
		$parsed = wp_parse_url('http://' . $url);

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
	 * Normalize an href target for identity.
	 *
	 * Same-site WordPress objects use stable object keys so permalink and slug
	 * changes do not create new element identities. External and unresolved
	 * targets retain the path-only behavior from the v2 contract.
	 *
	 * @param string $href Raw link target.
	 * @param string $fallback_path Browser-normalized path.
	 * @param string $page_url Scanned page URL for resolving relative targets.
	 * @return string Stable object key, normalized path, or an empty string.
	 */
	private static function normalize_href_target(
		string $href,
		string $fallback_path,
		string $page_url
	): string {
		$href = trim($href);
		$fallback_path = self::normalize_href_path($fallback_path ?: $href);

		if ('' === $href || str_starts_with($href, '#')) {
			return $fallback_path;
		}

		$scheme = strtolower((string) wp_parse_url($href, PHP_URL_SCHEME));
		if ($scheme && ! in_array($scheme, ['http', 'https'], true)) {
			return $fallback_path;
		}

		$absolute_url = self::make_absolute_url($href, $page_url);
		if (! $absolute_url || ! self::is_same_site_url($absolute_url)) {
			return $fallback_path;
		}

		$object_key = self::resolve_page_object_key($absolute_url);
		if (! str_starts_with($object_key, 'url:')) {
			return $object_key;
		}

		return $fallback_path;
	}

	/**
	 * Resolve a possibly relative href against the scanned page.
	 *
	 * @param string $href Raw href.
	 * @param string $page_url Scanned page URL.
	 * @return string Absolute URL, or an empty string when it cannot be built.
	 */
	private static function make_absolute_url(string $href, string $page_url): string {
		if (preg_match('#^https?://#i', $href)) {
			return $href;
		}

		if (str_starts_with($href, '//')) {
			$scheme = wp_parse_url(home_url('/'), PHP_URL_SCHEME) ?: 'https';
			return $scheme . ':' . $href;
		}

		if (str_starts_with($href, '/')) {
			$home = wp_parse_url(home_url('/'));
			if (! is_array($home) || empty($home['host'])) {
				return '';
			}

			$authority = ($home['scheme'] ?? 'https') . '://' . $home['host'];
			if (isset($home['port'])) {
				$authority .= ':' . $home['port'];
			}

			return $authority . $href;
		}

		if (! $page_url) {
			return '';
		}

		$page_parts = wp_parse_url($page_url);
		if (! is_array($page_parts) || empty($page_parts['host'])) {
			return '';
		}

		$base_path = (string) ($page_parts['path'] ?? '/');
		$base_path = preg_replace('#/[^/]*$#', '/', $base_path) ?: '/';
		$origin = ($page_parts['scheme'] ?? 'https') . '://' . $page_parts['host'];
		if (isset($page_parts['port'])) {
			$origin .= ':' . $page_parts['port'];
		}

		return $origin . '/' . ltrim($base_path . $href, '/');
	}

	/**
	 * Determine whether a URL belongs to the configured WordPress site.
	 *
	 * @param string $url Absolute URL.
	 * @return bool
	 */
	private static function is_same_site_url(string $url): bool {
		$target_host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
		$home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));

		if (! $target_host || $target_host !== $home_host) {
			return false;
		}

		$target_port = (int) (wp_parse_url($url, PHP_URL_PORT) ?: 0);
		$home_port = (int) (wp_parse_url(home_url('/'), PHP_URL_PORT) ?: 0);

		return $target_port === $home_port;
	}

	/**
	 * Normalize an href target to path only.
	 *
	 * @param string $href Link target.
	 * @return string Normalized path or an empty string.
	 */
	private static function normalize_href_path(string $href): string {
		if ('' === trim($href)) {
			return '';
		}

		$path = wp_parse_url($href, PHP_URL_PATH);
		if (! is_string($path) || '' === $path) {
			return '/';
		}

		return '/' === $path ? '/' : untrailingslashit($path);
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
