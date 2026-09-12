// @ts-check
/**
 * Atlas POLICY ≥3.5.10 — real each_value filter toggles (not a11y page-visit theater).
 * Seals filt-web-transactions + filt-web-workspace-overview.
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { login, credsFromEnv } = require('./helpers/auth.js');

const storageStatePath = path.join(__dirname, '..', '..', '.auth', 'storage-state.json');

function hasAuthMaterial() {
	return !!(
		process.env.NC_ADMIN_USER ||
		(process.env.E2E_USER && (process.env.E2E_PASSWORD || process.env.E2E_PASS)) ||
		process.env.E2E_STORAGE_STATE ||
		fs.existsSync(storageStatePath)
	);
}

/** @type {import('@playwright/test').TestOptions} */
const authOptions = fs.existsSync(storageStatePath)
	? { storageState: storageStatePath }
	: {};

/**
 * @param {import('@playwright/test').Page} page
 */
async function ensureAuth(page) {
	if (!page.url().includes('/login')) {
		return;
	}
	if (process.env.NC_ADMIN_USER) {
		await login(page, credsFromEnv('ADMIN'));
		return;
	}
	if (process.env.E2E_USER && (process.env.E2E_PASSWORD || process.env.E2E_PASS)) {
		await login(page, {
			username: process.env.E2E_USER,
			password: process.env.E2E_PASSWORD || process.env.E2E_PASS || '',
		});
		return;
	}
	test.skip(true, 'On login wall without credentials (storage state may be stale)');
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function waitTxSettled(page) {
	const state = page.locator('[data-bc-tx-state]');
	const rows = page.locator('[data-bc-tx-rows] tr');
	await expect
		.poll(async () => {
			const busy = await page.locator('[data-bc-tx-kpi-tiles][aria-busy="true"]').count();
			if (busy) return false;
			const hasState = await state.isVisible().catch(() => false);
			const rowCount = await rows.count();
			return hasState || rowCount > 0;
		}, { timeout: 20000 })
		.toBeTruthy();
}

test.describe('BudgetCheck filter each_value (Atlas 3.5.10)', () => {
	test.use(authOptions);

	test.beforeEach(({ }, testInfo) => {
		if (!hasAuthMaterial()) {
			testInfo.skip(true, 'No E2E auth material configured');
		}
	});

	test('transactions: each listed filter value toggled + empty honest + rangePreset×from/to', async ({ page }) => {
		await page.setViewportSize({ width: 1400, height: 900 });
		await page.goto('/apps/budgetcheck/transactions', { waitUntil: 'domcontentloaded' });
		await ensureAuth(page);
		await page.goto('/apps/budgetcheck/transactions', { waitUntil: 'domcontentloaded' });
		await ensureAuth(page);

		const form = page.locator('[data-bc-tx-filters]');
		await expect(form).toBeVisible({ timeout: 30000 });
		await waitTxSettled(page);

		const range = form.locator('[data-bc-filter="rangePreset"]');
		const rangeValues = ['thisMonth', 'all', 'lastMonth', 'last30', 'ytd', 'last12'];
		for (const value of rangeValues) {
			const txResp = page.waitForResponse(
				(r) => r.url().includes('/apps/budgetcheck/api/transactions') && r.request().method() === 'GET' && r.ok(),
				{ timeout: 20000 },
			);
			await range.selectOption(value);
			await txResp;
			await expect(range).toHaveValue(value);
			await waitTxSettled(page);
			// Filter chrome stays; ledger shows rows or honest empty (not fake KPI-only theater).
			await expect(form).toBeVisible();
			await expect(page.locator('[data-bc-tx-summary], [data-bc-tx-state]').first()).toBeVisible();
		}

		// Category — toggle first non-empty option when catalog is populated.
		const category = form.locator('[data-bc-filter="categoryId"]');
		await expect(category).toBeVisible();
		const catValues = await category.locator('option').evaluateAll((opts) =>
			opts.map((o) => /** @type {HTMLOptionElement} */ (o).value).filter((v) => v !== ''),
		);
		if (catValues.length > 0) {
			const txResp = page.waitForResponse(
				(r) => r.url().includes('/apps/budgetcheck/api/transactions') && r.url().includes('categoryId=') && r.ok(),
				{ timeout: 20000 },
			);
			await category.selectOption(catValues[0]);
			await txResp;
			await expect(category).toHaveValue(catValues[0]);
			await waitTxSettled(page);
		}

		// Open advanced panel for group/status/booleans/dates.
		const more = page.locator('[data-bc-tx-more-toggle]');
		const morePanel = page.locator('[data-bc-tx-more-panel]');
		await more.scrollIntoViewIfNeeded();
		if ((await more.getAttribute('aria-expanded')) !== 'true') {
			await page.getByRole('button', { name: /More filters|Weitere Filter/i }).click();
		}
		await expect(more).toHaveAttribute('aria-expanded', 'true', { timeout: 10000 });
		await expect(morePanel).not.toHaveAttribute('hidden');

		const group = form.locator('[data-bc-filter="groupKey"]');
		await expect(group).toBeVisible();
		const groupValues = await group.locator('option').evaluateAll((opts) =>
			opts.map((o) => /** @type {HTMLOptionElement} */ (o).value).filter((v) => v !== ''),
		);
		const groupPick = groupValues.includes('__none__') ? '__none__' : groupValues[0];
		if (groupPick) {
			const txResp = page.waitForResponse(
				(r) => r.url().includes('/apps/budgetcheck/api/transactions') && r.url().includes('groupKey=') && r.ok(),
				{ timeout: 20000 },
			);
			await group.selectOption(groupPick);
			await txResp;
			await expect(group).toHaveValue(groupPick);
			await waitTxSettled(page);
		}

		const status = form.locator('[data-bc-filter="statusId"]');
		if (await status.count()) {
			const statusValues = await status.locator('option').evaluateAll((opts) =>
				opts.map((o) => /** @type {HTMLOptionElement} */ (o).value).filter((v) => v !== ''),
			);
			if (statusValues.length > 0) {
				const txResp = page.waitForResponse(
					(r) => r.url().includes('/apps/budgetcheck/api/transactions') && r.url().includes('statusId=') && r.ok(),
					{ timeout: 20000 },
				);
				await status.selectOption(statusValues[0]);
				await txResp;
				await expect(status).toHaveValue(statusValues[0]);
				await waitTxSettled(page);
			}
		}
		// else: statusId is project-only (templates/transactions.php $isProject) — honest n_a on household.

		const special = form.locator('[data-bc-filter="isSpecial"]');
		const uncat = form.locator('[data-bc-filter="uncategorized"]');
		await expect(special).toBeVisible();
		await expect(uncat).toBeVisible();
		{
			const txResp = page.waitForResponse(
				(r) => r.url().includes('/apps/budgetcheck/api/transactions') && r.url().includes('isSpecial=1') && r.ok(),
				{ timeout: 20000 },
			);
			await special.check();
			await txResp;
			await expect(special).toBeChecked();
			await waitTxSettled(page);
		}
		{
			const txResp = page.waitForResponse(
				(r) => r.url().includes('/apps/budgetcheck/api/transactions') && r.url().includes('uncategorized=1') && r.ok(),
				{ timeout: 20000 },
			);
			await uncat.check();
			await txResp;
			await expect(uncat).toBeChecked();
			await waitTxSettled(page);
		}

		// Reset then prove search empty honesty (q).
		await form.locator('button[type="reset"]').click();
		await waitTxSettled(page);
		const q = form.locator('[data-bc-filter="q"]');
		await expect(q).toBeVisible();
		{
			const txResp = page.waitForResponse(
				(r) => r.url().includes('/apps/budgetcheck/api/transactions') && r.url().includes('q=') && r.ok(),
				{ timeout: 20000 },
			);
			await q.fill('ATLAS-NO-MATCH-FILTER-ZZZ');
			await txResp;
			await waitTxSettled(page);
		}
		await expect(page.locator('[data-bc-tx-state]')).toBeVisible();
		await expect(page.locator('.bc-tx-ledger__state-title')).toContainText(
			/No bookings match these filters|Keine Buchungen entsprechen diesen Filtern/i,
		);
		await expect(page.locator('.bc-tx-ledger__state-body')).toContainText(
			/wider date range|different category|clear the filters|Filter zurücksetzen|Datumsbereich|Kategorie|Filter/i,
		);
		// Filter chrome must remain (not stripped on empty).
		await expect(form).toBeVisible();
		await expect(q).toHaveValue('ATLAS-NO-MATCH-FILTER-ZZZ');

		// Coupled: rangePreset custom × from/to.
		await form.locator('button[type="reset"]').click();
		await waitTxSettled(page);
		if ((await more.getAttribute('aria-expanded')) !== 'true') {
			await page.getByRole('button', { name: /More filters|Weitere Filter/i }).click();
		}
		await expect(more).toHaveAttribute('aria-expanded', 'true');
		await range.selectOption('custom');
		await expect(range).toHaveValue('custom');
		const from = form.locator('[data-bc-filter="from"]');
		const to = form.locator('[data-bc-filter="to"]');
		await expect(from).toBeVisible();
		await expect(to).toBeVisible();
		{
			const txResp = page.waitForResponse(
				(r) =>
					r.url().includes('/apps/budgetcheck/api/transactions') &&
					r.url().includes('from=2020-01-01') &&
					r.url().includes('to=2020-01-31') &&
					r.ok(),
				{ timeout: 20000 },
			);
			await from.fill('2020-01-01');
			await to.fill('2020-01-31');
			// change events fire load; also click Apply for honesty.
			await form.locator('button[type="submit"]').click();
			await txResp;
			await waitTxSettled(page);
		}
		await expect(from).toHaveValue('2020-01-01');
		await expect(to).toHaveValue('2020-01-31');
		await expect(range).toHaveValue('custom');
	});

	test('workspace overview: search/type/role/onlyFavorites toggled + empty honest', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 });
		await page.goto('/apps/budgetcheck/workspaces', { waitUntil: 'domcontentloaded' });
		await ensureAuth(page);
		await page.goto('/apps/budgetcheck/workspaces', { waitUntil: 'domcontentloaded' });
		await ensureAuth(page);

		const root = page.locator('[data-bc-workspace-filters]');
		await expect(root).toBeVisible({ timeout: 30000 });
		const grid = page.locator('[data-bc-workspace-grid]');
		await expect(grid).toBeVisible();
		// aria-busy="false" is a truthy string — wait until not busy, then cards/empty exist.
		await expect
			.poll(async () => (await grid.getAttribute('aria-busy')) !== 'true', { timeout: 30000 })
			.toBeTruthy();
		await expect
			.poll(async () => grid.locator('.bc-workspace-card').count(), { timeout: 15000 })
			.toBeGreaterThan(0);

		const search = root.locator('[data-bc-filter-search]');
		const type = root.locator('[data-bc-filter-type]');
		const role = root.locator('[data-bc-filter-role]');
		const favorites = root.locator('[data-bc-filter-only-favorites]');

		await expect(search).toBeVisible();
		await expect(type).toBeVisible();
		await expect(role).toBeVisible();
		await expect(favorites).toBeVisible();

		/** @param {import('@playwright/test').Locator} g */
		async function assertGridHonest(g) {
			await expect
				.poll(async () => {
					const cards = await g.locator('.bc-workspace-card:not(.bc-workspace-card--empty)').count();
					const empty = await g.locator('.bc-workspace-card--empty').count();
					return cards + empty;
				}, { timeout: 10000 })
				.toBeGreaterThan(0);
		}

		for (const typeValue of ['household', 'project', 'all']) {
			await type.selectOption(typeValue);
			await expect(type).toHaveValue(typeValue);
			await expect(page.locator('[data-bc-workspace-stats]')).toContainText(/workspace|Arbeitsbereich/i);
			await assertGridHonest(grid);
		}

		for (const roleValue of ['manager', 'contributor', 'viewer', 'all']) {
			await role.selectOption(roleValue);
			await expect(role).toHaveValue(roleValue);
			await assertGridHonest(grid);
		}

		await favorites.check();
		await expect(favorites).toBeChecked();
		await assertGridHonest(grid);

		// Empty honesty: no-match search keeps filter chrome + recovery copy.
		await favorites.uncheck();
		await type.selectOption('all');
		await role.selectOption('all');
		await search.fill('ATLAS-NO-MATCH-WORKSPACE-ZZZ');
		await expect(grid.locator('.bc-workspace-card--empty')).toBeVisible({ timeout: 10000 });
		await expect(grid.locator('h3')).toContainText(/No matching workspaces|Keine passenden/i);
		await expect(grid.locator('p')).toContainText(
			/broadening your filters|Filter zu erweitern|Filter erweitern|Try broadening/i,
		);
		await expect(root).toBeVisible();
		await expect(search).toHaveValue('ATLAS-NO-MATCH-WORKSPACE-ZZZ');

		// Coupled type × favorites: both active still renders honest empty or cards.
		await type.selectOption('project');
		await favorites.check();
		await assertGridHonest(grid);

		await root.locator('[data-bc-action="workspace-filters-reset"]').click();
		await expect(search).toHaveValue('');
		await expect(type).toHaveValue('all');
		await expect(role).toHaveValue('all');
		await expect(favorites).not.toBeChecked();
	});
});
