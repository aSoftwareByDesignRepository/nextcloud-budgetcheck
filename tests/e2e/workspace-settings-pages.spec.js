// @ts-check
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const fs = require('fs');
const path = require('path');
const { login, credsFromEnv } = require('./helpers/auth.js');

/**
 * Multipage Workspace settings: axe smoke per visible section, redirect, hash
 * forward, nav active state. Sections asserted depend on the resolved workspace
 * type (household vs project) read from data-bc-workspace.
 */
const ALL_SECTIONS = [
  'planning-view',
  'workspace',
  'tax',
  'categories',
  'budget-defaults',
  'booking-statuses',
  'members',
  'recurring',
  'help',
];

const storageStatePath = path.join(__dirname, '..', '..', '.auth', 'storage-state.json');

/** @type {{ id: number, type: string, role: string }|null} */
let resolvedWorkspace = null;

/**
 * @param {string} type
 * @param {boolean} canManage
 * @returns {string[]}
 */
function visibleSections(type, canManage) {
  return ALL_SECTIONS.filter((section) => {
    if (section === 'planning-view') return type === 'household';
    if (section === 'budget-defaults') return type === 'household' && canManage;
    if (section === 'booking-statuses') return type === 'project';
    if (section === 'members' || section === 'recurring') return canManage;
    return true;
  });
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {{ preferType?: string }} [opts]
 */
async function ensureWorkspace(page, opts = {}) {
  const preferType = opts.preferType || '';
  if (resolvedWorkspace != null && (!preferType || resolvedWorkspace.type === preferType)) {
    return resolvedWorkspace;
  }
  const res = await page.request.get('/apps/budgetcheck/api/workspaces');
  if (res.ok()) {
    const data = await res.json();
    const list = Array.isArray(data.workspaces) ? data.workspaces : [];
    const preferred = preferType
      ? list.find((w) => w && w.id != null && String(w.type || '') === preferType)
      : null;
    const first = preferred || list.find((w) => w && w.id != null);
    if (first) {
      const picked = {
        id: Number(first.id),
        type: String(first.type || 'household'),
        role: String(first.role || 'manager'),
      };
      if (!preferType) {
        resolvedWorkspace = picked;
      }
      return picked;
    }
  }
  await page.goto('/apps/budgetcheck/settings', { waitUntil: 'domcontentloaded' });
  await ensureAuth(page);
  const wsAttr = await page.locator('#app-content').getAttribute('data-bc-workspace');
  if (wsAttr) {
    try {
      const ws = JSON.parse(wsAttr);
      if (ws && ws.id) {
        const picked = {
          id: Number(ws.id),
          type: String(ws.type || 'household'),
          role: String(ws.role || 'manager'),
        };
        if (!preferType) {
          resolvedWorkspace = picked;
        }
        return picked;
      }
    } catch (_err) {
      /* fall through */
    }
  }
  const empty = { id: 0, type: 'household', role: 'manager' };
  if (!preferType) {
    resolvedWorkspace = empty;
  }
  return empty;
}

/**
 * @param {number} wid
 * @param {string} pathSuffix
 */
function settingsPath(wid, pathSuffix) {
  const base = `/apps/budgetcheck/settings${pathSuffix}`;
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

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} pathUrl
 */
async function assertA11y(page, pathUrl) {
  test.skip(!hasAuthMaterial(), 'Requires storage-state or NC_ADMIN_* / E2E_* credentials');
  await page.goto(pathUrl, { waitUntil: 'domcontentloaded' });
  await ensureAuth(page);
  await page.waitForSelector('#bc-main-content', { timeout: 30000 });
  if (!page.url().includes('/settings')) {
    test.skip(true, 'Authenticated user could not open workspace settings');
  }
  await page.waitForFunction(() => {
    const body = getComputedStyle(document.body);
    return Boolean(body.getPropertyValue('--color-main-text') || body.color);
  });
  await page.locator('#bc-toasts .bc-toast').evaluateAll((nodes) => nodes.forEach((n) => n.remove())).catch(() => {});
  // Scope axe to the app main column — NC chrome (#header) can make full-page
  // analysis hang under dark themes without changing our settings surface.
  const results = await new AxeBuilder({ page })
    .include('#bc-main-content')
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
    .exclude('#bc-toasts')
    .analyze();
  expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([]);
}

test.describe('BudgetCheck multipage Workspace settings', () => {
  test.skip(!hasAuthMaterial(), 'Requires storage-state or NC_ADMIN_* / E2E_* credentials');

  for (const section of ALL_SECTIONS) {
    test(`axe: /settings/${section} when visible`, async ({ page }) => {
      const preferType = section === 'booking-statuses'
        ? 'project'
        : (section === 'planning-view' || section === 'budget-defaults' ? 'household' : '');
      const ws = await ensureWorkspace(page, preferType ? { preferType } : {});
      test.skip(ws.id <= 0, 'No workspace available for settings E2E');
      const canManage = ws.role === 'manager';
      const visible = visibleSections(ws.type, canManage);
      test.skip(!visible.includes(section), `Section ${section} not visible for ${ws.type}/${ws.role}`);
      await assertA11y(page, settingsPath(ws.id, `/${section}`));
      const attr = await page.locator('#app-content').getAttribute('data-bc-settings-section');
      expect(attr).toBe(section);
    });
  }

  test('/settings redirects to type-aware default section', async ({ page }) => {
    const ws = await ensureWorkspace(page);
    test.skip(ws.id <= 0, 'No workspace available for settings E2E');
    const expected = ws.type === 'household' ? 'planning-view' : 'workspace';
    await page.goto(settingsPath(ws.id, ''), { waitUntil: 'domcontentloaded' });
    await ensureAuth(page);
    await page.waitForURL(new RegExp(`/apps/budgetcheck/settings/${expected}`), { timeout: 30000 });
    expect(page.url()).toContain(`workspaceId=${ws.id}`);
    await expect(page.locator('#bc-main-content')).toBeVisible();
  });

  test('legacy hash forwards to categories', async ({ page }) => {
    const ws = await ensureWorkspace(page);
    test.skip(ws.id <= 0, 'No workspace available for settings E2E');
    const defaultSection = ws.type === 'household' ? 'planning-view' : 'workspace';
    const hashUrl = settingsPath(ws.id, `/${defaultSection}`) + '#bc-categories-title';
    await page.goto(hashUrl, { waitUntil: 'domcontentloaded' });
    await ensureAuth(page);
    await page.waitForURL(/\/apps\/budgetcheck\/settings\/categories/, { timeout: 30000 });
    expect(page.url()).toContain('#bc-categories-title');
    expect(page.url()).toContain(`workspaceId=${ws.id}`);
  });

  test('sidebar subnav and chip bar mark active section', async ({ page }) => {
    const ws = await ensureWorkspace(page);
    test.skip(ws.id <= 0, 'No workspace available for settings E2E');
    const canManage = ws.role === 'manager';
    const sections = visibleSections(ws.type, canManage);
    await page.goto(settingsPath(ws.id, '/categories'), { waitUntil: 'domcontentloaded' });
    await ensureAuth(page);
    await page.waitForSelector('#bc-settings-pages', { timeout: 30000 });

    const chips = page.locator('#bc-settings-pages .bc-settings-nav__link');
    await expect(chips).toHaveCount(sections.length);
    await expect(page.locator('#bc-settings-pages .bc-settings-nav__link[aria-current="page"]')).toHaveCount(1);
    await expect(page.locator('#bc-settings-pages .bc-settings-nav__link.is-active')).toContainText(/categories/i);

    const sublinks = page.locator('.bc-nav__item.is-active .bc-nav__sublink');
    await expect(sublinks).toHaveCount(sections.length);
    await expect(page.locator('.bc-nav__sublink[aria-current="page"]')).toHaveCount(1);

    await page.locator('#bc-settings-pages .bc-settings-nav__link', { hasText: /help/i }).click();
    await page.waitForURL(/\/apps\/budgetcheck\/settings\/help/, { timeout: 30000 });
    expect(page.url()).toContain(`workspaceId=${ws.id}`);
    await expect(page.locator('#bc-settings-pages .bc-settings-nav__link[aria-current="page"]')).toContainText(/help/i);
  });
});
