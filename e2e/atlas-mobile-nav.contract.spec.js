// @ts-check
/** ATLAS_MOBILE_NAV_CONTRACT — phone Menu → open nav + no h-scroll */
const { test } = require('@playwright/test');
const { assertAtlasMobileNav } = require('../../_shared/e2e/atlas-mobile-nav-contract');
const fs = require('fs');
const path = require('path');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');
const DASHBOARD = process.env.E2E_BC_DASHBOARD_URL || `${BASE}/apps/budgetcheck/`;

function resolveStorageState() {
	if (process.env.E2E_STORAGE_STATE && fs.existsSync(process.env.E2E_STORAGE_STATE)) {
		return process.env.E2E_STORAGE_STATE;
	}
	const auto = path.join(__dirname, '..', '.auth', 'storage-state.json');
	return fs.existsSync(auto) ? auto : undefined;
}

test.use({ storageState: resolveStorageState() });

test('ATLAS_MOBILE_NAV_CONTRACT in-page Menu opens drawer', async ({ page }) => {
	test.skip(!process.env.E2E_USER && !process.env.BASE_URL && !resolveStorageState(), 'Set BASE_URL / E2E_USER or storage state');
	await page.setViewportSize({ width: 375, height: 812 });
	await page.goto(DASHBOARD, { waitUntil: 'domcontentloaded' });
	await page.waitForSelector('[data-bc-nav-toggle], #bc-nav-toggle', { timeout: 30000 });
	await assertAtlasMobileNav(page, {
		toggle: page.locator('[data-bc-nav-toggle], #bc-nav-toggle').first(),
		nav: page.locator('#app-navigation'),
		openClass: /bc-nav--open/,
	});
});
