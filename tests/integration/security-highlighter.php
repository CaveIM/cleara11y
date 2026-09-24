<?php
/** Verify highlighting permissions and safe serialization of stored scan evidence. */
use ClearA11y\Database\Issue_Repository;
use ClearA11y\Database\Scan_Item_Repository;
use ClearA11y\Database\Scan_Repository;
use ClearA11y\Frontend\Highlighter;
use ClearA11y\Models\Issue;
use ClearA11y\Models\Scan;
use ClearA11y\Models\Scan_Item;

global $wpdb, $wp_query;
$original_user = get_current_user_id();
$original_get = $_GET;
$original_query = $wp_query;
$wpdb->query('START TRANSACTION');
try {
	$author_id = wp_insert_user(['user_login' => 'cleara11y_highlight_' . wp_generate_password(10, false), 'user_pass' => wp_generate_password(32), 'role' => 'author']);
	if (is_wp_error($author_id)) {
		throw new RuntimeException('Could not create test author.');
	}
	$post_id = wp_insert_post(['post_title' => 'Highlight security fixture', 'post_status' => 'publish', 'post_author' => $author_id]);
	$other_id = wp_insert_post(['post_title' => 'Other author fixture', 'post_status' => 'publish', 'post_author' => 1]);
	$scan = new Scan();
	$scan->status = 'completed';
	$scan->created_at = current_time('mysql', true);
	$scan_id = Scan_Repository::insert($scan);
	$item = new Scan_Item();
	$item->scan_id = $scan_id;
	$item->post_id = $post_id;
	$item->post_url = get_permalink($post_id);
	$item->status = 'completed';
	$item->created_at = current_time('mysql', true);
	$item_id = Scan_Item_Repository::insert($item);
	$issue = new Issue();
	$issue->scan_id = $scan_id;
	$issue->scan_item_id = $item_id;
	$issue->post_id = $post_id;
	$issue->rule_id = 'button-name';
	$issue->selector = 'body';
	$issue->message = '${globalThis.cleara11ySecurityProbe = true}`</script><script>globalThis.cleara11ySecurityProbe = true</script>';
	$issue_id = Issue_Repository::insert($issue);
	if (! $post_id || ! $other_id || ! $issue_id) {
		throw new RuntimeException('Could not create highlighter fixtures.');
	}
	$render = static function ($user_id, $page_id, $nonce_mode) use ($issue_id) {
		global $wp_query;
		wp_set_current_user($user_id);
		$wp_query = new WP_Query(['p' => $page_id]);
		$_GET = ['edac' => $issue_id];
		if ('valid' === $nonce_mode) {
			$_GET['edac_nonce'] = wp_create_nonce('edac_highlight');
		} elseif ('invalid' === $nonce_mode) {
			$_GET['edac_nonce'] = 'invalid';
		}
		$highlighter = new Highlighter();
		$highlighter->detect_highlight_request();
		wp_dequeue_script('cleara11y-highlighter');
		wp_deregister_script('cleara11y-highlighter');
		$highlighter->enqueue_highlighter_assets();
		return wp_scripts()->get_data('cleara11y-highlighter', 'data') ?: '';
	};
	foreach ([[0, $post_id, 'missing'], [0, $post_id, 'valid'], [$author_id, $post_id, 'invalid'], [$author_id, $other_id, 'valid'], [1, $other_id, 'valid']] as $case) {
		if ('' !== $render(...$case)) {
			throw new RuntimeException('Unauthorized or wrong-page highlighting returned stored evidence.');
		}
	}
	$output = $render($author_id, $post_id, 'valid');
	if (! preg_match('/var cleara11yHighlight = (.*?);$/s', $output, $match)) {
		throw new RuntimeException('Authorized highlighting did not serialize issue data.');
	}
	$data = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
	if ($issue->message !== $data['message'] || str_contains($output, '</script>') || str_contains($output, 'panel.innerHTML')) {
		throw new RuntimeException('Stored evidence was modified or rendered as executable markup.');
	}
	// An author cannot use a valid nonce to inspect another author's issue.
	$wpdb->update(\ClearA11y\Database\Schema::get_table_name('issues'), ['post_id' => $other_id], ['id' => $issue_id]);
	if ('' !== $render($author_id, $other_id, 'valid')) {
		throw new RuntimeException('Cross-author highlighting was allowed.');
	}
} finally {
	$wpdb->query('ROLLBACK');
	$_GET = $original_get;
	$wp_query = $original_query;
	wp_set_current_user($original_user);
	if (isset($author_id) && is_int($author_id)) {
		clean_user_cache($author_id);
	}
	foreach ([$post_id ?? 0, $other_id ?? 0] as $id) {
		clean_post_cache($id);
	}
}
echo "Highlighter security regression checks passed.\n";
