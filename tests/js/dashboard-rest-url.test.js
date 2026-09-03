const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(
	path.resolve(__dirname, '../../assets/js/dashboard.js'),
	'utf8'
);

function loadDashboard(wpApiUrl) {
	const context = {
		cleara11yData: {
			apiUrl: 'http://example.test/?rest_route=/cleara11y/v1/',
			wpApiUrl,
			ajaxUrl: 'http://example.test/wp-admin/admin-ajax.php',
			nonce: 'rest-nonce',
			ajaxNonce: 'ajax-nonce',
			strings: {},
		},
		window: {
			location: {origin: 'http://example.test'},
			addEventListener() {},
		},
		document: {
			readyState: 'loading',
			addEventListener() {},
		},
		URL,
		console,
	};
	vm.runInNewContext(source, context);
	return context.window.cleara11y;
}

test('builds WordPress collection URLs for plain permalinks', () => {
	const dashboard = loadDashboard('http://example.test/index.php?rest_route=/wp/v2/');
	const url = new URL(dashboard.buildWpRestUrl('pages', {
		per_page: 100,
		page: 2,
		_fields: 'id',
	}));

	assert.equal(url.searchParams.get('rest_route'), '/wp/v2/pages');
	assert.equal(url.searchParams.get('per_page'), '100');
	assert.equal(url.searchParams.get('page'), '2');
	assert.equal(url.searchParams.get('_fields'), 'id');
});

test('builds WordPress collection URLs for pretty permalinks', () => {
	const dashboard = loadDashboard('http://example.test/wp-json/wp/v2/');
	const url = new URL(dashboard.buildWpRestUrl('posts', {per_page: 20}));

	assert.equal(url.pathname, '/wp-json/wp/v2/posts');
	assert.equal(url.searchParams.get('per_page'), '20');
});

test('builds ClearA11y API URLs for plain permalinks', () => {
	const dashboard = loadDashboard('http://example.test/index.php?rest_route=/wp/v2/');
	const url = new URL(dashboard.buildApiUrl('jobs/stats', {scan_id: 42}));

	assert.equal(url.searchParams.get('rest_route'), '/cleara11y/v1/jobs/stats');
	assert.equal(url.searchParams.get('scan_id'), '42');
});

test('normalizes missing and failed job counters without NaN', () => {
	const dashboard = loadDashboard('http://example.test/index.php?rest_route=/wp/v2/');
	assert.deepEqual(
		JSON.parse(JSON.stringify(dashboard.normalizeJobStats({completed: 2, failed: 1}))),
		{pending: 0, active: 0, completed: 2, failed: 1, total: 3, finished: 3}
	);
	assert.equal(Number.isNaN(dashboard.normalizeJobStats({}).total), false);
});
