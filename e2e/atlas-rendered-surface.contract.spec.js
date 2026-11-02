// @ts-check
/**
 * ATLAS_RENDERED_SURFACE_CONTRACT — asserts the *rendered* truth of each app
 * page surface: content list markers are not reset to none by shell CSS
 * leaks, selects are vertically centred (not sunken/clipped), icons and SVGs
 * have non-zero boxes, and form controls/labels are not text-centered by an
 * inherited shell alignment. DOM-level specs pass on pages that are visually
 * broken; this is the pixel-adjacent invariant class.
 *
 * BudgetCheck routes are workspace-scoped (?workspaceId=); the seeded E2E
 * workspace id comes from BC_CRAFT_WS (default 100). Pages without a
 * workspace render the picker instead of main content — those cells skip.
 */
const { test, expect } = require('@playwright/test');
const { assertAtlasRenderedSurface } = require('../../_shared/e2e/atlas-rendered-surface-contract');
const fs = require('fs');
const path = require('path');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');
const WS = process.env.BC_CRAFT_WS || '100';
const W = `?workspaceId=${WS}`;

const SURFACES = [
	[`/apps/budgetcheck/dashboard${W}`, 'dashboard'],
	[`/apps/budgetcheck/transactions${W}`, 'transactions ledger'],
	[`/apps/budgetcheck/import${W}`, 'import'],
	[`/apps/budgetcheck/budgets${W}`, 'budgets'],
	[`/apps/budgetcheck/monthly${W}`, 'monthly'],
	[`/apps/budgetcheck/period${W}`, 'period'],
	[`/apps/budgetcheck/yearly${W}`, 'yearly'],
	['/apps/budgetcheck/workspaces', 'workspace overview'],
	[`/apps/budgetcheck/get-the-app${W}`, 'get the app'],
	[`/apps/budgetcheck/settings${W}`, 'settings hub'],
	[`/apps/budgetcheck/settings/categories${W}`, 'settings categories'],
	[`/apps/budgetcheck/settings/budget-defaults${W}`, 'settings budget defaults'],
	[`/apps/budgetcheck/settings/booking-statuses${W}`, 'settings booking statuses'],
	[`/apps/budgetcheck/settings/members${W}`, 'settings members'],
	[`/apps/budgetcheck/settings/recurring${W}`, 'settings recurring'],
	[`/apps/budgetcheck/settings/help${W}`, 'settings help'],
	[`/apps/budgetcheck/app-settings/access${W}`, 'app settings access'],
	[`/apps/budgetcheck/app-settings/defaults${W}`, 'app settings defaults'],
	[`/apps/budgetcheck/app-settings/support${W}`, 'app settings support'],
];

/**
 * Intentionally marker-less lists: nav, breadcrumbs, chip/pill rows
 * (active filters, allowed users/groups), warning cards and action menus use
 * custom row chrome (explicit `list-style: none` + icons/pills), not bullet
 * lists. Content lists must NOT appear here — they must show markers.
 */
const LIST_ALLOW = [
	'.bc-nav',
	'.bc-breadcrumb',
	'.bc-chip-list',
	'.bc-warnings',
	'.bc-badge-list',
	'.bc-tx-actions',
	'.bc-table-actions',
	'.bc-recurring-actions',
	'.bc-dash-actions-grid',
	'.bc-tx-attachments__grid',
	'.bc-get-app__features',
	'.bc-quickstart',
	'.bc-switcher',
	'.bc-modal',
].join(', ');

function resolveStorageState() {
	if (process.env.E2E_STORAGE_STATE && fs.existsSync(process.env.E2E_STORAGE_STATE)) {
		return process.env.E2E_STORAGE_STATE;
	}
	const auto = path.join(__dirname, '..', '.auth', 'storage-state.json');
	return fs.existsSync(auto) ? auto : undefined;
}

test.use({ storageState: resolveStorageState() });

test.describe('Rendered-surface contract', () => {
	for (const [routePath, name] of SURFACES) {
		test(`ATLAS_RENDERED_SURFACE_CONTRACT ${name} (${routePath})`, async ({ page }) => {
			test.skip(
				!process.env.E2E_USER && !process.env.BASE_URL && !resolveStorageState(),
				'Set BASE_URL / E2E_USER or storage state',
			);
			await page.setViewportSize({ width: 1280, height: 800 });
			await page.goto(`${BASE}${routePath}`, { waitUntil: 'domcontentloaded' });

			// No-workspace sessions render the picker — skip rather than assert
			// a surface that legitimately has no content lists.
			const picker = page.locator('#bc-empty-title');
			if (await picker.isVisible({ timeout: 2000 }).catch(() => false)) {
				test.skip(true, 'No active workspace in this E2E session');
			}
			// Access-denied surface (norole storage state) — nothing to render.
			const denied = page.locator('#bc-denied-main, .bc-denied');
			if (await denied.isVisible({ timeout: 1000 }).catch(() => false)) {
				test.skip(true, 'Workspace denied for this user');
			}
			await page.waitForSelector('#bc-main-content', { timeout: 30_000 });

			await assertAtlasRenderedSurface(page, {
				content: '#bc-main-content',
				navExclude: '#app-navigation, nav, .bc-nav, .bc-breadcrumb, .bc-modal',
				listAllow: LIST_ALLOW,
			});
		});
	}

	// The admin settings panel renders inside /settings/admin/additional —
	// outside #bc-main-content — so it gets its own leg: the dedicated scoped
	// stylesheet must be wired (addStyle) and the core dt pin
	// (width:130px; white-space:nowrap; text-align:end) must be defeated.
	test('ATLAS_RENDERED_SURFACE_CONTRACT admin settings dl defeats core dt pin', async ({ page }) => {
		test.skip(
			!process.env.E2E_USER && !process.env.BASE_URL && !resolveStorageState(),
			'Set BASE_URL / E2E_USER or storage state',
		);
		await page.goto(`${BASE}/settings/admin/additional`, { waitUntil: 'domcontentloaded' });
		const panel = page.locator('.bc-admin-settings');
		await panel.waitFor({ state: 'visible', timeout: 30_000 });

		const wired = await page.locator('link[href*="budgetcheck"][href*="admin-settings"]').count();
		expect(wired).toBeGreaterThan(0);

		const cells = await page.locator('.bc-admin-summary dt, .bc-admin-summary dd').evaluateAll((els) =>
			els.map((el) => {
				const cs = getComputedStyle(el);
				return { tag: el.tagName, ws: cs.whiteSpace, w: cs.width, client: el.clientWidth, scroll: el.scrollWidth };
			}),
		);
		expect(cells.length).toBeGreaterThan(0);
		for (const cell of cells) {
			expect(cell.ws).not.toBe('nowrap');
			expect(cell.scroll).toBeLessThanOrEqual(cell.client + 1);
		}
	});

	// section_header_controls_crush_title (workflow_step_text_clip sibling):
	// the desktop grid was `minmax(0,1fr) auto` — an unbounded `auto` second
	// track. `.bc-input{width:100%}` selects inside the stretched
	// .bc-period-picker-wrap resolved against free space, so the controls
	// column ate ~86% of the header at 1280px and crushed the title column to
	// one-word lines / mid-glyph overlap (shipped manual shots fr/07-08,
	// nl/08, pt_BR/08). The fix bounds the track with
	// fit-content(min(28rem,45%)) + an overflow-wrap residual guard.
	// Oracle: title cell keeps a readable share; no title text element paints
	// past the controls column's left edge; no clipped text.
	const HEADER_SURFACES = [
		[`/apps/budgetcheck/budgets${W}`, 'budgets'],
		[`/apps/budgetcheck/monthly${W}`, 'monthly'],
		[`/apps/budgetcheck/period${W}`, 'period'],
		[`/apps/budgetcheck/dashboard${W}`, 'dashboard'],
	];
	for (const [routePath, name] of HEADER_SURFACES) {
		for (const width of [1280, 1024]) {
			test(`section header controls never crush the title column ${name} @${width}`, async ({ page }) => {
				test.skip(
					!process.env.E2E_USER && !process.env.BASE_URL && !resolveStorageState(),
					'Set BASE_URL / E2E_USER or storage state',
				);
				await page.setViewportSize({ width, height: 900 });
				await page.goto(`${BASE}${routePath}`, { waitUntil: 'domcontentloaded' });
				if (await page.locator('#bc-empty-title').isVisible({ timeout: 2000 }).catch(() => false)) {
					test.skip(true, 'No active workspace in this E2E session');
				}
				if (await page.locator('#bc-denied-main, .bc-denied').isVisible({ timeout: 1000 }).catch(() => false)) {
					test.skip(true, 'Workspace denied for this user');
				}
				await page.waitForSelector('#bc-main-content', { timeout: 30_000 });

				const audit = await page.evaluate(() => {
					const out = [];
					document.querySelectorAll('#bc-main-content .bc-section__header').forEach((header) => {
						const hr = header.getBoundingClientRect();
						const title = header.firstElementChild;
						if (!title || hr.width < 200) return;
						const tr = title.getBoundingClientRect();
						const controls = [...header.children].find((c) => c !== title);
						const cr = controls ? controls.getBoundingClientRect() : null;
						const entry = {
							share: +(tr.width / hr.width).toFixed(3),
							title: tr.width,
							headerW: hr.width,
							hasControls: !!controls,
							bad: [],
						};
						// Title column must keep a readable share of the header —
						// a starved column is the defect's visible signature.
						if (controls && tr.width < hr.width * 0.45) {
							entry.bad.push(`title share ${(tr.width / hr.width).toFixed(2)} < 0.45`);
						}
						title.querySelectorAll('h2, .bc-section__sub, .bc-ledger-help').forEach((el) => {
							const r = el.getBoundingClientRect();
							if (!r.width) return;
							if (el.scrollWidth > el.clientWidth + 1) {
								entry.bad.push(`clip: scrollW=${el.scrollWidth} > clientW=${el.clientWidth}`);
							}
							if (cr && r.left + el.scrollWidth > cr.left + 1) {
								entry.bad.push(`paint-over: text reaches ${(r.left + el.scrollWidth).toFixed(1)} past controls.left=${cr.left.toFixed(1)}`);
							}
						});
						out.push(entry);
					});
					return out;
				});
				const mounted = audit.some((h) => h.hasControls);
				expect(mounted, `no .bc-section__header with controls rendered on ${routePath}`).toBe(true);
				const bad = audit.filter((h) => h.bad.length);
				expect(bad.map((b) => `${b.headerW}px header: ${b.bad.join('; ')}`),
					`${name} @${width}: section header title column crushed/overpainted`,
				).toEqual([]);
			});
		}
	}

	// Residual guard leg: an unbreakable long compound in the title must wrap
	// inside its cell (overflow-wrap: break-word) instead of painting over the
	// controls — the synthesized worst case of the same class.
	test('section header title long compound wraps inside its column', async ({ page }) => {
		test.skip(
			!process.env.E2E_USER && !process.env.BASE_URL && !resolveStorageState(),
			'Set BASE_URL / E2E_USER or storage state',
		);
		await page.setViewportSize({ width: 1280, height: 900 });
		await page.goto(`${BASE}/apps/budgetcheck/monthly${W}`, { waitUntil: 'domcontentloaded' });
		if (await page.locator('#bc-empty-title').isVisible({ timeout: 2000 }).catch(() => false)) {
			test.skip(true, 'No active workspace in this E2E session');
		}
		await page.waitForSelector('#bc-main-content .bc-section__header', { timeout: 30_000 });

		const res = await page.evaluate(() => {
			const header = document.querySelector('#bc-main-content .bc-section__header');
			const title = header.firstElementChild;
			const controls = [...header.children].find((c) => c !== title);
			const h2 = title.querySelector('h2');
			if (!controls || !h2) return { skip: 'no controls-bearing header' };
			const original = h2.textContent;
			h2.textContent = 'Planungszeitraumzusammenfassungsbudgetkontrolle';
			const h2r = h2.getBoundingClientRect();
			const cr = controls.getBoundingClientRect();
			const res = {
				h2Scroll: h2.scrollWidth,
				h2Client: h2.clientWidth,
				paintRight: h2r.left + h2.scrollWidth,
				controlsLeft: cr.left,
				overlaps: h2r.right > cr.left + 1,
			};
			h2.textContent = original;
			return res;
		});
		if (res.skip) test.skip(true, res.skip);
		expect(res.h2Scroll).toBeLessThanOrEqual(res.h2Client + 1);
		expect(res.paintRight).toBeLessThanOrEqual(res.controlsLeft + 1);
		expect(res.overlaps).toBe(false);
	});
});
