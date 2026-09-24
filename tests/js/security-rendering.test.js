const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function context() {
	return {
		URL,
		window: {location: {href: 'https://example.test/wp-admin/admin.php'}, ClearA11yIssueQuery: {parse: () => ({})}},
		cleara11yData: {apiUrl: '/', nonce: ''},
		document: {
			readyState: 'loading', addEventListener() {},
			createElement() {
				return {textContent: '', get innerHTML() { return this.textContent.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }};
			}
		}
	};
}
function explorer() {
	const ctx = context();
	let source = fs.readFileSync(path.resolve(__dirname, '../../assets/js/issues-list.js'), 'utf8');
	source = source.replace("\tif (document.readyState === 'loading')", '\twindow.renderSecurity = {safeUrl, escapeHtml, pageActions, ruleActions};\n\tif (document.readyState === \'loading\')');
	vm.runInNewContext(source, ctx);
	return ctx.window.renderSecurity;
}

test('explorer encodes quotes and markup in attribute values', () => {
	const render = explorer();
	assert.equal(render.escapeHtml('" onmouseover="probe()\'><svg>'), '&quot; onmouseover=&quot;probe()&#039;&gt;&lt;svg&gt;');
	assert.ok(!render.ruleActions({id: '" onmouseover="probe()', reference_url: '/wp-admin/'}).includes('aria-label="View issue reference for "'));
});

test('explorer rejects executable URLs and preserves ordinary links', () => {
	const render = explorer();
	for (const value of ['javascript:probe()', 'java\nscript:probe()', 'data:text/html,test', 'vbscript:probe()']) {
		assert.equal(render.safeUrl(value), '');
		assert.ok(!render.pageActions({url: value, edit_url: value}).includes(value));
	}
	assert.equal(render.safeUrl('/page/?x=1&y=2'), 'https://example.test/page/?x=1&amp;y=2');
});

test('frontend help links reject executable URL schemes', () => {
	const ctx = context();
	vm.runInNewContext(fs.readFileSync(path.resolve(__dirname, '../../assets/js/frontend.js'), 'utf8'), ctx);
	const panel = ctx.window.ClearA11yFrontend;
	for (const value of ['javascript:probe()', 'java\nscript:probe()', 'data:text/html,test']) {
		assert.equal(panel.safeHelpUrl(value), '');
	}
	assert.equal(panel.safeHelpUrl('https://dequeuniversity.com/rules/axe/4.10/button-name'), 'https://dequeuniversity.com/rules/axe/4.10/button-name');
});

test('frontend severity metadata cannot inject attributes or HTML', () => {
	const ctx = context();
	vm.runInNewContext(fs.readFileSync(path.resolve(__dirname, '../../assets/js/frontend.js'), 'utf8'), ctx);
	const panel = ctx.window.ClearA11yFrontend;
	panel.getElementPresentation = () => ({text: 'Button'});
	const html = panel.buildIssueCard({severity: '\" onclick=\"probe()\"><svg>', rule_id: 'button-name'}, 0);
	assert.ok(!html.includes('<svg>'));
	assert.ok(!html.includes(' onclick="probe()'));
	assert.ok(html.includes('&quot;'));
});
