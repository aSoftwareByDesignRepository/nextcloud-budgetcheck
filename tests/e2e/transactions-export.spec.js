// @ts-check
const { test, expect } = require('@playwright/test');
const fs = require('fs');

/**
 * Proves the all-transactions export (issue #21) end to end: the ledger
 * page offers CSV and ODS actions with accessible names + scope hint, each
 * triggers a real download, and the downloaded payload carries the seeded
 * booking — not an empty shell.
 *
 * Uses a create-or-reuse fixture workspace ("ATLAS export"): no hard
 * delete, because the workspace_delete bucket is 5/hour per user and
 * per-run deletes would make the suite rate-limit-flaky.
 */
test.describe('transactions export (CSV + ODS)', () => {
	test('export buttons download CSV and ODS with the seeded booking', async ({ page }) => {
		await page.goto('/apps/budgetcheck/transactions', { waitUntil: 'domcontentloaded' });
		const token = await page.evaluate(() => (window.OC && OC.requestToken) || '');
		expect(token).not.toBe('');
		const auth = { requesttoken: token };

		// Fixture: reuse "ATLAS export" if present, else create it.
		const list = await page.request.get('/apps/budgetcheck/api/workspaces');
		expect(list.ok(), 'workspace list').toBeTruthy();
		const workspaces = (await list.json()).workspaces || [];
		let workspaceId = (workspaces.find((w) => w && w.name === 'ATLAS export' && w.role === 'manager') || {}).id;

		if (!workspaceId) {
			const wsRes = await page.request.post('/apps/budgetcheck/api/workspaces', {
				headers: auth,
				data: { name: 'ATLAS export', type: 'household', currencyCode: 'EUR' },
			});
			expect(wsRes.ok(), 'household workspace create').toBeTruthy();
			workspaceId = (await wsRes.json()).workspace.id;
		}

		// Seed a marker booking if it is not already there (create-or-reuse idempotent).
		const txList = await page.request.get(
			`/apps/budgetcheck/api/transactions?workspaceId=${workspaceId}&q=ATLAS-export-marker&limit=1`,
		);
		expect(txList.ok(), 'marker lookup').toBeTruthy();
		const markerExists = ((await txList.json()).items || []).length > 0;
		if (!markerExists) {
			const cats = await page.request.get(`/apps/budgetcheck/api/categories?workspaceId=${workspaceId}`);
			const categoryId = ((await cats.json()).categories || [])[0].id;
			const today = new Date().toISOString().slice(0, 10);
			const txRes = await page.request.post('/apps/budgetcheck/api/transactions', {
				headers: auth,
				data: {
					workspaceId,
					direction: 'expense',
					amount: '3.50',
					bookingDate: today,
					categoryId,
					title: 'ATLAS-export-marker',
					notes: 'e2e seeded',
				},
			});
			expect(txRes.ok(), 'marker booking create').toBeTruthy();
		}

		await page.goto(`/apps/budgetcheck/transactions?workspaceId=${workspaceId}`, { waitUntil: 'domcontentloaded' });
		const csvBtn = page.locator('[data-bc-tx-export="csv"]');
		const odsBtn = page.locator('[data-bc-tx-export="ods"]');
		await expect(csvBtn).toBeVisible({ timeout: 30000 });
		await expect(odsBtn).toBeVisible();
		// Accessible contract: described by the scope hint so screen readers
		// announce what the export contains.
		await expect(csvBtn).toHaveAttribute('aria-describedby', 'bc-tx-export-hint');
		await expect(page.locator('#bc-tx-export-hint')).toContainText('All time');
		// Keyboard reachable.
		await csvBtn.focus();
		await expect(csvBtn).toBeFocused();

		// Scope the export to all bookings so the marker is included.
		const range = page.locator('[data-bc-filter="rangePreset"]');
		if (await range.count()) {
			await range.selectOption('all');
		}

		const csvDownload = page.waitForEvent('download', { timeout: 30000 });
		await csvBtn.click();
		const csvFile = await csvDownload;
		expect(csvFile.suggestedFilename()).toMatch(/_transactions_\d{4}-\d{2}-\d{2}\.csv$/);
		const csvPath = await csvFile.path();
		const csvBytes = fs.readFileSync(csvPath);
		expect([...csvBytes.subarray(0, 3)]).toEqual([0xEF, 0xBB, 0xBF]); // UTF-8 BOM
		const csvContent = csvBytes.toString('utf8');
		expect(csvContent).toContain('"bookingDate"');
		expect(csvContent).toContain('ATLAS-export-marker');
		expect(csvContent).toContain('"vatRatePercent"');

		const odsDownload = page.waitForEvent('download', { timeout: 30000 });
		await odsBtn.click();
		const odsFile = await odsDownload;
		expect(odsFile.suggestedFilename()).toMatch(/_transactions_\d{4}-\d{2}-\d{2}\.ods$/);
		const odsPath = await odsFile.path();
		const magic = fs.readFileSync(odsPath).subarray(0, 2).toString('latin1');
		expect(magic).toBe('PK'); // ODS is a ZIP package
	});
});
