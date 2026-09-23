<?php
/**
 * Local QA against real scanned fixtures. No synthetic scan results.
 * wp eval-file tests/integration/exception-qa-local.php [matrix|prepare|unchanged|changed|cleanup]
 * Requires tests/fixtures/exception-qa.php and a completed browser scan first.
 * Keeps identifiable disabled exceptions and a /tmp JSON evidence log.
 */
use ClearA11y\Database\Issue_Repository;
use ClearA11y\Database\Exception_Rule_Repository;
use ClearA11y\Services\Exception_Matcher_Service;

if ('local' !== wp_get_environment_type()) {
	throw new RuntimeException('Local environment required.');
}
global $state, $posts, $phase;
wp_set_current_user(get_user_by('login', 'admin')->ID);
$phase = $args[0] ?? 'matrix';
$state_file = '/tmp/cleara11y-exception-qa.json';
$state = is_file($state_file) ? json_decode(file_get_contents($state_file), true) : ['checks' => [], 'rules' => []];
$starting_check_count = count($state['checks']);
$posts = [];
foreach (['a' => 'page', 'b' => 'page', 'c' => 'post'] as $key => $type) {
	$post = get_page_by_path('cleara11y-exception-qa-' . $key, OBJECT, $type);
	if (!$post) {
		throw new RuntimeException('Missing fixture ' . $key);
	}
	$posts[$key] = $post->ID;
}
function qa_api($method, $path, $body = []) {
	$request = new WP_REST_Request($method, '/cleara11y/v1/exceptions' . $path);
	$request->set_header('Content-Type', 'application/json');
	$request->set_body(wp_json_encode($body));
	$response = rest_do_request($request);
	if ($response->get_status() >= 400) {
		throw new RuntimeException(wp_json_encode($response->get_data()));
	}
	return $response->get_data();
}
function qa_issue($page, $rule = 'label', $selector = '#qa-primary') {
	global $wpdb, $posts;
	$id = $wpdb->get_var($wpdb->prepare(
		"SELECT id FROM {$wpdb->prefix}cleara11y_issues WHERE post_id=%d AND rule_id=%s AND selector=%s ORDER BY id DESC LIMIT 1",
		$posts[$page], $rule, $selector
	));
	if (!$id) {
		throw new RuntimeException('Missing scanned issue ' . $page . ':' . $rule . ':' . $selector);
	}
	return Issue_Repository::get_by_id((int) $id);
}
function qa_create($name, $target, $scope, $duration = 'permanent', $rule = 'label', $selector = '#qa-primary') {
	global $state;
	$data = qa_api('POST', '', [
		'violation_id' => qa_issue('a', $rule, $selector)->id,
		'target_type' => $target,
		'scope' => $scope,
		'duration' => ['duration_type' => $duration],
		'reason_category' => 'other',
		'note' => 'Exception QA: ' . $name,
	]);
	$state['rules'][$name] = $data['id'];
	return $data['id'];
}
function qa_check($name, $expected, $actual) {
	global $state, $phase;
	$check = ['phase' => $phase, 'name' => $name, 'expected' => $expected, 'actual' => $actual, 'pass' => $expected === $actual];
	$state['checks'][] = $check;
	echo wp_json_encode($check) . "\n";
}
function qa_status($page, $rule = 'label', $selector = '#qa-primary') {
	$issue = qa_issue($page, $rule, $selector);
	return Issue_Repository::get_explorer_occurrence($issue->id)['status'];
}
function qa_matrix($name, $target, $scope, $expected) {
	$id = qa_create($name, $target, $scope);
	try {
		$actual = [qa_status('a'), qa_status('a', 'label', '#qa-secondary'), qa_status('a', 'aria-valid-attr-value'), qa_status('b'), qa_status('c')];
		qa_check($name, $expected, $actual);
	} finally {
		qa_api('POST', '/' . $id . '/disable');
	}
}
try {
	if ('matrix' === $phase) {
		$active = 'active'; $exception = 'exception';
		qa_matrix('rule-page', 'rule', ['scope_type' => 'page'], [$exception,$exception,$active,$active,$active]);
		qa_matrix('rule-site', 'rule', ['scope_type' => 'site'], [$exception,$exception,$active,$exception,$exception]);
		qa_matrix('rule-pages-only', 'rule', ['scope_type' => 'content_type','post_types' => ['page']], [$exception,$exception,$active,$exception,$active]);
		qa_matrix('rule-posts-only', 'rule', ['scope_type' => 'content_type','post_types' => ['post']], [$active,$active,$active,$active,$exception]);
		qa_matrix('rule-url-match', 'rule', ['scope_type' => 'url_pattern','patterns' => ['*page_id=' . $posts['b']]], [$active,$active,$active,$exception,$active]);
		qa_matrix('rule-url-no-match', 'rule', ['scope_type' => 'url_pattern','patterns' => ['*/not-a-qa-page/*']], [$active,$active,$active,$active,$active]);
		qa_matrix('rule-on-element-page', 'rule_on_element', ['scope_type' => 'page'], [$exception,$active,$active,$active,$active]);
		qa_matrix('all-rules-on-element', 'element', ['scope_type' => 'page'], [$exception,$active,$exception,$active,$active]);
		qa_matrix('element-scope-excludes-anchor', 'rule_on_element', ['scope_type' => 'content_type','post_types' => ['post']], [$active,$active,$active,$active,$active]);
		$id = qa_create('toggle', 'rule_on_element', ['scope_type' => 'page']);
		qa_check('created exception suppresses', 'exception', qa_status('a'));
		qa_api('POST', '/' . $id . '/disable');
		qa_check('disable restores finding', 'active', qa_status('a'));
		qa_api('POST', '/' . $id . '/enable');
		qa_check('enable suppresses again', 'exception', qa_status('a'));
		qa_api('POST', '/' . $id . '/disable');
	}
	if ('rescan-targets-prepare' === $phase) {
		qa_create('all-rules-rescan', 'element', ['scope_type' => 'page']);
	}
	if ('rescan-targets-check' === $phase) {
		qa_check('all rules: original finding suppressed after rescan', 'exception', qa_status('a'));
		qa_check('all rules: second rule suppressed after rescan', 'exception', qa_status('a', 'aria-valid-attr-value'));
		qa_check('all rules: other element remains active', 'active', qa_status('a', 'label', '#qa-secondary'));
		qa_check('all rules: other page remains active', 'active', qa_status('b'));
	}
	if ('prepare' === $phase) {
		$state['baseline_scan'] = qa_issue('a')->scan_id;
		qa_create('until-content-element', 'rule_on_element', ['scope_type' => 'page'], 'until_content_changes');
		qa_create('until-next-scan', 'rule_on_element', ['scope_type' => 'page'], 'until_next_scan', 'button-name', '#qa-button');
		qa_create('permanent-control', 'rule_on_element', ['scope_type' => 'page'], 'permanent', 'label', '#qa-secondary');
		qa_check('content exception initially suppresses', 'exception', qa_status('a'));
		qa_check('snooze initially suppresses', 'exception', qa_status('a','button-name','#qa-button'));
	}
	if ('unchanged' === $phase || 'changed' === $phase) {
		qa_check('new real scan exists', true, qa_issue('a')->scan_id > $state['baseline_scan']);
		qa_check('content exception ' . $phase, 'unchanged' === $phase ? 'exception' : 'active', qa_status('a'));
		qa_check('next scan ends snooze', 'active', qa_status('a','button-name','#qa-button'));
		qa_check('snooze recorded expired', 'expired', Exception_Rule_Repository::get_by_id($state['rules']['until-next-scan'])->status);
		qa_check('permanent unrelated element remains suppressed', 'exception', qa_status('a','label','#qa-secondary'));
		qa_check('other rule remains visible', 'active', qa_status('a','aria-valid-attr-value'));
		$state[$phase . '_scan'] = qa_issue('a')->scan_id;
	}
	if ('cleanup' === $phase) {
		foreach ($state['rules'] as $id) {
			$rule = Exception_Rule_Repository::get_by_id($id);
			if ($rule && 'active' === $rule->status) {
				qa_api('POST', '/' . $id . '/disable');
			}
		}
		echo "QA exceptions deactivated.\n";
	}
} finally {
	file_put_contents($state_file, wp_json_encode($state, JSON_PRETTY_PRINT));
}

foreach (array_slice($state['checks'], $starting_check_count) as $check) {
	if (! $check['pass']) {
		WP_CLI::halt(1);
	}
}
