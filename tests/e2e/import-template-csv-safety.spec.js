// @ts-check
const { test, expect } = require('@playwright/test');
const { readFileSync } = require('node:fs');

/**
 * csv_formula_injection regression (api-security-patterns §7): the import
 * page's client-side CSV template download must apostrophe-prefix cells
 * whose lead char is a spreadsheet formula trigger (= + - @ TAB CR VT) —
 * mirroring TransactionExportService::guardFormula on the PHP emitters.
 *
 * Hostile category names are seeded through the real write path
 * (POST /api/categories), the real template button is clicked, and the
 * downloaded bytes are asserted.
 *
 * Uses a create-or-reuse fixture workspace ("ATLAS csv-template") — same
 * pattern as the import-split spec — so repeat runs stay idempotent and
 * never hit the workspace-delete rate limit.
 */
test.describe('CSV template download neutralizes formula-lead cells', () => {
	test('hostile category names are apostrophe-prefixed in the emitted file', async ({ page }) => {
		await page.goto('/apps/budgetcheck/', { waitUntil: 'domcontentloaded' });
		const token = await page.evaluate(() => (window.OC && OC.requestToken) || '');
		expect(token).not.toBe('');
		const auth = { requesttoken: token };

		const list = await page.request.get('/apps/budgetcheck/api/workspaces');
		expect(list.ok(), 'workspace list').toBeTruthy();
		const workspaces = (await list.json()).workspaces || [];
		let workspaceId = (workspaces.find((w) => w && w.name === 'ATLAS csv-template' && w.role === 'manager') || {}).id;

		if (!workspaceId) {
			const wsRes = await page.request.post('/apps/budgetcheck/api/workspaces', {
				headers: auth,
				data: { name: 'ATLAS csv-template', type: 'household', currencyCode: 'EUR' },
			});
			expect(wsRes.ok(), 'household workspace create').toBeTruthy();
			workspaceId = (await wsRes.json()).workspace.id;
		}

		const stamp = `${Date.now().toString(36)}${process.pid.toString(36)}`.slice(-8);
		const hostileExpense = `=ATLASINJ-${stamp}("x","y")`;
		const hostileIncome = `-ATLASINJ-${stamp}`;
		const createdIds = [];

		try {
			for (const [name, type] of [[hostileExpense, 'expense'], [hostileIncome, 'income']]) {
				const res = await page.request.post('/apps/budgetcheck/api/categories', {
					headers: auth,
					data: { workspaceId, name, type },
				});
				expect(res.ok(), `${type} category create`).toBeTruthy();
				createdIds.push((await res.json()).category.id);
			}

			await page.goto(`/apps/budgetcheck/import?workspaceId=${workspaceId}`, { waitUntil: 'domcontentloaded' });
			const downloadBtn = page.locator('[data-bc-import-download-template]');
			await expect(downloadBtn).toBeVisible({ timeout: 30000 });
			// Wait for catalog load to finish (category reference table populated).
			await expect(
				page.locator('[data-bc-import-category-rows]'),
			).toContainText('ATLASINJ', { timeout: 30000 });

			const [download] = await Promise.all([
				page.waitForEvent('download', { timeout: 30000 }),
				downloadBtn.click(),
			]);
			const filePath = await download.path();
			expect(filePath, 'download materialized').toBeTruthy();
			const text = readFileSync(filePath, 'utf8');
			const lines = text.replace(/^﻿/, '').split('\n');

			expect(lines[0]).toBe('bookingDate,title,direction,amount,category,notes,externalRef');
			// Cells with formula leads are apostrophe-prefixed, then RFC-quoted
			// (inner double-quotes are doubled — assert on the name prefix).
			expect(text).toContain(`'=ATLASINJ-${stamp}`);
			expect(text).toContain(`'-ATLASINJ-${stamp}`);
			for (const row of lines.slice(1)) {
				expect(row, 'no unguarded formula lead survives').not.toMatch(/(^|,)"?[=+\-@\t\r\x0B]/);
			}
		} finally {
			for (const id of createdIds) {
				await page.request.post(`/apps/budgetcheck/api/categories/${id}/deactivate`, { headers: auth }).catch(() => {});
			}
		}
	});
});
