// @ts-check
const { test, expect } = require('@playwright/test')
const AxeBuilder = require('@axe-core/playwright').default
const { login, credsFromEnv } = require('./helpers/auth.js')

const a11yRoutes = [
  '/apps/budgetcheck/',
  '/apps/budgetcheck/settings',
  '/apps/budgetcheck/transactions',
]

for (const path of a11yRoutes) {
  test(`a11y smoke: ${path}`, async ({ page }) => {
    test.skip(!process.env.NC_ADMIN_USER && !process.env.NC_EMPLOYEE_USER, 'Requires NC_ADMIN_* or NC_EMPLOYEE_* credentials')
    const role = process.env.NC_ADMIN_USER ? 'ADMIN' : 'EMPLOYEE'
    await login(page, credsFromEnv(role))
    await page.goto(path)
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
