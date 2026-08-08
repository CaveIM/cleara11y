const test = require('node:test');
const assert = require('node:assert/strict');
const Query = require('../../assets/js/issues-explorer-query.js');

test('defaults to active live issues grouped by page', () => {
	const query = Query.parse('http://example.test/wp-admin/admin.php?page=cleara11y-issues');
	assert.equal(query.status, 'active');
	assert.equal(query.findingType, '');
	assert.equal(query.includeExceptions, false);
	assert.equal(query.groupBy, 'page');
	assert.equal(query.resultsPage, 1);
});

test('page and scan scopes default to rule grouping', () => {
	assert.equal(Query.parse('http://example.test/?pageId=12').groupBy, 'rule');
	const scan = Query.parse('http://example.test/?scanId=4&status=active');
	assert.equal(scan.groupBy, 'rule');
	assert.equal(scan.status, 'all');
	assert.equal(scan.includeExceptions, true);
});

test('invalid enums and identifiers recover safely', () => {
	const query = Query.parse('http://example.test/?status=deleted&severity=huge&pageId=-2&occurrenceId=text&groupBy=none');
	assert.equal(query.status, 'active');
	assert.equal(query.severity, '');
	assert.equal(query.pageId, undefined);
	assert.equal(query.occurrenceId, undefined);
	assert.equal(query.groupBy, 'page');
});

test('serialization preserves WordPress page slug and meaningful state', () => {
	const input = Query.parse('http://example.test/wp-admin/admin.php?page=cleara11y-issues&ruleId=listitem&occurrenceId=42&groupBy=page&findingType=review&includeExceptions=1');
	const url = Query.toUrl(input, 'http://example.test/wp-admin/admin.php?page=cleara11y-issues');
	assert.equal(url.searchParams.get('page'), 'cleara11y-issues');
	assert.equal(url.searchParams.get('ruleId'), 'listitem');
	assert.equal(url.searchParams.get('occurrenceId'), '42');
	assert.equal(url.searchParams.get('findingType'), 'review');
	assert.equal(url.searchParams.get('includeExceptions'), '1');
	assert.equal(Query.parse(url).occurrenceId, 42);
});

test('API parameters translate browser state and per-user pagination', () => {
	const query = Query.parse('http://example.test/?includeExceptions=1&findingType=violation&pageId=8&severity=critical&resultsPage=2');
	const params = Query.apiParams(query, 45);
	assert.equal(params.get('status'), 'all');
	assert.equal(params.get('finding_type'), 'violation');
	assert.equal(params.get('per_page'), '45');
	assert.equal(params.get('page_id'), '8');
	assert.equal(params.get('severity'), 'critical');
	assert.equal(params.get('page'), '2');
	assert.equal(params.has('occurrenceId'), false);
});

test('API pagination is clamped to the supported range', () => {
	const query = Query.parse('http://example.test/');
	assert.equal(Query.apiParams(query, 0).get('per_page'), '1');
	assert.equal(Query.apiParams(query, 1000).get('per_page'), '100');
	assert.equal(Query.apiParams(query, 'invalid').get('per_page'), '20');
});
