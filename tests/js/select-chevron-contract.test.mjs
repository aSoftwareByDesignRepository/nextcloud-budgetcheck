/**
 * Contract: no CSS rule that matches a <select> may use the bare `background:`
 * shorthand — it resets the chevron's background-image/position/repeat/size
 * to initial and the picker affordance vanishes (the control reads as a plain
 * text field).
 *
 * Regression of bc-web-selects-no-picker-affordance (visual r2, 2026-10-23):
 * `.bc-input` and the `.bc-modal` control enforcer painted `background:` —
 * every select in the app (20 template + 14 JS-created) lost its chevron.
 * Same class as maintenancecheck mn-vis-select-chevron-broken.
 *
 * Two legs, mirroring scripts/check-select-background-shorthand.py:
 *  1. rules whose selector literally mentions `select`
 *  2. rules on classes provably applied to <select> elements in templates/JS
 *     (a class rule reset is invisible to a literal-`select` scan — that is
 *     how the .bc-input defect shipped)
 * Plus a positive pin: the two fixed sites must keep `background-color:`.
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync, readdirSync, existsSync } from 'node:fs'
import { dirname, join, relative, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const appRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const cssRoot = join(appRoot, 'css')

function walkFiles(dir, exts) {
	if (!existsSync(dir)) {
		return []
	}
	return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
		const path = join(dir, entry.name)
		if (entry.isDirectory()) {
			return walkFiles(path, exts)
		}
		return exts.some((ext) => entry.name.endsWith(ext)) ? [path] : []
	})
}

function stripComments(css) {
	return css.replace(/\/\*[\s\S]*?\*\//g, '')
}

/** Brace-boundary walk: yields {selector, body} for every ruleset incl. @media children. */
function* rules(css) {
	let boundary = 0
	for (const m of css.matchAll(/[{}]/g)) {
		if (m[0] === '{') {
			const selector = css.slice(boundary, m.index).trim()
			let depth = 1
			let j = m.index + 1
			while (j < css.length && depth > 0) {
				if (css[j] === '{') depth++
				else if (css[j] === '}') depth--
				j++
			}
			boundary = m.index + 1
			yield { selector, body: css.slice(m.index + 1, j - 1) }
		} else {
			boundary = m.index + 1
		}
	}
}

const BARE_BG = /(^|;)\s*background\s*:/
const CLASS_TOKEN = /^[A-Za-z_-][\w-]*$/

/** Classes provably applied to <select> elements in templates and JS. */
function selectAppliedClasses() {
	const classes = new Set()
	const add = (raw) => {
		for (const tok of String(raw).split(/\s+/)) {
			if (CLASS_TOKEN.test(tok)) classes.add(tok)
		}
	}
	const sources = [
		...walkFiles(join(appRoot, 'templates'), ['.php']),
		...walkFiles(join(appRoot, 'js'), ['.js']),
	]
	for (const file of sources) {
		const src = stripComments(readFileSync(file, 'utf8'))
		for (const m of src.matchAll(/<select\b[^>]*?\bclass\s*=\s*["']([^"']+)["']/gi)) {
			add(m[1])
		}
		for (const m of src.matchAll(/(?:createElement|h|\$)\(\s*["']select["']/gi)) {
			let window = src.slice(m.index + m[0].length, m.index + m[0].length + 600)
			const next = window.match(/(?:createElement|h|\$)\(/i)
			if (next) window = window.slice(0, next.index)
			for (const cm of window.matchAll(/class(?:Name)?\s*[:=]\s*["']([^"']+)["']/g)) {
				add(cm[1])
			}
			for (const cl of window.matchAll(/classList\.add\(([^)]*)\)/g)) {
				for (const qm of cl[1].matchAll(/["']([^"']+)["']/g)) {
					add(qm[1])
				}
			}
		}
	}
	return classes
}

const cssFiles = walkFiles(cssRoot, ['.css'])
const selectClasses = selectAppliedClasses()
const classRes = [...selectClasses].map((cls) => ({
	cls,
	re: new RegExp(`(?<![\\w-])\\.${cls.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}(?![\\w-])`),
}))

function matchingRules() {
	const out = []
	for (const file of cssFiles) {
		const css = stripComments(readFileSync(file, 'utf8'))
		for (const rule of rules(css)) {
			const literal = /\bselect\b/.test(rule.selector)
			const classHit = classRes.find((c) => c.re.test(rule.selector))
			if (literal || classHit) {
				out.push({ file, ...rule, via: literal ? 'select' : `.${classHit.cls}` })
			}
		}
	}
	return out
}

test('no rule matching a <select> uses the bare background: shorthand', () => {
	const offenders = matchingRules()
		.filter((r) => BARE_BG.test(r.body))
		.map((r) => `${relative(appRoot, r.file)}: ${r.selector}  [via ${r.via}]`)
	assert.deepEqual(offenders, [],
		`bare background: resets the chevron (use background-color:):\n${offenders.join('\n')}`)
})

test('select-applied classes are discovered (contract is not vacuous)', () => {
	assert.ok(selectClasses.has('bc-input'),
		`.bc-input must be detected as a select class (got: ${[...selectClasses].join(', ') || 'none'})`)
})

test('the .bc-input and .bc-modal enforcer sites paint background-color:', () => {
	const css = stripComments(readFileSync(join(cssRoot, 'app.css'), 'utf8'))
	const all = [...rules(css)]
	const base = all.find((r) => r.selector === '.bc-input')
	assert.ok(base, '.bc-input rule missing')
	assert.match(base.body, /(^|;)\s*background-color\s*:/,
		'.bc-input must paint background-color:, not the shorthand')
	const enforcer = all.find((r) => r.selector.includes('.bc-modal select.bc-input'))
	assert.ok(enforcer, '.bc-modal control enforcer rule missing')
	assert.match(enforcer.body, /(^|;)\s*background-color\s*:/,
		'.bc-modal enforcer must paint background-color:, not the shorthand')
})
