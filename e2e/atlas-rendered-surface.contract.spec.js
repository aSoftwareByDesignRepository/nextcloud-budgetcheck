// @ts-check
/**
 * ATLAS_RENDERED_SURFACE_CONTRACT — asserts the *rendered* truth of each app
 * page surface: content list markers are not reset to none by shell CSS
 * leaks, selects are vertically centred (not sunken/clipped), icons and SVGs
 * have non-zero boxes, and form controls/labels are not text-centered by an
 * inherited shell alignment. DOM-level specs pass on pages that are visually
 * broken; this is the pixel-adjacent invariant class.
 *
 * BudgetCheck routes are workspace-scoped (?workspaceId=); the seeded E2E
 * workspace id comes from BC_CRAFT_WS (default 100). Pages without a
 * workspace render the picker instead of main content — those cells skip.
 */
const { test, expect } = require('@playwright/test');
const { assertAtlasRenderedSurface } = require('../../_shared/e2e/atlas-rendered-surface-contract');
const fs = require('fs');
const path = require('path');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');
const WS = process.env.BC_CRAFT_WS || '100';
const W = `?workspaceId=${WS}`;

const SURFACES = [
	[`/apps/budgetcheck/dashboard${W}`, 'dashboard'],
	[`/apps/budgetcheck/transactions${W}`, 'transactions ledger'],
	[`/apps/budgetcheck/import${W}`, 'import'],
	[`/apps/budgetcheck/budgets${W}`, 'budgets'],
	[`/apps/budgetcheck/monthly${W}`, 'monthly'],
	[`/apps/budgetcheck/period${W}`, 'period'],
	[`/apps/budgetcheck/yearly${W}`, 'yearly'],
	['/apps/budgetcheck/workspaces', 'workspace overview'],
	[`/apps/budgetcheck/get-the-app${W}`, 'get the app'],
	[`/apps/budgetcheck/settings${W}`, 'settings hub'],
	[`/apps/budgetcheck/settings/categories${W}`, 'settings categories'],
	[`/apps/budgetcheck/settings/budget-defaults${W}`, 'settings budget defaults'],
	[`/apps/budgetcheck/settings/booking-statuses${W}`, 'settings booking statuses'],
	[`/apps/budgetcheck/settings/members${W}`, 'settings members'],
	[`/apps/budgetcheck/settings/recurring${W}`, 'settings recurring'],
	[`/apps/budgetcheck/settings/help${W}`, 'settings help'],
	[`/apps/budgetcheck/app-settings/access${W}`, 'app settings access'],
	[`/apps/budgetcheck/app-settings/defaults${W}`, 'app settings defaults'],
	[`/apps/budgetcheck/app-settings/support${W}`, 'app settings support'],
];

/**
 * Intentionally marker-less lists: nav, breadcrumbs, chip/pill rows
 * (active filters, allowed users/groups), warning cards and action menus use
 * custom row chrome (explicit `list-style: none` + icons/pills), not bullet
 * lists. Content lists must NOT appear here — they must show markers.
 */
const LIST_ALLOW = [
	'.bc-nav',
	'.bc-breadcrumb',
	'.bc-chip-list',
	'.bc-warnings',
	'.bc-badge-list',
	'.bc-tx-actions',
	'.bc-table-actions',
	'.bc-recurring-actions',
	'.bc-dash-actions-grid',
	'.bc-tx-attachments__grid',
	'.bc-get-app__features',
	'.bc-quickstart',
	'.bc-switcher',
	'.bc-modal',
].join(', ');

function resolveStorageState() {
	if (process.env.E2E_STORAGE_STATE && fs.existsSync(process.env.E2E_STORAGE_STATE)) {
		return process.env.E2E_STORAGE_STATE;
	}
	const auto = path.join(__dirname, '..', '.auth', 'storage-state.json');
	return fs.existsSync(auto) ? auto : undefined;
}

test.use({ storageState: resolveStorageState() });

test.describe('Rendered-surface contract', () => {
	for (const [routePath, name] of SURFACES) {
		test(`ATLAS_RENDERED_SURFACE_CONTRACT ${name} (${routePath})`, async ({ page }) => {
			test.skip(
				!process.env.E2E_USER && !process.env.BASE_URL && !resolveStorageState(),
				'Set BASE_URL / E2E_USER or storage state',
			);
			await page.setViewportSize({ width: 1280, height: 800 });
			await page.goto(`${BASE}${routePath}`, { waitUntil: 'domcontentloaded' });

			// No-workspace sessions render the picker — skip rather than assert
			// a surface that legitimately has no content lists.
			const picker = page.locator('#bc-empty-title');
			if (await picker.isVisible({ timeout: 2000 }).catch(() => false)) {
				test.skip(true, 'No active workspace in this E2E session');
			}
			// Access-denied surface (norole storage state) — nothing to render.
			const denied = page.locator('#bc-denied-main, .bc-denied');
			if (await denied.isVisible({ timeout: 1000 }).catch(() => false)) {
				test.skip(true, 'Workspace denied for this user');
			}
			await page.waitForSelector('#bc-main-content', { timeout: 30_000 });

			await assertAtlasRenderedSurface(page, {
				content: '#bc-main-content',
				navExclude: '#app-navigation, nav, .bc-nav, .bc-breadcrumb, .bc-modal',
				listAllow: LIST_ALLOW,
			});
		});
	}

	// The admin settings panel renders inside /settings/admin/additional —
	// outside #bc-main-content — so it gets its own leg: the dedicated scoped
	// stylesheet must be wired (addStyle) and the core dt pin
	// (width:130px; white-space:nowrap; text-align:end) must be defeated.
	test('ATLAS_RENDERED_SURFACE_CONTRACT admin settings dl defeats core dt pin', async ({ page }) => {
		test.skip(
			!process.env.E2E_USER && !process.env.BASE_URL && !resolveStorageState(),
			'Set BASE_URL / E2E_USER or storage state',
		);
		await page.goto(`${BASE}/settings/admin/additional`, { waitUntil: 'domcontentloaded' });
		const panel = page.locator('.bc-admin-settings');
		await panel.waitFor({ state: 'visible', timeout: 30_000 });

		const wired = await page.locator('link[href*="budgetcheck"][href*="admin-settings"]').count();
		expect(wired).toBeGreaterThan(0);

		const cells = await page.locator('.bc-admin-summary dt, .bc-admin-summary dd').evaluateAll((els) =>
			els.map((el) => {
				const cs = getComputedStyle(el);
				return { tag: el.tagName, ws: cs.whiteSpace, w: cs.width, client: el.clientWidth, scroll: el.scrollWidth };
			}),
		);
		expect(cells.length).toBeGreaterThan(0);
		for (const cell of cells) {
			expect(cell.ws).not.toBe('nowrap');
			expect(cell.scroll).toBeLessThanOrEqual(cell.client + 1);
		}
	});
});
