// @ts-check
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

test.describe('Private workspaces UX', () => {
	test.use(authOptions);

	test.beforeEach(({ }, testInfo) => {
		if (!hasAuthMaterial()) {
			testInfo.skip(true, 'No E2E auth material configured');
		}
	});

	test('workspace settings expose privacy fieldset with honest disclosure', async ({ page }) => {
		await page.goto('/apps/budgetcheck/', { waitUntil: 'domcontentloaded' });
		await ensureAuth(page);
		const listRes = await page.request.get('/apps/budgetcheck/api/workspaces');
		test.skip(!listRes.ok(), 'BudgetCheck API not reachable');
		const data = await listRes.json();
		const list = Array.isArray(data.workspaces) ? data.workspaces : [];
		const manageable = list.find((w) => w && w.id != null && String(w.role || '') === 'manager');
		test.skip(!manageable, 'No manager workspace available');

		const wid = Number(manageable.id);
		await page.goto(`/apps/budgetcheck/settings/workspace?workspaceId=${wid}`, {
			waitUntil: 'domcontentloaded',
		});
		await ensureAuth(page);

		const fieldset = page.locator('[data-bc-privacy-fieldset]');
		await expect(fieldset).toBeVisible();
		await expect(page.locator('#bc-privacy-disclosure')).toContainText(/database or server access|Datenbank- oder Serverzugriff|base de données|base de datos/i);
		await expect(page.locator('#bc-privacy-disclosure')).toContainText(/not end-to-end encryption|keine Ende-zu-Ende-Verschlüsselung|pas un chiffrement|no es cifrado/i);
		await expect(page.locator('input[name="privacyMode"][value="private"]')).toHaveCount(1);
		await expect(page.locator('input[name="privacyMode"][value="standard"]')).toHaveCount(1);
		const privateRadio = page.locator('input[name="privacyMode"][value="private"]');
		await expect(privateRadio).toBeEnabled();
		await privateRadio.focus();
		await expect(privateRadio).toBeFocused();
	});

	test('switching to private shows confirmation with host/DB disclosure', async ({ page }) => {
		await page.goto('/apps/budgetcheck/', { waitUntil: 'domcontentloaded' });
		await ensureAuth(page);
		const listRes = await page.request.get('/apps/budgetcheck/api/workspaces');
		test.skip(!listRes.ok(), 'BudgetCheck API not reachable');
		const data = await listRes.json();
		const list = Array.isArray(data.workspaces) ? data.workspaces : [];
		const manageable = list.find(
			(w) => w && w.id != null && String(w.role || '') === 'manager' && String(w.privacyMode || 'standard') === 'standard',
		);
		test.skip(!manageable, 'No standard manager workspace available');

		const wid = Number(manageable.id);
		await page.goto(`/apps/budgetcheck/settings/workspace?workspaceId=${wid}`, {
			waitUntil: 'domcontentloaded',
		});
		await ensureAuth(page);

		const privateRadio = page.locator('input[name="privacyMode"][value="private"]');
		test.skip(!(await privateRadio.isEnabled()), 'Privacy radios disabled for this user');
		await privateRadio.check();

		/** @type {string|null} */
		let confirmText = null;
		page.once('dialog', async (dialog) => {
			confirmText = dialog.message();
			await dialog.dismiss();
		});

		await page.locator('[data-bc-workspace-form] button[type="submit"]').click();
		await expect.poll(() => confirmText, { timeout: 5000 }).not.toBeNull();
		expect(String(confirmText)).toMatch(/database|server access|recover|Datenbank|wiederherstellen|récupér|base de datos/i);
		expect(String(confirmText)).toMatch(/groups|Gruppen|groupes|grupos|manager|Manager|gestionnaire|gestor/i);
		expect(String(confirmText).toLowerCase()).not.toMatch(/two managers|you need two managers|zwei manager brauchen|besoin de deux/i);
		expect(String(confirmText).toLowerCase()).not.toMatch(/zero-knowledge|end-to-end encrypted|ende-zu-ende-verschlüsselt/);
	});
});
