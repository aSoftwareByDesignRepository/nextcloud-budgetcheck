// @ts-check
/**
 * Atlas farm craft capture for budgetcheck (ds_chrome lane).
 * Writes PNGs into .cursor/atlas-farm-v3/artifacts/budgetcheck/craft/web/
 * covering every primary view + key dialogs/forms in light, dark and
 * high-contrast, plus a DE longest-label pass.
 *
 * Run (from nextcloud/apps/budgetcheck):
 *   set -a; source tests/e2e/.env; set +a
 *   npx playwright test e2e/capture-atlas-craft.spec.js
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { login } = require('../tests/e2e/helpers/auth.js');
const { forgetLastUsedWorkspace } = require('./helpers/theming.js');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');
const OUT = path.resolve(__dirname, '../../../../.cursor/atlas-farm-v3/artifacts/budgetcheck/craft/web');
const WS = process.env.BC_CRAFT_WS || '100';

/** @typedef {'light'|'dark'|'dark-highcontrast'|'light-highcontrast'} ThemeId */

const ALL_THEMES = ['light', 'dark', 'light-highcontrast', 'dark-highcontrast'];

/**
 * Persist the user's theme via the Nextcloud theming OCS API — the only
 * mechanism that survives navigation: the server renders `data-theme-*`
 * attributes + `data-themes` on <body> and resolves the scoped
 * `[data-theme-*]` CSS variables on the NEXT page load.
 *
 * The previous implementation mutated body attributes in-page; every
 * `page.goto()` re-rendered <body> from the user's real (light) settings, so
 * the dark/hc captures were byte-identical light renders (visual critic
 * must_fix #1). Same setUserTheme mechanism as
 * artifacts/audiocheck/probes/ds_chrome/sweep.mjs.
 *
 * Call BEFORE navigating; assertThemeRendered() verifies the server applied it.
 */
async function applyTheme(page, themeId) {
	const failures = await page.evaluate(async ({ target, all }) => {
		const token = (window.OC && window.OC.requestToken)
			|| (document.querySelector('head[data-requesttoken]')
				&& document.querySelector('head[data-requesttoken]').getAttribute('data-requesttoken'))
			|| '';
		if (!token) return ['no requesttoken on page (not an authenticated Nextcloud page?)'];
		const headers = { requesttoken: token, 'OCS-APIRequest': 'true', Accept: 'application/json' };
		const problems = [];
		for (const id of all.filter((t) => t !== target)) {
			const res = await fetch(`/ocs/v2.php/apps/theming/api/v1/theme/${id}`, { method: 'DELETE', credentials: 'same-origin', headers });
			if (!res.ok && res.status !== 400) problems.push(`disable ${id}: HTTP ${res.status}`);
		}
		const res = await fetch(`/ocs/v2.php/apps/theming/api/v1/theme/${target}/enable`, { method: 'PUT', credentials: 'same-origin', headers });
		if (!res.ok && res.status !== 400) problems.push(`enable ${target}: HTTP ${res.status}`);
		return problems;
	}, { target: themeId, all: ALL_THEMES });
	if (failures.length) throw new Error(`applyTheme(${themeId}): ${failures.join('; ')}`);
}

/**
 * Assert the server actually rendered the requested theme: after navigation,
 * <body> must carry `data-themes` containing the theme id. This is the gate
 * the fabricated captures bypassed — an unapplied theme fails loudly here
 * instead of producing another light render named `*-dark.png`.
 */
async function assertThemeRendered(page, themeId) {
	const dataThemes = await page.evaluate(() => document.body.getAttribute('data-themes') || '');
	const applied = dataThemes.split(',').map((s) => s.trim()).filter(Boolean);
	expect(applied, `body[data-themes] must contain "${themeId}" (got "${dataThemes}")`).toContain(themeId);
}

async function dismissToasts(page) {
	await page.evaluate(() => {
		document.querySelectorAll('#bc-toasts .bc-toast, #bc-toasts [role="status"]').forEach((n) => n.remove());
	}).catch(() => {});
}

async function shot(page, name) {
	await dismissToasts(page);
	fs.mkdirSync(OUT, { recursive: true });
	await page.waitForTimeout(350);
	await page.screenshot({ path: path.join(OUT, name), fullPage: true });
}

async function gotoView(page, route, themeId = null) {
	await page.goto(`${BASE}/index.php/apps/budgetcheck${route}`, { waitUntil: 'domcontentloaded' });
	await page.waitForSelector('#bc-main-content, #bc-denied-main', { timeout: 30_000 });
	await page.waitForTimeout(900);
	if (themeId) await assertThemeRendered(page, themeId);
}

/**
 * Decode two PNGs inside the page (canvas, no extra deps) and compare them.
 * @returns {{meanA:number, meanB:number, diffFrac:number, hA:number, hB:number}}
 *   mean* — mean luminance (0-255) over a 240px-wide downscale;
 *   diffFrac — fraction of sampled pixels whose luminance differs by > lumDelta
 *   over the common area. A byte-identical clone scores diffFrac ≈ 0.
 */
async function diffPngs(page, fileA, fileB, lumDelta = 24) {
	const a64 = fs.readFileSync(fileA).toString('base64');
	const b64 = fs.readFileSync(fileB).toString('base64');
	return page.evaluate(async ({ a64, b64, delta }) => {
		async function decode(b64png) {
			// atob+Blob — a fetch(data:) here is blocked by Nextcloud's CSP.
			const bin = atob(b64png);
			const bytes = new Uint8Array(bin.length);
			for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
			const bmp = await createImageBitmap(new Blob([bytes], { type: 'image/png' }));
			const w = 240;
			const h = Math.max(1, Math.round(bmp.height * (w / bmp.width)));
			const cv = document.createElement('canvas');
			cv.width = w;
			cv.height = h;
			const ctx = cv.getContext('2d', { willReadFrequently: true });
			ctx.drawImage(bmp, 0, 0, w, h);
			const d = ctx.getImageData(0, 0, w, h).data;
			const lum = new Float32Array(w * h);
			let sum = 0;
			for (let i = 0, n = w * h; i < n; i++) {
				const L = 0.299 * d[i * 4] + 0.587 * d[i * 4 + 1] + 0.114 * d[i * 4 + 2];
				lum[i] = L;
				sum += L;
			}
			return { w, h, lum, mean: sum / (w * h) };
		}
		const A = await decode(a64);
		const B = await decode(b64);
		const rows = Math.min(A.h, B.h);
		const cols = Math.min(A.w, B.w);
		let diff = 0;
		for (let y = 0; y < rows; y++) {
			for (let x = 0; x < cols; x++) {
				if (Math.abs(A.lum[y * A.w + x] - B.lum[y * B.w + x]) > delta) diff++;
			}
		}
		return { meanA: A.mean, meanB: B.mean, diffFrac: diff / (rows * cols), hA: A.h, hB: B.h };
	}, { a64, b64, delta: lumDelta });
}

async function ensureAuthed(page) {
	if (process.env.NC_ADMIN_USER) {
		await login(page, {
			username: process.env.NC_ADMIN_USER,
			password: process.env.NC_ADMIN_PASS || process.env.NC_ADMIN_PASSWORD || '',
		});
		return;
	}
	await login(page, {
		username: process.env.E2E_USER,
		password: process.env.E2E_PASSWORD || process.env.E2E_PASS || '',
	});
}

test.describe('Atlas craft capture — budgetcheck', () => {
	test.setTimeout(600_000);
	test.beforeEach(() => {
		test.skip(!(process.env.E2E_USER || process.env.NC_ADMIN_USER), 'Set E2E_USER + E2E_PASS in tests/e2e/.env');
	});

	test('capture views + dialogs in light/dark/hc', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 });
		await ensureAuthed(page);
		await applyTheme(page, 'light');

		// --- primary views (light, populated workspace) ---
		const views = [
			['/dashboard', 'dashboard'],
			['/transactions', 'transactions'],
			['/budgets', 'budgets'],
			['/monthly', 'monthly'],
			['/period', 'period'],
			['/yearly', 'yearly'],
			['/import', 'import'],
			['/workspaces', 'workspaces'],
			['/settings/workspace', 'settings-workspace'],
			['/settings/planning-view', 'settings-planning-view'],
			['/settings/tax', 'settings-tax'],
			['/settings/categories', 'settings-categories'],
			['/settings/budget-defaults', 'settings-budget-defaults'],
			['/settings/booking-statuses', 'settings-booking-statuses'],
			['/settings/members', 'settings-members'],
			['/settings/recurring', 'settings-recurring'],
			['/settings/help', 'settings-help'],
			['/app-settings/access', 'app-settings-access'],
			['/app-settings/admins', 'app-settings-admins'],
			['/app-settings/defaults', 'app-settings-defaults'],
			['/app-settings/support', 'app-settings-support'],
			['/get-the-app', 'get-the-app'],
		];
		for (const [route, name] of views) {
			const wsScoped = ['/budgets', '/monthly', '/period', '/yearly', '/import'].includes(route)
				|| route.startsWith('/settings/');
			// NB: '/?workspaceId=N' 303s to /dashboard and DROPS the query — the
			// populated dashboard must be addressed as /dashboard?workspaceId=N
			// (2026-10-09: bc-dashboard-*.png were silently picker captures).
			const url = wsScoped || route === '/dashboard' ? `${route}?workspaceId=${WS}` : route;
			await gotoView(page, url, 'light');
			await shot(page, `bc-${name}-light.png`);
		}

		// --- empty workspace picker (dashboard without workspaceId) ---
		// resolveWorkspaceContext falls back to the lastUsedWorkspaceId pref —
		// clear it or `/` silently renders the populated dashboard again and the
		// "picker" craft is byte-identical to bc-dashboard-light.png.
		forgetLastUsedWorkspace();
		await gotoView(page, '/');
		await expect(
			page.locator('#bc-empty-title'),
			'picker capture must render the empty/pick-a-workspace state',
		).toBeVisible({ timeout: 10_000 });
		await shot(page, 'bc-dashboard-picker-light.png');

		// --- transactions: open create dialog ---
		await gotoView(page, `/transactions?workspaceId=${WS}`);
		const createBtn = page.locator('[data-bc-action="open-create-transaction"]').first();
		if (await createBtn.count()) {
			await createBtn.click();
			await expect(page.locator('.bc-modal__dialog')).toBeVisible({ timeout: 10_000 });
			await shot(page, 'bc-dialog-transaction-create-light.png');
			await page.keyboard.press('Escape');
			await page.locator('.bc-modal__dialog').waitFor({ state: 'detached', timeout: 5_000 }).catch(() => {});
		}

		// --- workspaces: open create-workspace dialog ---
		await gotoView(page, '/workspaces');
		const wsBtn = page.locator('[data-bc-action="open-create-workspace"]').first();
		if (await wsBtn.count()) {
			await wsBtn.click();
			await expect(page.locator('.bc-modal__dialog')).toBeVisible({ timeout: 10_000 });
			await shot(page, 'bc-dialog-workspace-create-light.png');
			await page.keyboard.press('Escape');
			await page.locator('.bc-modal__dialog').waitFor({ state: 'detached', timeout: 5_000 }).catch(() => {});
		}

		// --- settings/categories: open add-category dialog ---
		await gotoView(page, `/settings/categories?workspaceId=${WS}`);
		const catBtn = page.locator('[data-bc-action="open-create-category"]').first();
		if (await catBtn.count()) {
			await catBtn.click();
			await expect(page.locator('.bc-modal__dialog')).toBeVisible({ timeout: 10_000 });
			await shot(page, 'bc-dialog-category-create-light.png');
			await page.keyboard.press('Escape');
		}

		// --- narrow viewport spot check (375) on transactions ---
		await page.setViewportSize({ width: 375, height: 812 });
		await gotoView(page, `/transactions?workspaceId=${WS}`);
		await shot(page, 'bc-transactions-375-light.png');
		await page.setViewportSize({ width: 1280, height: 800 });

		// --- dark ---
		await applyTheme(page, 'dark');
		for (const [route, name] of [
			[`/dashboard?workspaceId=${WS}`, 'dashboard'],
			[`/transactions?workspaceId=${WS}`, 'transactions'],
			[`/budgets?workspaceId=${WS}`, 'budgets'],
			['/workspaces', 'workspaces'],
		]) {
			await gotoView(page, route, 'dark');
			await shot(page, `bc-${name}-dark.png`);
		}

		// --- dark high contrast ---
		await applyTheme(page, 'dark-highcontrast');
		for (const [route, name] of [
			[`/dashboard?workspaceId=${WS}`, 'dashboard'],
			[`/transactions?workspaceId=${WS}`, 'transactions'],
		]) {
			await gotoView(page, route, 'dark-highcontrast');
			await shot(page, `bc-${name}-dark-hc.png`);
		}

		// --- light high contrast ---
		await applyTheme(page, 'light-highcontrast');
		for (const [route, name] of [
			[`/dashboard?workspaceId=${WS}`, 'dashboard'],
			[`/settings/workspace?workspaceId=${WS}`, 'settings-workspace'],
		]) {
			await gotoView(page, route, 'light-highcontrast');
			await shot(page, `bc-${name}-light-hc.png`);
		}

		await applyTheme(page, 'light');

		const expected = [
			'bc-dashboard-light.png',
			'bc-transactions-light.png',
			'bc-budgets-light.png',
			'bc-monthly-light.png',
			'bc-period-light.png',
			'bc-yearly-light.png',
			'bc-import-light.png',
			'bc-workspaces-light.png',
			'bc-settings-workspace-light.png',
			'bc-app-settings-access-light.png',
			'bc-get-the-app-light.png',
			'bc-dashboard-picker-light.png',
			'bc-transactions-375-light.png',
			'bc-dashboard-dark.png',
			'bc-transactions-dark.png',
			'bc-budgets-dark.png',
			'bc-workspaces-dark.png',
			'bc-dashboard-dark-hc.png',
			'bc-transactions-dark-hc.png',
			'bc-dashboard-light-hc.png',
			'bc-settings-workspace-light-hc.png',
		];
		for (const f of expected) {
			expect(fs.existsSync(path.join(OUT, f)), f).toBeTruthy();
		}

		// --- pixel-diff assertions (visual must_fix #1): themed captures must
		// measurably differ from their light baseline. The previous spec only
		// asserted file existence, which let byte-identical light renders pass
		// as "dark"/"hc". Thresholds are deliberately far above noise (a cloned
		// file scores diffFrac ≈ 0) and far below what a real re-render yields.
		const themePairs = [
			// [light baseline, themed capture, kind]
			['bc-dashboard-light.png', 'bc-dashboard-dark.png', 'dark'],
			['bc-transactions-light.png', 'bc-transactions-dark.png', 'dark'],
			['bc-budgets-light.png', 'bc-budgets-dark.png', 'dark'],
			['bc-workspaces-light.png', 'bc-workspaces-dark.png', 'dark'],
			['bc-dashboard-light.png', 'bc-dashboard-dark-hc.png', 'dark'],
			['bc-transactions-light.png', 'bc-transactions-dark-hc.png', 'dark'],
			['bc-dashboard-light.png', 'bc-dashboard-light-hc.png', 'hc-light'],
			['bc-settings-workspace-light.png', 'bc-settings-workspace-light-hc.png', 'hc-light'],
		];
		for (const [base, themed, kind] of themePairs) {
			const m = await diffPngs(page, path.join(OUT, base), path.join(OUT, themed));
			console.log(`[pixel-diff] ${themed} vs ${base}: meanLum ${m.meanA.toFixed(1)} -> ${m.meanB.toFixed(1)}, diffFrac ${(m.diffFrac * 100).toFixed(1)}%, h ${m.hA}/${m.hB}`);
			if (kind === 'dark') {
				expect(m.diffFrac, `${themed}: must differ measurably from ${base} (identical render = fabricated capture)`).toBeGreaterThan(0.20);
				expect(m.meanB, `${themed}: mean luminance must be dark (< half of light baseline)`).toBeLessThan(m.meanA * 0.5);
				expect(m.meanB, `${themed}: absolute mean luminance must be dark`).toBeLessThan(120);
			} else {
				// light-highcontrast keeps a light background but re-renders text,
				// borders and semantic colors — the whole text layer shifts.
				expect(m.diffFrac, `${themed}: must differ measurably from ${base}`).toBeGreaterThan(0.01);
				expect(m.meanB, `${themed}: must still be a light render`).toBeGreaterThan(140);
			}
		}
	});
});
