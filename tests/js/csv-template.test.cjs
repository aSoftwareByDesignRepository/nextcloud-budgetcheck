#!/usr/bin/env node
/**
 * csv_formula_injection regression (api-security-patterns §7): the import
 * page's client-side CSV template download must apostrophe-prefix cells
 * whose lead char is a spreadsheet formula trigger (= + - @ TAB CR VT) —
 * mirroring TransactionExportService::guardFormula on the PHP emitters.
 * Hostile workspace category names are seeded through the catalog stub, the
 * real template-download click handler fires, and the emitted Blob bytes are
 * asserted.
 *
 * Run: node tests/js/csv-template.test.cjs
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..', '..');
const SRC = [
	'js/common/csv.js',
	'js/common/bootstrap.js',
	'js/common/money.js',
	'js/common/check-dates.js',
	'js/common/dates.js',
	'js/import.js',
].map((f) => fs.readFileSync(path.join(ROOT, f), 'utf8')).join('\n;\n');

let failures = 0;
function assert(cond, msg) {
	if (!cond) {
		failures += 1;
		process.stderr.write('FAIL: ' + msg + '\n');
	}
}

/* --- unit surface: BudgetCheckCsv.cell --- */

function loadCsvHelper() {
	const sandbox = { window: {} };
	vm.createContext(sandbox);
	vm.runInContext(fs.readFileSync(path.join(ROOT, 'js', 'common', 'csv.js'), 'utf8'), sandbox);
	return sandbox.window.BudgetCheckCsv;
}

const CSV = loadCsvHelper();
assert(CSV && typeof CSV.cell === 'function', 'common/csv.js exposes BudgetCheckCsv.cell');

const leads = ['=', '+', '-', '@', '\t', '\r', '\x0B'];
for (const ch of leads) {
	const out = CSV.neutralize(ch + 'PAYLOAD');
	assert(out.startsWith("'"), `neutralize() apostrophe-prefixes lead ${JSON.stringify(ch)} (got ${JSON.stringify(out)})`);
	const cellOut = CSV.cell(ch + 'PAYLOAD');
	assert(cellOut === out || cellOut === '"' + out.replace(/"/g, '""') + '"',
		`cell() carries the apostrophe for lead ${JSON.stringify(ch)} (got ${JSON.stringify(cellOut)})`);
}
assert(CSV.cell('plain') === 'plain', 'plain cell passes through');
assert(CSV.cell('a,b') === '"a,b"', 'comma cell is RFC-quoted');
assert(CSV.cell('=x,y') === '"\'=x,y"', 'formula lead + comma: apostrophe inside quotes');
assert(CSV.cell('say "hi"') === '"say ""hi"""', 'embedded quote doubled');
assert(CSV.cell(null) === '' && CSV.cell(undefined) === '', 'null/undefined → empty cell');
assert(CSV.cell(' mid=formula') === ' mid=formula', 'mid-string = is not a formula lead');

/* --- integration: import.js template download emits neutralized cells --- */

function domEl(tag) {
	const el = {
		tag,
		textContent: '',
		children: [],
		hidden: false,
		disabled: false,
		value: '',
		checked: false,
		files: [],
		classList: { add() {}, remove() {} },
		style: {},
		appendChild(c) { this.children.push(c); return c; },
		replaceChildren() { this.children = []; },
		setAttribute() {},
		getAttribute() { return null; },
		addEventListener() {},
		closest() { return domEl('div'); },
		querySelector() { return null; },
		querySelectorAll() { return []; },
		click() {},
		remove() {},
	};
	return el;
}

function bootImport(categories) {
	const selectors = {};
	for (const sel of [
		'[data-bc-import-form]',
		'[data-bc-import-file]',
		'[data-bc-import-file-picker]',
		'[data-bc-import-file-name]',
		'[data-bc-import-file-name-text]',
		'[data-bc-import-validate]',
		'[data-bc-import-commit]',
		'[data-bc-import-status]',
		'[data-bc-import-errors-wrap]',
		'[data-bc-import-errors]',
		'[data-bc-import-errors-title]',
		'[data-bc-import-preview-wrap]',
		'[data-bc-import-preview]',
		'[data-bc-import-preview-summary]',
		'[data-bc-import-category-rows]',
		'[data-bc-import-default-expense]',
		'[data-bc-import-default-income]',
		'[data-bc-import-direction-mode]',
		'[data-bc-import-skip-duplicates]',
		'[data-bc-import-fingerprint-wrap]',
		'[data-bc-import-skip-fingerprint]',
		'[data-bc-import-download-template]',
	]) {
		selectors[sel] = domEl('div');
	}
	selectors['[data-bc-import-file-picker]'].querySelector = () => domEl('div');
	const prefEls = [
		selectors['[data-bc-import-default-expense]'],
		selectors['[data-bc-import-default-income]'],
		selectors['[data-bc-import-direction-mode]'],
		selectors['[data-bc-import-skip-duplicates]'],
		selectors['[data-bc-import-skip-fingerprint]'],
	];
	selectors['[data-bc-import-form]'].querySelectorAll = () => prefEls;
	selectors['[data-bc-import-direction-mode]'].value = 'auto';

	let templateClick = null;
	selectors['[data-bc-import-download-template]'].addEventListener = (type, fn) => {
		if (type === 'click') templateClick = fn;
	};

	const anchor = domEl('a');
	const blobs = [];
	function BlobStub(parts, opts) { this.parts = parts; this.opts = opts; blobs.push(this); }

	const state = { announced: [], apiErrors: [] };
	const sandbox = {
		console,
		TextDecoder,
		TextEncoder,
		Uint8Array,
		ArrayBuffer,
		DataView,
		DataTransfer: function DataTransfer() {},
		JSON,
		Promise,
		process,
		setTimeout,
		clearTimeout,
		t: (_app, s) => s,
		n: (_app, s, p, count) => (count === 1 ? s : p),
		localStorage: { getItem: () => null, setItem() {} },
		FileReader: function FileReader() {},
		Blob: BlobStub,
		URL: { createObjectURL: () => 'blob:atlas', revokeObjectURL() {} },
		location: { href: '', reload() {} },
	};
	sandbox.document = {
		readyState: 'complete',
		documentElement: { lang: 'en', getAttribute: () => 'en' },
		getElementById: (id) => (id === 'app-content' ? { getAttribute: () => 'en' } : null),
		querySelector: (sel) => selectors[sel] || null,
		querySelectorAll: () => [],
		createElement: () => anchor,
		addEventListener() {},
		body: domEl('body'),
	};
	sandbox.BudgetCheckApi = {
		get: async (url) => {
			if (url.includes('/api/categories')) return { categories };
			if (url.includes('/api/booking-statuses')) return { statuses: [] };
			if (url.includes('import-preferences')) return { preferences: null };
			throw new Error('unexpected GET ' + url);
		},
		post: async () => ({}),
		put: async () => ({}),
		del: async () => ({}),
	};
	sandbox.BudgetCheckMessaging = {
		announce: (msg, kind) => state.announced.push({ msg, kind }),
		handleApiError: (err) => state.apiErrors.push(err),
	};
	sandbox.BudgetCheckComponents = {
		createElement: (tag, opts, children) => {
			const el = domEl(tag);
			el.opts = opts || {};
			if (el.opts.text !== undefined) el.textContent = el.opts.text;
			(children || []).forEach((c) => el.appendChild(c));
			return el;
		},
	};
	sandbox.BudgetCheckWorkspace = {
		workspace: { id: 7, type: 'household', currencyDecimals: 2, name: 'Home' },
		canContribute: true,
		urls: { transactions: '/apps/budgetcheck/transactions' },
		withWorkspace: (u) => u,
	};

	sandbox.window = sandbox;
	sandbox.self = sandbox;

	vm.runInNewContext(SRC, sandbox);
	return { getTemplateClick: () => templateClick, anchor, blobs, state };
}

async function main() {
	const hostileExpense = '=ATLASINJ-HYPERLINK("https://evil","x")';
	const hostileIncome = '-ATLASINJ-INCOME';
	const { getTemplateClick, anchor, blobs, state } = bootImport([
		{ id: 10, name: hostileExpense, type: 'expense', isActive: true },
		{ id: 20, name: hostileIncome, type: 'income', isActive: true },
	]);
	// Let onReady(boot) fire and pageInit finish (catalog fetch + wiring).
	for (let i = 0; i < 20; i += 1) await Promise.resolve();

	const click = getTemplateClick();
	assert(typeof click === 'function', 'template download button wires a click handler');
	assert(state.apiErrors.length === 0, 'pageInit surfaced no API error (got ' + JSON.stringify(state.apiErrors) + ')');
	click();

	assert(blobs.length === 1, 'exactly one Blob emitted');
	assert(/^budgetcheck-import-template-.+\.csv$/.test(anchor.download), 'download filename carries workspace slug, got ' + anchor.download);
	const text = blobs[0].parts.join('');
	assert(text.charCodeAt(0) === 0xFEFF, 'payload carries the UTF-8 BOM for Excel');
	const lines = text.replace(/^﻿/, '').split('\n');
	assert(lines[0] === 'bookingDate,title,direction,amount,category,notes,externalRef',
		'header line intact');
	assert(lines.length === 3, 'two template rows emitted, got ' + lines.length);
	assert(text.indexOf("'=ATLASINJ") !== -1, 'expense category =-lead is apostrophe-prefixed');
	assert(text.indexOf("'-ATLASINJ-INCOME") !== -1, 'income category --lead is apostrophe-prefixed');
	for (const row of lines.slice(1)) {
		// A cell is unsafe iff a trigger char directly follows the field start
		// (an optional opening quote is part of the field, an apostrophe means
		// the cell was neutralized first).
		assert(!/(^|,)"?[=+\-@\t\r\x0B]/.test(row), 'no unguarded formula lead survives, row=' + JSON.stringify(row));
	}
}

main().then(() => {
	if (failures) {
		process.stderr.write(`${failures} failure(s)\n`);
		process.exit(1);
	}
	process.stdout.write('OK csv-template: formula-lead neutralization verified on helper + import template download\n');
});
