<?php
/** Verify lower-privilege users cannot mutate the site-wide queue through AJAX. */
if (! defined('DOING_AJAX')) {
	define('DOING_AJAX', true);
}
require_once ABSPATH . 'wp-admin/includes/user.php';
$original_user = get_current_user_id();
$user_id = wp_insert_user([
	'user_login' => 'cleara11y_security_' . strtolower(wp_generate_password(12, false)),
	'user_pass' => wp_generate_password(32),
	'role' => 'author',
]);
if (is_wp_error($user_id)) {
	throw new RuntimeException('Could not create the security test user.');
}
$die = static function () {
	return static function () { throw new RuntimeException('cleara11y_test_ajax_exit'); };
};
add_filter('wp_die_ajax_handler', $die);
$original_request = $_REQUEST;
$original_post = $_POST;
try {
	wp_set_current_user($user_id);
	$_REQUEST['nonce'] = wp_create_nonce('cleara11y-nonce');
	$_POST = [];
	$admin = \ClearA11y\Admin\Admin::get_instance();
	foreach (['ajax_get_scan_state', 'ajax_advance_scan', 'ajax_save_scan_result', 'ajax_stop_scan'] as $method) {
		ob_start();
		try {
			$admin->$method();
		} catch (RuntimeException $error) {
			if ('cleara11y_test_ajax_exit' !== $error->getMessage()) {
				throw $error;
			}
		} finally {
			$response = json_decode(ob_get_clean(), true);
		}
		if (false !== ($response['success'] ?? null) || 'Permission denied' !== ($response['data']['message'] ?? '')) {
			throw new RuntimeException('Author was not denied by ' . $method);
		}
	}
} finally {
	remove_filter('wp_die_ajax_handler', $die);
	$_REQUEST = $original_request;
	$_POST = $original_post;
	wp_set_current_user($original_user);
	wp_delete_user($user_id);
}
echo "AJAX security regression checks passed.\n";
