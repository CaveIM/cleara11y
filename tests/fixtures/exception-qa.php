<?php
/** Local-only content for manual exception regression tests. Run with wp eval-file. */
if ('local' !== wp_get_environment_type()) {
	throw new RuntimeException('Exception QA fixtures require a local WordPress environment.');
}
$content = <<<'HTML'
<!-- wp:html -->
<section aria-label="Exception QA fixtures">
<p>Deliberately inaccessible controls for testing ClearA11y exception boundaries.</p>
<p>Primary input:</p><input id="qa-primary" name="qa-primary" type="text" aria-required="banana">
<p>Secondary input:</p><input id="qa-secondary" name="qa-secondary" type="email">
<p>Unnamed button:</p><button id="qa-button" type="button" style="width:60px;height:35px"></button>
</section>
<!-- /wp:html -->
HTML;
$fixtures = [];
foreach (['a' => 'page', 'b' => 'page', 'c' => 'post'] as $key => $type) {
	$slug = 'cleara11y-exception-qa-' . $key;
	$existing = get_page_by_path($slug, OBJECT, $type);
	$id = $existing ? $existing->ID : wp_insert_post([
		'post_title' => 'Exception QA ' . strtoupper($key),
		'post_name' => $slug,
		'post_type' => $type,
		'post_status' => 'publish',
		'post_content' => $content,
	], true);
	if (is_wp_error($id)) {
		throw new RuntimeException($id->get_error_message());
	}
	$fixtures[$key] = ['id' => $id, 'url' => get_permalink($id), 'type' => $type];
}
echo wp_json_encode($fixtures, JSON_PRETTY_PRINT) . "\n";
