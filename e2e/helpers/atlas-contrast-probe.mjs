// @ts-check
/**
 * ATLAS ds_chrome live contrast probe (budgetcheck).
 *
 * Ported from snackcheck/e2e/helpers/atlas-contrast-probe.mjs (itself ported
 * from maintenancecheck). Logs in, walks representative BudgetCheck pages,
 * and measures the COMPUTED WCAG 2.1 contrast of semantic chrome: badges,
 * pills, callouts, primary/danger CTAs, control borders (incl. body-mounted
 * .bc-modal dialogs — the 4860-line cascade guard only covers #app-content),
 * invalid-field borders, and the error toast — across
 * light / dark / light-highcontrast / dark-highcontrast user themes.
 *
 *   text ink   >= 4.5:1  (WCAG 1.4.3 AA)
 *   borders    >= 3.0:1  (WCAG 1.4.11)
 *
 * Usage (from the app dir):
 *   node e2e/helpers/atlas-contrast-probe.mjs [--out <path.json>]
 *
 * Requires E2E_USER + E2E_PASS (e2e/.env or tests/e2e/.env) and
 * http://localhost:8081. BC_PROBE_WS selects the workspace id (default 100).
 */
import { writeFileSync, mkdirSync, existsSync, readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { createRequire } from 'node:module'
import { chromium } from '@playwright/test'

const HERE = dirname(fileURLToPath(import.meta.url))
const APP_ROOT = resolve(HERE, '../..')
const require = createRequire(import.meta.url)
const { setUserTheme, resetUserTheme } = require('./theming.js')

for (const envPath of [resolve(APP_ROOT, 'e2e/.env'), resolve(APP_ROOT, 'tests/e2e/.env')]) {
	if (!existsSync(envPath)) continue
	for (const line of readFileSync(envPath, 'utf8').split('\n')) {
		const t = line.trim()
		if (!t || t.startsWith('#')) continue
		const eq = t.indexOf('=')
		if (eq <= 0) continue
		const k = t.slice(0, eq).trim()
		let v = t.slice(eq + 1).trim()
		if ((v.startsWith('"') && v.endsWith('"')) || (v.startsWith("'") && v.endsWith("'"))) v = v.slice(1, -1)
		if (process.env[k] === undefined) process.env[k] = v
	}
}

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '')
const USER = process.env.E2E_USER
const PASS = process.env.E2E_PASS || process.env.E2E_PASSWORD
const WS = process.env.BC_PROBE_WS || '100'

// ── WCAG contrast helpers (injected into the page for computed colors) ──
const EVAL_FN = String.raw`
function hexToRgb(c) {
  c = c.trim()
  // Chrome serialises color-mix() as color(srgb r g b / a) — floats 0..1.
  if (c.startsWith('color(')) {
    const m = c.match(/[\d.]+/g)
    if (m && m.length >= 3) {
      const s = m.map(parseFloat)
      const scale = s.every((v) => v <= 1) ? 255 : 1
      return [s[0] * scale, s[1] * scale, s[2] * scale]
    }
    return null
  }
  if (c.startsWith('rgb')) {
    const m = c.match(/[\d.]+/g)
    if (m && m.length >= 3) return [parseFloat(m[0]), parseFloat(m[1]), parseFloat(m[2])]
    return null
  }
  if (c.startsWith('#')) {
    let h = c.slice(1)
    if (h.length === 3) h = h.split('').map(x => x + x).join('')
    if (h.length === 4) h = h.split('').map(x => x + x).join('')
    if (h.length === 6 || h.length === 8) {
      return [parseInt(h.slice(0,2),16), parseInt(h.slice(2,4),16), parseInt(h.slice(4,6),16)]
    }
  }
  return null
}
function lum(rgb) {
  const f = v => {
    v /= 255
    return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4)
  }
  return 0.2126 * f(rgb[0]) + 0.7152 * f(rgb[1]) + 0.0722 * f(rgb[2])
}
function effBg(el) {
  // Walk ancestors for the first non-transparent background.
  let n = el
  while (n && n !== document.documentElement) {
    const bg = getComputedStyle(n).backgroundColor
    const m = bg && bg.match(/[\d.]+/g)
    if (m && m.length >= 4 && parseFloat(m[3]) > 0) return bg
    if (m && m.length === 3 && !bg.includes('transparent')) return bg
    n = n.parentElement
  }
  return getComputedStyle(document.body).backgroundColor
}
function alphaOf(c) {
  const m = c && c.match(/[\d.]+/g)
  if (m && m.length >= 4) return parseFloat(m[3])
  // color(srgb r g b / a) — fourth channel is the alpha.
  if (c && c.startsWith('color(')) {
    const parts = c.match(/[\d.]+/g)
    if (parts && parts.length >= 4) return parseFloat(parts[3])
  }
  return 1
}
function blend(fgRgb, bgRgb, a) {
  return [
    a * fgRgb[0] + (1 - a) * bgRgb[0],
    a * fgRgb[1] + (1 - a) * bgRgb[1],
    a * fgRgb[2] + (1 - a) * bgRgb[2],
  ]
}
function ratio(fg, bg) {
  const a = hexToRgb(fg), b = hexToRgb(bg)
  if (!a || !b) return null
  const l1 = lum(a), l2 = lum(b)
  const hi = Math.max(l1, l2), lo = Math.min(l1, l2)
  return (hi + 0.05) / (lo + 0.05)
}
function borderRatio(border, bg) {
  const f = hexToRgb(border), b = hexToRgb(bg)
  if (!f || !b) return null
  const alpha = alphaOf(border)
  const eff = alpha >= 1 ? f : blend(f, b, alpha)
  const l1 = lum(eff), l2 = lum(b)
  const hi = Math.max(l1, l2), lo = Math.min(l1, l2)
  return (hi + 0.05) / (lo + 0.05)
}
function ancestorBg(el) {
  // Effective background BEHIND the element (skip its own fill) — for filled
  // CTAs the control boundary is fill-vs-page, not transparent-border-vs-fill.
  let n = el.parentElement
  while (n && n !== document.documentElement) {
    const bg = getComputedStyle(n).backgroundColor
    const m = bg && bg.match(/[\d.]+/g)
    if (m && m.length >= 4 && parseFloat(m[3]) > 0) return bg
    if (m && m.length === 3 && !bg.includes('transparent')) return bg
    n = n.parentElement
  }
  return getComputedStyle(document.body).backgroundColor
}
window.__bcProbe = { effBg, ancestorBg, ratio, alphaOf, borderRatio }
`

/** Elements to measure per page (populated workspace fixture = WS). */
const PROBES = [
	{
		page: `/?workspaceId=${WS}`,
		label: 'dashboard',
		ready: '#bc-main-content',
		rows: [
			{ sel: '.bc-dash-action__title', kind: 'dash-action-ink', what: 'text' },
			{ sel: '.bc-dash-action__hint, .bc-section__sub, .bc-field__hint', kind: 'muted-ink', what: 'text' },
			{ sel: '.bc-dash-action', kind: 'dash-action-border', what: 'border' },
			{ sel: '.bc-summary-tile__value, .bc-summary-tile__label', kind: 'kpi-ink', what: 'text' },
			{ sel: '.bc-badge', kind: 'badge', what: 'text+border', surface: true },
			{ sel: '.bc-pill', kind: 'pill', what: 'text+border', surface: true },
			{ sel: '.bc-callout', kind: 'callout', what: 'text+border', surface: true },
			{ sel: 'a.button.primary:not(.skip-navigation):not(.skip-content), button.primary:not(.skip-navigation):not(.skip-content), .button.primary:not(.skip-navigation):not(.skip-content)', kind: 'primary-cta', what: 'fill' },
			{ sel: '#bc-main-content a.button:not(.primary), #bc-main-content button.button:not(.primary)', kind: 'secondary-cta', what: 'text+border' },
		],
	},
	{
		page: `/transactions?workspaceId=${WS}`,
		label: 'transactions',
		ready: '#bc-main-content',
		rows: [
			{ sel: '.bc-tx-filterbar .bc-input, .bc-tx-filterbar select, .bc-tx-filterbar input', kind: 'control-border', what: 'border' },
			{ sel: '.bc-tx-new-btn, .button.primary:not(.skip-navigation):not(.skip-content)', kind: 'primary-cta', what: 'fill' },
			{ sel: '.bc-chip', kind: 'chip', what: 'text+border', surface: true },
			{ sel: 'table.bc-table td, table.bc-table th', kind: 'table-ink', what: 'text' },
			{ sel: '.bc-pill, .bc-badge', kind: 'pill/badge', what: 'text+border', surface: true },
		],
	},
	{
		page: `/budgets?workspaceId=${WS}`,
		label: 'budgets',
		ready: '#bc-main-content',
		rows: [
			{ sel: '.bc-input, .bc-field input, .bc-field select', kind: 'control-border', what: 'border' },
			{ sel: '.button.primary:not(.skip-navigation):not(.skip-content)', kind: 'primary-cta', what: 'fill' },
			{ sel: '.bc-badge, .bc-pill', kind: 'badge/pill', what: 'text+border', surface: true },
			{ sel: 'table td, table th', kind: 'table-ink', what: 'text' },
		],
	},
	{
		page: '/workspaces',
		label: 'workspaces',
		ready: '#bc-main-content',
		rows: [
			{ sel: '.bc-badge', kind: 'badge', what: 'text+border', surface: true },
			{ sel: '.button.primary:not(.skip-navigation):not(.skip-content)', kind: 'primary-cta', what: 'fill' },
			{ sel: '.button.danger', kind: 'danger-cta', what: 'fill' },
			{ sel: '.bc-card', kind: 'card-border', what: 'border', surface: true },
			{ sel: '#bc-main-content a.button:not(.primary), #bc-main-content button.button:not(.primary)', kind: 'secondary-cta', what: 'text+border' },
		],
	},
	{
		page: `/settings/categories?workspaceId=${WS}`,
		label: 'settings-categories',
		ready: '#bc-main-content',
		rows: [
			{ sel: '.bc-input, .bc-field input, .bc-field select, .bc-settings input, .bc-settings select', kind: 'control-border', what: 'border' },
			{ sel: '.button.primary:not(.skip-navigation):not(.skip-content)', kind: 'primary-cta', what: 'fill' },
			{ sel: '.bc-field__hint, .bc-section__sub', kind: 'muted-ink', what: 'text' },
		],
	},
	{
		page: `/settings/workspace?workspaceId=${WS}`,
		label: 'settings-workspace',
		ready: '#bc-main-content',
		rows: [
			{ sel: '.bc-input, .bc-field input, .bc-field select, input[type="text"], select', kind: 'control-border', what: 'border' },
			{ sel: '.button.primary:not(.skip-navigation):not(.skip-content)', kind: 'primary-cta', what: 'fill' },
			{ sel: '.button.danger', kind: 'danger-cta', what: 'fill' },
			{ sel: '.bc-danger-zone', kind: 'danger-zone-border', what: 'border', surface: true },
		],
	},
]

/** Programmatic login (same flow as tests/e2e/helpers/auth.js). */
async function login(page) {
	await page.goto(`${BASE}/index.php/login`, { waitUntil: 'domcontentloaded' })
	const userField = page.locator('input#user, input[name="user"]').first()
	await userField.waitFor({ state: 'visible', timeout: 30_000 })
	await userField.fill(USER)
	await page.locator('input#password, input[name="password"]').first().fill(PASS)
	await page.getByRole('button', { name: /^log in$|^anmelden$/i }).click()
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
}

async function measure(page, probes) {
	const results = []
	for (const p of probes) {
		await page.goto(`${BASE}/index.php/apps/budgetcheck${p.page}`, { waitUntil: 'domcontentloaded' })
		await page.waitForSelector(p.ready || '#bc-main-content', { timeout: 30_000 })
		await page.waitForTimeout(600)
		for (const row of p.rows) {
			const found = await page.evaluate(
				async ({ sel, what }) => {
					const els = Array.from(document.querySelectorAll(sel)).filter(
						(n) => n.offsetParent !== null,
					)
					const out = []
					for (const el of els.slice(0, 6)) {
						const cs = getComputedStyle(el)
						const bg = window.__bcProbe.effBg(el)
						const item = {
							tag: el.tagName.toLowerCase(),
							cls: (el.getAttribute('class') || '').slice(0, 80),
							fg: cs.color,
							bg,
							borderColor: cs.borderColor,
							borderWidth: cs.borderWidth,
						}
						if (what === 'fill') {
							// Filled CTA: text ink on fill + fill vs surrounding page bg.
							item.textRatio = window.__bcProbe.ratio(cs.color, bg)
							const surround = window.__bcProbe.ancestorBg(el)
							item.fill = cs.backgroundColor
							item.surroundBg = surround
							item.fillRatio = window.__bcProbe.ratio(cs.backgroundColor, surround)
						} else {
							if (what !== 'border') {
								item.textRatio = window.__bcProbe.ratio(cs.color, bg)
							}
							if (what !== 'text' && parseFloat(cs.borderWidth) > 0) {
								const bAlpha = window.__bcProbe.alphaOf(cs.borderColor)
								item.borderAlpha = bAlpha
								// alpha=0 border = invisible boundary — record so the
								// verdict can flag outlined controls that lost their frame.
								item.borderTransparent = bAlpha === 0
								if (bAlpha > 0) {
									item.borderRatio = window.__bcProbe.borderRatio(cs.borderColor, bg)
								}
							}
						}
						out.push(item)
					}
					return out
				},
				{ sel: row.sel, what: row.what },
			)
			results.push({ page: p.label, kind: row.kind, selector: row.sel, what: row.what, surface: !!row.surface, found: found.length, samples: found })
		}
	}
	return results
}

/**
 * Live field-error + error-toast state: open the transaction create dialog
 * (body-mounted .bc-modal — outside the #app-content cascade guard, so modal
 * control borders are measured here), drive the canonical
 * CheckFieldErrors.markValidationFields wiring (same call the api.js
 * VALIDATION path makes) onto the amount field, and measure the painted
 * aria-invalid border + inline .bc-field-error ink. Then announce a real
 * error toast through BC.Messaging.announce and measure its ink + accent.
 */
async function measureFieldError(page) {
	await page.goto(`${BASE}/index.php/apps/budgetcheck/transactions?workspaceId=${WS}`, { waitUntil: 'domcontentloaded' })
	await page.waitForSelector('#bc-main-content', { timeout: 30_000 })
	const createBtn = page.locator('[data-bc-action="open-create-transaction"]').first()
	if ((await createBtn.count()) === 0) return { skipped: 'no open-create-transaction action rendered' }
	await createBtn.click()
	await page.locator('.bc-modal__dialog').waitFor({ state: 'visible', timeout: 15_000 })
	await page.waitForTimeout(300)

	const res = await page.evaluate(async () => {
		const out = {}
		const modal = document.querySelector('.bc-modal__dialog')
		if (modal) {
			// Every visible named control in the modal — painted border vs its bg.
			const controls = Array.from(modal.querySelectorAll('input, select, textarea'))
				.filter((n) => n.offsetParent !== null && !['hidden', 'submit', 'button', 'checkbox', 'radio'].includes(n.type))
			out.modalControls = controls.slice(0, 8).map((el) => {
				const cs = getComputedStyle(el)
				const bg = window.__bcProbe.effBg(el)
				return {
					name: el.getAttribute('name') || el.getAttribute('data-field') || el.type,
					cls: (el.getAttribute('class') || '').slice(0, 60),
					borderColor: cs.borderColor,
					borderWidth: cs.borderWidth,
					borderRatio: window.__bcProbe.borderRatio(cs.borderColor, bg),
				}
			})
		}
		// Drive the real validation-marking path (api.js markFieldErrors calls
		// exactly this on a server VALIDATION response).
		if (window.CheckFieldErrors && typeof window.CheckFieldErrors.markValidationFields === 'function') {
			window.CheckFieldErrors.install({ prefix: 'bc' })
			window.CheckFieldErrors.markValidationFields({ amount: 'Enter a valid amount.' })
		}
		const input = document.querySelector('.bc-modal__dialog [name="amount"][aria-invalid="true"]')
			|| document.querySelector('[name="amount"][aria-invalid="true"]')
		if (input) {
			const cs = getComputedStyle(input)
			const bg = window.__bcProbe.effBg(input)
			out.invalidField = {
				ariaInvalid: input.getAttribute('aria-invalid'),
				borderColor: cs.borderColor,
				borderWidth: cs.borderWidth,
				bg,
				borderRatio: window.__bcProbe.borderRatio(cs.borderColor, bg),
			}
			const errEl = document.querySelector('.bc-field-error')
			if (errEl) {
				const ecs = getComputedStyle(errEl)
				out.fieldErrorText = {
					fg: ecs.color,
					bg: window.__bcProbe.effBg(errEl),
					textRatio: window.__bcProbe.ratio(ecs.color, window.__bcProbe.effBg(errEl)),
				}
			}
		} else {
			out.invalidField = { skipped: 'markValidationFields did not pin aria-invalid on [name=amount]' }
		}
		return out
	})

	// Real error toast via the app-owned container (dedup + roles live here).
	await page.evaluate(() => {
		const msg = (window.BudgetCheck && typeof window.BudgetCheck.get === 'function' && window.BudgetCheck.get('Messaging'))
			|| window.BudgetCheckMessaging
		if (msg && typeof msg.announce === 'function') {
			msg.announce('ATLAS contrast probe error toast', 'error')
		}
	})
	await page.waitForTimeout(250)
	res.errorToast = await page.evaluate(() => {
		const toast = document.querySelector('.bc-toasts .bc-toast--error, .bc-toast--error')
		if (!toast) return { skipped: 'no .bc-toast--error rendered' }
		const textEl = toast.querySelector('.bc-toast__text') || toast
		const tcs = getComputedStyle(textEl)
		const cs = getComputedStyle(toast)
		return {
			role: toast.getAttribute('role'),
			fg: tcs.color,
			bg: window.__bcProbe.effBg(textEl),
			textRatio: window.__bcProbe.ratio(tcs.color, window.__bcProbe.effBg(textEl)),
			borderColor: cs.borderColor,
			borderLeftColor: cs.borderLeftColor,
			borderRatio: window.__bcProbe.borderRatio(cs.borderLeftColor, window.__bcProbe.effBg(toast)),
		}
	})
	await page.keyboard.press('Escape')
	return res
}

async function main() {
	if (!USER || !PASS) throw new Error('E2E_USER + E2E_PASS required (e2e/.env)')
	const browser = await chromium.launch()
	const context = await browser.newContext({ viewport: { width: 1440, height: 900 } })
	const page = await context.newPage()
	const report = { app: 'budgetcheck', probe: 'live-computed-contrast', base: BASE, workspace: WS, generated_at: new Date().toISOString(), themes: {} }
	let failures = 0

	try {
		await login(page)
		await context.addInitScript(EVAL_FN)

		for (const theme of ['light', 'dark', 'light-highcontrast', 'dark-highcontrast']) {
			await page.goto(`${BASE}/index.php/apps/budgetcheck/?workspaceId=${WS}`, { waitUntil: 'domcontentloaded' })
			await setUserTheme(page, theme)
			await page.waitForSelector('#bc-main-content', { timeout: 30_000 })
			const themeRes = { pages: await measure(page, PROBES) }
			if (theme === 'light') {
				themeRes.field_error_state = await measureFieldError(page)
			}
			report.themes[theme] = themeRes
		}
		await resetUserTheme(page)
	} finally {
		await context.close()
		await browser.close()
	}

	// Verdict
	const TEXT_MIN = 4.5
	const BORDER_MIN = 3.0
	const findings = []
	const boundary_notes = []
	for (const [theme, t] of Object.entries(report.themes)) {
		for (const row of t.pages) {
			for (const s of row.samples || []) {
				const bucket = row.surface ? boundary_notes : findings
				if (s.textRatio !== undefined && s.textRatio !== null && s.textRatio < TEXT_MIN) {
					// Text ink is a hard failure even on passive surfaces.
					findings.push({ theme, page: row.page, kind: row.kind, cls: s.cls, ratio: s.textRatio, min: TEXT_MIN })
				}
				if (s.borderRatio !== undefined && s.borderRatio !== null && s.borderRatio < BORDER_MIN) {
					bucket.push({ theme, page: row.page, kind: row.kind + '-border', cls: s.cls, ratio: s.borderRatio, min: BORDER_MIN })
				}
				if (s.borderTransparent === true) {
					bucket.push({ theme, page: row.page, kind: row.kind + '-border', cls: s.cls, ratio: 0, min: 'opaque border expected' })
				}
				if (s.fillRatio !== undefined && s.fillRatio !== null && s.fillRatio < BORDER_MIN) {
					bucket.push({ theme, page: row.page, kind: row.kind + '-fill', cls: s.cls, ratio: s.fillRatio, min: BORDER_MIN })
				}
			}
		}
		const fe = t.field_error_state
		if (fe) {
			if (fe.errorToast && fe.errorToast.textRatio !== undefined && fe.errorToast.textRatio !== null && fe.errorToast.textRatio < TEXT_MIN) {
				findings.push({ theme, page: 'toast', kind: 'error-toast-text', ratio: fe.errorToast.textRatio, min: TEXT_MIN })
			}
			if (fe.invalidField && fe.invalidField.borderRatio !== undefined && fe.invalidField.borderRatio !== null && fe.invalidField.borderRatio < BORDER_MIN) {
				findings.push({ theme, page: 'transaction-create-dialog', kind: 'invalid-border', ratio: fe.invalidField.borderRatio, min: BORDER_MIN })
			}
			if (fe.fieldErrorText && fe.fieldErrorText.textRatio !== undefined && fe.fieldErrorText.textRatio !== null && fe.fieldErrorText.textRatio < TEXT_MIN) {
				findings.push({ theme, page: 'transaction-create-dialog', kind: 'field-error-text', ratio: fe.fieldErrorText.textRatio, min: TEXT_MIN })
			}
			for (const c of fe.modalControls || []) {
				if (c.borderRatio !== null && c.borderRatio !== undefined && c.borderRatio < BORDER_MIN) {
					findings.push({ theme, page: 'transaction-create-dialog', kind: 'modal-control-border', cls: c.name, ratio: c.borderRatio, min: BORDER_MIN })
				}
			}
		}
	}
	report.findings = findings
	report.boundary_notes = boundary_notes
	report.verdict = findings.length === 0 ? 'PASS' : 'FAIL'
	failures = findings.length

	const outIdx = process.argv.indexOf('--out')
	const outPath = outIdx > 0 ? process.argv[outIdx + 1] : null
	if (outPath) {
		mkdirSync(dirname(outPath), { recursive: true })
		writeFileSync(outPath, JSON.stringify(report, null, 2))
		console.log(`wrote ${outPath}`)
	} else {
		console.log(JSON.stringify(report, null, 2).slice(0, 4000))
	}
	console.log(`contrast probe: ${report.verdict} (${findings.length} findings, ${boundary_notes.length} boundary notes)`)
	process.exit(failures > 0 ? 1 : 0)
}

main().catch((e) => {
	console.error(e)
	process.exit(2)
})
