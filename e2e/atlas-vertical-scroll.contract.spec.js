// @ts-check
/**
 * ATLAS_VERTICAL_SCROLL_CONTRACT — tall app-settings access page must scroll to the end.
 * Guards CSS Overflow L3 unpaired overflow-x:clip truncating Save app policy CTA.
 * BudgetCheck has no license settings page (free companion); access policy is the tall surface.
 */
const { test } = require('@playwright/test');
const { assertAtlasVerticalScrollReachable } = require('../../_shared/e2e/atlas-vertical-scroll-contract');
const fs = require('fs');
const path = require('path');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');
const ACCESS = process.env.E2E_BC_ACCESS_URL || `${BASE}/apps/budgetcheck/app-settings/access`;

function resolveStorageState() {
	if (process.env.E2E_STORAGE_STATE && fs.existsSync(process.env.E2E_STORAGE_STATE)) {
		return process.env.E2E_STORAGE_STATE;
	}
	const auto = path.join(__dirname, '..', '.auth', 'storage-state.json');
	return fs.existsSync(auto) ? auto : undefined;
}

test.use({ storageState: resolveStorageState() });

test.describe('ATLAS_VERTICAL_SCROLL_CONTRACT', () => {
	test('app-settings access scrolls to Save app policy CTA', async ({ page }) => {
		test.skip(
			!process.env.E2E_USER && !process.env.BASE_URL && !resolveStorageState(),
			'Set BASE_URL / E2E_USER or storage state',
		);
		await page.setViewportSize({ width: 1280, height: 640 });
		await page.goto(ACCESS, { waitUntil: 'domcontentloaded' });
		await page.waitForSelector(
			'[data-bc-app-policy-form], #bc-access-gate-title, #bc-allowed-groups-label',
			{ timeout: 45_000 },
		);

		await assertAtlasVerticalScrollReachable(page, {
			scrollport: '#app-content',
			target:
				'[data-bc-app-policy-form] button[type="submit"], #bc-allowed-groups-label, #bc-app-policy-title',
			bottomSlopPx: 12,
		});
	});

	test('app-settings access stays reachable at phone height', async ({ page }) => {
		test.skip(
			!process.env.E2E_USER && !process.env.BASE_URL && !resolveStorageState(),
			'Set BASE_URL / E2E_USER or storage state',
		);
		await page.setViewportSize({ width: 390, height: 667 });
		await page.goto(ACCESS, { waitUntil: 'domcontentloaded' });
		await page.waitForSelector(
			'[data-bc-app-policy-form], #bc-access-gate-title, #bc-allowed-groups-label',
			{ timeout: 45_000 },
		);

		await assertAtlasVerticalScrollReachable(page, {
			scrollport: '#app-content',
			target:
				'[data-bc-app-policy-form] button[type="submit"], #bc-allowed-groups-label, #bc-app-policy-title',
			bottomSlopPx: 16,
		});
	});
});
