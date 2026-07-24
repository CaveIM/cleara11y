<?php
/**
 * Template Attribution Service
 *
 * Adds opaque source markers to tokenized scan responses only.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Services
 */

namespace ClearA11y\Services;

/**
 * Template Attribution Service Class
 */
class Template_Attribution_Service {

	/**
	 * Whether attribution is active for this request.
	 *
	 * @var bool
	 */
	private static bool $active = false;

	/**
	 * Request-scoped source counter.
	 *
	 * @var int
	 */
	private static int $counter = 0;

	/**
	 * Request-scoped source descriptors.
	 *
	 * @var array<string,array>
	 */
	private static array $sources = [];

	/**
	 * Top-level template source ID.
	 *
	 * @var string|null
	 */
	private static ?string $document_source_id = null;

	/**
	 * Output-buffer levels opened for classic sidebars.
	 *
	 * @var array<int,array>
	 */
	private static array $sidebar_buffers = [];

	/**
	 * Enable attribution hooks for the current tokenized request.
	 *
	 * @return void
	 */
	public static function enable(): void {
		if (self::$active) {
			return;
		}

		self::$active = true;
		add_filter('render_block', [self::class, 'attribute_block'], PHP_INT_MAX, 2);
		add_filter('the_content', [self::class, 'attribute_content'], PHP_INT_MAX);
		add_filter('do_shortcode_tag', [self::class, 'attribute_shortcode'], PHP_INT_MAX, 4);
		add_filter('template_include', [self::class, 'record_template'], PHP_INT_MAX);
		add_action('dynamic_sidebar_before', [self::class, 'start_sidebar'], PHP_INT_MAX, 2);
		add_action('dynamic_sidebar_after', [self::class, 'end_sidebar'], PHP_INT_MAX, 2);
		add_action('wp_footer', [self::class, 'print_source_map'], 1);
	}

	/**
	 * Whether attribution is active.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return self::$active;
	}

	/**
	 * Attribute a rendered block.
	 *
	 * @param string $block_content Rendered block HTML.
	 * @param array  $block Parsed block data.
	 * @return string
	 */
	public static function attribute_block(string $block_content, array $block): string {
		if (empty($block['blockName']) || ! is_string($block['blockName'])) {
			return $block_content;
		}

		$block_name = $block['blockName'];
		$source_type = 'block';
		$source_ref = $block_name;

		if ('core/template-part' === $block_name) {
			$source_type = 'template_part';
			$slug = isset($block['attrs']['slug']) ? sanitize_key((string) $block['attrs']['slug']) : 'unknown';
			$theme = isset($block['attrs']['theme']) ? sanitize_key((string) $block['attrs']['theme']) : '';
			$source_ref = $theme ? $theme . ':' . $slug : $slug;
		}

		$descriptor = self::descriptor_for_block($source_type, $source_ref, $block_name);
		return self::wrap_fragment($block_content, $descriptor);
	}

	/**
	 * Attribute the post-content boundary.
	 *
	 * @param string $content Rendered post content.
	 * @return string
	 */
	public static function attribute_content(string $content): string {
		return self::wrap_fragment(
			$content,
			[
				'source_type' => 'content',
				'source_ref' => 'post:' . (int) get_the_ID(),
				'owner_type' => 'content',
				'owner_name' => 'Editor-authored content',
			]
		);
	}

	/**
	 * Attribute shortcode output.
	 *
	 * @param string       $output Shortcode output.
	 * @param string       $tag Shortcode tag.
	 * @param array|string $attr Shortcode attributes.
	 * @param array        $match Shortcode regex match.
	 * @return string
	 */
	public static function attribute_shortcode(string $output, string $tag, array|string $attr, array $match): string {
		global $shortcode_tags;

		unset($attr, $match);
		$descriptor = [
			'source_type' => 'shortcode',
			'source_ref' => sanitize_key($tag),
			'owner_type' => 'unknown',
			'owner_name' => '',
		];
		$callback = $shortcode_tags[$tag] ?? null;
		$file = self::callback_file($callback);

		if ($file) {
			$descriptor = array_merge($descriptor, self::classify_file_owner($file));
		}

		return self::wrap_fragment($output, $descriptor);
	}

	/**
	 * Record the top-level template as the document fallback source.
	 *
	 * @param string $template Template file path.
	 * @return string
	 */
	public static function record_template(string $template): string {
		$descriptor = array_merge(
			[
				'source_type' => 'template',
				'source_ref' => self::relative_path($template),
			],
			self::classify_file_owner($template)
		);

		self::$document_source_id = self::register_source($descriptor);
		return $template;
	}

	/**
	 * Buffer a classic sidebar so markers are emitted only as a balanced pair.
	 *
	 * @param int|string $index Sidebar index.
	 * @param bool       $has_widgets Whether the sidebar contains widgets.
	 * @return void
	 */
	public static function start_sidebar(int|string $index, bool $has_widgets): void {
		if (! self::$active || ! $has_widgets) {
			return;
		}

		$level = ob_get_level();
		ob_start();
		self::$sidebar_buffers[] = [
			'index' => sanitize_text_field((string) $index),
			'level' => $level,
		];
	}

	/**
	 * Close a classic sidebar buffer and emit balanced attribution markers.
	 *
	 * @param int|string $index Sidebar index.
	 * @param bool       $has_widgets Whether the sidebar contains widgets.
	 * @return void
	 */
	public static function end_sidebar(int|string $index, bool $has_widgets): void {
		unset($index);
		if (! self::$active || ! $has_widgets || empty(self::$sidebar_buffers)) {
			return;
		}

		$buffer = array_pop(self::$sidebar_buffers);
		if (ob_get_level() !== $buffer['level'] + 1) {
			// Another component left a nested buffer open. Leave all output
			// untouched rather than risk closing or corrupting its buffer.
			return;
		}

		$html = (string) ob_get_clean();
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Complete widget HTML is preserved verbatim.
		echo self::wrap_fragment(
			$html,
			[
				'source_type' => 'widget',
				'source_ref' => 'sidebar:' . $buffer['index'],
				'owner_type' => 'unknown',
				'owner_name' => '',
			]
		);
	}

	/**
	 * Print the request-scoped source map after page content has rendered.
	 *
	 * @return void
	 */
	public static function print_source_map(): void {
		if (! self::$active) {
			return;
		}

		$payload = [
			'sources' => self::$sources,
			'documentSourceId' => self::$document_source_id,
		];

		printf(
			'<script type="application/json" id="cleara11y-attribution-map">%s</script>',
			wp_json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
		);
	}

	/**
	 * Wrap a complete HTML fragment with balanced opaque markers.
	 *
	 * @param string $html Complete HTML fragment.
	 * @param array  $descriptor Source descriptor.
	 * @return string
	 */
	private static function wrap_fragment(string $html, array $descriptor): string {
		if (! self::$active || '' === trim($html) || self::is_unsafe_fragment($html)) {
			return $html;
		}

		$source_id = self::register_source($descriptor);
		return sprintf('<!--a11y:s:%1$s-->%2$s<!--a11y:e:%1$s-->', $source_id, $html);
	}

	/**
	 * Register and normalize a source descriptor.
	 *
	 * @param array $descriptor Source descriptor.
	 * @return string
	 */
	private static function register_source(array $descriptor): string {
		$descriptor = [
			'source_type' => sanitize_key((string) ($descriptor['source_type'] ?? 'unknown')),
			'source_ref' => sanitize_text_field((string) ($descriptor['source_ref'] ?? 'unknown')),
			'owner_type' => sanitize_key((string) ($descriptor['owner_type'] ?? 'unknown')),
			'owner_name' => sanitize_text_field((string) ($descriptor['owner_name'] ?? '')),
		];
		$descriptor['source_key'] = hash(
			'sha256',
			implode("\0", [
				$descriptor['source_type'],
				$descriptor['source_ref'],
				$descriptor['owner_type'],
				$descriptor['owner_name'],
			])
		);

		self::$counter++;
		$source_id = str_pad(base_convert((string) self::$counter, 10, 36), 6, '0', STR_PAD_LEFT);
		self::$sources[$source_id] = $descriptor;
		return $source_id;
	}

	/**
	 * Build ownership information for a block.
	 *
	 * @param string $source_type Source type.
	 * @param string $source_ref Source reference.
	 * @param string $block_name Registered block name.
	 * @return array
	 */
	private static function descriptor_for_block(string $source_type, string $source_ref, string $block_name): array {
		$descriptor = [
			'source_type' => $source_type,
			'source_ref' => $source_ref,
			'owner_type' => str_starts_with($block_name, 'core/') ? 'core' : 'unknown',
			'owner_name' => str_starts_with($block_name, 'core/') ? 'WordPress' : '',
		];

		if (! str_starts_with($block_name, 'core/') && class_exists('\WP_Block_Type_Registry')) {
			$block_type = \WP_Block_Type_Registry::get_instance()->get_registered($block_name);
			$file = null;

			if ($block_type && is_callable($block_type->render_callback)) {
				$file = self::callback_file($block_type->render_callback);
			} elseif ($block_type && isset($block_type->path) && is_string($block_type->path)) {
				$file = $block_type->path;
			}

			if ($file) {
				$descriptor = array_merge($descriptor, self::classify_file_owner($file));
			}
		}

		return $descriptor;
	}

	/**
	 * Resolve a callback to its declaring file.
	 *
	 * @param mixed $callback Callable.
	 * @return string|null
	 */
	private static function callback_file(mixed $callback): ?string {
		if (! is_callable($callback)) {
			return null;
		}

		try {
			$reflection = is_array($callback)
				? new \ReflectionMethod($callback[0], $callback[1])
				: new \ReflectionFunction(\Closure::fromCallable($callback));
			$file = $reflection->getFileName();
			return is_string($file) ? $file : null;
		} catch (\ReflectionException|\TypeError $exception) {
			return null;
		}
	}

	/**
	 * Classify a source file by WordPress ownership boundaries.
	 *
	 * @param string $file Absolute file path.
	 * @return array
	 */
	private static function classify_file_owner(string $file): array {
		$file = wp_normalize_path($file);
		$stylesheet_directory = wp_normalize_path(get_stylesheet_directory());
		$template_directory = wp_normalize_path(get_template_directory());
		$boundaries = [
			'mu_plugin' => defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : '',
			'plugin' => defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : '',
		];
		if ($stylesheet_directory !== $template_directory) {
			$boundaries['child_theme'] = $stylesheet_directory;
		}
		$boundaries['theme'] = $template_directory;
		$boundaries['core'] = defined('ABSPATH') && defined('WPINC') ? ABSPATH . WPINC : '';

		foreach ($boundaries as $owner_type => $directory) {
			$directory = wp_normalize_path((string) $directory);
			if ('' === $directory || ! str_starts_with($file, trailingslashit($directory))) {
				continue;
			}

			$owner_name = '';
			if ('plugin' === $owner_type || 'mu_plugin' === $owner_type) {
				$relative = ltrim(substr($file, strlen($directory)), '/');
				$owner_name = self::plugin_name($relative, 'mu_plugin' === $owner_type);
			} elseif ('theme' === $owner_type || 'child_theme' === $owner_type) {
				$theme = wp_get_theme('child_theme' === $owner_type ? get_stylesheet() : get_template());
				$owner_name = $theme->exists() ? $theme->get('Name') : basename($directory);
			} elseif ('core' === $owner_type) {
				$owner_name = 'WordPress';
			}

			return [
				'owner_type' => $owner_type,
				'owner_name' => sanitize_text_field((string) $owner_name),
			];
		}

		return [
			'owner_type' => 'unknown',
			'owner_name' => '',
		];
	}

	/**
	 * Resolve a plugin display name from its registered headers.
	 *
	 * @param string $relative_file File path relative to the plugin directory.
	 * @param bool   $must_use Whether this is a must-use plugin.
	 * @return string
	 */
	private static function plugin_name(string $relative_file, bool $must_use): string {
		if (! function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = $must_use && function_exists('get_mu_plugins') ? get_mu_plugins() : get_plugins();
		$directory = strtok($relative_file, '/') ?: $relative_file;

		foreach ($plugins as $plugin_file => $headers) {
			if (
				$plugin_file === $relative_file
				|| str_starts_with($plugin_file, trailingslashit($directory))
			) {
				return sanitize_text_field((string) ($headers['Name'] ?? $directory));
			}
		}

		return sanitize_text_field($directory);
	}

	/**
	 * Convert an absolute path to a stable root-relative path.
	 *
	 * @param string $file File path.
	 * @return string
	 */
	private static function relative_path(string $file): string {
		$file = wp_normalize_path($file);
		$root = wp_normalize_path(ABSPATH);
		return str_starts_with($file, $root) ? ltrim(substr($file, strlen($root)), '/') : basename($file);
	}

	/**
	 * Skip fragments whose root is a raw-text or non-HTML context.
	 *
	 * @param string $html HTML fragment.
	 * @return bool
	 */
	private static function is_unsafe_fragment(string $html): bool {
		return 1 === preg_match('/^\s*<(?:head|script|style|svg)(?:\s|>)/i', $html);
	}
}
