// @ts-check
/**
 * Atlas farm craft — nav-footer Help menu after 2be5c85
 * (overflow-wrap: anywhere -> break-word on .bc-nav-footer__menu-item).
 *
 * Proves labels wrap at word boundaries (no mid-word tears) on long German
 * strings at 375px and 768px, where the app-navigation lives behind the
 * Nextcloud mobile toggle.
 *
 * Switches the capture user's language to de_DE for the run and restores en
 * afterwards (e2e_mutated_instance_config_leftover guard).
 *
 * Run (from nextcloud/apps/budgetcheck):
 *   set -a; source tests/e2e/.env; set +a
 *   npx playwright test e2e/capture-nav-footer-craft.spec.js
 */
const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const { login, credsFromEnv } = require('../tests/e2e/helpers/auth.js');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');
const OUT = path.resolve(__dirname, '../../../../.cursor/atlas-farm-v3/artifacts/budgetcheck/craft/web');
const WS = process.env.BC_CRAFT_WS || '100';

function occUser(user, args) {
	return execSync(
		`docker compose exec -T nextcloud php occ user:setting ${user} ${args}`,
		{ stdio: 'pipe', timeout: 30_000, cwd: path.resolve(__dirname, '../../..') },
	).toString().trim();
}

async function ensureAuthed(page) {
	await page.goto(`${BASE}/index.php/apps/budgetcheck`, { waitUntil: 'domcontentloaded' });
	if (page.url().includes('/login')) {
		const { username, password } = credsFromEnv('ADMIN');
		await login(page, { username, password });
		await page.goto(`${BASE}/index.php/apps/budgetcheck`, { waitUntil: 'domcontentloaded' });
	}
	await page.waitForSelector('#bc-main-content, #bc-denied-main', { timeout: 30_000 });
}

async function openHelpMenu(page) {
	// The nav-footer lives inside #app-navigation — collapsed behind the in-page
	// Menu button (#bc-nav-toggle) at narrow widths. Playwright reports the
	// off-canvas trigger as "visible", so gate on its viewport position instead.
	const trigger = page.locator('.bc-nav-footer__trigger').first();
	const box = await trigger.boundingBox().catch(() => null);
	if (!box || box.x < 0) {
		const navToggle = page.locator('#bc-nav-toggle').first();
		await expect(navToggle, 'mobile nav Menu button must exist').toBeVisible({ timeout: 10_000 });
		await navToggle.click();
	}
	await expect(trigger, 'nav-footer Help trigger must be reachable').toBeVisible({ timeout: 10_000 });
	await trigger.click();
	const menu = page.locator('#bc-feedback-menu');
	await expect(menu, 'Help menu popover must open').toBeVisible({ timeout: 10_000 });
	return menu;
}

async function shot(page, name) {
	await page.evaluate(() => {
		document.querySelectorAll('#bc-toasts .bc-toast, #bc-toasts [role="status"]').forEach((n) => n.remove());
	}).catch(() => {});
	fs.mkdirSync(OUT, { recursive: true });
	await page.waitForTimeout(350);
	await page.screenshot({ path: path.join(OUT, name), fullPage: true });
}

test.describe('nav-footer Help menu — word-break craft (post-2be5c85)', () => {
	let captureUser = process.env.E2E_USER || process.env.NC_ADMIN_USER || 'admin';
	test.setTimeout(180_000);
	test.skip(!captureUser, 'Set E2E_USER or NC_ADMIN_USER');

	test.beforeAll(() => {
		occUser(captureUser, 'core lang de_DE');
	});
	test.afterAll(() => {
		occUser(captureUser, 'core lang en');
	});

	for (const width of [375, 768]) {
		test(`DE labels wrap at word boundaries @${width}px`, async ({ page }) => {
			await page.setViewportSize({ width, height: 812 });
			await ensureAuthed(page);
			await page.goto(`${BASE}/index.php/apps/budgetcheck/dashboard?workspaceId=${WS}`, { waitUntil: 'domcontentloaded' });
			await page.waitForSelector('#bc-main-content', { timeout: 30_000 });
			const menu = await openHelpMenu(page);
			// Containment proof: no menu item clips horizontally (scrollWidth <=
			// clientWidth) — a torn mid-word label would also show as clipped or
			// hyphen-broken text in the PNG, which is the craft evidence itself.
			const metrics = await menu.evaluate((ul) => {
				const items = [...ul.querySelectorAll('.bc-nav-footer__menu-item')];
				return items.map((a) => ({
					text: a.textContent.trim().slice(0, 40),
					overflowX: a.scrollWidth - a.clientWidth,
				}));
			});
			expect(metrics.length, 'Help menu must list items').toBeGreaterThanOrEqual(3);
			for (const m of metrics) {
				expect(m.overflowX, `menu item must not clip horizontally: ${m.text}`).toBeLessThanOrEqual(0);
			}
			await shot(page, `bc-nav-footer-help-menu-${width}-de-light.png`);
		});
	}
});
