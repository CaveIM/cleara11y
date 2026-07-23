const {defineConfig} = require('@playwright/test');

module.exports = defineConfig({
	testDir: './tests/e2e',
	fullyParallel: false,
	workers: 1,
	timeout: 30000,
	reporter: 'line',
	use: {
		baseURL: process.env.CLEARA11Y_BASE_URL || 'http://localhost:8888',
		trace: 'retain-on-failure'
	}
});
