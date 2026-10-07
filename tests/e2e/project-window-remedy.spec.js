// @ts-check
const { test, expect } = require('@playwright/test');

/**
 * Proves the project-window recovery path end to end — the reporter's
 * confirmed root cause. A bookingDate outside the project period must
 * reject with a field-level error under the date control plus an
 * "Open workspace settings" remedy link, not a bare toast.
 *
 * Uses a create-or-reuse fixture workspace ("ATLAS remedy"): no hard
 * delete, because the workspace_delete bucket is 5/hour per user and
 * per-run deletes would make the suite rate-limit-flaky.
 */
test.describe('project window remedy', () => {
	test('out-of-window booking renders field error + settings link', async ({ page }) => {
		// requesttoken is required for mutations via page.request.
		await page.goto('/apps/budgetcheck/transactions', { waitUntil: 'domcontentloaded' });
		const token = await page.evaluate(() => (window.OC && OC.requestToken) || '');
		expect(token).not.toBe('');
		const auth = { requesttoken: token };

		// Fixture: reuse "ATLAS remedy" if present, else create it.
		const list = await page.request.get('/apps/budgetcheck/api/workspaces');
		expect(list.ok(), 'workspace list').toBeTruthy();
		const workspaces = (await list.json()).workspaces || [];
		let workspaceId = (workspaces.find((w) => w && w.name === 'ATLAS remedy' && w.role === 'manager') || {}).id;

		if (!workspaceId) {
			const wsRes = await page.request.post('/apps/budgetcheck/api/workspaces', {
				headers: auth,
				data: {
					name: 'ATLAS remedy',
					type: 'project',
					currencyCode: 'EUR',
					projectStartDate: '2026-01-01',
					projectEndDate: '2026-12-31',
				},
			});
			expect(wsRes.ok(), 'project workspace create').toBeTruthy();
			workspaceId = (await wsRes.json()).workspace.id;
		}

		// Pin the project window and clear any billing override every run so
		// the fixture stays deterministic (a set billing period relaxes the
		// booking-date window and changes the error wording).
		const winRes = await page.request.put(`/apps/budgetcheck/api/workspaces/${workspaceId}`, {
			headers: auth,
			data: {
				projectStartDate: '2026-01-01',
				projectEndDate: '2026-12-31',
				billingStartDate: null,
				billingEndDate: null,
			},
		});
		expect(winRes.ok(), 'project window update').toBeTruthy();

		// Ensure the "Materials" category exists (workspaces auto-seed only
		// the internal "Uncategorized", which is not a valid pick here).
		const cats = await page.request.get(`/apps/budgetcheck/api/categories?workspaceId=${workspaceId}`);
		expect(cats.ok(), 'category list').toBeTruthy();
		const hasMaterials = ((await cats.json()).categories || [])
			.some((c) => c && c.isActive && c.name === 'Materials');
		if (!hasMaterials) {
			const catRes = await page.request.post('/apps/budgetcheck/api/categories', {
				headers: auth,
				data: { workspaceId, name: 'Materials', type: 'expense', groupKey: 'expense' },
			});
			expect(catRes.ok(), 'category create').toBeTruthy();
		}

		await page.goto(`/apps/budgetcheck/transactions?workspaceId=${workspaceId}`, { waitUntil: 'domcontentloaded' });
		await page.locator('button.bc-tx-new-btn').click();
		const dialog = page.getByRole('dialog', { name: 'New transaction' });
		await expect(dialog).toBeVisible();

		await dialog.locator('[name="categoryId"]').selectOption({ label: 'Materials' });
		await dialog.locator('[name="amount"]').fill('5.00');
		await dialog.locator('[name="bookingDate"]').fill('2030-05-01');
		await dialog.getByRole('button', { name: 'Add transaction' }).click();

		// The save must be rejected with the inline field error…
		const err = dialog.locator('#bc-field-error-bookingDate');
		await expect(err).toBeVisible({ timeout: 10_000 });
		await expect(err).toContainText('period');

		// …and the remedy link to the project-period settings section.
		const link = err.locator('a[data-bc-remedy="project-window"]');
		await expect(link).toBeVisible();
		await expect(link).toHaveAttribute('href', /\/settings\/workspace\?.*workspaceId=/);
		await expect(link).toHaveAttribute('target', '_blank');
		await expect(link).toHaveAttribute('rel', /noopener/);

		// aria-invalid + describedby wiring from the shared field-errors module.
		const input = dialog.locator('[name="bookingDate"]');
		await expect(input).toHaveAttribute('aria-invalid', 'true');
		await expect(input).toHaveAttribute('aria-describedby', /bc-field-error-bookingDate/);
	});
});
