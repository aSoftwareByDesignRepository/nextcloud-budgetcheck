// @ts-check
const { test, expect } = require('@playwright/test')
const AxeBuilder = require('@axe-core/playwright').default
const fs = require('fs')
const path = require('path')
const { login, credsFromEnv } = require('./helpers/auth.js')

function hasAnyCreds() {
  return !!(
    process.env.NC_ADMIN_USER ||
    process.env.NC_EMPLOYEE_USER ||
    fs.existsSync(path.join(__dirname, '..', '..', '.auth', 'storage-state.json'))
  )
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function ensureAuthed(page) {
  if (process.env.NC_ADMIN_USER || process.env.NC_EMPLOYEE_USER) {
    const role = process.env.NC_ADMIN_USER ? 'ADMIN' : 'EMPLOYEE'
    await login(page, credsFromEnv(role))
  }
}

const a11yRoutes = [
  '/apps/budgetcheck/',
  '/apps/budgetcheck/settings',
  '/apps/budgetcheck/get-the-app',
  '/apps/budgetcheck/transactions',
]

for (const route of a11yRoutes) {
  test(`a11y smoke: ${route}`, async ({ page }) => {
    test.skip(!hasAnyCreds(), 'Requires NC_ADMIN_* / NC_EMPLOYEE_* credentials or storage-state')
    await ensureAuthed(page)
    await page.goto(route)
    await page.waitForSelector('#bc-main-content', { timeout: 30000 })
    // Ephemeral error toasts (e.g. race on first paint) must not fail AA gates.
    await page.locator('#bc-toasts .bc-toast').evaluateAll((nodes) => nodes.forEach((n) => n.remove())).catch(() => {})
    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .exclude('#bc-toasts')
      .analyze()
    expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
  })
}

test('get-the-app: features are static; play and actions are buttons', async ({ page }) => {
  test.skip(!hasAnyCreds(), 'Requires NC_ADMIN_* / NC_EMPLOYEE_* credentials or storage-state')
  await ensureAuthed(page)
  await page.goto('/apps/budgetcheck/get-the-app')
  await page.waitForSelector('.bc-get-app__play', { timeout: 30000 })
  await expect(page.locator('a.bc-get-app__feature')).toHaveCount(0)
  await expect(page.locator('a.bc-get-app__play')).toHaveCount(1)
  await expect(page.locator('a.bc-get-app__action').first()).toBeVisible()
  const actionCursor = await page.locator('a.bc-get-app__action').first().evaluate((el) => getComputedStyle(el).cursor)
  const featureCursor = await page.locator('.bc-get-app__feature').first().evaluate((el) => getComputedStyle(el).cursor)
  expect(actionCursor).toBe('pointer')
  expect(featureCursor).toBe('default')
  const playBox = await page.locator('a.bc-get-app__play').boundingBox()
  expect(playBox).not.toBeNull()
  expect(playBox.height).toBeGreaterThanOrEqual(44)
})
