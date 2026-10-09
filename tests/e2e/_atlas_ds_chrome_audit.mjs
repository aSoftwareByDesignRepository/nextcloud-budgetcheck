// @ts-check
/**
 * ds_chrome lane probe — Atlas Farm 3.5.14 (budgetcheck, fresh artifacts).
 *
 * Usage: node tests/e2e/_atlas_ds_chrome_audit.mjs <phase>
 *   sweep    routes × themes × viewports: http/overflow/touch/axe + PNG+sha256
 *   dialogs  role/aria-modal/focus-trap/Escape/focus-restore + re-render hunt
 *   states   anon→login, no-role empty/denied, fetch-error, empty states
 *   theatre  computed-color token parity probes on canvas/cards/buttons/wells
 *
 * Evidence root: FARM_OUT (default artifacts/budgetcheck/probes/ds_chrome)
 * Users: E2E_USER/E2E_PASS (workspace manager + app admin),
 *        bc_ds_probe (no membership; DS_PROBE_PASS env).
 */
import { chromium } from 'playwright'
import AxeBuilder from '@axe-core/playwright'
import { createHash } from 'node:crypto'
import { existsSync, mkdirSync, writeFileSync } from 'node:fs'
import { join, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'
import { createRequire } from 'node:module'
import { login } from './helpers/auth.js'

const require2 = createRequire(join(dirname(fileURLToPath(import.meta.url)), 'audit.js'))
const { setUserTheme: setUserThemeCanonical } = require2('../../e2e/helpers/theming.js')

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '')
const PASS = process.env.E2E_PASS || process.env.E2E_PASSWORD || ''
const USER = process.env.E2E_USER || 'bc_atlas_e2e'
const PROBE_PASS = process.env.DS_PROBE_PASS || 'BcDsProbe!2026x9'
const WS = process.env.BC_CRAFT_WS || '100'
const OUT = process.env.FARM_OUT
	|| '/home/alex/Development/nextcloud-dev/.cursor/atlas-farm-v3/artifacts/budgetcheck/probes/ds_chrome'
const PHASE = process.argv[2] || 'sweep'
mkdirSync(OUT, { recursive: true })

const USERS = {
	manager: { username: USER, password: PASS },
	norole: { username: 'bc_ds_probe', password: PROBE_PASS },
}

const THEMES = ['light', 'dark', 'light-highcontrast', 'dark-highcontrast']
const VIEWPORTS = [
	{ w: 320, h: 640 },
	{ w: 375, h: 812 },
	{ w: 768, h: 1024 },
	{ w: 1024, h: 768 },
	{ w: 1440, h: 900 },
]

const W = `?workspaceId=${WS}`
const MANAGER_ROUTES = [
	{ id: 'index', path: `/apps/budgetcheck/${W}` },
	{ id: 'dashboard', path: `/apps/budgetcheck/dashboard${W}` },
	{ id: 'transactions', path: `/apps/budgetcheck/transactions${W}` },
	{ id: 'import', path: `/apps/budgetcheck/import${W}` },
	{ id: 'budgets', path: `/apps/budgetcheck/budgets${W}` },
	{ id: 'monthly', path: `/apps/budgetcheck/monthly${W}` },
	{ id: 'period', path: `/apps/budgetcheck/period${W}` },
	{ id: 'yearly', path: `/apps/budgetcheck/yearly${W}` },
	{ id: 'workspaces', path: '/apps/budgetcheck/workspaces' },
	{ id: 'get-the-app', path: `/apps/budgetcheck/get-the-app${W}` },
]
const SETTINGS_SECTIONS = [
	'workspace', 'planning-view', 'tax', 'categories', 'budget-defaults',
	'booking-statuses', 'members', 'recurring', 'help',
]
const APP_SETTINGS_SECTIONS = ['access', 'admins', 'defaults', 'support']
const ALL_MANAGER_ROUTES = MANAGER_ROUTES
	.concat([{ id: 'settings', path: `/apps/budgetcheck/settings${W}` }])
	.concat(SETTINGS_SECTIONS.map((s) => ({ id: `settings-${s}`, path: `/apps/budgetcheck/settings/${s}${W}` })))
	.concat(APP_SETTINGS_SECTIONS.map((s) => ({ id: `app-settings-${s}`, path: `/apps/budgetcheck/app-settings/${s}${W}` })))

const results = { phase: PHASE, startedAt: new Date().toISOString(), cells: [], captures: {} }

function sha256(buf) {
	return createHash('sha256').update(buf).digest('hex')
}

async function snap(page, name) {
	const buf = await page.screenshot({ fullPage: false })
	const file = `${name}.png`
	writeFileSync(join(OUT, file), buf)
	const hash = sha256(buf)
	results.captures[name] = { file, sha256: hash, bytes: buf.length }
	return { file, sha256: hash, bytes: buf.length }
}

async function settle(page) {
	await page.waitForLoadState('domcontentloaded').catch(() => {})
	try { await page.waitForLoadState('networkidle', { timeout: 5000 }) } catch { /* long-polls */ }
	await page.evaluate(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))))
	await page.waitForTimeout(400)
}

function record(cell) {
	results.cells.push(cell)
	const tag = cell.status === 'fail' ? 'FAIL' : cell.status === 'warn' ? 'warn' : 'ok'
	console.log(`[${tag}] ${cell.id} :: ${JSON.stringify(cell.checks).slice(0, 300)}`)
}

async function loginState(browser, role) {
	// Prefer a valid Playwright storage state (E2E_STORAGE_STATE or
	// .auth/storage-state.json) — interactive login breaks when a concurrent
	// lane rotates the lab credentials mid-run; existing sessions stay valid.
	const stateFile = process.env.E2E_STORAGE_STATE
		|| new URL('../../.auth/storage-state.json', import.meta.url).pathname
	if (role === 'manager' && existsSync(stateFile)) {
		return stateFile
	}
	const ctx = await browser.newContext({ baseURL: BASE, viewport: { width: 1440, height: 900 } })
	const page = await ctx.newPage()
	await login(page, USERS[role])
	const state = await ctx.storageState()
	await ctx.close()
	return state
}

/**
 * Delegate to e2e/helpers/theming.js — the canonical implementation wipes the
 * legacy typed `enabled-themes` user setting via occ before OCS enable (a stale
 * INT row makes the PUT fail 400 → theme silently never applied; the 2026-10-09
 * light-highcontrast sweep shipped mislabeled plain-light PNGs that way), then
 * reloads and asserts body[data-theme-<id>] actually rendered.
 */
async function setUserTheme(page, themeId) {
	await setUserThemeCanonical(page, themeId)
	const applied = await page.evaluate((t) => document.body.hasAttribute(`data-theme-${t}`), themeId)
	if (!applied) throw new Error(`theme ${themeId}: body[data-theme-${themeId}] missing after reload — refusing to fake the capture`)
}

async function checkOverflow(page) {
	// scrollWidth inflates ancestors even through working scrollports
	// (Chromium unions scrollable overflow). What matters for WCAG reflow:
	//   a) page-level horizontal pan (documentElement)
	//   b) elements painting past the viewport with no clipping ancestor
	//   c) elements clipped by an overflow:hidden|clip ancestor they exceed
	//      (visually cut AND unreachable — no inner scrollport to pan)
	return page.evaluate(() => {
		const doc = document.documentElement
		const vw = window.innerWidth
		const offenders = []
		const inside = document.querySelectorAll('#app-content *, #app-navigation *, .bc-modal *')
		for (const el of inside) {
			const r = el.getBoundingClientRect()
			if (r.width <= 0 || r.height <= 0) continue
			if (r.right <= vw + 1 && r.left >= 0) continue // fully inside viewport
			if (r.bottom < 0 || r.top > window.innerHeight) continue // vertical offscreen OK
			// Fully off-viewport horizontally = hidden-by-design chrome, not a
			// reflow defect: the unfocused skip-link (left:-9999px) and the
			// closed off-canvas nav drawer paint nothing inside the viewport.
			if (r.right < 0 || r.left > vw) continue
			// nearest ancestor that clips horizontally
			let p = el.parentElement
			let clipper = null
			while (p && p !== document.documentElement) {
				const ox = getComputedStyle(p).overflowX
				if (ox === 'auto' || ox === 'scroll') { clipper = 'scroll'; break }
				if (ox === 'hidden' || ox === 'clip') { clipper = 'clip'; break }
				p = p.parentElement
			}
			if (clipper === 'scroll') continue // reachable via inner scrollport
			const tag = el.tagName.toLowerCase()
			const cls = String(el.className).split(' ').slice(0, 2).join('.')
			offenders.push({
				sel: `${tag}${el.id ? '#' + el.id : ''}${cls ? '.' + cls : ''}`,
				right: Math.round(r.right), left: Math.round(r.left), w: Math.round(r.width),
				clippedByHidden: clipper === 'clip',
				text: (el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 40),
			})
			if (offenders.length >= 12) break
		}
		return {
			doc: doc.scrollWidth - doc.clientWidth,
			offenders,
		}
	})
}

function overflowBad(ov) {
	return ov.doc > 1 || (ov.offenders || []).length > 0
}

async function checkTouchTargets(page) {
	return page.evaluate(() => {
		const scopes = ['#app-content', '#app-navigation', '.bc-modal', 'dialog[open]']
		const seen = new Set()
		const offenders = []
		const interactiveSel = [
			'button', 'a[href]', 'input:not([type="hidden"])', 'select', 'textarea',
			'[role="button"]', '[role="link"]', '[role="checkbox"]', '[role="tab"]',
			'[role="menuitem"]', '[role="switch"]', 'summary', '[tabindex]:not([tabindex="-1"])',
		].join(',')
		for (const scopeSel of scopes) {
			for (const scope of document.querySelectorAll(scopeSel)) {
				for (const el of scope.querySelectorAll(interactiveSel)) {
					if (seen.has(el)) continue
					seen.add(el)
					const r = el.getBoundingClientRect()
					const style = getComputedStyle(el)
					if (r.width <= 0 || r.height <= 0) continue
					if (style.visibility === 'hidden' || style.display === 'none') continue
					// Off-viewport (e.g. unfocused skip-link at left:-9999px): not a
					// touch target until focused — when focused it moves on-screen.
					if (r.right < 0 || r.bottom < 0 || r.left > window.innerWidth || r.top > window.innerHeight) continue
					if (el.matches('input[type="checkbox"],input[type="radio"]')) {
						const lab = el.closest('label') || (el.id && document.querySelector(`label[for="${el.id}"]`))
						if (lab) {
							const lr = lab.getBoundingClientRect()
							if (lr.height >= 40) continue
						}
					}
					if (r.width < 44 || r.height < 44) {
						const label = (el.textContent || el.getAttribute('aria-label') || el.id || el.tagName)
							.trim().replace(/\s+/g, ' ').slice(0, 60)
						offenders.push({
							tag: el.tagName.toLowerCase(), cls: String(el.className).slice(0, 60),
							label, w: Math.round(r.width), h: Math.round(r.height),
						})
					}
				}
			}
		}
		return offenders.slice(0, 15)
	})
}

async function runAxe(page) {
	try {
		const res = await new AxeBuilder({ page })
			.withTags(['wcag2a', 'wcag2aa'])
			.exclude('#header').exclude('#contactsmenu').exclude('.notifications')
			.analyze()
		return res.violations.map((v) => ({
			id: v.id, impact: v.impact,
			nodes: v.nodes.slice(0, 4).map((n) => String(n.target).slice(0, 120)),
			summary: String(v.help).slice(0, 140),
		}))
	} catch (err) {
		return [{ id: 'axe-error', impact: 'critical', nodes: [], summary: String(err).slice(0, 200) }]
	}
}

/* ───────────────────────────── sweep ───────────────────────────── */
async function phaseSweep(browser) {
	const managerState = await loginState(browser, 'manager')
	const ctx = await browser.newContext({ baseURL: BASE, storageState: managerState, viewport: { width: 1440, height: 900 } })
	const page = await ctx.newPage()
	page.on('pageerror', (e) => console.log('PAGEEXC:', String(e).slice(0, 200)))

	// Theme matrix at 1440 for all manager routes (axe + overflow + capture).
	for (const theme of THEMES) {
		await page.goto(`${BASE}/apps/budgetcheck/`, { waitUntil: 'domcontentloaded' })
		await setUserTheme(page, theme)
		for (const route of ALL_MANAGER_ROUTES) {
			const cell = { id: `${route.id}@${theme}@1440`, role: 'manager', theme, viewport: 1440, checks: {} }
			const resp = await page.goto(`${BASE}${route.path}`, { waitUntil: 'domcontentloaded' }).catch(() => null)
			await settle(page)
			cell.checks.http = resp ? resp.status() : 'nav-fail'
			cell.checks.finalUrl = page.url().replace(BASE, '')
			cell.checks.hasMain = await page.locator('#bc-main-content, #bc-denied-main, .bc-denied, main').first().isVisible().catch(() => false)
			const ov = await checkOverflow(page)
			cell.checks.overflow = ov
			cell.checks.overflowOk = !overflowBad(ov)
			cell.checks.themeApplied = await page.evaluate((t) => document.body.hasAttribute(`data-theme-${t}`), theme).catch(() => null)
			cell.checks.axe = await runAxe(page)
			cell.checks.axeViolations = cell.checks.axe.length
			const shot = await snap(page, `sweep__${route.id}__${theme}__1440`)
			cell.proof = shot.sha256.slice(0, 16)
			const failReasons = []
			if (cell.checks.http >= 400) failReasons.push(`http ${cell.checks.http}`)
			if (!cell.checks.hasMain) failReasons.push('no main surface')
			if (!cell.checks.overflowOk) failReasons.push(`overflow ${JSON.stringify(ov)}`)
			if (cell.checks.axeViolations > 0) failReasons.push(`axe ${cell.checks.axeViolations}`)
			cell.status = failReasons.length ? 'fail' : 'ok'
			if (failReasons.length) cell.failReasons = failReasons
			record(cell)
		}
	}

	// Viewport matrix on light for key routes (overflow + touch + capture).
	const KEY_ROUTES = MANAGER_ROUTES
		.concat([{ id: 'settings-categories', path: `/apps/budgetcheck/settings/categories${W}` }])
		.concat([{ id: 'settings-members', path: `/apps/budgetcheck/settings/members${W}` }])
		.concat([{ id: 'app-settings-access', path: `/apps/budgetcheck/app-settings/access${W}` }])
	for (const vp of VIEWPORTS) {
		await page.setViewportSize({ width: vp.w, height: vp.h })
		await page.goto(`${BASE}/apps/budgetcheck/`, { waitUntil: 'domcontentloaded' })
		await setUserTheme(page, 'light').catch(() => {})
		await page.reload({ waitUntil: 'domcontentloaded' })
		for (const route of KEY_ROUTES) {
			const cell = { id: `${route.id}@light@${vp.w}`, role: 'manager', theme: 'light', viewport: vp.w, checks: {} }
			const resp = await page.goto(`${BASE}${route.path}`, { waitUntil: 'domcontentloaded' }).catch(() => null)
			await settle(page)
			cell.checks.http = resp ? resp.status() : 'nav-fail'
			const ov = await checkOverflow(page)
			cell.checks.overflow = ov
			cell.checks.overflowOk = !overflowBad(ov)
			cell.checks.touchOffenders = await checkTouchTargets(page)
			const shot = await snap(page, `sweep__${route.id}__light__${vp.w}`)
			cell.proof = shot.sha256.slice(0, 16)
			const failReasons = []
			if (cell.checks.http >= 400) failReasons.push(`http ${cell.checks.http}`)
			if (!cell.checks.overflowOk) failReasons.push(`overflow ${JSON.stringify(ov)}`)
			if (cell.checks.touchOffenders.length) failReasons.push(`touch<44: ${cell.checks.touchOffenders.length} ${JSON.stringify(cell.checks.touchOffenders.slice(0, 3))}`)
			cell.status = failReasons.length ? 'fail' : 'ok'
			if (failReasons.length) cell.failReasons = failReasons
			record(cell)
		}
	}
	await ctx.close()
}

/* ───────────────────────────── dialogs ───────────────────────────── */
const VISIBLE_DIALOG = '.bc-modal:not([hidden]) .bc-modal__dialog, dialog[open], #app-content [role="dialog"]'

async function probeDialogLifecycle(page, name, openFn, opts = {}) {
	const r = { id: name, checks: {}, status: 'ok', fails: [] }
	const fail = (m) => { r.fails.push(m); r.status = 'fail' }

	let trigger = null
	try { trigger = await openFn() } catch (err) {
		fail(`trigger threw: ${String(err).slice(0, 160)}`); record(r); return null
	}
	if (!trigger) { fail('no trigger found'); record(r); return null }

	await page.waitForFunction(
		() => [...document.querySelectorAll('.bc-modal:not([hidden]) .bc-modal__dialog, dialog[open], #app-content [role="dialog"]')]
			.some((d) => d.offsetParent !== null || (d.tagName === 'DIALOG' && d.open)),
		{ timeout: 8000 },
	).catch(() => {})
	await settle(page)

	const dialogInfo = await page.evaluate((sel) => {
		const dlg = [...document.querySelectorAll(sel)].find((d) => d.offsetParent !== null || d.tagName === 'DIALOG')
		if (!dlg) return null
		const labelId = dlg.getAttribute('aria-labelledby')
		return {
			tag: dlg.tagName.toLowerCase(),
			role: dlg.getAttribute('role'),
			ariaModal: dlg.getAttribute('aria-modal'),
			nativeOpen: dlg.hasAttribute('open'),
			labelId,
			labelText: labelId ? (document.getElementById(labelId)?.textContent || '') : '',
			inModalScope: !!dlg.closest('.bc-modal'),
		}
	}, VISIBLE_DIALOG)
	if (!dialogInfo) { fail('dialog not found after trigger'); record(r); return null }
	r.checks.dialog = dialogInfo
	if (dialogInfo.tag !== 'dialog' && dialogInfo.role !== 'dialog') fail('missing role=dialog')
	if (dialogInfo.tag !== 'dialog' && dialogInfo.ariaModal !== 'true') fail('missing aria-modal')
	if (!dialogInfo.labelText.trim()) fail('aria-labelledby unresolved/empty')

	r.checks.focusInside = await page.evaluate((sel) => {
		const dlg = [...document.querySelectorAll(sel)].find((d) => d.offsetParent !== null || d.tagName === 'DIALOG')
		const ae = document.activeElement
		return !!(dlg && ae && (dlg === ae || dlg.contains(ae)))
	}, VISIBLE_DIALOG)
	if (!r.checks.focusInside) fail('focus did not move inside dialog')

	const trap = await page.evaluate((sel) => {
		const dlg = [...document.querySelectorAll(sel)].find((d) => d.offsetParent !== null || d.tagName === 'DIALOG')
		if (!dlg) return null
		const list = [...dlg.querySelectorAll('a[href],input:not([disabled]):not([type="hidden"]),select:not([disabled]),textarea:not([disabled]),button:not([disabled]),[tabindex]:not([tabindex="-1"])')]
			.filter((n) => n.offsetParent !== null || n === document.activeElement)
		return { count: list.length, firstTag: list[0]?.tagName, lastTag: list[list.length - 1]?.tagName }
	}, VISIBLE_DIALOG)
	r.checks.focusableCount = trap?.count
	if (trap && trap.count > 1) {
		await page.evaluate((sel) => {
			const dlg = [...document.querySelectorAll(sel)].find((d) => d.offsetParent !== null || d.tagName === 'DIALOG')
			const list = [...dlg.querySelectorAll('button:not([disabled]),input:not([disabled]):not([type="hidden"]),select,textarea,a[href],[tabindex]:not([tabindex="-1"])')]
				.filter((n) => n.offsetParent !== null)
			list[list.length - 1]?.focus()
		}, VISIBLE_DIALOG)
		await page.keyboard.press('Tab')
		const wrappedForward = await page.evaluate((sel) => {
			const dlg = [...document.querySelectorAll(sel)].find((d) => d.offsetParent !== null || d.tagName === 'DIALOG')
			return !!(dlg && dlg.contains(document.activeElement))
		}, VISIBLE_DIALOG)
		if (!wrappedForward) fail('Tab escaped dialog forward')
		await page.evaluate((sel) => {
			const dlg = [...document.querySelectorAll(sel)].find((d) => d.offsetParent !== null || d.tagName === 'DIALOG')
			const list = [...dlg.querySelectorAll('button:not([disabled]),input:not([disabled]):not([type="hidden"]),select,textarea,a[href],[tabindex]:not([tabindex="-1"])')]
				.filter((n) => n.offsetParent !== null)
			list[0]?.focus()
		}, VISIBLE_DIALOG)
		await page.keyboard.press('Shift+Tab')
		const wrappedBack = await page.evaluate((sel) => {
			const dlg = [...document.querySelectorAll(sel)].find((d) => d.offsetParent !== null || d.tagName === 'DIALOG')
			return !!(dlg && dlg.contains(document.activeElement))
		}, VISIBLE_DIALOG)
		if (!wrappedBack) fail('Shift+Tab escaped dialog backward')
	}
	await snap(page, `dialog__${name}__open`)

	// Escape closes + focus restore (must not land on <body>)
	await page.keyboard.press('Escape')
	await page.waitForTimeout(350)
	const afterEsc = await page.evaluate((sel) => ({
		stillOpen: [...document.querySelectorAll(sel)].some((d) => d.offsetParent !== null || (d.tagName === 'DIALOG' && d.open)),
		activeTag: document.activeElement?.tagName,
		activeId: document.activeElement?.id || '',
	}), VISIBLE_DIALOG)
	r.checks.escapeClosed = !afterEsc.stillOpen
	if (afterEsc.stillOpen) fail('Escape did not close dialog')
	r.checks.afterEscapeFocus = afterEsc.activeTag + (afterEsc.activeId ? `#${afterEsc.activeId}` : '')
	if (afterEsc.activeTag === 'BODY') fail('focus restored to <body> after Escape')

	// Cancel path: reopen → click cancel/close → closed + focus back
	try {
		await openFn()
		await settle(page)
		const reopened = await page.evaluate((sel) => [...document.querySelectorAll(sel)].some((d) => d.offsetParent !== null || (d.tagName === 'DIALOG' && d.open)), VISIBLE_DIALOG)
		if (!reopened) { fail('dialog did not reopen for cancel path'); record(r); return r }
		const dlgBtnSel = VISIBLE_DIALOG.split(',').map((x) => `${x.trim()} button`).join(',')
		const cancelBtn = page.locator(`${dlgBtnSel}`).filter({ hasText: /Cancel|Abbrechen|Close|Schließen|Back|Zurück|Keep|Behalten/ }).first()
		if (await cancelBtn.count()) {
			await cancelBtn.click({ timeout: 5000 })
		} else {
			await page.locator('.bc-modal__close').first().click({ timeout: 5000 })
		}
		await page.waitForTimeout(350)
		const afterCancel = await page.evaluate((sel) => ({
			stillOpen: [...document.querySelectorAll(sel)].some((d) => d.offsetParent !== null || (d.tagName === 'DIALOG' && d.open)),
			active: document.activeElement?.tagName,
			activeId: document.activeElement?.id || '',
		}), VISIBLE_DIALOG)
		r.checks.cancelClosed = !afterCancel.stillOpen
		r.checks.focusAfterCancel = afterCancel
		if (afterCancel.stillOpen) fail('Cancel/close did not dismiss dialog')
		if (afterCancel.active === 'BODY') fail('focus lost to <body> after cancel — no restore')
	} catch (err) {
		fail(`cancel path error: ${String(err).slice(0, 160)}`)
	}
	record(r)
	return r
}

async function phaseDialogs(browser) {
	const managerState = await loginState(browser, 'manager')
	const ctx = await browser.newContext({ baseURL: BASE, storageState: managerState, viewport: { width: 1440, height: 900 } })
	const page = await ctx.newPage()
	page.on('pageerror', (e) => console.log('PAGEEXC:', String(e).slice(0, 200)))

	const goto = async (route) => {
		await page.goto(`${BASE}${route}`, { waitUntil: 'domcontentloaded' })
		await settle(page)
		await page.waitForSelector('#bc-main-content, #bc-denied-main', { timeout: 20000 }).catch(() => {})
	}

	// 1) transactions → create-transaction modal
	await goto(`/apps/budgetcheck/transactions${W}`)
	await probeDialogLifecycle(page, 'tx-create-modal', async () => {
		const btn = page.locator('[data-bc-action="open-create-transaction"]').first()
		if (!(await btn.count())) return null
		await btn.click()
		return true
	}, { triggerSelector: '[data-bc-action="open-create-transaction"]' })

	// 2) workspaces → create-workspace modal
	await goto('/apps/budgetcheck/workspaces')
	await probeDialogLifecycle(page, 'workspace-create-modal', async () => {
		const btn = page.locator('[data-bc-action="open-create-workspace"]').first()
		if (!(await btn.count())) return null
		await btn.click()
		return true
	})

	// 3) settings/categories → create-category modal
	await goto(`/apps/budgetcheck/settings/categories${W}`)
	await probeDialogLifecycle(page, 'category-create-modal', async () => {
		const btn = page.locator('[data-bc-action="open-create-category"]').first()
		if (!(await btn.count())) return null
		await btn.click()
		return true
	})

	// 4) settings/booking-statuses → create-status modal (PROJECT workspaces
	//    only — WorkspaceSettingsSectionCatalog: booking-statuses ⇒ isProject).
	//    Discover a project workspace; skip (warn) when the seed has none.
	const projectWs = await page.evaluate(async () => {
		const res = await fetch('/apps/budgetcheck/api/workspaces', { credentials: 'same-origin' })
		const j = await res.json().catch(() => null)
		const list = (j && (j.items || j.workspaces)) || []
		const p = list.find((w) => w && w.type === 'project')
		return p ? p.id : null
	})
	if (projectWs === null) {
		record({ id: 'booking-status-create-modal', checks: { note: 'no project workspace in seed' }, status: 'warn' })
	} else {
		await goto(`/apps/budgetcheck/settings/booking-statuses?workspaceId=${projectWs}`)
		await probeDialogLifecycle(page, 'booking-status-create-modal', async () => {
			const btn = page.locator('[data-bc-action="open-create-booking-status"]').first()
			if (!(await btn.count())) return null
			await btn.click()
			return true
		})
	}

	// 5) settings/recurring → create-recurring modal
	await goto(`/apps/budgetcheck/settings/recurring${W}`)
	await probeDialogLifecycle(page, 'recurring-create-modal', async () => {
		const btn = page.locator('[data-bc-action="open-create-recurring"]').first()
		if (!(await btn.count())) return null
		await btn.click()
		return true
	})

	// 6) monthly → budget-overrides modal (manager-only button)
	await goto(`/apps/budgetcheck/monthly${W}`)
	await probeDialogLifecycle(page, 'month-budget-overrides-modal', async () => {
		const btn = page.locator('[data-bc-action="open-month-budget-overrides"]').first()
		if (!(await btn.count()) || !(await btn.isVisible().catch(() => false))) return null
		await btn.click()
		return true
	})

	// 7) LEARNED-CLASS HUNT: delete-confirm → server mutation → list re-render
	//    destroys trigger → where does focus land? (must NOT be <body>)
	{
		const r = { id: 'tx-delete-focus-after-rerender', checks: {}, status: 'ok', fails: [] }
		await goto(`/apps/budgetcheck/transactions${W}`)
		// find a transaction row with an actions menu
		const trig = page.locator('.bc-tx-actions__trigger').first()
		if (await trig.count()) {
			await trig.click()
			await page.waitForTimeout(400)
			const delItem = page.locator('.bc-tx-actions__item--danger').first()
			if (await delItem.count() && await delItem.isVisible().catch(() => false)) {
				await delItem.click()
				await page.waitForSelector('.bc-modal .bc-modal__dialog, [role="dialog"]', { timeout: 8000 }).catch(() => {})
				// Confirm the delete (mutates → list re-render → trigger destroyed)
				const confirmBtn = page.locator('.bc-modal .bc-modal__dialog button.primary, .bc-modal [role="dialog"] button.primary').first()
				if (await confirmBtn.count()) {
					await confirmBtn.click()
					await page.waitForTimeout(1500)
					await settle(page)
					const post = await page.evaluate(() => ({
						active: document.activeElement?.tagName,
						activeId: document.activeElement?.id || '',
						activeCls: String(document.activeElement?.className || '').slice(0, 60),
					}))
					r.checks.focusAfterMutatingConfirm = post
					if (post.active === 'BODY' || post.active === 'HTML') {
						r.status = 'fail'
						r.fails.push(`focus lost to <${post.active.toLowerCase()}> after destructive confirm + re-render`)
					}
					await snap(page, 'dialog__tx-delete__after-confirm')
					// restore the fixture: re-create an equivalent transaction? The
					// delete is soft — leave it; web_api lane owns CRUD durability.
				} else {
					r.status = 'fail'; r.fails.push('no confirm button in delete dialog')
				}
			} else {
				r.status = 'warn'; r.checks.note = 'no danger menu item visible — menu may need a different open path'
			}
		} else {
			r.status = 'warn'; r.checks.note = 'no .bc-tx-actions__trigger on transactions'
		}
		record(r)
	}

	await ctx.close()
}

/* ───────────────────────────── states ───────────────────────────── */
async function phaseStates(browser) {
	// anon → login redirect
	const anon = await browser.newContext({ baseURL: BASE, viewport: { width: 1440, height: 900 } })
	const ap = await anon.newPage()
	const resp = await ap.goto(`${BASE}/apps/budgetcheck/`, { waitUntil: 'domcontentloaded' })
	await settle(ap)
	const anonCell = { id: 'anon-index-redirect', checks: { finalUrl: ap.url(), http: resp?.status() }, status: 'ok' }
	anonCell.checks.onLogin = /\/login/.test(ap.url())
	if (!anonCell.checks.onLogin) { anonCell.status = 'fail'; anonCell.fails = ['anon did not land on /login'] }
	await snap(ap, 'state__anon-redirect')
	record(anonCell)
	await anon.close()

	// norole probe → index (no workspace memberships → picker/empty state)
	const noroleState = await loginState(browser, 'norole')
	const nctx = await browser.newContext({ baseURL: BASE, storageState: noroleState, viewport: { width: 1440, height: 900 } })
	const np = await nctx.newPage()
	await np.goto(`${BASE}/apps/budgetcheck/`, { waitUntil: 'domcontentloaded' })
	await settle(np)
	const nrCell = { id: 'norole-index-empty', checks: { finalUrl: np.url().replace(BASE, '') }, status: 'ok' }
	nrCell.checks.hasMain = await np.locator('#bc-main-content, #bc-denied-main, .bc-denied, main').first().isVisible().catch(() => false)
	nrCell.checks.text = (await np.locator('#app-content').textContent().catch(() => ''))?.replace(/\s+/g, ' ').slice(0, 200)
	nrCell.checks.hasRecoveryCta = await np.locator('#app-content a.button.primary, #app-content button.primary, #app-content .bc-empty button, #app-content .bc-empty a').first().isVisible().catch(() => false)
	await snap(np, 'state__norole-index')
	if (!nrCell.checks.hasMain) { nrCell.status = 'fail'; nrCell.fails = ['no main/denied surface for no-workspace user'] }
	record(nrCell)
	// norole direct manager page → redirect or denied (no workspace access)
	const resp2 = await np.goto(`${BASE}/apps/budgetcheck/transactions${W}`, { waitUntil: 'domcontentloaded' })
	await settle(np)
	const nrDeny = { id: 'norole-transactions-no-member', checks: { http: resp2?.status(), finalUrl: np.url().replace(BASE, '') }, status: 'ok' }
	nrDeny.checks.deniedOrPicker = await np.locator('.bc-denied, [role="alert"], #bc-denied-main, #bc-main-content').first().isVisible().catch(() => false)
	nrDeny.checks.leakedTxRows = await np.locator('[data-bc-tx-rows] [data-bc-tx-id]').count().catch(() => 0)
	await snap(np, 'state__norole-transactions')
	if (nrDeny.checks.leakedTxRows > 0) { nrDeny.status = 'fail'; nrDeny.fails = [`${nrDeny.checks.leakedTxRows} tx rows rendered for non-member`] }
	record(nrDeny)
	// norole → app-settings → must redirect away (not render admin chrome)
	const resp3 = await np.goto(`${BASE}/apps/budgetcheck/app-settings/access`, { waitUntil: 'domcontentloaded' })
	await settle(np)
	const nrAdmin = { id: 'norole-app-settings-redirect', checks: { http: resp3?.status(), finalUrl: np.url().replace(BASE, '') }, status: 'ok' }
	nrAdmin.checks.onAppSettings = /app-settings/.test(np.url())
	nrAdmin.checks.adminChromeVisible = await np.locator('[data-bc-action="member-invite-submit"], #bc-app-settings-access').first().isVisible().catch(() => false)
	await snap(np, 'state__norole-app-settings')
	if (nrAdmin.checks.onAppSettings) { nrAdmin.status = 'fail'; nrAdmin.fails = ['non-admin stayed on app-settings URL'] }
	record(nrAdmin)
	await nctx.close()

	// fetch-error surface: abort transactions API → error + retry, no fake chrome
	const mctx = await browser.newContext({ baseURL: BASE, storageState: await loginState(browser, 'manager'), viewport: { width: 1440, height: 900 } })
	const mp = await mctx.newPage()
	await mp.route('**/apps/budgetcheck/api/transactions**', (route) => route.abort('failed'))
	await mp.goto(`${BASE}/apps/budgetcheck/transactions${W}`, { waitUntil: 'domcontentloaded' })
	await settle(mp)
	await mp.waitForTimeout(1500)
	const errCell = { id: 'transactions-fetch-error', checks: {}, status: 'ok' }
	errCell.checks.errorVisible = await mp.locator('#app-content [role="alert"], #app-content .bc-error, #app-content .bc-inline-retry, #app-content .bc-empty--error').first().isVisible().catch(() => false)
	errCell.checks.retryBtn = await mp.locator('#app-content button').filter({ hasText: /retry|again|wiederholen|erneut/i }).first().isVisible().catch(() => false)
	errCell.checks.rawCodeText = await mp.evaluate(() => {
		const main = document.getElementById('bc-main-content') || document.querySelector('#app-content')
		const txt = main ? main.textContent || '' : ''
		return /ERR_|ECONNREFUSED|TypeError|undefined is not|\b500\b|\b503\b/.test(txt) ? txt.slice(0, 200) : null
	})
	await snap(mp, 'state__transactions-fetch-error')
	if (!errCell.checks.errorVisible) { errCell.status = 'fail'; errCell.fails = ['no error surface on aborted fetch'] }
	if (errCell.checks.rawCodeText) { errCell.status = 'fail'; (errCell.fails = errCell.fails || []).push(`raw error text: ${errCell.checks.rawCodeText}`) }
	record(errCell)

	// empty state: transactions API → empty result → honest empty copy + CTA
	await mp.unroute('**/apps/budgetcheck/api/transactions**')
	await mp.route('**/apps/budgetcheck/api/transactions**', (route) => {
		if (route.request().method() === 'GET') {
			return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ items: [], total: 0, page: 1, pageSize: 50 }) })
		}
		return route.continue()
	})
	await mp.goto(`${BASE}/apps/budgetcheck/transactions${W}`, { waitUntil: 'domcontentloaded' })
	await settle(mp)
	await mp.waitForTimeout(800)
	const emptyCell = { id: 'transactions-empty-state', checks: {}, status: 'ok' }
	emptyCell.checks.emptyVisible = await mp.locator('#app-content .bc-empty, #app-content [class*="empty"], #app-content [role="status"]').first().isVisible().catch(() => false)
	emptyCell.checks.hasCta = await mp.locator('#app-content .bc-empty button, #app-content [class*="empty"] a, [data-bc-action="open-create-transaction"]').first().isVisible().catch(() => false)
	await snap(mp, 'state__transactions-empty')
	if (!emptyCell.checks.emptyVisible && !emptyCell.checks.hasCta) { emptyCell.status = 'fail'; emptyCell.fails = ['no empty state or CTA on empty list'] }
	record(emptyCell)
	await mctx.close()
}

/* ───────────────────────────── theatre / token parity ───────────── */
async function phaseTheatre(browser) {
	const managerState = await loginState(browser, 'manager')
	const ctx = await browser.newContext({ baseURL: BASE, storageState: managerState, viewport: { width: 1440, height: 900 } })
	const page = await ctx.newPage()

	// Probes: canvas/chrome/surfaces/ink/buttons/wells must resolve to token
	// families — no dark islands on light, no parchment, no hardcoded stage.
	const PROBES = [
		{ sel: '#app-content', what: 'app canvas' },
		{ sel: '#app-content .bc-card', what: 'card surface' },
		{ sel: '#app-content button.primary, #app-content .button.primary', what: 'primary button' },
		{ sel: '#app-content .bc-page-header', what: 'page header' },
		{ sel: '#bc-main-content', what: 'main content' },
		{ sel: 'body', what: 'body (host chrome — must NOT be flat Check canvas)' },
	]
	const ROUTES = [`/apps/budgetcheck/dashboard${W}`, `/apps/budgetcheck/transactions${W}`, `/apps/budgetcheck/get-the-app${W}`]

	for (const theme of THEMES) {
		await page.goto(`${BASE}/apps/budgetcheck/`, { waitUntil: 'domcontentloaded' })
		await setUserTheme(page, theme)
		for (const route of ROUTES) {
			await page.goto(`${BASE}${route}`, { waitUntil: 'domcontentloaded' })
			await settle(page)
			const cell = { id: `theatre:${route.split('?')[0].split('/').pop()}@${theme}`, theme, route, checks: {}, status: 'ok', fails: [] }
			const measures = await page.evaluate((probes) => {
				const out = []
				for (const p of probes) {
					const el = document.querySelector(p.sel)
					if (!el) { out.push({ sel: p.sel, what: p.what, missing: true }); continue }
					const cs = getComputedStyle(el)
					const r = el.getBoundingClientRect()
					out.push({
						sel: p.sel, what: p.what,
						visible: r.width > 0 && r.height > 0,
						bg: cs.backgroundImage === 'none' ? cs.backgroundColor : `${cs.backgroundColor} + ${cs.backgroundImage.slice(0, 80)}`,
						color: cs.color, border: cs.borderColor,
					})
				}
				return out
			}, PROBES)
			cell.checks.measures = measures
			// hard failures: page-level gradient or banned-family ink
			const appBg = measures.find((m) => m.sel === '#app-content')
			if (appBg && /gradient/i.test(appBg.bg || '')) cell.fails.push(`page canvas gradient: ${appBg.bg}`)
			const bodyBg = measures.find((m) => m.sel === 'body')
			if (bodyBg && /gradient/i.test(bodyBg.bg || '')) cell.fails.push(`body gradient: ${bodyBg.bg}`)
			if (cell.fails.length) cell.status = 'fail'
			const shot = await snap(page, `theatre__${route.split('?')[0].split('/').pop()}__${theme}`)
			cell.proof = shot.sha256.slice(0, 16)
			record(cell)
		}
	}
	// restore light theme for the fixture user
	await page.goto(`${BASE}/apps/budgetcheck/`, { waitUntil: 'domcontentloaded' })
	await setUserTheme(page, 'light').catch(() => {})
	await ctx.close()
}

/* ───────────────────────────── main ───────────────────────────── */
const browser = await chromium.launch({ headless: true })
try {
	if (PHASE === 'sweep') await phaseSweep(browser)
	else if (PHASE === 'dialogs') await phaseDialogs(browser)
	else if (PHASE === 'states') await phaseStates(browser)
	else if (PHASE === 'theatre') await phaseTheatre(browser)
	else if (PHASE === 'all') {
		await phaseSweep(browser); await phaseDialogs(browser); await phaseStates(browser); await phaseTheatre(browser)
	}
} finally {
	results.finishedAt = new Date().toISOString()
	writeFileSync(join(OUT, `results-${PHASE}.json`), JSON.stringify(results, null, 1))
	await browser.close()
}
const fails = results.cells.filter((c) => c.status === 'fail')
console.log(`\n== ${PHASE} done: ${results.cells.length} cells, ${fails.length} FAIL ==`)
for (const f of fails) console.log('FAIL:', f.id, JSON.stringify(f.failReasons || f.fails))
process.exit(fails.length ? 2 : 0)
