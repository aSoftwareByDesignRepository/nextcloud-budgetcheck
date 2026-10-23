// @ts-check
const { test, expect } = require('@playwright/test')
const { login, credsFromEnv } = require('./helpers/auth.js')

/**
 * Rendered contract for bc-web-selects-no-picker-affordance (visual r2,
 * 2026-10-23): `.bc-input` and the `.bc-modal` enforcer painted the
 * `background:` shorthand, resetting the NC core select chevron's
 * background-image/position/repeat — every <select> read as a plain text
 * field (required_field_or_step_undiscoverable).
 *
 * Proof: a real select.bc-input keeps a url() background-image, no-repeat,
 * a non-zero position and appearance:none in light AND dark.
 * (Companion of tests/js/select-chevron-contract.test.mjs, which pins the
 * static CSS side; mirrors maintenancecheck visual-fix-regressions.)
 */

const APP = '/apps/budgetcheck'
const ALL_THEMES = ['light', 'dark', 'light-highcontrast', 'dark-highcontrast']

/**
 * Persist the user's theme via the Nextcloud theming OCS API — the only
 * mechanism that survives navigation: the server renders data-theme-*
 * attributes on <body> and resolves the scoped CSS variables on the NEXT
 * page load. (Same mechanism as e2e/capture-atlas-craft.spec.js.)
 * @param {import('@playwright/test').Page} page
 * @param {string} themeId
 */
async function applyTheme(page, themeId) {
	const failures = await page.evaluate(async ({ target, all }) => {
		const token = (window.OC && window.OC.requestToken)
			|| (document.querySelector('head[data-requesttoken]')
				&& document.querySelector('head[data-requesttoken]').getAttribute('data-requesttoken'))
			|| ''
		if (!token) return ['no requesttoken on page (not an authenticated Nextcloud page?)']
		const headers = { requesttoken: token, 'OCS-APIRequest': 'true', Accept: 'application/json' }
		const problems = []
		for (const id of all.filter((t) => t !== target)) {
			const res = await fetch(`/ocs/v2.php/apps/theming/api/v1/theme/${id}`, { method: 'DELETE', credentials: 'same-origin', headers })
			if (!res.ok && res.status !== 400) problems.push(`disable ${id}: HTTP ${res.status}`)
		}
		const res = await fetch(`/ocs/v2.php/apps/theming/api/v1/theme/${target}/enable`, { method: 'PUT', credentials: 'same-origin', headers })
		if (!res.ok && res.status !== 400) problems.push(`enable ${target}: HTTP ${res.status}`)
		return problems
	}, { target: themeId, all: ALL_THEMES })
	if (failures.length) throw new Error(`applyTheme(${themeId}): ${failures.join('; ')}`)
}

test.describe('select chevron contract (bc-web-selects-no-picker-affordance)', () => {
	test('select.bc-input keeps a computed chevron in light and dark', async ({ page }) => {
		let creds
		try {
			creds = credsFromEnv('ADMIN')
		} catch {
			test.skip(true, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS env')
			return
		}
		await login(page, creds)
		await page.setViewportSize({ width: 1440, height: 900 })
		await page.goto(`${APP}/transactions`, { waitUntil: 'domcontentloaded' })

		for (const theme of ['light', 'dark']) {
			await applyTheme(page, theme)
			await page.goto(`${APP}/transactions`, { waitUntil: 'domcontentloaded' })
			const select = page.locator('#app-content select.bc-input').first()
			await expect(select).toBeVisible({ timeout: 30_000 })
			const probe = await select.evaluate((node) => {
				const cs = getComputedStyle(node)
				return {
					image: cs.backgroundImage,
					repeat: cs.backgroundRepeat,
					position: cs.backgroundPosition,
					appearance: cs.appearance,
				}
			})
			expect(probe.appearance, `${theme}: select lost appearance:none`).toBe('none')
			expect(probe.image, `${theme}: chevron background-image missing — select reads as a text field`).toContain('url(')
			expect(probe.repeat, `${theme}: chevron tiles (caret-grid regression)`).toBe('no-repeat')
			expect(probe.position, `${theme}: chevron unpositioned`).not.toBe('0% 0%')
		}
		await applyTheme(page, 'light')
	})
})
