const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');
const DASHBOARD = process.env.E2E_BC_DASHBOARD_URL || `${BASE}/apps/budgetcheck/dashboard`;

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
		test.skip(true, 'Nextcloud shows Update needed — run occ upgrade');
	}
	const login = page.getByRole('heading', { name: /log in to nextcloud|bei nextcloud anmelden/i });
	const onLogin = await login.isVisible({ timeout: 3000 }).catch(() => false);
	if (onLogin) {
		const hasCreds = !!(process.env.E2E_USER && (process.env.E2E_PASSWORD || process.env.E2E_PASS));
		if (hasCreds) {
			throw new Error('Still on login despite E2E_USER — storage state invalid or session expired');
		}
		test.skip(true, 'Set E2E_USER + E2E_PASSWORD (or E2E_PASS) and re-run');
	}
}

test.describe('Dashboard summary + warning recovery', () => {
	test('summary tiles survive warnings with Open recovery links', async ({ page }) => {
		const pageErrors = [];
		page.on('pageerror', (err) => pageErrors.push(String(err && err.message ? err.message : err)));

		await page.goto(DASHBOARD, { waitUntil: 'domcontentloaded' });
		await skipIfLogin(page);

		const app = page.locator('#app-content.bc-app, #app-content[data-bc-app], .bc-app').first();
		await expect(app).toBeVisible({ timeout: 20_000 });

		const emptyPicker = page.getByRole('heading', { name: /pick or create a workspace|workspace wählen|workspace auswählen/i });
		if (await emptyPicker.isVisible({ timeout: 1500 }).catch(() => false)) {
			test.skip(true, 'No active workspace in this E2E session');
		}

		const summaryGrid = page.locator('[data-bc-summary-grid]');
		await expect(summaryGrid).toBeVisible({ timeout: 20_000 });

		// Wait until the busy flag clears (API + render finished).
		await expect(summaryGrid).not.toHaveAttribute('aria-busy', 'true', { timeout: 20_000 });

		await expect(summaryGrid).not.toContainText(/Could not load the summary/i);

		const tile = summaryGrid.locator('.bc-summary-tile, .bc-summary-section').first();
		await expect(tile).toBeVisible({ timeout: 10_000 });

		const workspaceCrash = pageErrors.filter((m) => /reading ['"]workspace['"]/i.test(m));
		expect(workspaceCrash, 'no pageerror reading workspace').toEqual([]);

		const warnings = page.locator('[data-bc-warnings]');
		if (await warnings.isVisible().catch(() => false)) {
			const openLinks = warnings.locator('a.button, a.bc-warning__action a, .bc-warning__action a');
			const count = await openLinks.count();
			if (count > 0) {
				const href = await openLinks.first().getAttribute('href');
				expect(href || '').toMatch(/workspaceId=/);
			}
		}

		// Direct DOM proof: unbound withWorkspace must not throw in the live page.
		const unboundOk = await page.evaluate(() => {
			const Ws = window.BudgetCheckWorkspace;
			if (!Ws || typeof Ws.withWorkspace !== 'function') return { ok: false, reason: 'missing' };
			const extracted = Ws.withWorkspace;
			try {
				const href = extracted('/apps/budgetcheck/transactions');
				return { ok: /workspaceId=/.test(href), href };
			} catch (err) {
				return { ok: false, reason: String(err && err.message ? err.message : err) };
			}
		});
		expect(unboundOk.ok, JSON.stringify(unboundOk)).toBeTruthy();
	});
});
