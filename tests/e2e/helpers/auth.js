async function login(page, { username, password }) {
  await page.goto('/login', { waitUntil: 'domcontentloaded' })
  // Already authenticated (e.g. Playwright storageState from global-setup).
  if (!page.url().includes('/login')) {
    return
  }
  const userInput = page.locator('input#user, input[name="user"]').first()
  const passInput = page.locator('input#password, input[name="password"]').first()
  try {
    await userInput.waitFor({ state: 'visible', timeout: 30_000 })
  } catch {
    if (!page.url().includes('/login')) {
      return
    }
    throw new Error('Login form not ready (Vue #login)')
  }
  await userInput.fill(username)
  await passInput.fill(password)
  await page.locator('button[type="submit"], input[type="submit"]').first().click()
  await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30000 })
  await page.waitForLoadState('domcontentloaded')
}

function credsFromEnv(role) {
  const u = process.env[`NC_${role}_USER`]
  const p = process.env[`NC_${role}_PASS`] || process.env[`NC_${role}_PASSWORD`]
  if (!u || !p) throw new Error(`Missing env vars NC_${role}_USER / NC_${role}_PASS`)
  return { username: u, password: p }
}

module.exports = { login, credsFromEnv }
