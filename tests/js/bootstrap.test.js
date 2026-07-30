#!/usr/bin/env node
/**
 * Unit + integration tests for BudgetCheck.define / require / live / onReady.
 *
 * Proves:
 *  - define() is the producer source of truth (registry survives window wipe)
 *  - redefine / bad values fail loud
 *  - live reads never freeze across later registration
 *  - require() fails loud on missing required deps
 *  - onReady waits for DOMContentLoaded; page-style boot assigns lets after ready
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..', '..');
const BOOTSTRAP = fs.readFileSync(path.join(ROOT, 'js', 'common', 'bootstrap.js'), 'utf8');

let failures = 0;
function assert(cond, msg) {
	if (!cond) {
		failures += 1;
		process.stderr.write('FAIL: ' + msg + '\n');
	}
}

function makeSandbox(readyState) {
	const listeners = [];
	const sandbox = {
		console,
		document: {
			readyState: readyState || 'complete',
			addEventListener(type, fn, opts) {
				listeners.push({ type, fn, opts });
			},
		},
		window: null,
		Promise,
		Object,
		Array,
		Error,
		setTimeout,
		setImmediate,
	};
	sandbox.window = sandbox;
	sandbox.globalThis = sandbox;
	vm.runInNewContext(BOOTSTRAP, sandbox, { filename: 'bootstrap.js' });
	return { sandbox, listeners };
}

function flushMicrotasks() {
	return new Promise((resolve) => setImmediate(resolve));
}

async function main() {
	// --- define is source of truth ---
	{
		const { sandbox } = makeSandbox('complete');
		const BC = sandbox.window.BudgetCheck;
		assert(!!BC && typeof BC.define === 'function', 'BudgetCheck.define published');
		assert(BC.REGISTRY.Api === 'BudgetCheckApi', 'REGISTRY maps Api');

		const api = { get() { return 7; } };
		BC.define('Api', api);
		assert(BC.get('Api') === api, 'get returns defined module');
		assert(sandbox.window.BudgetCheckApi === api, 'define mirrors onto window');
		assert(BC.has('Api') === true, 'has after define');

		// Wipe the window mirror — registry must still answer.
		delete sandbox.window.BudgetCheckApi;
		assert(BC.get('Api') === api, 'get prefers registry after window wipe');
		assert(BC.has('Api') === true, 'has still true after window wipe');

		let redefine = false;
		try {
			BC.define('Api', { get() { return 0; } });
		} catch (e) {
			redefine = /already registered/.test(String(e && e.message));
		}
		assert(redefine, 'redefine with different object throws');

		// Idempotent same reference is allowed
		BC.define('Api', api);
		assert(BC.get('Api') === api, 'redefine same reference is idempotent');

		let badVal = false;
		try {
			BC.define('Money', null);
		} catch (e) {
			badVal = /must be an object or function/.test(String(e && e.message));
		}
		assert(badVal, 'define null throws');

		let unknown = false;
		try {
			BC.define('Nope', {});
		} catch (e) {
			unknown = /unknown module/.test(String(e && e.message));
		}
		assert(unknown, 'define unknown module throws');

		let empty = false;
		try {
			BC.define('', {});
		} catch (e) {
			empty = /non-empty string/.test(String(e && e.message));
		}
		assert(empty, 'define empty name throws');
	}

	// --- legacy window-only fallback still readable (migration safety) ---
	{
		const { sandbox } = makeSandbox('complete');
		const BC = sandbox.window.BudgetCheck;
		sandbox.window.BudgetCheckDates = { currentYearMonthSafe() { return '2099-01'; } };
		assert(BC.get('Dates').currentYearMonthSafe() === '2099-01', 'get falls back to window if never define()d');
	}

	// --- require ---
	{
		const { sandbox } = makeSandbox('complete');
		const BC = sandbox.window.BudgetCheck;
		BC.define('Api', { ok: true });

		let missing = false;
		try {
			BC.require(['Api', 'Workspace']);
		} catch (e) {
			missing = /missing required module\(s\): Workspace/.test(String(e && e.message));
		}
		assert(missing, 'require throws listing missing required');

		const soft = BC.require(['Api', 'Workspace'], { optional: ['Workspace'], strict: true });
		assert(soft.Api && soft.Api.ok === true, 'require returns present deps');
		assert(soft.Workspace === null, 'optional missing → null');

		const nonStrict = BC.require(['Workspace'], { strict: false });
		assert(nonStrict.Workspace === null, 'strict:false returns nulls');

		let badName = false;
		try {
			BC.require(['']);
		} catch (e) {
			badName = /non-empty strings/.test(String(e && e.message));
		}
		assert(badName, 'require rejects empty name');
	}

	// --- live ---
	{
		const { sandbox } = makeSandbox('complete');
		const BC = sandbox.window.BudgetCheck;
		const bag = BC.live(['Api', 'Messaging']);
		assert(bag.Api === null, 'live Api starts null');
		BC.define('Api', { v: 2 });
		assert(bag.Api && bag.Api.v === 2, 'live Api updates after define');
		BC.define('Messaging', { announce() {} });
		assert(typeof bag.Messaging.announce === 'function', 'live Messaging updates');

		const frozen = bag.Api;
		BC.define('Api', bag.Api); // same ref ok
		// Replace via internal path: wipe registry by new sandbox instead —
		// prove destructuring is stale relative to a new define on fresh bag after redefine throw.
		assert(frozen.v === 2, 'destructured snapshot stays stale (why pages use onReady)');
	}

	// --- onReady loading ---
	{
		const { sandbox, listeners } = makeSandbox('loading');
		const BC = sandbox.window.BudgetCheck;
		let ran = 0;
		let got = null;
		BC.onReady((deps) => {
			ran += 1;
			got = deps;
		}, { required: ['Api'], optional: ['Workspace'] });

		assert(ran === 0, 'onReady does not run while document still loading');
		assert(listeners.length === 1 && listeners[0].type === 'DOMContentLoaded', 'registers DOMContentLoaded');

		BC.define('Api', { ready: true });
		listeners[0].fn();
		assert(ran === 1, 'onReady runs on DOMContentLoaded');
		assert(got && got.Api && got.Api.ready === true, 'onReady passes resolved deps');
		assert(got.Workspace === null, 'onReady optional missing is null');
	}

	// --- onReady complete + page boot ---
	{
		const { sandbox } = makeSandbox('complete');
		const BC = sandbox.window.BudgetCheck;
		BC.define('Api', { x: 1 });
		BC.define('Workspace', { workspace: { id: 1 } });

		let ran = false;
		BC.onReady((deps) => {
			ran = true;
			assert(deps.Api.x === 1, 'complete-path Api');
			assert(deps.Workspace.workspace.id === 1, 'complete-path Workspace');
		}, ['Api', 'Workspace']);

		assert(ran === false, 'onReady defers to microtask when already complete');
		await flushMicrotasks();
		assert(ran === true, 'onReady microtask fires');

		let badCb = false;
		try {
			BC.onReady(null, ['Api']);
		} catch (e) {
			badCb = /callback must be a function/.test(String(e && e.message));
		}
		assert(badCb, 'onReady rejects non-function');

		let Api;
		let Ws;
		function boot(deps) {
			Api = deps.Api;
			Ws = deps.Workspace;
		}
		// Fresh names already defined — boot receives them
		BC.onReady(boot, { required: ['Api', 'Workspace'] });
		await flushMicrotasks();
		assert(Api && Api.x === 1 && Ws.workspace.id === 1, 'page-style boot assigns lets after ready');
	}

	// --- onReady missing required ---
	{
		const { sandbox, listeners } = makeSandbox('loading');
		const BC = sandbox.window.BudgetCheck;
		BC.onReady(() => {}, { required: ['Dates'] });
		let threw = false;
		try {
			listeners[0].fn();
		} catch (e) {
			threw = /missing required module\(s\): Dates/.test(String(e && e.message));
		}
		assert(threw, 'onReady throws when required module missing at DOMContentLoaded');
	}

	// --- producer integration: constants.js define path ---
	{
		const { sandbox } = makeSandbox('complete');
		sandbox.t = (_a, s) => s;
		vm.runInNewContext(
			fs.readFileSync(path.join(ROOT, 'js', 'common', 'constants.js'), 'utf8'),
			sandbox,
			{ filename: 'constants.js' }
		);
		const C = sandbox.window.BudgetCheck.get('Constants');
		assert(!!C && C.GROUP_INTERNAL_UNCATEGORIZED === '_bc_internal_uncategorized', 'Constants defined via bootstrap');
		assert(C.isInternalUncategorizedGroupKey('_bc_internal_uncategorized') === true, 'Constants method works');
		delete sandbox.window.BudgetCheckConstants;
		assert(sandbox.window.BudgetCheck.get('Constants') === C, 'Constants registry survives window wipe');
	}

	if (failures > 0) {
		process.stderr.write(failures + ' bootstrap test(s) failed\n');
		process.exit(1);
	}
	process.stdout.write('bootstrap tests: OK\n');
}

main().catch((err) => {
	process.stderr.write(String(err && err.stack ? err.stack : err) + '\n');
	process.exit(1);
});
