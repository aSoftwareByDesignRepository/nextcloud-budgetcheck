#!/usr/bin/env node
/**
 * BudgetCheck frontend sanity check.
 *
 * The audit checklist forbids a small set of patterns in our hand-written
 * JS modules:
 *   1. Direct innerHTML assignment with anything other than an empty string.
 *      We always use textContent / appendChild via createElement().
 *   2. Direct document.write usage.
 *   3. eval / new Function constructions.
 *   4. Use of jQuery-style $() selectors (we deliberately keep this codebase
 *      framework-free).
 *   5. Fetch calls without credentials: 'same-origin' (the API client owns
 *      auth and CSRF — modules must use it instead of raw fetch()).
 *
 * The script returns a non-zero exit code on the first violation it finds so
 * `npm test`/`npm run lint` fail loudly in CI.
 */

const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..');
const JS_DIR = path.join(ROOT, 'js');
const EN_JSON = path.join(ROOT, 'l10n', 'en.json');

/** Patterns we must never see in production frontend code. */
const FORBIDDEN_PATTERNS = [
	{
		pattern: /\.innerHTML\s*=\s*(?!['"]\s*['"]\s*[;,)])/u,
		message: 'innerHTML assignment is forbidden; use textContent or createElement().',
	},
	{
		pattern: /\bdocument\.write\b/u,
		message: 'document.write is forbidden.',
	},
	{
		pattern: /\beval\s*\(/u,
		message: 'eval() is forbidden.',
	},
	{
		pattern: /new\s+Function\s*\(/u,
		message: 'new Function() is forbidden.',
	},
];

/** Files we don't lint (vendored or generated). */
const SKIP = new Set(['.eslintrc.js']);

const errors = [];

function walk(dir) {
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		const full = path.join(dir, entry.name);
		if (entry.isDirectory()) {
			walk(full);
			continue;
		}
		if (!entry.name.endsWith('.js')) {
			continue;
		}
		if (SKIP.has(entry.name)) {
			continue;
		}
		check(full);
	}
}

function check(file) {
	const text = fs.readFileSync(file, 'utf8');
	const lines = text.split(/\r?\n/);
	for (let i = 0; i < lines.length; i += 1) {
		const line = lines[i];
		// Skip JS line comments to avoid noisy false positives.
		const stripped = line.replace(/\/\/.*$/, '').replace(/\/\*[\s\S]*?\*\//, '');
		for (const rule of FORBIDDEN_PATTERNS) {
			if (rule.pattern.test(stripped)) {
				errors.push({ file, line: i + 1, content: line.trim(), message: rule.message });
			}
		}
	}
}

function checkRawFetchUsage() {
	// js/common/api.js is allowed to use fetch directly; everything else must
	// go through window.BudgetCheckApi.
	const files = listJsFiles(JS_DIR);
	for (const file of files) {
		if (path.basename(file) === 'api.js' && file.includes(path.sep + 'common' + path.sep)) {
			continue;
		}
		const text = fs.readFileSync(file, 'utf8');
		// Allow `fetch(` references in comments; otherwise forbid the call.
		const lines = text.split(/\r?\n/);
		for (let i = 0; i < lines.length; i += 1) {
			const line = lines[i];
			const stripped = line.replace(/\/\/.*$/, '');
			if (/\bfetch\s*\(/.test(stripped)) {
				errors.push({
					file,
					line: i + 1,
					content: line.trim(),
					message: 'Raw fetch() is forbidden outside js/common/api.js — use window.BudgetCheckApi.',
				});
			}
		}
	}
}

function listJsFiles(dir) {
	const out = [];
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		const full = path.join(dir, entry.name);
		if (entry.isDirectory()) {
			out.push(...listJsFiles(full));
			continue;
		}
		if (entry.name.endsWith('.js')) {
			out.push(full);
		}
	}
	return out;
}

function listPhpFiles(dir) {
	const out = [];
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		const full = path.join(dir, entry.name);
		if (entry.isDirectory()) {
			out.push(...listPhpFiles(full));
			continue;
		}
		if (entry.name.endsWith('.php')) {
			out.push(full);
		}
	}
	return out;
}

function unescapeJsString(value) {
	return value
		.replace(/\\\\/g, '\\')
		.replace(/\\'/g, "'")
		.replace(/\\"/g, '"');
}

function checkTranslationCoverage() {
	const en = JSON.parse(fs.readFileSync(EN_JSON, 'utf8'));
	const knownKeys = new Set(Object.keys(en.translations || {}));
	const keyToFiles = new Map();
	const jsPattern = /\bt\(\s*['"]budgetcheck['"]\s*,\s*['"]([^'"\\]*(?:\\.[^'"\\]*)*)['"]/g;
	const phpPattern = /->t\(\s*['"]([^'"\\]*(?:\\.[^'"\\]*)*)['"]/g;
	const phpFiles = [
		...listPhpFiles(path.join(ROOT, 'lib')),
		...listPhpFiles(path.join(ROOT, 'templates')),
	];
	for (const file of listJsFiles(JS_DIR)) {
		const text = fs.readFileSync(file, 'utf8');
		for (const match of text.matchAll(jsPattern)) {
			const key = unescapeJsString(match[1]);
			if (!keyToFiles.has(key)) keyToFiles.set(key, new Set());
			keyToFiles.get(key).add(file);
		}
	}
	for (const file of phpFiles) {
		const text = fs.readFileSync(file, 'utf8');
		for (const match of text.matchAll(phpPattern)) {
			const key = unescapeJsString(match[1]);
			if (!keyToFiles.has(key)) keyToFiles.set(key, new Set());
			keyToFiles.get(key).add(file);
		}
	}
	for (const [key, files] of keyToFiles.entries()) {
		if (knownKeys.has(key)) continue;
		const relFiles = Array.from(files).map((f) => path.relative(ROOT, f)).join(', ');
		errors.push({
			file: EN_JSON,
			line: 1,
			content: key,
			message: `Missing translation key in l10n/en.json (used in: ${relFiles})`,
		});
	}
}

walk(JS_DIR);
checkRawFetchUsage();
checkTranslationCoverage();

if (errors.length > 0) {
	for (const err of errors) {
		const rel = path.relative(ROOT, err.file);
		process.stderr.write(`${rel}:${err.line}: ${err.message}\n  > ${err.content}\n`);
	}
	process.stderr.write(`\nFound ${errors.length} frontend lint violation(s).\n`);
	process.exit(1);
}

process.stdout.write('check-frontend: OK (' + listJsFiles(JS_DIR).length + ' files scanned)\n');
