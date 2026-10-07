#!/usr/bin/env node
/**
 * Behavioural tests for BudgetCheck messaging.js handleApiError().
 *
 * Guards the actionable-error contract:
 *  - HTTP 400 'invalid_input' surfaces the authored server message (via t(),
 *    so msgids shared with the client localize) instead of a dead-end
 *    generic toast.
 *  - HTTP 413 maps to the upload-limit copy (proxy/WAF dropped the body
 *    before PHP could parse it).
 *  - Unknown 4xx and 5xx stay on the generic localized fallbacks.
 *
 * Run: node tests/js/messaging-handle-api-error.test.js
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..', '..');
const SRC = fs.readFileSync(path.join(ROOT, 'js', 'common', 'messaging.js'), 'utf8');

let failures = 0;
function assert(cond, msg) {
	if (!cond) {
		failures += 1;
		process.stderr.write('FAIL: ' + msg + '\n');
	}
}

const DICT = {
	'The file exceeds the maximum upload size configured on this server.': 'DE-limit',
	'The server could not complete the request. Please try again.': 'DE-500',
	'The action could not be completed. Please try again.': 'DE-generic',
	'Your session expired. Please reload and sign in again.': 'DE-401',
	'You are not authorized to perform that action.': 'DE-403',
	'Too many requests. Please wait and retry.': 'DE-429',
};

function el() {
	return {
		children: [],
		attrs: {},
		style: {},
		setAttribute(k, v) { this.attrs[k] = v; },
		getAttribute(k) { return Object.prototype.hasOwnProperty.call(this.attrs, k) ? this.attrs[k] : null; },
		removeAttribute(k) { delete this.attrs[k]; },
		appendChild(c) { this.children.push(c); c.parentNode = this; return c; },
		removeChild(c) { const i = this.children.indexOf(c); if (i >= 0) this.children.splice(i, 1); },
		addEventListener() {},
		querySelectorAll() { return []; },
		remove() {},
		focus() {},
		textContent: '',
	};
}

function boot() {
	const regions = { 'bc-live-region': el(), 'bc-alert-region': el() };
	const doc = {
		body: el(),
		createElement: () => el(),
		getElementById: (id) => regions[id] || null,
		querySelector: () => null,
		querySelectorAll: () => [],
	};
	doc.body.contains = () => false;

	const sandbox = {
		document: doc,
		console: { warn() {}, error() {}, log() {} },
		t: (appId, s) => Object.prototype.hasOwnProperty.call(DICT, s) ? DICT[s] : s,
	};
	sandbox.window = sandbox;
	sandbox.setTimeout = (fn) => { fn(); return 1; };
	sandbox.clearTimeout = () => {};
	sandbox.BudgetCheck = {
		_defs: {},
		define(name, api) { this._defs[name] = api; },
	};
	vm.createContext(sandbox);
	vm.runInContext(SRC, sandbox, { filename: 'messaging.js' });
	return {
		messaging: sandbox.BudgetCheck._defs.Messaging,
		alert: regions['bc-alert-region'],
		polite: regions['bc-live-region'],
	};
}

// 400 invalid_input announces the authored server message — localized when
// the string is a known msgid, English otherwise, but never swallowed.
{
	const { messaging, alert } = boot();
	messaging.handleApiError({
		status: 400,
		code: 'invalid_input',
		message: 'The file exceeds the maximum upload size configured on this server.',
	});
	assert(alert.textContent === 'DE-limit', 'invalid_input must surface the (localized) server message, got: ' + alert.textContent);
}

{
	const { messaging, alert } = boot();
	messaging.handleApiError({
		status: 400,
		code: 'invalid_input',
		message: 'bookingDate must lie inside the project date window.',
	});
	assert(
		alert.textContent === 'bookingDate must lie inside the project date window.',
		'unknown invalid_input message must pass through verbatim, got: ' + alert.textContent
	);
}

// 413 — web server dropped the body before PHP; map to upload-limit copy.
{
	const { messaging, alert } = boot();
	messaging.handleApiError({ status: 413, message: 'Request failed.' });
	assert(alert.textContent === 'DE-limit', '413 must map to upload-limit copy, got: ' + alert.textContent);
}

// Unmapped statuses keep the deliberate generic copy.
{
	const { messaging, alert } = boot();
	messaging.handleApiError({ status: 500, message: 'stack trace guts' });
	assert(alert.textContent === 'DE-500', '5xx must stay on server-error copy, got: ' + alert.textContent);
}

{
	const { messaging, alert } = boot();
	messaging.handleApiError({ status: 404, code: 'NOT_FOUND', message: 'internal detail' });
	assert(alert.textContent === 'DE-generic', 'unmapped 4xx must stay generic, got: ' + alert.textContent);
}

// A 400 without the invalid_input code stays generic.
{
	const { messaging, alert } = boot();
	messaging.handleApiError({ status: 400, code: 'something_else', message: 'raw' });
	assert(alert.textContent === 'DE-generic', 'non-invalid_input 400 must stay generic, got: ' + alert.textContent);
}

// Existing mappings keep precedence over the new branches.
{
	const { messaging, alert } = boot();
	messaging.handleApiError({ status: 401, message: 'raw' });
	assert(alert.textContent === 'DE-401', '401 must keep session-expired copy, got: ' + alert.textContent);
}

{
	const { messaging, alert } = boot();
	messaging.handleApiError({ status: 403, code: 'access_denied', message: 'raw' });
	assert(alert.textContent === 'DE-403', '403 must keep not-authorized copy, got: ' + alert.textContent);
}

if (failures > 0) {
	process.stderr.write(failures + ' messaging-handle-api-error test(s) failed\n');
	process.exit(1);
}
process.stdout.write('messaging-handle-api-error: OK\n');
