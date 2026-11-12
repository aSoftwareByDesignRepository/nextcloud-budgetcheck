// @ts-check
/**
 * ATLAS web craft capture — transfer settings surfaces. Writes PNGs to
 * .cursor/atlas-farm-v3/artifacts/budgetcheck/craft/web/.
 * Flow: app-settings/transfer load → uniform file error → real
 * export→preview→report dialog → apply → progress dialog → after-apply.
 * Recaptured after the pack re-sync @03a1dddb34/d8c5a2e593: the rendered
 * strings must show interpolated values (never %d/%s/{0} residue), the
 * replace token row must escape the NC-core 130px pin, the mode/file
 * controls must not clip mid-word at 320px, the receipt mode label must be
 * localized (never raw "merge"/"replace"), and the dialog footer
 * (token row + Cancel + Apply import) must paint inside the dialog client
 * box at 1280/375/320 (required_field_or_step_undiscoverable donor fix —
 * the .bc-dialog flex-column + .bc-dialog__body scroller).
 *
 * Session note: SessionCsrfMiddleware demands the session requesttoken on
 * every staff non-GET — API calls here go through the page's own
 * authenticated request context + OC.requestToken, never bare basic auth.
 *
 * Fixture: a throwaway system-admin user (replace preview is sysadmin-only),
 * provisioned via occ and deleted in afterAll — never a shared mutable uid.
 */
const { test, expect } = require('@playwright/test')
const { execFileSync, execSync } = require('child_process')
const { randomBytes } = require('crypto')
const fs = require('fs')
const path = require('path')
const { assertAtlasRenderedSurface } = require('../../_shared/e2e/atlas-rendered-surface-contract')
const { login } = require('../tests/e2e/helpers/auth.js')

const NC_ROOT = path.resolve(__dirname, '../../..')
const APP = '/index.php/apps/budgetcheck'
const PASS = 'atlas-e2e-passphrase-2026'
const OUT = path.resolve(__dirname, '../../../../.cursor/atlas-farm-v3/artifacts/budgetcheck/craft/web')
fs.mkdirSync(OUT, { recursive: true })

function occ(args, env) {
	const envArgs = Object.entries(env || {}).flatMap(([k, v]) => ['-e', `${k}=${v}`])
	return execFileSync(
		'docker', ['compose', 'exec', '-T', ...envArgs, '-u', 'www-data', 'nextcloud', 'php', 'occ', ...args],
		{ stdio: ['ignore', 'pipe', 'pipe'], timeout: 60_000, cwd: NC_ROOT },
	).toString().trim()
}

function queryDb(q) {
	const b64 = Buffer.from(q, 'utf8').toString('base64')
	return execSync(
		`docker compose exec -T mariadb bash -lc 'echo ${b64} | base64 -d | mariadb -u root -p"$MYSQL_ROOT_PASSWORD" nextcloud -N'`,
		{ stdio: ['ignore', 'pipe', 'pipe'], timeout: 60_000, cwd: NC_ROOT },
	).toString().trim()
}

function provisionFixture() {
	const uid = 'bcxfer' + randomBytes(5).toString('hex')
	const password = 'BcXfer-' + randomBytes(8).toString('hex')
	occ(['user:add', '--password-from-env', uid], { OC_PASS: password })
	// Replace preview requires a system admin — the radio is rendered
	// disabled for plain app admins.
	occ(['group:adduser', 'admin', uid])
	// Pin the fixture language: without it the user inherits whatever
	// default_language a sibling lane last mutated on the shared instance —
	// specs asserting rendered copy then flake under arbitrary locales.
	occ(['user:setting', uid, 'core', 'lang', 'en'])
	return { uid, password }
}

function deprovisionFixture(uid) {
	try { occ(['user:delete', uid]) } catch { /* already gone */ }
}

async function shot(page, name) {
	await page.screenshot({ path: path.join(OUT, `${name}.png`), fullPage: false })
}
/** Authenticated request through the page's own cookie jar + the session
 * CSRF token the app middleware demands on non-GET. */
async function authedPost(page, url, data) {
	const token = await page.evaluate(() => (window.OC && OC.requestToken) || '')
	return page.context().request.post(url, {
		data,
		headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
	})
}
async function authedGet(page, url) {
	return page.context().request.get(url, { headers: { 'OCS-APIRequest': 'true' } })
}

/** overlay_toast_button_armor_gap: the toast dismiss is a bare
 * <button class="bc-toast__close"> mounted on document.body inside
 * #bc-toasts — NC core's button repaint (width:130px + pale fill + border
 * at 0-1-1, margin-inline-start:0 at 0-2-1) must not reach it. Assert the
 * painted geometry is the declared transparent ~44px icon-close docked at
 * the toast's inline-end on one row — not the collapsed ~128px pale
 * core-skin block wrapped to a second row. */
async function assertToastCloseArmored(page) {
	const close = page.locator('.bc-toast.bc-toast--error .bc-toast__close').first()
	await expect(close).toBeVisible()
	const m = await close.evaluate((el) => {
		const r = el.getBoundingClientRect()
		const cs = getComputedStyle(el)
		const host = el.closest('.bc-toast').getBoundingClientRect()
		return {
			x: r.x, y: r.y, w: r.width, h: r.height,
			hostX: host.x, hostY: host.y, hostW: host.width, hostH: host.height,
			vpH: window.innerHeight,
			bg: cs.backgroundColor, bw: cs.borderWidth,
		}
	})
	expect(m.bg, 'dismiss keeps transparent background (no pale core skin)')
		.toBe('rgba(0, 0, 0, 0)')
	expect(m.bw, 'dismiss keeps border:0').toBe('0px')
	expect(m.w, 'dismiss ≈44px — core width:130px repaint would blow it out')
		.toBeLessThanOrEqual(56)
	expect(m.w).toBeGreaterThanOrEqual(40)
	expect(m.h).toBeGreaterThanOrEqual(40)
	expect(m.h).toBeLessThanOrEqual(64)
	// inline-end dock + single row: glyph sits in the toast's trailing half
	// and inside its height (the collapsed block wrapped to a second row)
	expect(m.x + m.w / 2).toBeGreaterThan(m.hostX + m.hostW / 2)
	expect(m.y + m.h).toBeLessThanOrEqual(m.hostY + m.hostH + 1)
	// the stack clears the bottom viewport edge (safe-area floor)
	expect(m.hostY + m.hostH).toBeLessThanOrEqual(m.vpH + 1)
}

/** Same class on the modal dismiss: <button class="bc-modal__close">
 * renders inside the body-mounted .bc-modal backdrop (outside .bc-app), so
 * the bare class rule needs the same overlay-scoped !important armor.
 * Opens a real modal through the app's own Components registry on the
 * current page and asserts the painted geometry is the transparent ~44px
 * icon-close inside the dialog's header — not the ~130px pale core block. */
async function assertModalCloseArmored(page) {
	await page.evaluate(() => {
		const C = window.BudgetCheck.get('Components')
		C.openModal({
			title: 'Armor probe',
			render: () => document.createElement('div'),
		})
	})
	const close = page.locator('.bc-modal .bc-modal__close').first()
	await expect(close).toBeVisible()
	const m = await close.evaluate((el) => {
		const r = el.getBoundingClientRect()
		const cs = getComputedStyle(el)
		const dlg = el.closest('.bc-modal__dialog').getBoundingClientRect()
		return {
			x: r.x, y: r.y, w: r.width, h: r.height,
			dlgX: dlg.x, dlgY: dlg.y, dlgW: dlg.width, dlgH: dlg.height,
			bg: cs.backgroundColor, bw: cs.borderWidth,
		}
	})
	expect(m.bg, 'modal dismiss keeps transparent background').toBe('rgba(0, 0, 0, 0)')
	expect(m.bw, 'modal dismiss keeps border:0').toBe('0px')
	expect(m.w, 'modal dismiss ≈44px — core width:130px repaint would blow it out')
		.toBeLessThanOrEqual(56)
	expect(m.w).toBeGreaterThanOrEqual(40)
	expect(m.h).toBeGreaterThanOrEqual(40)
	expect(m.h).toBeLessThanOrEqual(64)
	// inside the dialog box, docked at its trailing half (header space-between)
	expect(m.x + m.w / 2).toBeGreaterThan(m.dlgX + m.dlgW / 2)
	expect(m.x + m.w).toBeLessThanOrEqual(m.dlgX + m.dlgW + 1)
	expect(m.y).toBeGreaterThanOrEqual(m.dlgY - 1)
	expect(m.y + m.h).toBeLessThanOrEqual(m.dlgY + m.dlgH + 1)
	await page.keyboard.press('Escape')
	await expect(page.locator('.bc-modal')).toBeHidden()
}

test.describe('craft transfer', () => {
	let fixture
	test.beforeAll(() => {
		fixture = provisionFixture()
	})
	test.afterAll(() => {
		deprovisionFixture(fixture?.uid)
	})

	test('admin transfer surface + report/progress dialogs 1280/375', async ({ page }) => {
		test.setTimeout(240_000)

		await page.setViewportSize({ width: 1280, height: 800 })
		await login(page, { username: fixture.uid, password: fixture.password })
		await page.goto(`${APP}/app-settings/transfer`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#bc-transfer-export-card')).toBeVisible({ timeout: 20000 })

		const exp = await authedPost(page, `${APP}/api/admin/transfer/export`, { passphrase: PASS })
		expect(exp.status()).toBe(200)
		const bytes = await exp.body()
		expect(bytes.length).toBeGreaterThan(200)

		await page.waitForTimeout(600)
		await shot(page, 'bc-admin-transfer-light-1280')

		// uniform file error — wrong passphrase on a real container
		await page.locator('#bc-transfer-file').setInputFiles({
			name: 't.nctransfer', mimeType: 'application/json', buffer: bytes,
		})
		await page.locator('#bc-transfer-import-passphrase').fill('wrong-passphrase-000')
		// dead_platform_toast_api: the failure must reach a VISIBLE app-owned
		// toast, not only the visually-hidden live region (OC.Notification is
		// gone from NC≥31 bundles — the old bridge silently no-ops there).
		await expect(page.locator('.bc-toast--error').first()).toBeVisible({ timeout: 30000 })
		await assertToastCloseArmored(page)
		await assertModalCloseArmored(page)
		await expect(page.locator('#bc-transfer-result-live')).not.toBeEmpty()
		await shot(page, 'bc-admin-transfer-fileerror-light-1280')

		// correct passphrase → preview → report dialog (interpolated counters)
		await page.locator('#bc-transfer-import-passphrase').fill(PASS)
		const report = page.locator('#bc-transfer-report')
		await expect(report).toBeVisible({ timeout: 30000 })
		await expect(page.locator('#bc-transfer-report-verdict')).toContainText(/\d/)
		// positional t() interpolation: localized values, never raw tokens
		const fileLine = await page.locator('#bc-transfer-report-file').textContent()
		expect(fileLine || '').not.toMatch(/\{\d+\}|%\d+\$s|%d(?!\w)/)
		await shot(page, 'bc-admin-transfer-report-dialog-light-1280')

		// apply → progress dialog stays open while the job waits queued
		await page.locator('#bc-transfer-confirm').click()
		const progress = page.locator('#bc-transfer-progress')
		await expect(progress).toBeVisible({ timeout: 10000 })
		await expect(page.locator('#bc-transfer-progress-text')).not.toBeEmpty()
		await shot(page, 'bc-admin-transfer-progress-light-1280')

		// app-namespaced class id — other apps' TransferApplyJob rows never match
		const jobId = queryDb("SELECT id FROM oc_jobs WHERE class LIKE '%BudgetCheck%TransferApplyJob%' ORDER BY id DESC LIMIT 1")
		expect(jobId).toMatch(/^\d+$/)
		const priorReceiptId = ((((await (await authedGet(page, `${APP}/api/admin/transfer/status`)).json()).data || {}).lastReceipt) || {}).id || ''
		occ(['background-job:execute', jobId])

		let receipt = {}
		for (let i = 0; i < 60; i++) {
			const s = await authedGet(page, `${APP}/api/admin/transfer/status`)
			receipt = (((await s.json()).data || {}).lastReceipt) || {}
			if (receipt.id && receipt.id !== priorReceiptId
				&& (receipt.result === 'applied' || receipt.result === 'failed')) break
			await new Promise((r) => setTimeout(r, 1000))
		}
		expect(receipt.result).toBe('applied')

		// page reloads after apply; the server-rendered receipt line localizes
		// the mode (donor 03a1dddb34) — never the raw stored value.
		await page.waitForLoadState('domcontentloaded')
		await expect(page.locator('#bc-transfer-last')).toBeVisible({ timeout: 20000 })
		const lastLine = await page.locator('#bc-transfer-last').textContent()
		expect(lastLine || '').not.toMatch(/\bmerge\b|\breplace\b/)
		expect(lastLine || '').toContain('Merge')
		await page.waitForTimeout(600)
		await shot(page, 'bc-admin-transfer-after-apply-light-1280')
	})

	test('admin transfer surface mobile 375 + 320 narrow controls', async ({ page }) => {
		await page.setViewportSize({ width: 375, height: 812 })
		await login(page, { username: fixture.uid, password: fixture.password })
		await page.goto(`${APP}/app-settings/transfer`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#bc-transfer-export-card')).toBeVisible({ timeout: 20000 })
		await page.waitForTimeout(600)
		await shot(page, 'bc-admin-transfer-light-375')

		// File-input specificity (transfer-pack sync @38c8ff3): NC core pins
		// input[type=file] to width:auto (0-1-1) and the intrinsic ~320px
		// min-size overflows narrow sheets — the pack rule must land the
		// declared 100% column width (width:auto would leave the intrinsic
		// ~238–320px selector-button width, visibly short of the row).
		const fileGeo = await page.locator('#bc-transfer-file').evaluate((el) => {
			const r = el.getBoundingClientRect()
			const row = el.closest('.bc-form-row') || el.parentElement
			const rr = row.getBoundingClientRect()
			return { w: r.width, rowW: rr.width, docScrollW: document.documentElement.scrollWidth, vpW: window.innerWidth }
		})
		expect(fileGeo.w, `file input ${Math.round(fileGeo.w)}px not pinned to form-row width ${Math.round(fileGeo.rowW)}px — width:auto pin leaked`)
			.toBeGreaterThanOrEqual(fileGeo.rowW - 1)
		expect(fileGeo.docScrollW - fileGeo.vpW,
			`page horizontal overflow at 375 (scrollW ${fileGeo.docScrollW} > vpW ${fileGeo.vpW})`)
			.toBeLessThanOrEqual(1)

		// 320px clipped-narrow-text donor fix (03a1dddb34): mode radio labels
		// and the file input wrap instead of clipping mid-word.
		await page.setViewportSize({ width: 320, height: 700 })
		await page.waitForTimeout(400)
		for (const sel of [
			'#bc-transfer-import-form input[name="mode"][value="merge"]',
			'#bc-transfer-import-form input[name="mode"][value="replace"]',
			'#bc-transfer-file',
		]) {
			const clip = await page.locator(sel).evaluate((el) => {
				const host = el.closest('label') || el
				return { scrollW: host.scrollWidth, clientW: host.clientWidth }
			})
			expect(clip.scrollW - clip.clientW,
				`${sel}: ${clip.scrollW - clip.clientW}px clipped mid-word at 320px`)
				.toBeLessThanOrEqual(1)
		}
		await shot(page, 'bc-admin-transfer-light-320')

		// dead_platform_toast_api at narrow width: a failed preview must paint
		// a visible .bc-toast--error (not only the hidden live region).
		await page.setViewportSize({ width: 375, height: 812 })
		const exp = await authedPost(page, `${APP}/api/admin/transfer/export`, { passphrase: PASS })
		expect(exp.status()).toBe(200)
		const bytes = await exp.body()
		await page.locator('#bc-transfer-file').setInputFiles({
			name: 't.nctransfer', mimeType: 'application/json', buffer: bytes,
		})
		await page.locator('#bc-transfer-import-passphrase').fill('wrong-passphrase-000')
		await expect(page.locator('.bc-toast--error').first()).toBeVisible({ timeout: 30000 })
		await assertToastCloseArmored(page)
		await expect(page.locator('#bc-transfer-result-live')).not.toBeEmpty()
		await shot(page, 'bc-admin-transfer-fileerror-light-375')
	})

	test('admin transfer replace-confirm token row wide + footer painted (320/375/1280)', async ({ page }) => {
		test.setTimeout(180_000)

		await page.setViewportSize({ width: 1280, height: 800 })
		await login(page, { username: fixture.uid, password: fixture.password })
		await page.goto(`${APP}/app-settings/transfer`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#bc-transfer-export-card')).toBeVisible({ timeout: 20000 })

		const exp = await authedPost(page, `${APP}/api/admin/transfer/export`, { passphrase: PASS })
		expect(exp.status()).toBe(200)
		const bytes = await exp.body()
		expect(bytes.length).toBeGreaterThan(200)

		// Full-form pinned-control sweep (cramped_form_controls).
		await assertAtlasRenderedSurface(page, { content: '#app-content.bc-app' })

		// Replace mode → real preview → report dialog footer shows token row.
		await page.locator('#bc-transfer-import-form input[type="radio"][value="replace"]').check()
		await page.locator('#bc-transfer-file').setInputFiles({
			name: 't.nctransfer', mimeType: 'application/json', buffer: bytes,
		})
		await page.locator('#bc-transfer-import-passphrase').fill(PASS)
		const report = page.locator('#bc-transfer-report')
		await expect(report).toBeVisible({ timeout: 30000 })
		const tokenRow = page.locator('#bc-transfer-replace-confirm')
		await expect(tokenRow).toBeVisible()
		const token = page.locator('#bc-transfer-replace-token')
		await expect(token).toBeVisible()

		// typed-REPLACE gating: Apply import stays disabled until the literal
		// token is typed (donor confirm re-arm contract).
		const confirm = page.locator('#bc-transfer-confirm')
		await expect(confirm).toBeDisabled()
		await token.fill('REPLAC')
		await expect(confirm).toBeDisabled()
		await token.fill('REPLACE')
		await expect(confirm).toBeEnabled()

		// cramped_form_controls fix live: the input escapes the core 130px pin.
		const w = await token.evaluate((el) => el.getBoundingClientRect().width)
		expect(w, `token input ${Math.round(w)}px still on the NC-core 130px pin`).toBeGreaterThan(140)

		// required_field_or_step_undiscoverable: the report dialog's footer —
		// token row + Cancel + Apply import — must paint INSIDE the dialog's
		// client box at every viewport; .bc-dialog__body absorbs overflow.
		const assertDialogFooterPainted = async (where) => {
			const g = await report.evaluate((d) => {
				const cs = getComputedStyle(d)
				const r = d.getBoundingClientRect()
				const bt = parseFloat(cs.borderTopWidth) || 0
				const bl = parseFloat(cs.borderLeftWidth) || 0
				const client = { top: r.top + bt, left: r.left + bl, bottom: r.top + bt + d.clientHeight, right: r.left + bl + d.clientWidth }
				const f = d.querySelector('.bc-dialog__footer').getBoundingClientRect()
				const a = d.querySelector('#bc-transfer-confirm').getBoundingClientRect()
				return { client, dScrollH: d.scrollHeight, dClientH: d.clientHeight,
					footer: { top: f.top, bottom: f.bottom, left: f.left, right: f.right },
					apply: { top: a.top, bottom: a.bottom, left: a.left, right: a.right } }
			})
			for (const [name, box] of Object.entries({ footer: g.footer, apply: g.apply })) {
				expect(box.bottom, `${where}: ${name} bottom ${Math.round(box.bottom)} hangs below dialog client box ${Math.round(g.client.bottom)}`).toBeLessThanOrEqual(g.client.bottom + 0.5)
				expect(box.top, `${where}: ${name} top above dialog client box`).toBeGreaterThanOrEqual(g.client.top - 0.5)
				expect(box.right, `${where}: ${name} right outside dialog client box`).toBeLessThanOrEqual(g.client.right + 0.5)
				expect(box.left, `${where}: ${name} left outside dialog client box`).toBeGreaterThanOrEqual(g.client.left - 0.5)
			}
			expect(g.dScrollH - g.dClientH,
				`${where}: dialog shell scrolls (scrollHeight ${g.dScrollH} > clientHeight ${g.dClientH}) — .bc-dialog__body must absorb the overflow`)
				.toBeLessThanOrEqual(1)
		}
		await assertDialogFooterPainted('1280')

		await shot(page, 'bc-admin-transfer-replace-confirm-light-1280')
		await page.setViewportSize({ width: 375, height: 812 })
		await page.waitForTimeout(300)
		await assertDialogFooterPainted('375')
		await shot(page, 'bc-admin-transfer-replace-confirm-light-375')
		await page.setViewportSize({ width: 320, height: 700 })
		await page.waitForTimeout(300)
		await assertDialogFooterPainted('320')
		await shot(page, 'bc-admin-transfer-replace-confirm-light-320')
		// Never apply — replace is destructive; close the dialog instead.
		await page.keyboard.press('Escape')
		await expect(report).toBeHidden()
	})
})
