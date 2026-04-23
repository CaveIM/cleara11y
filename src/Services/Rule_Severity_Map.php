<?php
/**
 * Rule Severity Map
 *
 * Maps axe-core rule IDs to standardized severity levels.
 * Based on accessibility-checker's severity classification standard.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Services
 */

namespace ClearA11y\Services;

// Force OPcache to reload this file
if (function_exists('opcache_invalidate')) {
	opcache_invalidate(__FILE__, true);
}

/**
 * Rule Severity Map Class
 */
class Rule_Severity_Map {

	/**
	 * Severity levels following accessibility-checker standard.
	 *
	 * @var int
	 */
	public const SEVERITY_CRITICAL = 1;
	public const SEVERITY_HIGH     = 2;
	public const SEVERITY_MEDIUM   = 3;
	public const SEVERITY_LOW      = 4;

	/**
	 * Get rule severity mapping.
	 *
	 * Maps axe-core rule IDs to numeric severities (1-4).
	 * 1 = Critical, 2 = High, 3 = Medium, 4 = Low
	 *
	 * Based on accessibility-checker's rule classifications.
	 *
	 * @return array Rule ID to severity mapping.
	 */
	public static function get_severity_map(): array {
		$map = [
			// === CRITICAL (Severity 1) ===
			// Empty or missing essential content
			'empty-link'                  => self::SEVERITY_CRITICAL,
			'link-empty'                  => self::SEVERITY_CRITICAL,
			'empty-button'                => self::SEVERITY_CRITICAL,
			'button-name'                 => self::SEVERITY_CRITICAL,
			'empty-heading'               => self::SEVERITY_MEDIUM, // Medium based on a11y-checker

			// Image alt issues
			'image-alt'                   => self::SEVERITY_CRITICAL,
			'image-redundant-alt'         => self::SEVERITY_MEDIUM,
			'img-alt'                     => self::SEVERITY_CRITICAL,
			'alt-space-value'             => self::SEVERITY_HIGH,
			'image-object-alt'            => self::SEVERITY_CRITICAL,
			'area-alt'                    => self::SEVERITY_CRITICAL,
			'input-image-alt'             => self::SEVERITY_CRITICAL,
			'img-alt-missing'             => self::SEVERITY_CRITICAL,
			'img-alt-empty'               => self::SEVERITY_HIGH,

			// Form labels
			'label'                       => self::SEVERITY_CRITICAL,
			'label-title-only'            => self::SEVERITY_CRITICAL,
			'form-field-multiple-labels'  => self::SEVERITY_MEDIUM,
			'labels'                      => self::SEVERITY_CRITICAL,

			// Tables
			'table-headers'               => self::SEVERITY_CRITICAL,
			'table-duplicate-header'      => self::SEVERITY_HIGH,
			'table-structure'             => self::SEVERITY_CRITICAL,
			'th-has-data-cells'           => self::SEVERITY_CRITICAL,
			'duplicate-header'            => self::SEVERITY_HIGH,

			// Language and meta
			'html-has-lang'               => self::SEVERITY_HIGH,
			'html-lang-valid'             => self::SEVERITY_HIGH,
			'lang'                        => self::SEVERITY_CRITICAL,
			'valid-lang'                  => self::SEVERITY_HIGH,
			'title-has-text'              => self::SEVERITY_HIGH,
			'document-title'              => self::SEVERITY_HIGH,

			// ARIA issues
			'aria-valid-attr-value'       => self::SEVERITY_HIGH,
			'aria-valid-attr'             => self::SEVERITY_HIGH,
			'aria-allowed-attr'           => self::SEVERITY_HIGH,
			'aria-required-attr'          => self::SEVERITY_CRITICAL,
			'aria-roles'                  => self::SEVERITY_HIGH,
			'aria-hidden-body'            => self::SEVERITY_CRITICAL,
			'aria-hidden-focus'           => self::SEVERITY_CRITICAL,
			'aria-valid-role'             => self::SEVERITY_HIGH,
			'aria-required-children'      => self::SEVERITY_HIGH,
			'aria-required-parent'        => self::SEVERITY_HIGH,
			'aria-unsupported-attr'       => self::SEVERITY_HIGH,

			// === HIGH (Severity 2) ===
			// Color contrast
			'color-contrast'              => self::SEVERITY_HIGH,
			'color-contrast-enhanced'     => self::SEVERITY_HIGH,
			'color-contrast-error'        => self::SEVERITY_HIGH,

			// Focus and keyboard
			'focus-order-semantics'       => self::SEVERITY_HIGH,
			'tabindex'                    => self::SEVERITY_HIGH,
			'focusable-content'           => self::SEVERITY_HIGH,
			'focus-trap'                  => self::SEVERITY_CRITICAL,
			'focusable-not-tabbable'      => self::SEVERITY_MEDIUM,
			'keyboard'                    => self::SEVERITY_CRITICAL,

			// Links and navigation
			'link-in-text-block'          => self::SEVERITY_HIGH,
			'link-name'                   => self::SEVERITY_CRITICAL,
			'name-role-value'             => self::SEVERITY_HIGH,
			'aria-allowed-role'           => self::SEVERITY_HIGH,
			'link-text'                   => self::SEVERITY_CRITICAL,
			'area-alt'                    => self::SEVERITY_CRITICAL,
			'image-map-alt'               => self::SEVERITY_CRITICAL,

			// Iframes and embedded content
			'frame-title'                 => self::SEVERITY_HIGH,
			'frame-title-unique'          => self::SEVERITY_HIGH,

			// Media
			'video-caption'               => self::SEVERITY_HIGH,
			'audio-caption'               => self::SEVERITY_HIGH,
			'video-description'           => self::SEVERITY_HIGH,
			'object-alt'                  => self::SEVERITY_HIGH,
			'embed-alt'                   => self::SEVERITY_HIGH,

			// Lists and structure
			'list'                        => self::SEVERITY_MEDIUM,
			'listitem'                    => self::SEVERITY_MEDIUM,
			'definition-list'             => self::SEVERITY_MEDIUM,
			'dlitem'                      => self::SEVERITY_MEDIUM,
			'empty-list'                  => self::SEVERITY_LOW,

			// === MEDIUM (Severity 3) ===
			// Orientation and scale
			'meta-viewport-large'         => self::SEVERITY_MEDIUM,
			'meta-viewport'               => self::SEVERITY_CRITICAL,
			'viewport'                    => self::SEVERITY_HIGH,

			// Skip links and landmarks
			'skip-link'                   => self::SEVERITY_MEDIUM,
			'region'                      => self::SEVERITY_MEDIUM,
			'landmark'                    => self::SEVERITY_MEDIUM,

			// Identifiers
			'duplicate-id'                => self::SEVERITY_MEDIUM,
			'duplicate-id-aria'           => self::SEVERITY_HIGH,
			'id-attr-unique'              => self::SEVERITY_MEDIUM,

			// Headings
			'empty-heading'               => self::SEVERITY_MEDIUM,
			'heading-order'               => self::SEVERITY_HIGH,
			'heading-level'               => self::SEVERITY_HIGH,
			'no-heading'                  => self::SEVERITY_MEDIUM,

			// Content and formatting
			'p-as-heading'                => self::SEVERITY_LOW,
			'bold-and-italic'             => self::SEVERITY_LOW,
			'paragraph-like-labels'       => self::SEVERITY_MEDIUM,

			// === LOW (Severity 4) ===
			// Best practices and recommendations
			'html-xmlns'                  => self::SEVERITY_LOW,
			'image-redundant-alt'         => self::SEVERITY_MEDIUM,
			'landmark-unique'             => self::SEVERITY_LOW,
			'landmark-no-duplicate-banner' => self::SEVERITY_LOW,
			'landmark-no-duplicate-contentinfo' => self::SEVERITY_LOW,
			'landmark-main-is-top-level'  => self::SEVERITY_LOW,
			'no-duplicate-banner'         => self::SEVERITY_LOW,
			'no-duplicate-contentinfo'    => self::SEVERITY_LOW,
			'main-is-top-level'           => self::SEVERITY_LOW,

			// Deprecated or legacy
			'accesskeys'                  => self::SEVERITY_MEDIUM,
			'css-orientation-lock'        => self::SEVERITY_MEDIUM,
			'avoid-inline-spacing'        => self::SEVERITY_LOW,

			// Additional axe-core rules
			'aria-hidden-body'            => self::SEVERITY_CRITICAL,
			'aria-input-field-name'       => self::SEVERITY_CRITICAL,
			'abstract'                    => self::SEVERITY_MEDIUM,
			'access-keys'                 => self::SEVERITY_MEDIUM,
			'ajax-wealth'                 => self::SEVERITY_LOW,
			'aria-allowed-attr'           => self::SEVERITY_HIGH,
			'aria-conditional-attr'       => self::SEVERITY_HIGH,
			'aria-deprecated-role'        => self::SEVERITY_MEDIUM,
			'aria-dialog-role'            => self::SEVERITY_MEDIUM,
			'aria-dpub-role-fallback'     => self::SEVERITY_MEDIUM,
			'aria-hidden-element'         => self::SEVERITY_MEDIUM,
			'aria-label'                  => self::SEVERITY_HIGH,
			'aria-live'                   => self::SEVERITY_MEDIUM,
			'aria-prohibited-attr'        => self::SEVERITY_CRITICAL,
			'aria-toggle-field-name'      => self::SEVERITY_CRITICAL,
			'aria-tooltip'                => self::SEVERITY_MEDIUM,
			'aria-valid-attr-value'       => self::SEVERITY_HIGH,
			'aria-valid-attr'             => self::SEVERITY_HIGH,
			'attr-spacings'               => self::SEVERITY_LOW,
			'autocomplete-valid'          => self::SEVERITY_LOW,
			'avoid-inline-styles'         => self::SEVERITY_LOW,
			'bdo-not-ltr'                 => self::SEVERITY_LOW,
			'blink'                       => self::SEVERITY_CRITICAL,
			'button-has-visible-text'     => self::SEVERITY_CRITICAL,
			'button-name'                 => self::SEVERITY_CRITICAL,
			'by-content-only'             => self::SEVERITY_CRITICAL,
			'by-id'                       => self::SEVERITY_HIGH,
			'by-name'                     => self::SEVERITY_HIGH,
			'by-title'                    => self::SEVERITY_MEDIUM,
			'checkboxgroup'               => self::SEVERITY_MEDIUM,
			'color-contrast'              => self::SEVERITY_HIGH,
			'color-contrast-enhanced'     => self::SEVERITY_HIGH,
			'contentinfo-missing'         => self::SEVERITY_LOW,
			'context'                     => self::SEVERITY_LOW,
			'css-no-overlap'              => self::SEVERITY_MEDIUM,
			'custom-element-defined'      => self::SEVERITY_LOW,
			'definition-list'             => self::SEVERITY_MEDIUM,
			'dlitem'                      => self::SEVERITY_MEDIUM,
			'dl-has-dt'                   => self::SEVERITY_MEDIUM,
			'doctype'                     => self::SEVERITY_LOW,
			'document-title'              => self::SEVERITY_HIGH,
			'duplicate-id'                => self::SEVERITY_MEDIUM,
			'duplicate-id-active'         => self::SEVERITY_HIGH,
			'duplicate-id-aria'           => self::SEVERITY_HIGH,
			'empty-heading'               => self::SEVERITY_MEDIUM,
			'empty-link'                  => self::SEVERITY_CRITICAL,
			'empty-list'                  => self::SEVERITY_LOW,
			'empty-table-header'          => self::SEVERITY_CRITICAL,
			'escape-slash'                => self::SEVERITY_LOW,
			'fieldset'                    => self::SEVERITY_MEDIUM,
			'fieldset-legend'             => self::SEVERITY_MEDIUM,
			'form-field-multiple-labels'  => self::SEVERITY_MEDIUM,
			'form-field-multiple-labels-visual' => self::SEVERITY_MEDIUM,
			'frame-focusable-content'     => self::SEVERITY_MEDIUM,
			'frame-title'                 => self::SEVERITY_HIGH,
			'frame-title-unique'          => self::SEVERITY_HIGH,
			'header-present'              => self::SEVERITY_HIGH,
			'heading-order'               => self::SEVERITY_HIGH,
			'heading-parent'              => self::SEVERITY_MEDIUM,
			'hidden-content'              => self::SEVERITY_LOW,
			'hide-focus'                  => self::SEVERITY_MEDIUM,
			'html-has-lang'               => self::SEVERITY_HIGH,
			'html-lang-valid'             => self::SEVERITY_HIGH,
			'html-xmlns'                  => self::SEVERITY_LOW,
			'image-alt'                   => self::SEVERITY_CRITICAL,
			'image-alt-space'             => self::SEVERITY_HIGH,
			'image-button-alt'            => self::SEVERITY_CRITICAL,
			'image-redundant-alt'         => self::SEVERITY_MEDIUM,
			'img-alt-missing'             => self::SEVERITY_CRITICAL,
			'img-alt-empty'               => self::SEVERITY_HIGH,
			'img-has-alt'                 => self::SEVERITY_CRITICAL,
			'img-non-decorative-alt'      => self::SEVERITY_CRITICAL,
			'img-redundant-alt'           => self::SEVERITY_MEDIUM,
			'implicit-role'               => self::SEVERITY_LOW,
			'input-button-name'           => self::SEVERITY_CRITICAL,
			'input-checkbox-label'        => self::SEVERITY_HIGH,
			'input-email'                 => self::SEVERITY_LOW,
			'input-file-label'            => self::SEVERITY_HIGH,
			'input-has-alt'               => self::SEVERITY_CRITICAL,
			'input-image-alt'             => self::SEVERITY_CRITICAL,
			'input-label'                 => self::SEVERITY_CRITICAL,
			'input-password-label'        => self::SEVERITY_CRITICAL,
			'input-radio-label'           => self::SEVERITY_HIGH,
			'input-text-label'            => self::SEVERITY_CRITICAL,
			'label'                       => self::SEVERITY_CRITICAL,
			'label-content'               => self::SEVERITY_CRITICAL,
			'label-for'                   => self::SEVERITY_CRITICAL,
			'label-title-only'            => self::SEVERITY_CRITICAL,
			'landmark'                    => self::SEVERITY_MEDIUM,
			'landmark-banner-is-top-level' => self::SEVERITY_LOW,
			'landmark-contentinfo-is-top-level' => self::SEVERITY_LOW,
			'landmark-main-is-top-level'  => self::SEVERITY_LOW,
			'landmark-no-duplicate-banner' => self::SEVERITY_LOW,
			'landmark-no-duplicate-contentinfo' => self::SEVERITY_LOW,
			'landmark-one-main'           => self::SEVERITY_LOW,
			'lang'                        => self::SEVERITY_CRITICAL,
			'lang-mismatch'               => self::SEVERITY_LOW,
			'layout-table'                => self::SEVERITY_LOW,
			'link-ambiguous'              => self::SEVERITY_CRITICAL,
			'link-in-text-block'          => self::SEVERITY_HIGH,
			'link-name'                   => self::SEVERITY_CRITICAL,
			'link-text'                   => self::SEVERITY_CRITICAL,
			'list'                        => self::SEVERITY_MEDIUM,
			'list-and-listitem'           => self::SEVERITY_MEDIUM,
			'listitem'                    => self::SEVERITY_MEDIUM,
			'listitem-contains-img'       => self::SEVERITY_MEDIUM,
			'main-has-landmark'           => self::SEVERITY_LOW,
			'marquee'                     => self::SEVERITY_CRITICAL,
			'meta-refresh'                => self::SEVERITY_CRITICAL,
			'meta-viewport'               => self::SEVERITY_CRITICAL,
			'meta-viewport-large'         => self::SEVERITY_MEDIUM,
			'misleading-aria-label'       => self::SEVERITY_HIGH,
			'missing-aria-roles'          => self::SEVERITY_LOW,
			'misused-aria-labels'         => self::SEVERITY_HIGH,
			'more-than-one-main'          => self::SEVERITY_MEDIUM,
			'name-role-value'             => self::SEVERITY_HIGH,
			'no-autoplay-audio'           => self::SEVERITY_HIGH,
			'no-heading'                  => self::SEVERITY_MEDIUM,
			'no-placeholder'              => self::SEVERITY_LOW,
			'object-alt'                  => self::SEVERITY_HIGH,
			'optgroup-label'              => self::SEVERITY_MEDIUM,
			'p-as-heading'                => self::SEVERITY_LOW,
			'page-has-title'              => self::SEVERITY_HIGH,
			'presentation-role'           => self::SEVERITY_LOW,
			'radiogroup'                  => self::SEVERITY_MEDIUM,
			'redundant-aria-label'        => self::SEVERITY_MEDIUM,
			'region'                      => self::SEVERITY_MEDIUM,
			'role-img-alt'                => self::SEVERITY_HIGH,
			'role-listitem'               => self::SEVERITY_LOW,
			'scrollable-region-focusable' => self::SEVERITY_HIGH,
			'select-name'                 => self::SEVERITY_CRITICAL,
			'server-side-image-map'       => self::SEVERITY_CRITICAL,
			'skip-content'                => self::SEVERITY_MEDIUM,
			'skip-link'                   => self::SEVERITY_MEDIUM,
			'structurally-distinct'       => self::SEVERITY_LOW,
			'svg-img-alt'                 => self::SEVERITY_CRITICAL,
			'table-headers'               => self::SEVERITY_CRITICAL,
			'table-duplicate-header'      => self::SEVERITY_HIGH,
			'tabindex'                    => self::SEVERITY_HIGH,
			'target-size'                 => self::SEVERITY_MEDIUM,
			'td-has-header'               => self::SEVERITY_CRITICAL,
			'td-headers-attr'              => self::SEVERITY_MEDIUM,
			'template'                    => self::SEVERITY_LOW,
			'text-alternative'            => self::SEVERITY_CRITICAL,
			'th-has-data-cells'           => self::SEVERITY_CRITICAL,
			'time'                        => self::SEVERITY_LOW,
			'title-attribute'             => self::SEVERITY_LOW,
			'title-element'               => self::SEVERITY_HIGH,
			'title-has-text'              => self::SEVERITY_HIGH,
			'unique-landmark'             => self::SEVERITY_LOW,
			'valid-aria-roles'            => self::SEVERITY_LOW,
			'valid-lang'                  => self::SEVERITY_HIGH,
			'video-caption'               => self::SEVERITY_HIGH,
			'video-description'           => self::SEVERITY_HIGH,
			'video-title'                 => self::SEVERITY_MEDIUM,
			'visibility'                  => self::SEVERITY_LOW,
			'wcag2a'                      => self::SEVERITY_MEDIUM,
			'wcag2aa'                     => self::SEVERITY_MEDIUM,
			'xml-lang'                    => self::SEVERITY_LOW,

			// === DEFAULT FALLBACK ===
			// For any rules not explicitly mapped, use a sensible default
			'_default'                    => self::SEVERITY_MEDIUM,
		];

		/**
		 * Filter rule severity map.
		 *
		 * Allows plugins/themes to override rule severities.
		 *
		 * @param array $map Rule ID to severity mapping.
		 */
		return apply_filters('cleara11y_rule_severity_map', $map);
	}

	/**
	 * Get severity for a specific rule.
	 *
	 * @param string $rule_id Axe-core rule ID.
	 * @return int Severity level (1-4).
	 */
	public static function get_severity(string $rule_id): int {
		$map = self::get_severity_map();

		// Return mapped severity or default
		return $map[$rule_id] ?? $map['_default'] ?? self::SEVERITY_MEDIUM;
	}

	/**
	 * Convert numeric severity to category string.
	 *
	 * Maps to ClearA11y's category system: critical, moderate, minor
	 *
	 * @param int $severity Numeric severity (1-4).
	 * @return string Severity category.
	 */
	public static function severity_to_category(int $severity): string {
		switch ($severity) {
			case self::SEVERITY_CRITICAL:
				return 'critical';
			case self::SEVERITY_HIGH:
				return 'critical'; // High maps to critical in our 3-tier system
			case self::SEVERITY_MEDIUM:
				return 'moderate';
			case self::SEVERITY_LOW:
				return 'minor';
			default:
				return 'moderate';
		}
	}

	/**
	 * Convert category string to numeric severity range.
	 *
	 * @param string $category Category (critical, moderate, minor).
	 * @return array Array of numeric severities in this category.
	 */
	public static function category_to_severity_numbers(string $category): array {
		switch ($category) {
			case 'critical':
				return [self::SEVERITY_CRITICAL, self::SEVERITY_HIGH];
			case 'moderate':
				return [self::SEVERITY_MEDIUM];
			case 'minor':
				return [self::SEVERITY_LOW];
			default:
				return [];
		}
	}

	/**
	 * Get severity label for display.
	 *
	 * @param int $severity Numeric severity (1-4).
	 * @return string Human-readable label.
	 */
	public static function get_severity_label(int $severity): string {
		switch ($severity) {
			case self::SEVERITY_CRITICAL:
				return __('Critical', 'cleara11y');
			case self::SEVERITY_HIGH:
				return __('High', 'cleara11y');
			case self::SEVERITY_MEDIUM:
				return __('Medium', 'cleara11y');
			case self::SEVERITY_LOW:
				return __('Low', 'cleara11y');
			default:
				return __('Unknown', 'cleara11y');
		}
	}
}
