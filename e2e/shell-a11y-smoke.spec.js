const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');
const DASHBOARD = process.env.E2E_BC_DASHBOARD_URL || `${BASE}/apps/budgetcheck/`;
const viewports = [
	{ name: 'mobile-320', width: 320, height: 720 },
	{ name: 'mobile-375', width: 375, height: 812 },
	{ name: 'tablet-768', width: 768, height: 1024 },
	{ name: 'desktop-1024', width: 1024, height: 768 },
	{ name: 'desktop-1280', width: 1280, height: 800 },
	{ name: 'desktop-1440', width: 1440, height: 900 },
];

function resolveStorageState() {
	if (process.env.E2E_STORAGE_STATE && fs.existsSync(process.env.E2E_STORAGE_STATE)) {
		return process.env.E2E_STORAGE_STATE;
	}
	const auto = path.join(__dirname, '..', '.auth', 'storage-state.json');
	return fs.existsSync(auto) ? auto : undefined;
}

test.use({ storageState: resolveStorageState() });

async function skipIfLogin(page) {
	const updateNeeded = page.getByRole('heading', { name: /update needed|aktualisierung erforderlich/i });
	if (await updateNeeded.isVisible({ timeout: 1500 }).catch(() => false)) {
		test.skip(true, 'Nextcloud shows Update needed — run: docker compose exec -u www-data nextcloud php occ upgrade');
	}
	const login = page.getByRole('heading', { name: /log in to nextcloud|bei nextcloud anmelden/i });
	const onLogin = await login.isVisible({ timeout: 3000 }).catch(() => false);
	if (onLogin) {
		const hasCreds = !!(process.env.E2E_USER && (process.env.E2E_PASSWORD || process.env.E2E_PASS));
		if (hasCreds) {
			throw new Error('Still on login despite E2E_USER — storage state invalid or session expired');
		}
		test.skip(true, 'Set E2E_USER + E2E_PASSWORD (or E2E_PASS) and re-run; global-setup writes .auth/storage-state.json');
	}
}

for (const vp of viewports) {
	test.describe(`BudgetCheck shell @ ${vp.name}`, () => {
		test.beforeEach(async ({ page }) => {
			await page.setViewportSize({ width: vp.width, height: vp.height });
		});

		test('dashboard shell, skip link, and budgets nav when workspace active', async ({ page }) => {
			await page.goto(DASHBOARD, { waitUntil: 'domcontentloaded' });
			await skipIfLogin(page);

			const app = page.locator('#app-content.bc-app, #app-content[data-bc-app], .bc-app').first();
			await expect(app).toBeVisible({ timeout: 20_000 });

			const skip = page.locator('a.bc-skip-link');
			await expect(skip).toBeVisible();

			const main = page.locator('#bc-main-content');
			await expect(main).toBeVisible();

			const budgetsNav = page.locator('#app-navigation a').filter({ hasText: /Budgets|Budgets/i });
			// German UI uses "Budgets" in en; de may use "Budgets" or localized label
			const budgetsDe = page.locator('#app-navigation a').filter({ hasText: /Budget/i });
			const budgetsCount = Math.max(await budgetsNav.count(), await budgetsDe.count());
			const budgetsLink = (await budgetsNav.count()) > 0 ? budgetsNav.first() : budgetsDe.first();
			if (budgetsCount > 0) {
				await expect(budgetsLink).toBeVisible();
				const box = await budgetsLink.boundingBox();
				expect(box).toBeTruthy();
				if (box && vp.width >= 720) {
					expect(box.height).toBeGreaterThanOrEqual(24);
				}
			}

			const overflow = await page.evaluate(() => {
				const root = document.querySelector('#app-content') || document.body;
				return root.scrollWidth > root.clientWidth + 2;
			});
			expect(overflow).toBeFalsy();
		});
	});
}
