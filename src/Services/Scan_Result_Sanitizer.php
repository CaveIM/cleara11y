<?php
/**
 * Validate browser evidence and sanitize result metadata before persistence.
 *
 * @package ClearA11y
 */

namespace ClearA11y\Services;

/** Scan results contain source code as data, never display-ready HTML. */
class Scan_Result_Sanitizer {
	/**
	 * Decode and normalize a result payload shared by AJAX and REST workers.
	 *
	 * @param mixed $json JSON result payload.
	 * @return array|\WP_Error
	 */
	public static function sanitize($json): array|\WP_Error {
		if (! is_string($json) || strlen($json) > 10 * MB_IN_BYTES) {
			return self::invalid();
		}
		$data = json_decode($json, true, 64);
		if (JSON_ERROR_NONE !== json_last_error() || ! is_array($data)) {
			return self::invalid();
		}
		foreach (['violations', 'incomplete', 'evidence'] as $key) {
			if (! isset($data[$key]) || ! is_array($data[$key]) || ! self::is_list($data[$key])) {
				return self::invalid();
			}
		}
		// Reject malformed nested values rather than silently dropping findings.
		if (! self::valid_evidence_value($data)) {
			return self::invalid();
		}
		foreach (['violations', 'incomplete'] as $type) {
			foreach ($data[$type] as &$rule) {
				if (! is_array($rule) || ! isset($rule['id'], $rule['nodes']) || ! is_string($rule['id'])
					|| ! preg_match('/^[a-z0-9-]+$/D', $rule['id']) || ! is_array($rule['nodes']) || ! self::is_list($rule['nodes'])) {
					return self::invalid();
				}
				if (isset($rule['impact']) && ! in_array($rule['impact'], ['minor', 'moderate', 'serious', 'critical'], true)) {
					return self::invalid();
				}
				foreach (['description', 'help', 'helpUrl'] as $field) {
					if (isset($rule[$field])) {
						if (! is_string($rule[$field])) {
							return self::invalid();
						}
						$rule[$field] = 'helpUrl' === $field ? esc_url_raw($rule[$field], ['http', 'https']) : sanitize_textarea_field($rule[$field]);
					}
				}
				if (isset($rule['tags'])) {
					if (! is_array($rule['tags']) || ! self::is_list($rule['tags'])) {
						return self::invalid();
					}
					foreach ($rule['tags'] as $tag) {
						if (! is_string($tag) || ! preg_match('/^[a-zA-Z0-9_.-]+$/D', $tag)) {
							return self::invalid();
						}
					}
				}
				foreach ($rule['nodes'] as $node) {
					if (isset($node['failureSummary']) && ! is_string($node['failureSummary'])) {
						return self::invalid();
					}
					if (! is_array($node) || ! isset($node['target'], $node['html']) || ! is_array($node['target'])
						|| ! self::is_list($node['target']) || ! self::valid_target($node['target']) || ! is_string($node['html'])) {
						return self::invalid();
					}
				}
			}
			unset($rule);
		}
		foreach ($data['evidence'] as &$evidence) {
			if (! is_array($evidence)) {
				return self::invalid();
			}
			foreach (['selector', 'rule_id', 'result_type'] as $field) {
				if (isset($evidence[$field]) && ! is_string($evidence[$field])) {
					return self::invalid();
				}
			}
			if (isset($evidence['node_evidence']) && ! is_array($evidence['node_evidence'])) {
				return self::invalid();
			}
			foreach (['message', 'failure_summary', 'help_url'] as $field) {
				if (isset($evidence[$field])) {
					if (! is_string($evidence[$field])) {
						return self::invalid();
					}
					$evidence[$field] = 'help_url' === $field ? esc_url_raw($evidence[$field], ['http', 'https']) : sanitize_textarea_field($evidence[$field]);
				}
			}
			if (isset($evidence['selector_match_count']) && ! is_int($evidence['selector_match_count'])) {
				return self::invalid();
			}
			if (isset($evidence['selector_score']) && (! is_array($evidence['selector_score'])
				|| (isset($evidence['selector_score']['score']) && ! is_numeric($evidence['selector_score']['score'])))) {
				return self::invalid();
			}
			$node = $evidence['node_evidence'] ?? [];
			foreach (['xpath', 'accessible_name', 'inner_text_snippet', 'fingerprint_strict', 'fingerprint_loose', 'outer_html_snippet', 'tag_name', 'computed_role', 'input_type', 'href_path'] as $field) {
				if (isset($node[$field]) && ! is_string($node[$field])) {
					return self::invalid();
				}
			}
			foreach (['dom_path', 'ancestor_chain', 'bounding_box', 'computed_style', 'attributes', 'ancestor_role_chain'] as $field) {
				if (isset($node[$field]) && ! is_array($node[$field])) {
					return self::invalid();
				}
			}
			foreach (['attributes', 'computed_style', 'ancestor_role_chain'] as $field) {
				foreach (($node[$field] ?? []) as $value) {
					if (null !== $value && ! is_string($value)) {
						return self::invalid();
					}
				}
			}
			foreach (($node['bounding_box'] ?? []) as $value) {
				if (! is_int($value) && ! is_float($value)) {
					return self::invalid();
				}
			}
			if (isset($node['signature_version']) && ! is_int($node['signature_version'])) {
				return self::invalid();
			}
			if (isset($evidence['source_descriptor'])) {
				if (! is_array($evidence['source_descriptor'])) {
					return self::invalid();
				}
				foreach ($evidence['source_descriptor'] as $value) {
					if (! is_string($value)) {
						return self::invalid();
					}
				}
			}
		}
		unset($evidence);
		// Store only fields consumed by result processing, not arbitrary request metadata.
		return array_intersect_key($data, array_flip(['violations', 'incomplete', 'evidence']));
	}

	/** Validate axe selectors, including nested shadow-root and iframe targets. */
	private static function valid_target(array $target): bool {
		foreach ($target as $selector) {
			if (is_array($selector)) {
				if (! self::is_list($selector) || ! self::valid_target($selector)) {
					return false;
				}
			} elseif (! is_string($selector)) {
				return false;
			}
		}
		return true;
	}

	/** PHP 8.0-compatible list validation. */
	private static function is_list(array $value): bool {
		return [] === $value || array_keys($value) === range(0, count($value) - 1);
	}

	/**
	 * Evidence is opaque UTF-8 source data. Reject NUL bytes and excessive
	 * nesting, preserving HTML, CSS selectors, whitespace and fingerprints.
	 * Consumers must escape this data for their output context.
	 *
	 * @param mixed $value Decoded JSON value.
	 * @param int   $depth Current nesting depth.
	 * @return bool
	 */
	private static function valid_evidence_value($value, int $depth = 0): bool {
		if ($depth > 32) {
			return false;
		}
		if (is_array($value)) {
			foreach ($value as $key => $child) {
				if ((is_string($key) && ! self::valid_evidence_value($key, $depth + 1)) || ! self::valid_evidence_value($child, $depth + 1)) {
					return false;
				}
			}
			return true;
		}
		if (is_string($value)) {
			return wp_check_invalid_utf8($value) === $value && ! preg_match('/\x00/', $value);
		}
		return null === $value || is_bool($value) || is_int($value) || (is_float($value) && is_finite($value));
	}

	/** Invalid payload response. */
	private static function invalid(): \WP_Error {
		return new \WP_Error('invalid_scan_results', __('The browser returned malformed scan results. Run the scan again.', 'cleara11y'), ['status' => 400]);
	}
}
