// @ts-check
/**
 * ATLAS_UI_INVARIANTS — shared-contract coverage for BudgetCheck web surfaces.
 *
 * Wires nextcloud/apps/_shared/e2e/atlas-ui-invariants.js onto representative
 * surfaces inside a dedicated throwaway workspace (created + deleted by this
 * spec, unique `bcui-` mark — no shared fixture mutation):
 *   a11y-dom sweep, console errors, raw i18n keys, O(1) list requests,
 *   stored-xss via category name, mutation→surface freshness,
 *   double-submit guard, form-survival-on-5xx.
 */
const { test, expect } = require('@playwright/test');
const {
	ATLAS_XSS_PAYLOADS,
	assertA11yDom,
	assertNoConsoleErrors,
	assertNoDuplicateSubmit,
	assertNoInjection,
	assertNoRawI18nKeys,
	assertSurfaceFresh,
	countApiRequests,
	trackConsoleErrors,
} = require('../../_shared/e2e/atlas-ui-invariants');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');
const APP = `${BASE}/index.php/apps/budgetcheck`;
const CONTENT = '#app-content';
const MARK = `bcui-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 7)}`;

/** session-cookie JSON call into the app API. */
async function api(page, method, path, body) {
	return page.evaluate(async ({ method: m, path: p, body: b }) => {
		const token = (typeof window.OC !== 'undefined' && window.OC.requestToken)
			|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
			|| '';
		const res = await fetch(p, {
			method: m,
			credentials: 'same-origin',
			headers: {
				requesttoken: token,
				'OCS-APIRequest': 'true',
				Accept: 'application/json',
				'Content-Type': 'application/json',
			},
			body: b === undefined ? undefined : JSON.stringify(b),
		});
		return { status: res.status, body: await res.text() };
	}, { method, path: `${APP}${path}`, body });
}

let wsId = 0;
let wsName = '';

test.describe('BudgetCheck UI invariants (atlas-ui-invariants)', () => {
	test.describe.configure({ mode: 'serial' });
	test.beforeEach(async ({ page }) => {
		test.skip(!process.env.E2E_USER, 'Set E2E_USER + E2E_PASS in e2e/.env');
		await page.setViewportSize({ width: 1280, height: 800 });
	});

	test.beforeAll(async ({ browser }) => {
		if (!process.env.E2E_USER) return;
		const page = await browser.newPage();
		try {
			await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' });
			wsName = `${MARK} ws`;
			const created = await api(page, 'POST', '/api/workspaces', { name: wsName, type: 'household' });
			wsId = JSON.parse(created.body)?.workspace?.id || 0;
		} finally {
			await page.close();
		}
	});

	test.afterAll(async ({ browser }) => {
		if (!wsId) return;
		const page = await browser.newPage();
		try {
			await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' });
			// DELETE requires the exact workspace name; a non-200 (incl. a
			// saturated workspace_delete rate bucket) must fail loudly — a
			// silent cleanup miss is how ws 1108-class residue happens (bc-m2).
			let del = { status: 0, body: '' };
			for (let attempt = 0; attempt < 3; attempt++) {
				del = await api(page, 'DELETE', `/api/workspaces/${wsId}`, { confirmName: wsName });
				if (del.status !== 429) break;
				await page.waitForTimeout(15000);
			}
			expect(
				del.status,
				`fixture workspace ${wsId} (${wsName}) delete returned ${del.status}: ${del.body.slice(0, 200)}`,
			).toBe(200);
		} finally {
			await page.close();
		}
	});

	test('workspace fixture created', async () => {
		expect(wsId, `fixture workspace create failed (name ${wsName})`).toBeGreaterThan(0);
	});

	for (const [label, path] of [
		['transactions', `/transactions?workspaceId=`],
		['settings categories', `/settings/categories?workspaceId=`],
	]) {
		test(`a11y-dom sweep /${label}`, async ({ page }) => {
			test.skip(!wsId, 'fixture workspace missing');
			await page.goto(`${APP}${path}${wsId}`, { waitUntil: 'domcontentloaded' });
			await page.waitForLoadState('networkidle').catch(() => {});
			// .bc-boolean-control inputs are checkboxes wrapped in a 44-48px
			// label card — the label (bordered box + text) is the real pointer
			// target, same allow-list pattern as snackcheck's .snk-check.
			const findings = await assertA11yDom(page, { content: CONTENT, sizeAllow: '.bc-boolean-control input' });
			expect(findings, `a11y-dom findings on /${label}:\n${findings.join('\n')}`).toEqual([]);
			const smallLabels = await page.evaluate(() => {
				const out = [];
				document.querySelectorAll('label.bc-boolean-control, label:has(> .bc-boolean-control)').forEach((el) => {
					const r = el.getBoundingClientRect();
					if (r.width === 0) return;
					if (r.width < 24 || r.height < 24) out.push(`boolean-control label ${Math.round(r.width)}x${Math.round(r.height)}`);
				});
				return out;
			});
			expect(smallLabels, `sub-24px boolean-control labels on /${label}`).toEqual([]);
		});

		test(`console errors + raw i18n keys /${label}`, async ({ page }) => {
			test.skip(!wsId, 'fixture workspace missing');
			const errs = trackConsoleErrors(page);
			await page.goto(`${APP}${path}${wsId}`, { waitUntil: 'domcontentloaded' });
			await page.waitForLoadState('networkidle').catch(() => {});
			assertNoConsoleErrors(errs, { allow: [/favicon/i] });
			await assertNoRawI18nKeys(page, { content: CONTENT });
		});
	}

	test('quick toggles: clicking a boolean-control card toggles ITS checkbox', async ({ page }) => {
		test.skip(!wsId, 'fixture workspace missing');
		await page.goto(`${APP}/transactions?workspaceId=${wsId}`, { waitUntil: 'domcontentloaded' });
		await page.waitForLoadState('networkidle').catch(() => {});
		// Filter advanced panel holds the two quick toggles behind [data-bc-tx-more-toggle].
		const uncategorized = page.locator('input[data-bc-filter="uncategorized"]');
		const isSpecial = page.locator('input[data-bc-filter="isSpecial"]');
		if (!(await uncategorized.isVisible().catch(() => false))) {
			const toggle = page.locator('[data-bc-tx-more-toggle]');
			if (await toggle.isVisible().catch(() => false)) await toggle.click();
		}
		const uncCard = page.locator('label.bc-boolean-control', { has: uncategorized });
		await expect(uncCard).toBeVisible();
		await uncCard.click();
		await expect(uncategorized, 'uncategorized card click did not toggle its checkbox').toBeChecked();
		await expect(isSpecial, 'uncategorized card click leaked a toggle onto isSpecial').not.toBeChecked();
	});

	test('n+1: /transactions issues a bounded number of app API requests', async ({ page }) => {
		test.skip(!wsId, 'fixture workspace missing');
		const hits = await countApiRequests(page, async () => {
			await page.goto(`${APP}/transactions?workspaceId=${wsId}`, { waitUntil: 'domcontentloaded' });
			await page.waitForLoadState('networkidle').catch(() => {});
		}, '/apps/budgetcheck/api/');
		expect(
			hits.length,
			`transactions fired ${hits.length} app API requests (N+1 suspect): ${hits.map((h) => `${h.method} ${h.url}`).join(' | ')}`,
		).toBeLessThanOrEqual(12);
	});

	test('stored-xss: category name renders escaped on /settings/categories', async ({ page }) => {
		test.skip(!wsId, 'fixture workspace missing');
		await page.goto(`${APP}/settings/categories?workspaceId=${wsId}`, { waitUntil: 'domcontentloaded' });
		const payload = `${MARK}${ATLAS_XSS_PAYLOADS[0]}`;
		const created = await api(page, 'POST', '/api/categories', { workspaceId: wsId, name: payload, type: 'expense' });
		test.skip(created.status !== 200 && created.status !== 201,
			`category create returned ${created.status}: ${created.body.slice(0, 160)}`);
		const catId = JSON.parse(created.body)?.category?.id;
		try {
			await page.reload({ waitUntil: 'domcontentloaded' });
			await page.waitForLoadState('networkidle').catch(() => {});
			await assertNoInjection(page);
		} finally {
			if (catId) await api(page, 'POST', `/api/categories/${catId}/deactivate`);
		}
	});

	test('mutation freshness: API-created category shows without manual reload', async ({ page }) => {
		test.skip(!wsId, 'fixture workspace missing');
		const catName = `${MARK} fresh`;
		let catId = 0;
		try {
			await page.goto(`${APP}/settings/categories?workspaceId=${wsId}`, { waitUntil: 'domcontentloaded' });
			await assertSurfaceFresh(page, {
				mutate: async () => {
					const created = await api(page, 'POST', '/api/categories', { workspaceId: wsId, name: catName, type: 'expense' });
					catId = JSON.parse(created.body)?.category?.id || 0;
					expect(catId, `category create failed: ${created.body.slice(0, 160)}`).toBeGreaterThan(0);
				},
				visit: () => page.goto(`${APP}/settings/categories?workspaceId=${wsId}`, { waitUntil: 'networkidle' }),
				expect: { present: catName },
				content: CONTENT,
			});
		} finally {
			if (catId) await api(page, 'POST', `/api/categories/${catId}/deactivate`);
		}
	});

	test('double-submit: category create fires at most one write', async ({ page }) => {
		test.skip(!wsId, 'fixture workspace missing');
		await page.goto(`${APP}/settings/categories?workspaceId=${wsId}`, { waitUntil: 'domcontentloaded' });
		await page.waitForLoadState('networkidle').catch(() => {});
		await page.locator('[data-bc-action="open-create-category"]').first().click();
		const dialog = page.locator('.bc-modal__dialog');
		await expect(dialog).toBeVisible();
		await dialog.locator('form input[type="text"], form input:not([type])').first().fill(`${MARK} dbl`);
		let createdIds = [];
		try {
			await assertNoDuplicateSubmit(page, {
				mutatingUrl: '/apps/budgetcheck/api/categories',
				method: 'POST',
				submit: () => dialog.locator('.button.primary').first().click({ force: true }),
			});
		} finally {
			const list = await api(page, 'GET', `/api/categories?workspaceId=${wsId}&includeInactive=1`);
			createdIds = (JSON.parse(list.body)?.categories || [])
				.filter((c) => String(c.name || '').startsWith(`${MARK} dbl`))
				.map((c) => c.id);
			for (const id of createdIds) {
				await api(page, 'POST', `/api/categories/${id}/deactivate`);
			}
		}
	});

	test('form survival: create-category keeps values on server 500', async ({ page }) => {
		test.skip(!wsId, 'fixture workspace missing');
		await page.goto(`${APP}/settings/categories?workspaceId=${wsId}`, { waitUntil: 'domcontentloaded' });
		await page.waitForLoadState('networkidle').catch(() => {});
		await page.locator('[data-bc-action="open-create-category"]').first().click();
		const dialog = page.locator('.bc-modal__dialog');
		await expect(dialog).toBeVisible();
		const nameInput = dialog.locator('form input[type="text"], form input:not([type])').first();
		const val = `${MARK} survives`;
		await nameInput.fill(val);
		await page.route('**/apps/budgetcheck/api/categories*', (route) => {
			if (route.request().method() === 'POST') {
				return route.fulfill({
					status: 500,
					contentType: 'application/json',
					body: JSON.stringify({ success: false, error: 'INJECTED_TEST_FAILURE' }),
				});
			}
			return route.continue();
		});
		try {
			await dialog.locator('.button.primary').first().click();
			// an error surface must appear (toast, callout or inline error)
			const errSel = '.bc-toast, .bc-callout, .bc-field-error, [role="alert"], [role="status"]';
			await expect(page.locator(errSel).first()).toBeVisible({ timeout: 8000 });
			await expect(nameInput, 'name input lost its value on server 500').toHaveValue(val);
		} finally {
			await page.unroute('**/apps/budgetcheck/api/categories*');
		}
	});
});
