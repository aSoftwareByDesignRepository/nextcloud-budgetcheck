const fs = require('fs');
const path = require('path');
const { defineConfig } = require('@playwright/test');

function resolveStorageState() {
	if (process.env.E2E_STORAGE_STATE) {
		const resolved = path.resolve(process.env.E2E_STORAGE_STATE);
		return fs.existsSync(resolved) ? resolved : undefined;
	}
	const autoPath = path.join(__dirname, '.auth', 'storage-state.json');
	if ((process.env.E2E_USER && (process.env.E2E_PASSWORD || process.env.E2E_PASS)) && fs.existsSync(autoPath)) {
		return autoPath;
	}
	return undefined;
}

module.exports = defineConfig({
	globalSetup: require.resolve('./e2e/global-setup.js'),
	testDir: '.',
	testMatch: ['e2e/**/*.spec.js', 'tests/e2e/**/*.spec.js'],
	timeout: 60_000,
	expect: { timeout: 10_000 },
	use: {
		headless: true,
		baseURL: (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, ''),
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		storageState: resolveStorageState(),
	},
	reporter: [['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }]],
});
