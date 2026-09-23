const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const source = fs.readFileSync(
	path.resolve(__dirname, '../../assets/js/global-admin-scanner.js'),
	'utf8'
);

test('serializes parent axe configuration into the isolated scan iframe', () => {
	assert.match(
		source,
		/const axeTags = Array\.isArray\(window\.cleara11yData\?\.axeTags\)/
	);
	assert.match(source, /values: \$\{JSON\.stringify\(axeTags\)\}/);

	const injectedScript = source.match(/scanScript\.textContent = `([\s\S]*?)`;/)?.[1] || '';
	assert.doesNotMatch(
		injectedScript,
		/\bcleara11yData\b/,
		'The iframe script must not reference globals that exist only in the parent admin window.'
	);
});

test('workers accept results only from their own iframe and scope parent errors to their job', () => {
	const handlerBody = source.match(/messageHandler = \(event\) => \{([\s\S]*?)\n\t\t\t\t\};/)?.[1];
	assert.ok(handlerBody);
	const parent = {location: {origin: 'https://example.test'}};
	const frame = {};
	const worker = {iframe: {contentWindow: frame}, currentJob: {jobId: 42}};
	let resolved = 0;
	let rejected = 0;
	const handle = new Function('window', 'cleanup', 'resolve', 'reject', 'event', handlerBody)
		.bind(worker, parent, () => {}, () => { resolved++; }, () => { rejected++; });
	const result = {type: 'CLEARA11Y_SCAN_COMPLETE', payload: {}};
	handle({origin: parent.location.origin, source: {}, data: result});
	handle({origin: 'https://attacker.test', source: frame, data: result});
	assert.equal(resolved, 0);
	handle({origin: parent.location.origin, source: frame, data: result});
	assert.equal(resolved, 1);
	handle({origin: parent.location.origin, source: parent, data: {type: 'CLEARA11Y_SCAN_ERROR', jobId: 43}});
	assert.equal(rejected, 0);
	handle({origin: parent.location.origin, source: parent, data: {type: 'CLEARA11Y_SCAN_ERROR', jobId: 42}});
	assert.equal(rejected, 1);
});
