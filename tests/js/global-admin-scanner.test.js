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
