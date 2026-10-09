// @ts-check
const { test, expect } = require('@playwright/test');

/**
 * End-to-end proof for GitHub issue #23: bank CSVs that split income and
 * expense into two separate columns (one blank per row, optionally a minus
 * sign on the expense side) import correctly, and extra columns such as a
 * running balance are ignored.
 *
 * Uses a create-or-reuse fixture workspace ("ATLAS import-split") — same
 * pattern as the transactions-export spec — so repeat runs stay idempotent
 * and never hit the workspace-delete rate limit.
 */
test.describe('CSV import with split income/expense columns (issue #23)', () => {
	test('Expenses/Income + balance column file validates, commits, and lands correctly', async ({ page }) => {
		await page.goto('/apps/budgetcheck/', { waitUntil: 'domcontentloaded' });
		const token = await page.evaluate(() => (window.OC && OC.requestToken) || '');
		expect(token).not.toBe('');
		const auth = { requesttoken: token };

		const list = await page.request.get('/apps/budgetcheck/api/workspaces');
		expect(list.ok(), 'workspace list').toBeTruthy();
		const workspaces = (await list.json()).workspaces || [];
		let workspaceId = (workspaces.find((w) => w && w.name === 'ATLAS import-split' && w.role === 'manager') || {}).id;

		if (!workspaceId) {
			const wsRes = await page.request.post('/apps/budgetcheck/api/workspaces', {
				headers: auth,
				data: { name: 'ATLAS import-split', type: 'household', currencyCode: 'EUR' },
			});
			expect(wsRes.ok(), 'household workspace create').toBeTruthy();
			workspaceId = (await wsRes.json()).workspace.id;
		}

		// The import defaults need one category of each type; seed an income
		// category when the fixture workspace lacks one (system seeds are
		// expense-only).
		const catsRes = await page.request.get(`/apps/budgetcheck/api/categories?workspaceId=${workspaceId}`);
		expect(catsRes.ok(), 'categories list').toBeTruthy();
		const cats = (await catsRes.json()).categories || [];
		if (!cats.some((c) => c && c.type === 'income' && c.isActive !== false)) {
			const catRes = await page.request.post('/apps/budgetcheck/api/categories', {
				headers: auth,
				data: { workspaceId, name: 'ATLAS import income', type: 'income' },
			});
			expect(catRes.ok(), 'income category create').toBeTruthy();
		}
		if (!cats.some((c) => c && c.type === 'expense' && c.isActive !== false)) {
			const catRes = await page.request.post('/apps/budgetcheck/api/categories', {
				headers: auth,
				data: { workspaceId, name: 'ATLAS import expense', type: 'expense' },
			});
			expect(catRes.ok(), 'expense category create').toBeTruthy();
		}

		await page.goto(`/apps/budgetcheck/import?workspaceId=${workspaceId}`, { waitUntil: 'domcontentloaded' });

		// Default category pickers auto-fill once categories are loaded.
		const expenseSel = page.locator('[data-bc-import-default-expense]');
		const incomeSel = page.locator('[data-bc-import-default-income]');
		await expect(expenseSel).toHaveValue(/\d+/, { timeout: 30000 });
		await expect(incomeSel).toHaveValue(/\d+/);

		// The reporter's bank shape: separate Expenses/Income columns, a minus
		// sign on the expense side, and a balance column that must be ignored.
		const csv = [
			'Date,Description,Expenses,Income,Balance',
			'2026-10-01,ATLAS-split-expense,-12.34,,987.66',
			'2026-10-02,ATLAS-split-income,,150.00,1137.66',
		].join('\n');
		await page.locator('[data-bc-import-file]').setInputFiles({
			name: 'bank-split.csv',
			mimeType: 'text/csv',
			buffer: Buffer.from(csv, 'utf8'),
		});

		await page.locator('[data-bc-import-validate]').click();
		const status = page.locator('[data-bc-import-status]');
		await expect(status).toContainText('Validation passed', { timeout: 30000 });

		// Preview renders both rows with resolved directions.
		const previewRows = page.locator('[data-bc-import-preview] tr');
		await expect(previewRows).toHaveCount(2);
		await expect(previewRows.nth(0)).toContainText('expense');
		await expect(previewRows.nth(0)).toContainText('12.34');
		await expect(previewRows.nth(1)).toContainText('income');
		await expect(previewRows.nth(1)).toContainText('150.00');

		const commitBtn = page.locator('[data-bc-import-commit]');
		await expect(commitBtn).toBeEnabled();
		await commitBtn.click();
		await expect(page).toHaveURL(/\/apps\/budgetcheck\/transactions/, { timeout: 30000 });

		// Server-side truth: both bookings exist with the right direction/amount.
		const txList = await page.request.get(
			`/apps/budgetcheck/api/transactions?workspaceId=${workspaceId}&q=ATLAS-split&limit=50`,
		);
		expect(txList.ok(), 'transactions list').toBeTruthy();
		const items = (await txList.json()).items || [];
		const expense = items.find((i) => i.title === 'ATLAS-split-expense');
		const income = items.find((i) => i.title === 'ATLAS-split-income');
		expect(expense, 'imported expense row').toBeTruthy();
		expect(income, 'imported income row').toBeTruthy();
		expect(expense.direction).toBe('expense');
		expect(income.direction).toBe('income');
	});
});
