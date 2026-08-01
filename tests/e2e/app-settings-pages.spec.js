// @ts-check
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const fs = require('fs');
const path = require('path');
const { login, credsFromEnv } = require('./helpers/auth.js');

/**
 * Multipage App settings: axe smoke per section, redirect, hash forward, nav active state.
 * Uses storageState / E2E_* / NC_ADMIN_* when available.
 */
const appSettingsSections = [
  'access',
  'admins',
  'defaults',
  'support',
];

const storageStatePath = path.join(__dirname, '..', '..', '.auth', 'storage-state.json');

/** @type {number|null} */
let resolvedWorkspaceId = null;

/**
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<number>}
 */
async function ensureWorkspaceId(page) {
  if (resolvedWorkspaceId != null) {
    return resolvedWorkspaceId;
  }
  const res = await page.request.get('/apps/budgetcheck/api/workspaces');
  if (res.ok()) {
    const data = await res.json();
    const list = Array.isArray(data.workspaces) ? data.workspaces : [];
    const first = list.find((w) => w && w.id != null);
    if (first) {
      resolvedWorkspaceId = Number(first.id);
      return resolvedWorkspaceId;
    }
  }
  // Fall back to a query param already present in chrome after any app page load.
  await page.goto('/apps/budgetcheck/app-settings/access', { waitUntil: 'domcontentloaded' });
  await ensureAppAdmin(page);
  const url = new URL(page.url());
  const wid = Number(url.searchParams.get('workspaceId') || '0');
  if (wid > 0) {
    resolvedWorkspaceId = wid;
    return wid;
  }
  resolvedWorkspaceId = 0;
  return 0;
}

/**
 * @param {number} wid
 * @param {string} pathSuffix
 */
function appSettingsPath(wid, pathSuffix) {
  const base = `/apps/budgetcheck/app-settings${pathSuffix}`;
  return wid > 0 ? `${base}?workspaceId=${wid}` : base;
}
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
async function ensureAppAdmin(page) {
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

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} pathUrl
 */
async function assertA11y(page, pathUrl) {
  test.skip(!hasAuthMaterial(), 'Requires storage-state or NC_ADMIN_* / E2E_* credentials');
  await page.goto(pathUrl, { waitUntil: 'domcontentloaded' });
  await ensureAppAdmin(page);
  await page.waitForSelector('#bc-main-content', { timeout: 30000 });
  // Soft-skip if redirected away from app-settings (non-admin storage state).
  if (!page.url().includes('/app-settings')) {
    test.skip(true, 'Authenticated user is not an app admin (no /app-settings access)');
  }
  await page.waitForFunction(() => {
    const body = getComputedStyle(document.body);
    return Boolean(body.getPropertyValue('--color-main-text') || body.color);
  });
  await page.locator('#bc-toasts .bc-toast').evaluateAll((nodes) => nodes.forEach((n) => n.remove())).catch(() => {});
  const results = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
    .exclude('#bc-toasts')
    .analyze();
  expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([]);
}

test.describe('BudgetCheck multipage App settings', () => {
  test.skip(!hasAuthMaterial(), 'Requires storage-state or NC_ADMIN_* / E2E_* credentials');

  for (const section of appSettingsSections) {
    test(`axe: /app-settings/${section}`, async ({ page }) => {
      const wid = await ensureWorkspaceId(page);
      await assertA11y(page, appSettingsPath(wid, `/${section}`));
    });
  }

  test('/app-settings redirects to /app-settings/access', async ({ page }) => {
    const wid = await ensureWorkspaceId(page);
    await page.goto(appSettingsPath(wid, ''), { waitUntil: 'domcontentloaded' });
    await ensureAppAdmin(page);
    if (!page.url().includes('/app-settings') && !page.url().includes('/login')) {
      test.skip(true, 'Authenticated user is not an app admin');
    }
    await page.waitForURL(/\/apps\/budgetcheck\/app-settings\/access/, { timeout: 30000 });
    if (wid > 0) {
      expect(page.url()).toContain(`workspaceId=${wid}`);
    }
    await expect(page.locator('#bc-main-content')).toBeVisible();
  });

  test('legacy hash forwards to owning section', async ({ page }) => {
    const wid = await ensureWorkspaceId(page);
    const hashUrl = appSettingsPath(wid, '/access') + '#bc-policy-admins-q';
    await page.goto(hashUrl, { waitUntil: 'domcontentloaded' });
    await ensureAppAdmin(page);
    if (!page.url().includes('/app-settings')) {
      test.skip(true, 'Authenticated user is not an app admin');
    }
    await page.waitForURL(/\/apps\/budgetcheck\/app-settings\/admins/, { timeout: 30000 });
    expect(page.url()).toContain('#bc-policy-admins-q');
    if (wid > 0) {
      expect(page.url()).toContain(`workspaceId=${wid}`);
    }
  });

  test('sidebar subnav and chip bar mark active section', async ({ page }) => {
    const wid = await ensureWorkspaceId(page);
    await page.goto(appSettingsPath(wid, '/admins'), { waitUntil: 'domcontentloaded' });
    await ensureAppAdmin(page);
    if (!page.url().includes('/app-settings')) {
      test.skip(true, 'Authenticated user is not an app admin');
    }
    await page.waitForSelector('#bc-app-settings-pages', { timeout: 30000 });

    const chips = page.locator('#bc-app-settings-pages .bc-settings-nav__link');
    await expect(chips).toHaveCount(appSettingsSections.length);
    await expect(page.locator('#bc-app-settings-pages .bc-settings-nav__link[aria-current="page"]')).toHaveCount(1);
    await expect(page.locator('#bc-app-settings-pages .bc-settings-nav__link.is-active')).toContainText(/admins|admin/i);

    const sublinks = page.locator('.bc-nav__sublink');
    await expect(sublinks).toHaveCount(appSettingsSections.length);
    await expect(page.locator('.bc-nav__sublink[aria-current="page"]')).toHaveCount(1);

    await page.locator('#bc-app-settings-pages .bc-settings-nav__link', { hasText: /support/i }).click();
    await page.waitForURL(/\/apps\/budgetcheck\/app-settings\/support/, { timeout: 30000 });
    if (wid > 0) {
      expect(page.url()).toContain(`workspaceId=${wid}`);
    }
    await expect(page.locator('#bc-app-settings-pages .bc-settings-nav__link[aria-current="page"]')).toContainText(/support/i);
  });
});
