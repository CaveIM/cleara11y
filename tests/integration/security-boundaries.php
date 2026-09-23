<?php
/** Security regression checks. Run through wp eval-file. */
use ClearA11y\Services\Scan_Token_Manager;

$original_user = get_current_user_id();
$original_timezone = get_option('timezone_string');
$token = wp_generate_password(32, false);
try {
	wp_set_current_user(0);
	$routes = rest_get_server()->get_routes();
	$checked = 0;
	foreach ($routes as $route => $endpoints) {
		if (! str_starts_with($route, '/cleara11y/v1/')) {
			continue;
		}
		foreach ($endpoints as $endpoint) {
			if (! is_array($endpoint) || ! isset($endpoint['permission_callback'])) {
				continue;
			}
			// Browser results use a short-lived bearer token validated in the handler.
			if ('/cleara11y/v1/scan/results' === $route) {
				continue;
			}
			$request = new WP_REST_Request('POST', $route);
			$request->set_param('id', 1);
			$allowed = call_user_func($endpoint['permission_callback'], $request);
			if (false !== $allowed && ! is_wp_error($allowed)) {
				throw new RuntimeException('Anonymous access allowed: ' . $route);
			}
			++$checked;
		}
	}
	$request = new WP_REST_Request('POST', '/cleara11y/v1/scan/results');
	$request->set_header('Content-Type', 'application/json');
	$request->set_body(wp_json_encode(['token' => str_repeat('0', 32), 'results' => (object) [], 'evidence' => []]));
	$response = rest_get_server()->dispatch($request);
	if (403 !== $response->get_status()) {
		throw new RuntimeException('Anonymous result submission without a valid token was not rejected.');
	}
	foreach (['America/Chicago', 'Pacific/Auckland'] as $timezone) {
		update_option('timezone_string', $timezone);
		update_option('cleara11y_scan_token_' . $token, ['expires_at' => gmdate('Y-m-d H:i:s', time() - 60)], false);
		if (false !== Scan_Token_Manager::validate_token($token)) {
			throw new RuntimeException('Expired token accepted in ' . $timezone);
		}
		update_option('cleara11y_scan_token_' . $token, ['expires_at' => gmdate('Y-m-d H:i:s', time() + 120)], false);
		if (false === Scan_Token_Manager::validate_token($token)) {
			throw new RuntimeException('Valid token rejected in ' . $timezone);
		}
	}
	if (false !== Scan_Token_Manager::validate_token('../invalid')) {
		throw new RuntimeException('Malformed token accepted.');
	}
} finally {
	delete_option('cleara11y_scan_token_' . $token);
	update_option('timezone_string', $original_timezone);
	wp_set_current_user($original_user);
}
echo "Security boundary regression checks passed ($checked protected route handlers).\n";
