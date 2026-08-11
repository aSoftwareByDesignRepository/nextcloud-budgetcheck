// @ts-check
const { test, expect } = require('@playwright/test')
const AxeBuilder = require('@axe-core/playwright').default
const fs = require('fs')
const path = require('path')
const { login, credsFromEnv } = require('./helpers/auth.js')

/**
 * Theme + viewport + WCAG 2.1 AA gauntlet for BudgetCheck.
 * Skips when neither storage-state nor NC_* / E2E_* credentials are available.
 */

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || process.env.NC_BASE_URL || 'http://localhost:8081').replace(/\/$/, '')

const a11yRoutes = [
	'/apps/budgetcheck/',
	'/apps/budgetcheck/settings',
	'/apps/budgetcheck/get-the-app',
	'/apps/budgetcheck/transactions',
	'/apps/budgetcheck/budgets',
	'/apps/budgetcheck/monthly',
	'/apps/budgetcheck/period',
	'/apps/budgetcheck/yearly',
	'/apps/budgetcheck/import',
	'/apps/budgetcheck/workspaces',
	'/apps/budgetcheck/app-settings',
]

const viewports = [
	{ name: 'mobile-320', width: 320, height: 720 },
	{ name: 'mobile-375', width: 375, height: 812 },
	{ name: 'tablet-768', width: 768, height: 1024 },
	{ name: 'desktop-1024', width: 1024, height: 768 },
	{ name: 'desktop-1440', width: 1440, height: 900 },
]

/** @typedef {'light'|'dark'|'dark-highcontrast'} ThemeId */

/** @type {{ id: ThemeId, theme: string, themeType?: string }[]} */
const themes = [
	{ id: 'light', theme: 'default', themeType: 'theme' },
	{ id: 'dark', theme: 'dark', themeType: 'theme' },
	{ id: 'dark-highcontrast', theme: 'dark-highcontrast', themeType: 'theme' },
]

function hasAnyCreds() {
	return !!(
		process.env.NC_ADMIN_USER ||
		process.env.NC_EMPLOYEE_USER ||
		(process.env.E2E_USER && (process.env.E2E_PASSWORD || process.env.E2E_PASS)) ||
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
		return
	}
	if (process.env.E2E_USER && (process.env.E2E_PASSWORD || process.env.E2E_PASS)) {
		await login(page, {
			username: process.env.E2E_USER,
			password: process.env.E2E_PASSWORD || process.env.E2E_PASS || '',
		})
	}
}

/**
 * Force a Nextcloud theming body class + data attribute when available.
 * Falls back to toggling body classes that NC uses for dark / high-contrast.
 * @param {import('@playwright/test').Page} page
 * @param {ThemeId} themeId
 */
async function applyTheme(page, themeId) {
	await page.evaluate((id) => {
		const body = document.body
		body.classList.remove('theme--light', 'theme--dark', 'theme--dark-highcontrast')
		body.removeAttribute('data-theme-default')
		body.removeAttribute('data-theme-dark')
		body.removeAttribute('data-theme-dark-highcontrast')
		if (id === 'light') {
			body.classList.add('theme--light')
			body.setAttribute('data-theme-default', 'true')
			body.setAttribute('data-themes', 'default')
		} else if (id === 'dark') {
			body.classList.add('theme--dark')
			body.setAttribute('data-theme-dark', 'true')
			body.setAttribute('data-themes', 'dark')
		} else {
			body.classList.add('theme--dark', 'theme--dark-highcontrast')
			body.setAttribute('data-theme-dark-highcontrast', 'true')
			body.setAttribute('data-themes', 'dark-highcontrast')
		}
		// Nudge CSS custom properties if NC exposes a theming API on window.
		try {
			document.documentElement.style.colorScheme = id === 'light' ? 'light' : 'dark'
		} catch (_) { /* ignore */ }
	}, themeId)
	await page.waitForTimeout(150)
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function assertNoHorizontalOverflow(page) {
	const overflow = await page.evaluate(() => {
		const root = document.querySelector('#app-content') || document.body
		const main = document.getElementById('bc-main-content')
		const rootOverflow = root.scrollWidth > root.clientWidth + 2
		const mainOverflow = main ? main.scrollWidth > main.clientWidth + 2 : false
		return { rootOverflow, mainOverflow, scrollWidth: root.scrollWidth, clientWidth: root.clientWidth }
	})
	expect(overflow, JSON.stringify(overflow)).toMatchObject({ rootOverflow: false, mainOverflow: false })
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function assertThemeTokensResolved(page) {
	const tokens = await page.evaluate(() => {
		const el = document.querySelector('#app-content.bc-app') || document.body
		const cs = getComputedStyle(el)
		return {
			bg: cs.getPropertyValue('--bc-bg-card').trim() || cs.getPropertyValue('--color-main-background').trim(),
			text: cs.getPropertyValue('--bc-text').trim() || cs.getPropertyValue('--color-main-text').trim(),
			primary: cs.getPropertyValue('--color-primary-element').trim(),
			muted: cs.getPropertyValue('--bc-muted').trim() || cs.getPropertyValue('--color-text-maxcontrast').trim(),
			tintInfo: cs.getPropertyValue('--bc-tint-info').trim(),
		}
	})
	expect(tokens.bg, 'theme background token').not.toEqual('')
	expect(tokens.text, 'theme text token').not.toEqual('')
	expect(tokens.primary, 'primary element token').not.toEqual('')
	expect(tokens.tintInfo, 'tint-info must resolve (mixed into main-background)').not.toEqual('')
	expect(tokens.tintInfo.includes('transparent') && tokens.tintInfo.endsWith('0%)'), 'tint must not be fully transparent').toBeFalsy()
}

test.describe('BudgetCheck theme × a11y matrix', () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!hasAnyCreds(), 'Requires NC_* / E2E_* credentials or .auth/storage-state.json')
		await ensureAuthed(page)
	})

	for (const theme of themes) {
		test(`axe WCAG 2.1 AA on dashboard @ ${theme.id}`, async ({ page }) => {
			await page.goto(`${BASE}/apps/budgetcheck/`, { waitUntil: 'domcontentloaded' })
			await page.waitForSelector('#bc-main-content', { timeout: 30_000 })
			await applyTheme(page, theme.id)
			await assertThemeTokensResolved(page)
			await page.locator('#bc-toasts .bc-toast').evaluateAll((nodes) => nodes.forEach((n) => n.remove())).catch(() => {})
			const results = await new AxeBuilder({ page })
				.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
				.exclude('#bc-toasts')
				.exclude('.bc-attach-gallery')
				.analyze()
			expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
		})
	}
})

test.describe('BudgetCheck route a11y smoke', () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!hasAnyCreds(), 'Requires NC_* / E2E_* credentials or .auth/storage-state.json')
		await ensureAuthed(page)
	})

	for (const route of a11yRoutes) {
		test(`a11y smoke: ${route}`, async ({ page }) => {
			await page.goto(`${BASE}${route}`, { waitUntil: 'domcontentloaded' })
			await page.waitForSelector('#bc-main-content, #bc-denied-main', { timeout: 30_000 })
			await page.locator('#bc-toasts .bc-toast').evaluateAll((nodes) => nodes.forEach((n) => n.remove())).catch(() => {})
			const results = await new AxeBuilder({ page })
				.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
				.exclude('#bc-toasts')
				.analyze()
			expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
		})
	}
})

test.describe('BudgetCheck responsive overflow matrix', () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!hasAnyCreds(), 'Requires NC_* / E2E_* credentials or .auth/storage-state.json')
		await ensureAuthed(page)
	})

	for (const vp of viewports) {
		test(`no horizontal overflow @ ${vp.name}`, async ({ page }) => {
			await page.setViewportSize({ width: vp.width, height: vp.height })
			await page.goto(`${BASE}/apps/budgetcheck/`, { waitUntil: 'domcontentloaded' })
			await page.waitForSelector('#bc-main-content', { timeout: 30_000 })
			await expect(page.locator('.bc-page-header').first()).toBeVisible()
			await expect(page.locator('a.bc-skip-link')).toBeAttached()
			await assertNoHorizontalOverflow(page)

			const touch = await page.evaluate(() => {
				const actions = document.querySelector('#bc-page-actions')
				if (!actions) return { ok: true }
				const cs = getComputedStyle(actions)
				const minH = parseFloat(cs.minHeight || '0')
				return { ok: minH >= 44 || actions.getBoundingClientRect().height >= 36, minH }
			})
			expect(touch.ok, JSON.stringify(touch)).toBeTruthy()
		})
	}
})

test.describe('BudgetCheck keyboard chrome', () => {
	test('skip link lands on main', async ({ page }) => {
		test.skip(!hasAnyCreds(), 'Requires NC_* / E2E_* credentials or .auth/storage-state.json')
		await ensureAuthed(page)
		await page.setViewportSize({ width: 1280, height: 800 })
		await page.goto(`${BASE}/apps/budgetcheck/`, { waitUntil: 'domcontentloaded' })
		await page.waitForSelector('#bc-main-content', { timeout: 30_000 })
		await page.locator('a.bc-skip-link').focus()
		await page.keyboard.press('Enter')
		const focused = await page.evaluate(() => document.activeElement && document.activeElement.id)
		expect(focused).toBe('bc-main-content')
	})
})
