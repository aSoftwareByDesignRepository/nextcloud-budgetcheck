#!/usr/bin/env node
/**
 * Behavioural tests for api.js field-error handling:
 *
 *  - server `error.fields` values are msgids run through t() before they are
 *    rendered inline (same localisation path as the top-level message);
 *  - a `fields.bookingDate` error (the project-window rejection) gains a
 *    remedy link to the workspace "workspace" settings section, opened in a
 *    new tab so pending form state survives;
 *  - payloads without bookingDate get no remedy link.
 *
 * Run: node tests/js/api-field-remedy.test.js
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..', '..');
const SRC = fs.readFileSync(path.join(ROOT, 'js', 'common', 'api.js'), 'utf8');

let failures = 0;
function assert(cond, msg) {
	if (!cond) {
		failures += 1;
		process.stderr.write('FAIL: ' + msg + '\n');
	}
}

const DICT = {
	'Pick a date inside the project period, or extend the project period in workspace settings.': 'DE-hint',
	'Open workspace settings': 'DE-open-settings',
};

function el(tag) {
	return {
		tag,
		children: [],
		attrs: {},
		textContent: '',
		href: '',
		className: '',
		setAttribute(k, v) { this.attrs[k] = v; },
		getAttribute(k) { return Object.prototype.hasOwnProperty.call(this.attrs, k) ? this.attrs[k] : null; },
		appendChild(c) { this.children.push(c); return c; },
		querySelector(sel) { return this.children.find((c) => sel === 'a[data-bc-remedy]' && c.attrs['data-bc-remedy']) || null; },
	};
}

function boot(payload, opts) {
	const errEl = el('p');
	const marked = [];
	const doc = {
		createElement: (tag) => el(tag),
		getElementById: (id) => (id === 'bc-field-error-bookingDate' ? errEl : null),
		querySelector: () => null,
		querySelectorAll: () => [],
	};
	const sandbox = {
		document: doc,
		console,
		URLSearchParams,
		OC: {
			requestToken: 'tok',
			generateUrl: (p) => p,
		},
		t: (appId, s) => (Object.prototype.hasOwnProperty.call(DICT, s) ? DICT[s] : s),
		fetch: async () => ({
			ok: false,
			status: 400,
			headers: { get: () => 'application/json' },
			json: async () => payload,
			text: async () => JSON.stringify(payload),
		}),
		CheckFieldErrors: {
			install() {},
			markValidationFields(fields) { marked.push(fields); },
		},
		BudgetCheckWorkspace: (opts && opts.noWorkspace) ? undefined : {
			urls: { settingsSections: { workspace: '/apps/budgetcheck/settings/workspace' } },
			withWorkspace: (url) => url + '?workspaceId=42',
		},
	};
	sandbox.window = sandbox;
	sandbox.BudgetCheck = {
		_defs: {},
		define(name, api) { this._defs[name] = api; },
	};
	vm.createContext(sandbox);
	vm.runInContext(SRC, sandbox, { filename: 'api.js' });
	return { api: sandbox.BudgetCheck._defs.Api, errEl, marked };
}

const WINDOW_PAYLOAD = {
	ok: false,
	message: 'bookingDate must lie inside the project date window.',
	error: {
		code: 'invalid_input',
		fields: { bookingDate: 'Pick a date inside the project period, or extend the project period in workspace settings.' },
	},
};

async function main() {
	// bookingDate field error: localized + remedy link appended.
	{
		const { api, errEl, marked } = boot(WINDOW_PAYLOAD);
		await api.get('/x').catch(() => {});
		assert(marked.length === 1, 'fields must reach markValidationFields');
		assert(marked[0].bookingDate === 'DE-hint', 'field message must pass through t(), got: ' + marked[0].bookingDate);
		const link = errEl.children.find((c) => c.attrs['data-bc-remedy'] === 'project-window');
		assert(link, 'bookingDate field error must gain a remedy link');
		assert(link && link.href === '/apps/budgetcheck/settings/workspace?workspaceId=42',
			'remedy link must point at the workspace section with workspaceId, got: ' + (link && link.href));
		assert(link && link.attrs.target === '_blank' && link.attrs.rel === 'noopener',
			'remedy link must open in a new tab with noopener');
		assert(link && link.textContent === ' DE-open-settings',
			'remedy link label must be localized, got: ' + (link && JSON.stringify(link.textContent)));
	}

	// No bookingDate field → no remedy link, fields still localized-marked.
	{
		const { api, errEl, marked } = boot({
			ok: false,
			message: 'Other validation.',
			error: { code: 'invalid_input', fields: { amount: 'Amount is required.' } },
		});
		await api.get('/x').catch(() => {});
		assert(marked.length === 1, 'non-bookingDate fields must still be marked');
		assert(errEl.children.length === 0, 'no remedy link without a bookingDate field error');
	}

	// Missing workspace context → link silently skipped, marking still works.
	{
		const { api, errEl, marked } = boot(WINDOW_PAYLOAD, { noWorkspace: true });
		await api.get('/x').catch(() => {});
		assert(marked.length === 1, 'marking must work without BudgetCheckWorkspace');
		assert(errEl.children.length === 0, 'no remedy link without workspace urls');
	}
}

main().then(() => {
	if (failures > 0) {
		process.stderr.write(failures + ' api-field-remedy test(s) failed\n');
		process.exit(1);
	}
	process.stdout.write('api-field-remedy: OK\n');
}).catch((e) => {
	process.stderr.write('api-field-remedy crashed: ' + e.stack + '\n');
	process.exit(1);
});
