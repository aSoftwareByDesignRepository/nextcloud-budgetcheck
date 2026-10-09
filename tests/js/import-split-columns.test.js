'use strict';

/**
 * Behavioural tests for the bank-CSV importer's split income/expense columns
 * (GitHub issue #23): files with separate "Expenses"/"Income" or
 * "Debit"/"Credit" amount columns, optional minus sign on the expense side.
 *
 * Drives the real js/import.js page flow inside a vm sandbox: file picker →
 * FileReader → csvToRows → Api preview. Assertions run on the normalized rows
 * handed to POST /api/transactions/import/preview.
 *
 * Run: node tests/js/import-split-columns.test.js
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..', '..');

let failures = 0;
function assert(cond, msg) {
	if (!cond) {
		failures += 1;
		process.stderr.write('FAIL: ' + msg + '\n');
	}
}
function assertRow(rows, idx, direction, amount, msg) {
	const row = rows[idx];
	assert(row && row.direction === direction, msg + ' — row ' + idx + ' direction (got ' + (row && row.direction) + ')');
	assert(row && row.amount === amount, msg + ' — row ' + idx + ' amount (got ' + (row && row.amount) + ')');
}

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
	};
	return el;
}

function buildSandbox(categories) {
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

	const state = { lastPost: null, announced: [], apiErrors: [] };

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
		FileReader: function FileReader() {
			this.readAsArrayBuffer = (file) => {
				this.result = file._bytes;
				Promise.resolve().then(() => this.onload && this.onload());
			};
		},
		Blob: function Blob() {},
		URL: { createObjectURL: () => 'blob:test', revokeObjectURL() {} },
		location: { href: '', reload() {} },
	};

	sandbox.document = {
		readyState: 'complete',
		documentElement: { lang: 'en', getAttribute: () => 'en' },
		getElementById: (id) => (id === 'app-content' ? { getAttribute: () => 'en' } : null),
		querySelector: (sel) => selectors[sel] || null,
		querySelectorAll: () => [],
		createElement: (tag) => domEl(tag),
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
		post: async (url, body) => {
			state.lastPost = { url, body };
			if (url.includes('/import/preview')) {
				return { preview: { validRows: (body.rows || []).length, invalidRows: 0, skippedRows: 0 } };
			}
			return { result: { createdCount: (body.rows || []).length, errorCount: 0 } };
		},
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

	const src = [
		'js/common/bootstrap.js',
		'js/common/money.js',
		'js/common/dates.js',
		'js/import.js',
	].map((f) => fs.readFileSync(path.join(ROOT, f), 'utf8')).join('\n;\n');
	vm.runInNewContext(src, sandbox);

	let validateHandler = null;
	selectors['[data-bc-import-validate]'].addEventListener = (type, fn) => {
		if (type === 'click') validateHandler = fn;
	};

	const setCsv = async (csv) => {
		state.lastPost = null;
		state.announced = [];
		selectors['[data-bc-import-status]'].textContent = '';
		selectors['[data-bc-import-file]'].files = [{
			name: 'bank.csv',
			_bytes: new TextEncoder().encode(csv).buffer,
		}];
		if (!validateHandler) throw new Error('validate handler not wired');
		await validateHandler();
		// FileReader resolves on a microtask inside the handler's await chain.
		for (let i = 0; i < 10; i += 1) await Promise.resolve();
		return {
			rows: state.lastPost && state.lastPost.body ? state.lastPost.body.rows : null,
			status: selectors['[data-bc-import-status]'].textContent,
			announced: state.announced,
		};
	};

	return { sandbox, selectors, setCsv };
}

const CATEGORIES = [
	{ id: 10, name: 'Groceries', type: 'expense', isActive: true },
	{ id: 20, name: 'Salary', type: 'income', isActive: true },
];

async function main() {
	const env = buildSandbox(CATEGORIES);
	// Let onReady(boot) fire (Promise.resolve().then(run)) and pageInit finish.
	for (let i = 0; i < 20; i += 1) await Promise.resolve();

	// --- Regression: signed single amount column (pre-existing behaviour) ---
	{
		const r = await env.setCsv('date,title,amount\n2026-01-05,Groceries,-42.50\n2026-01-06,Salary,2500.00\n');
		assert(r.rows && r.rows.length === 2, 'single amount column: two rows parsed');
		assertRow(r.rows, 0, 'expense', '42.50', 'single amount column');
		assertRow(r.rows, 1, 'income', '2500.00', 'single amount column');
	}

	// --- Regression: keyword direction column keeps working ---
	{
		const r = await env.setCsv('date,title,direction,amount\n2026-01-05,Groceries,expense,42.50\n2026-01-06,Salary,income,2500.00\n');
		assertRow(r.rows, 0, 'expense', '42.50', 'direction column');
		assertRow(r.rows, 1, 'income', '2500.00', 'direction column');
	}

	// --- Regression: a *keyword* column named "Debit" stays a direction column ---
	{
		const r = await env.setCsv('date,title,debit,amount\n2026-01-05,Groceries,debit,42.50\n2026-01-06,Salary,credit,2500.00\n');
		assertRow(r.rows, 0, 'expense', '42.50', 'keyword Debit column');
		assertRow(r.rows, 1, 'income', '2500.00', 'keyword Debit column');
	}

	// --- Issue #23: separate Expenses/Income columns ---
	{
		const r = await env.setCsv('Date,Description,Expenses,Income\n2026-01-05,Groceries,45.00,\n2026-01-06,Salary,,1200.00\n');
		assert(r.rows && r.rows.length === 2, 'split Expenses/Income: two rows parsed');
		assertRow(r.rows, 0, 'expense', '45.00', 'split Expenses/Income');
		assertRow(r.rows, 1, 'income', '1200.00', 'split Expenses/Income');
	}

	// --- Issue #23: minus sign on the expense column ---
	{
		const r = await env.setCsv('Date;Description;Expenses;Income\n2026-01-05;Groceries;-45,00;\n');
		assertRow(r.rows, 0, 'expense', '45,00', 'minus sign on expense column');
	}

	// --- Issue #23: ambiguous Debit/Credit *amount* columns (numeric data) ---
	{
		const r = await env.setCsv('Date,Text,Debit,Credit\n2026-01-05,Groceries,45.00,\n2026-01-06,Salary,,1200.00\n');
		assertRow(r.rows, 0, 'expense', '45.00', 'numeric Debit/Credit');
		assertRow(r.rows, 1, 'income', '1200.00', 'numeric Debit/Credit');
	}

	// --- German bank style: Soll/Haben amount columns + dash placeholder ---
	{
		const r = await env.setCsv('Buchungsdatum;Verwendungszweck;Soll;Haben\n2026-01-05;Einkauf;45,00;\n2026-01-06;Gehalt;-;1200,00\n');
		assertRow(r.rows, 0, 'expense', '45,00', 'Soll/Haben');
		assertRow(r.rows, 1, 'income', '1200,00', 'Soll/Haben with dash placeholder');
	}

	// --- Balance column is ignored (issue #23 second part) ---
	{
		const r = await env.setCsv('date,title,amount,balance\n2026-01-05,Groceries,-42.50,957.50\n');
		assertRow(r.rows, 0, 'expense', '42.50', 'balance column ignored');
	}

	// --- Both split cells filled → row error ---
	{
		const r = await env.setCsv('Date,Description,Expenses,Income\n2026-01-05,Bad,10.00,20.00\n');
		assert(r.rows === null, 'both split cells filled: no preview posted');
		assert(/both income and expense columns/.test(r.status), 'both-filled error shown (got: ' + r.status + ')');
	}

	// --- amount column + split cell filled in the same row → row error ---
	{
		const r = await env.setCsv('date,title,amount,income\n2026-01-05,Bad,10.00,20.00\n');
		assert(r.rows === null, 'amount+split filled: no preview posted');
		assert(/fill either the amount column or the income\/expense columns/.test(r.status), 'amount+split error shown (got: ' + r.status + ')');
	}

	// --- direction column contradicts split column → row error ---
	{
		const r = await env.setCsv('date,title,direction,expenses\n2026-01-05,Bad,income,10.00\n');
		assert(r.rows === null, 'direction conflict: no preview posted');
		assert(/does not match the income\/expense column/.test(r.status), 'conflict error shown (got: ' + r.status + ')');
	}

	// --- Mixed file: split columns exist but a row only fills `amount` → sign inference still works ---
	{
		const r = await env.setCsv('date,title,amount,expenses,income\n2026-01-05,Groceries,-42.50,,\n');
		assertRow(r.rows, 0, 'expense', '42.50', 'amount fallback in split-column file');
	}

	// --- Entirely empty sided column must take the *direction* slot, not an amount slot ---
	// (mutation-killer: columnLooksLikeAmounts must return false when every cell is blank/dash.
	//  With the mutation, empty `debit` becomes expenseAmount and `credit` becomes the direction
	//  column — its 'credit' keyword then overrides sign inference: -42.50 would import as income.)
	{
		const r = await env.setCsv('date,title,amount,debit,credit\n2026-01-05,Groceries,-42.50,,credit\n2026-01-06,Salary,2500.00,-,debit\n');
		assert(r.rows && r.rows.length === 2, 'empty sided column: rows parsed (status: ' + r.status + ')');
		if (r.rows) {
			assertRow(r.rows, 0, 'expense', '42.50', 'empty sided column takes direction slot');
			assertRow(r.rows, 1, 'income', '2500.00', 'empty sided column takes direction slot');
		}
	}

	// --- Row with no amount anywhere → clear error ---
	{
		const r = await env.setCsv('Date,Description,Expenses,Income\n2026-01-05,Bad,,\n');
		assert(r.rows === null, 'empty split row: no preview posted');
		assert(/amount is required/.test(r.status), 'empty-row error shown (got: ' + r.status + ')');
	}

	// --- Missing everything amount-like → header error mentions split columns ---
	{
		const r = await env.setCsv('date,title\n2026-01-05,Groceries\n');
		assert(r.rows === null, 'no amount column: no preview posted');
		assert(/income and expense columns/.test(r.status), 'missing-amount error mentions split columns (got: ' + r.status + ')');
	}

	if (failures === 0) {
		process.stdout.write('import-split-columns: OK\n');
	} else {
		process.stderr.write(failures + ' failure(s)\n');
		process.exitCode = 1;
	}
}

main().catch((err) => {
	process.stderr.write('import-split-columns crashed: ' + (err && err.stack || err) + '\n');
	process.exitCode = 1;
});
