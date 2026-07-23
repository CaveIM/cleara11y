const test = require('node:test');
const assert = require('node:assert/strict');
const Query = require('../../assets/js/issues-explorer-query.js');

test('defaults to active live issues grouped by page', () => {
	const query = Query.parse('http://example.test/wp-admin/admin.php?page=cleara11y-issues');
	assert.equal(query.status, 'active');
	assert.equal(query.groupBy, 'page');
	assert.equal(query.resultsPage, 1);
});

test('page and scan scopes default to rule grouping', () => {
	assert.equal(Query.parse('http://example.test/?pageId=12').groupBy, 'rule');
	const scan = Query.parse('http://example.test/?scanId=4&status=active');
	assert.equal(scan.groupBy, 'rule');
	assert.equal(scan.status, 'all');
});

test('invalid enums and identifiers recover safely', () => {
	const query = Query.parse('http://example.test/?status=deleted&severity=huge&pageId=-2&occurrenceId=text');
	assert.equal(query.status, 'active');
	assert.equal(query.severity, '');
	assert.equal(query.pageId, undefined);
	assert.equal(query.occurrenceId, undefined);
});

test('serialization preserves WordPress page slug and meaningful state', () => {
	const input = Query.parse('http://example.test/wp-admin/admin.php?page=cleara11y-issues&ruleId=listitem&occurrenceId=42&groupBy=page');
	const url = Query.toUrl(input, 'http://example.test/wp-admin/admin.php?page=cleara11y-issues');
	assert.equal(url.searchParams.get('page'), 'cleara11y-issues');
	assert.equal(url.searchParams.get('ruleId'), 'listitem');
	assert.equal(url.searchParams.get('occurrenceId'), '42');
	assert.equal(Query.parse(url).occurrenceId, 42);
});

test('API parameters translate browser state without visual-only fields', () => {
	const query = Query.parse('http://example.test/?status=active&pageId=8&severity=critical&resultsPage=2');
	const params = Query.apiParams(query);
	assert.equal(params.get('status'), 'active');
	assert.equal(params.get('page_id'), '8');
	assert.equal(params.get('severity'), 'critical');
	assert.equal(params.get('page'), '2');
	assert.equal(params.has('occurrenceId'), false);
});
