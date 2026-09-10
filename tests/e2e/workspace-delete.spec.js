// @ts-check
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { login, credsFromEnv } = require('./helpers/auth.js');

/**
 * E2E: manager can open typed-name delete confirm on Workspace settings.
 * Does not hard-delete a shared fixture workspace (destructive); proves UI + preview API.
 */
const storageStatePath = path.join(__dirname, '..', '..', '.auth', 'storage-state.json');

function hasAuthMaterial() {
	return !!(
		process.env.NC_ADMIN_USER ||
		(process.env.E2E_USER && (process.env.E2E_PASSWORD || process.env.E2E_PASS)) ||
		process.env.E2E_STORAGE_STATE ||
		fs.existsSync(storageStatePath)
	);
}

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

test.describe('workspace delete danger zone', () => {
	test('manager sees delete zone and typed confirm gates the primary button', async ({ page }) => {
		test.skip(!hasAuthMaterial(), 'Requires storage-state or NC_ADMIN_* / E2E_* credentials');
		await page.goto('/apps/budgetcheck/workspaces', { waitUntil: 'domcontentloaded' });
		await ensureAuth(page);

		const list = await page.request.get('/apps/budgetcheck/api/workspaces');
		expect(list.ok()).toBeTruthy();
		const data = await list.json();
		const workspaces = Array.isArray(data.workspaces) ? data.workspaces : [];
		const managed = workspaces.find((w) => w && String(w.role) === 'manager');
		test.skip(!managed, 'No managed workspace available for this user');

		const id = Number(managed.id);
		const name = String(managed.name || '');
		expect(name.length).toBeGreaterThan(0);

		const impactRes = await page.request.get(`/apps/budgetcheck/api/workspaces/${id}/delete-impact`);
		expect(impactRes.ok()).toBeTruthy();
		const impactBody = await impactRes.json();
		expect(impactBody.ok).toBeTruthy();
		expect(impactBody.impact.workspaceId).toBe(id);

		await page.goto(`/apps/budgetcheck/settings/workspace?workspaceId=${id}`, {
			waitUntil: 'domcontentloaded',
		});
		await ensureAuth(page);
		const zone = page.locator('[data-bc-workspace-delete-zone]');
		await expect(zone).toBeVisible();
		await page.locator('[data-bc-workspace-delete]').click();

		const dialog = page.locator('.bc-modal__dialog');
		await expect(dialog).toBeVisible();
		const primary = dialog.locator('button.primary');
		await expect(primary).toBeDisabled();

		const input = dialog.locator('input[name="confirmName"]');
		await input.fill(name);
		await expect(primary).toBeEnabled();

		await dialog.locator('.bc-modal__close').click();
		await expect(dialog).toHaveCount(0);
	});

	test('wrong confirmName is rejected by API without deleting', async ({ page }) => {
		test.skip(!hasAuthMaterial(), 'Requires storage-state or NC_ADMIN_* / E2E_* credentials');
		await page.goto('/apps/budgetcheck/workspaces', { waitUntil: 'domcontentloaded' });
		await ensureAuth(page);

		const list = await page.request.get('/apps/budgetcheck/api/workspaces');
		expect(list.ok()).toBeTruthy();
		const data = await list.json();
		const managed = (Array.isArray(data.workspaces) ? data.workspaces : [])
			.find((w) => w && String(w.role) === 'manager');
		test.skip(!managed, 'No managed workspace available for this user');

		const id = Number(managed.id);
		const token = await page.evaluate(() => {
			if (window.OC && OC.requestToken) return OC.requestToken;
			return document.querySelector('head')?.getAttribute('data-requesttoken') || '';
		});
		expect(token).toBeTruthy();

		const res = await page.request.delete(`/apps/budgetcheck/api/workspaces/${id}`, {
			headers: { requesttoken: token, 'Content-Type': 'application/json' },
			data: { confirmName: '__definitely_not_the_name__' },
		});
		expect(res.status()).toBe(400);

		const still = await page.request.get(`/apps/budgetcheck/api/workspaces/${id}`);
		expect(still.ok()).toBeTruthy();
	});
});
