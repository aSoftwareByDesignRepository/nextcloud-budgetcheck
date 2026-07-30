/**
 * Receipt AI suggest — web E2E closeout.
 * AI-off: attach stays manual. AI-on (mocked): Bachus overlay review → Save.
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');
const DASHBOARD = process.env.E2E_BC_DASHBOARD_URL || `${BASE}/apps/budgetcheck/`;

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
		test.skip(true, 'Set E2E_USER + E2E_PASSWORD and re-run for storage state');
	}
}

async function ensureExpenseCategory(page) {
	return page.evaluate(async () => {
		const Api = window.BudgetCheck && window.BudgetCheck.get && window.BudgetCheck.get('Api');
		const Ws = window.BudgetCheckWorkspace;
		if (!Api || !Ws || !Ws.workspace) return null;
		const workspaceId = Ws.workspace.id;
		const data = await Api.get('/apps/budgetcheck/api/categories', { workspaceId });
		const list = data.categories || [];
		const existing = list.find((c) => c.type === 'expense' && c.isActive !== false);
		if (existing) return Number(existing.id);
		const created = await Api.post('/apps/budgetcheck/api/categories', {
			workspaceId,
			name: 'E2E Receipt Food',
			type: 'expense',
		});
		const cat = created.category || created;
		return cat && cat.id ? Number(cat.id) : null;
	});
}

async function openNewTransaction(page) {
	const dashBtn = page.locator('[data-bc-dash-action="new-transaction"]').first();
	if (await dashBtn.isVisible({ timeout: 4000 }).catch(() => false)) {
		await dashBtn.click();
	} else {
		await page.goto(`${BASE}/apps/budgetcheck/transactions`, { waitUntil: 'domcontentloaded' });
		await skipIfLogin(page);
		const txBtn = page.getByRole('button', { name: /new transaction|neue buchung|neue transaktion/i }).first();
		await expect(txBtn).toBeVisible({ timeout: 15_000 });
		await txBtn.click();
	}
	const dialog = page.locator('.bc-modal__dialog');
	await expect(dialog).toBeVisible({ timeout: 15_000 });
	return dialog;
}

function tinyPngBuffer() {
	return Buffer.from(
		'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
		'base64',
	);
}

test.describe('Receipt AI suggest web parity', () => {
	test('receipt-suggest module is registered on dashboard', async ({ page }) => {
		await page.goto(DASHBOARD, { waitUntil: 'domcontentloaded' });
		await skipIfLogin(page);
		await expect(page.locator('#bc-main-content')).toBeVisible({ timeout: 20_000 });
		const info = await page.evaluate(() => {
			const BC = window.BudgetCheck;
			const rs = BC && BC.get ? BC.get('ReceiptSuggest') : null;
			return {
				hasModule: !!(rs && typeof rs.passesClientGate === 'function'),
				threshold: rs ? rs.CONFIDENCE_SINGLE_MIN : null,
				mirrored: !!window.BudgetCheckReceiptSuggest,
			};
		});
		expect(info.hasModule).toBe(true);
		expect(info.threshold).toBe(0.72);
		expect(info.mirrored).toBe(true);
	});

	test('AI-off: attaching a receipt does not open suggest overlay', async ({ page }) => {
		await page.goto(DASHBOARD, { waitUntil: 'domcontentloaded' });
		await skipIfLogin(page);
		await expect(page.locator('#bc-main-content')).toBeVisible({ timeout: 20_000 });

		await page.evaluate(() => {
			const RS = window.BudgetCheck.get('ReceiptSuggest');
			RS.forceCapabilityForTests(false);
		});

		const catId = await ensureExpenseCategory(page);
		if (!catId) {
			test.skip(true, 'Cannot create expense category for AI-off attach test');
		}
		// Catalog may be cached empty from earlier — invalidate so the modal opens.
		await page.evaluate(() => {
			const Ed = window.BudgetCheck && window.BudgetCheck.get && window.BudgetCheck.get('TransactionEditor');
			if (Ed && typeof Ed.invalidateCatalog === 'function') {
				Ed.invalidateCatalog();
			}
		});

		const dialog = await openNewTransaction(page);
		const fileInput = dialog.locator('input[type="file"]').first();
		await expect(fileInput).toBeAttached({ timeout: 10_000 });
		await fileInput.setInputFiles({
			name: 'receipt.png',
			mimeType: 'image/png',
			buffer: tinyPngBuffer(),
		});

		await page.waitForTimeout(900);
		await expect(dialog.locator('.bc-receipt-suggest')).toHaveCount(0);
		await expect(dialog.locator('.bc-tx-attachments__card--pending')).toBeVisible({ timeout: 8_000 });
	});

	test('AI-on mock: review overlay Save closes without double-attach', async ({ page }) => {
		const suggestion = {
			jobId: 9001,
			status: 'ready',
			quality: 'high',
			mode: 'single',
			title: 'REWE Test',
			merchant: 'REWE',
			bookingDate: '2026-07-29',
			currencyCode: 'EUR',
			totalMinor: 4523,
			direction: 'expense',
			lines: [{
				label: 'Total',
				amountMinor: 4523,
				categoryId: 1,
				confidence: 0.9,
			}],
			warnings: [],
			source: 'analyze-images',
		};

		await page.route('**/apps/budgetcheck/api/workspaces/*/receipt-suggestions**', async (route) => {
			const url = route.request().url();
			const method = route.request().method();
			if (method === 'POST' && /\/receipt-suggestions$/.test(url.split('?')[0])) {
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({ jobId: 9001, status: 'pending' }),
				});
				return;
			}
			if (method === 'GET' && /\/receipt-suggestions\/\d+$/.test(url.split('?')[0])) {
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify(suggestion),
				});
				return;
			}
			if (method === 'POST' && /\/receipt-suggestions\/\d+\/accept/.test(url)) {
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({
						transactions: [{ id: 555, version: 1 }],
						attachments: [{ id: 1 }],
					}),
				});
				return;
			}
			await route.continue();
		});

		await page.goto(DASHBOARD, { waitUntil: 'domcontentloaded' });
		await skipIfLogin(page);
		await expect(page.locator('#bc-main-content')).toBeVisible({ timeout: 20_000 });

		await page.evaluate(() => {
			const RS = window.BudgetCheck.get('ReceiptSuggest');
			RS.forceCapabilityForTests(true, ['analyze-images']);
		});

		const catId = await ensureExpenseCategory(page);
		if (!catId) {
			test.skip(true, 'Cannot create expense category for AI-on mock test');
		}
		await page.evaluate(() => {
			const Ed = window.BudgetCheck && window.BudgetCheck.get && window.BudgetCheck.get('TransactionEditor');
			if (Ed && typeof Ed.invalidateCatalog === 'function') {
				Ed.invalidateCatalog();
			}
		});

		const dialog = await openNewTransaction(page);

		const meta = await dialog.evaluate(() => {
			const select = document.querySelector('.bc-modal__dialog select[name="categoryId"]');
			const currency = (window.BudgetCheckWorkspace && window.BudgetCheckWorkspace.workspace
				&& window.BudgetCheckWorkspace.workspace.currencyCode) || 'EUR';
			if (!select) return { categoryId: null, currency };
			const opts = Array.from(select.options)
				.map((o) => Number(o.value))
				.filter((n) => Number.isInteger(n) && n > 0);
			return { categoryId: opts[0] || null, currency };
		});
		if (!meta.categoryId) {
			test.skip(true, 'No expense category options in new-transaction dialog');
		}
		suggestion.lines[0].categoryId = meta.categoryId;
		suggestion.currencyCode = meta.currency;

		const fileInput = dialog.locator('input[type="file"]').first();
		await fileInput.setInputFiles({
			name: 'receipt.png',
			mimeType: 'image/png',
			buffer: tinyPngBuffer(),
		});

		const overlay = dialog.locator('.bc-receipt-suggest');
		await expect(overlay).toBeVisible({ timeout: 15_000 });
		await expect(overlay.getByText(/does this look right|stimmt das so/i)).toBeVisible({ timeout: 15_000 });

		await overlay.getByRole('button', { name: /^(save|speichern)$/i }).click();
		await expect(page.locator('.bc-modal__dialog')).toHaveCount(0, { timeout: 15_000 });
	});
});
