<?php
/**
 * Scan Token Manager
 *
 * Handles generation and validation of scan tokens for client-side scanning.
 *
 * @package ClearA11y
 * @namespace ClearA11y\Services
 */

namespace ClearA11y\Services;

use ClearA11y\Database\Scan_Repository;
use ClearA11y\Database\Scan_Item_Repository;

// Force OPcache to reload this file (temporary workaround for persistent cache)
if (function_exists('opcache_invalidate')) {
    opcache_invalidate(__FILE__, true);
}

/**
 * Scan Token Manager Class
 */
class Scan_Token_Manager {

	/**
	 * Token option prefix.
	 *
	 * @var string
	 */
	private const TOKEN_OPTION_PREFIX = 'cleara11y_scan_token_';

	/**
	 * Token expiry time in seconds.
	 *
	 * @var int
	 */
	private const TOKEN_EXPIRY = 300; // 5 minutes

	/**
	 * Generate a scan token for a post.
	 *
	 * @param int    $post_id  Post ID to scan.
	 * @param string $scan_type Scan type (individual, full).
	 * @return array {
	 *     Token data.
	 *
	 *     @type string $token       Generated token.
	 *     @type string $scan_url    URL to open for scanning.
	 *     @type int    $scan_id     Scan ID.
	 *     @type int    $scan_item_id Scan Item ID.
	 *     @type string $expires_at  Expiration timestamp.
	 * }
	 */
	public static function generate_token(int $post_id, string $scan_type = 'individual'): array {
		$token = \wp_generate_password(32, false);

		$expiry_seconds = (int) \get_option('cleara11y_scan_token_expiry', self::TOKEN_EXPIRY);
		$expires_at = date('Y-m-d H:i:s', time() + $expiry_seconds);

		// Create or update scan
		$scan = new \ClearA11y\Models\Scan();
		$scan->scan_type = $scan_type;
		$scan->status = 'in_progress';
		$scan->total_items = 1;
		$scan->scanned_items = 0;
		$scan->started_at = \current_time('mysql');
		$scan->created_at = \current_time('mysql');

		$scan_id = Scan_Repository::insert($scan);

		if (!$scan_id) {
			return [
				'error' => 'Failed to create scan record.',
			];
		}

		// Create scan item
		$post = \get_post($post_id);
		if (!$post) {
			return [
				'error' => 'Post not found.',
			];
		}

		$scan_item = new \ClearA11y\Models\Scan_Item();
		$scan_item->scan_id = $scan_id;
		$scan_item->post_id = $post_id;
		$scan_item->post_type = $post->post_type;
		$scan_item->post_title = $post->post_title;
		$scan_item->post_url = \get_permalink($post_id);
		$scan_item->status = 'in_progress';
		$scan_item->scan_method = 'client';
		$scan_item->created_at = \current_time('mysql');

		$scan_item_id = Scan_Item_Repository::insert($scan_item);

		if (!$scan_item_id) {
			return [
				'error' => 'Failed to create scan item.',
			];
		}

		// Store token data
		$token_data = [
			'scan_id' => $scan_id,
			'scan_item_id' => $scan_item_id,
			'post_id' => $post_id,
			'created_at' => \current_time('mysql'),
			'expires_at' => $expires_at,
		];

		\update_option(
			self::TOKEN_OPTION_PREFIX . $token,
			$token_data,
			false // No autoload
		);

		// Generate scan URL
		$scan_url = \add_query_arg(
			[
				'cleara11y_scan' => $token,
			],
			\get_permalink($post_id)
		);

		\do_action('cleara11y_scan_token_generated', $token, $scan_id, $post_id);

		return [
			'token' => $token,
			'scan_url' => $scan_url,
			'scan_id' => $scan_id,
			'scan_item_id' => $scan_item_id,
			'expires_at' => $expires_at,
		];
	}

	/**
	 * Validate a scan token.
	 *
	 * @param string $token Token to validate.
	 * @return array|false {
	 *     Token data if valid, false if invalid.
	 *
	 *     @type int    $scan_id      Scan ID.
	 *     @type int    $scan_item_id Scan Item ID.
	 *     @type int    $post_id      Post ID.
	 *     @type string $created_at   Creation timestamp.
	 *     @type string $expires_at   Expiration timestamp.
	 * }
	 */
	public static function validate_token(string $token): array|false {
		$token_data = \get_option(self::TOKEN_OPTION_PREFIX . $token);

		if (!$token_data) {
			return false;
		}

		// Check if token has expired
		$current_time = \current_time('mysql');
		if ($current_time > $token_data['expires_at']) {
			self::delete_token($token);
			return false;
		}

		\do_action('cleara11y_scan_token_validated', $token, $token_data);

		return $token_data;
	}

	/**
	 * Delete a scan token.
	 *
	 * @param string $token Token to delete.
	 * @return bool True if deleted, false if not found.
	 */
	public static function delete_token(string $token): bool {
		return \delete_option(self::TOKEN_OPTION_PREFIX . $token);
	}

	/**
	 * Generate multiple scan tokens for bulk scanning.
	 *
	 * @param int[] $post_ids Array of post IDs.
	 * @return array {
	 *     Bulk token data.
	 *
	 *     @type string $bulk_token  Bulk token identifier.
	 *     @type array  $tokens      Array of individual tokens.
	 *     @type int    $scan_id     Scan ID.
	 * }
	 */
	public static function generate_bulk_tokens(array $post_ids): array {
		$bulk_token = \wp_generate_password(16, false);

		// Create a full scan
		$scan = new \ClearA11y\Models\Scan();
		$scan->scan_type = 'full';
		$scan->scan_name = 'Bulk Scan - ' . count($post_ids) . ' pages';
		$scan->status = 'pending';
		$scan->total_items = count($post_ids);
		$scan->scanned_items = 0;
		$scan->created_at = \current_time('mysql');

		$scan_id = Scan_Repository::insert($scan);

		if (!$scan_id) {
			return [
				'error' => 'Failed to create scan record.',
			];
		}

		// Create scan items for all posts
		$tokens = [];
		foreach ($post_ids as $post_id) {
			$post = \get_post($post_id);
			if (!$post || $post->post_status !== 'publish') {
				continue;
			}

			$token = \wp_generate_password(32, false);
			$expiry_seconds = (int) \get_option('cleara11y_scan_token_expiry', self::TOKEN_EXPIRY);
			$expires_at = date('Y-m-d H:i:s', time() + $expiry_seconds);

			// Create scan item
			$scan_item = new \ClearA11y\Models\Scan_Item();
			$scan_item->scan_id = $scan_id;
			$scan_item->post_id = $post_id;
			$scan_item->post_type = $post->post_type;
			$scan_item->post_title = $post->post_title;
			$scan_item->post_url = \get_permalink($post_id);
			$scan_item->status = 'pending';
			$scan_item->scan_method = 'client';
			$scan_item->created_at = \current_time('mysql');

			$scan_item_id = Scan_Item_Repository::insert($scan_item);

			// Store token data
			$token_data = [
				'scan_id' => $scan_id,
				'scan_item_id' => $scan_item_id,
				'post_id' => $post_id,
				'created_at' => \current_time('mysql'),
				'expires_at' => $expires_at,
			];

			\update_option(
				self::TOKEN_OPTION_PREFIX . $token,
				$token_data,
				false
			);

			$tokens[] = [
				'post_id' => $post_id,
				'token' => $token,
				'scan_url' => \add_query_arg(
					['cleara11y_scan' => $token],
					\get_permalink($post_id)
				),
				'expires_at' => $expires_at,
			];
		}

		return [
			'bulk_token' => $bulk_token,
			'tokens' => $tokens,
			'scan_id' => $scan_id,
		];
	}

	/**
	 * Clean up expired tokens.
	 *
	 * @return int Number of tokens cleaned up.
	 */
	public static function cleanup_expired(): int {
		global $wpdb;

		$current_time = \current_time('mysql');

		// Get all token options
		$options = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options}
			WHERE option_name LIKE '" . self::TOKEN_OPTION_PREFIX . "%'"
		);

		$cleaned = 0;
		foreach ($options as $option) {
			$token_data = \maybe_unserialize($option->option_value);

			if (isset($token_data['expires_at']) && $current_time > $token_data['expires_at']) {
				\delete_option($option->option_name);
				$cleaned++;
			}
		}

		return $cleaned;
	}

	/**
	 * Get token expiry time in seconds.
	 *
	 * @return int
	 */
	public static function get_token_expiry(): int {
		return (int) \get_option('cleara11y_scan_token_expiry', self::TOKEN_EXPIRY);
	}

	/**
	 * Check if a token is for a valid scan.
	 *
	 * @param string $token Token to check.
	 * @return bool True if valid, false otherwise.
	 */
	public static function is_valid_token(string $token): bool {
		return self::validate_token($token) !== false;
	}

	/**
	 * Get scan ID from token.
	 *
	 * @param string $token Token.
	 * @return int|null Scan ID or null if invalid.
	 */
	public static function get_scan_id(string $token): ?int {
		$token_data = self::validate_token($token);

		return $token_data['scan_id'] ?? null;
	}

	/**
	 * Get scan item ID from token.
	 *
	 * @param string $token Token.
	 * @return int|null Scan Item ID or null if invalid.
	 */
	public static function get_scan_item_id(string $token): ?int {
		$token_data = self::validate_token($token);

		return $token_data['scan_item_id'] ?? null;
	}

	/**
	 * Get post ID from token.
	 *
	 * @param string $token Token.
	 * @return int|null Post ID or null if invalid.
	 */
	public static function get_post_id(string $token): ?int {
		$token_data = self::validate_token($token);

		return $token_data['post_id'] ?? null;
	}
}
