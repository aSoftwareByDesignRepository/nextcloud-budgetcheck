#!/usr/bin/env node
/**
 * Unit + mutation-style checks for BudgetCheckWorkspace.withWorkspace.
 *
 * Regression: dashboard warnings extracted `workspace.withWorkspace` and called it
 * unbound. In strict mode `this` is undefined → TypeError reading `.workspace`,
 * which wiped a healthy summary behind "Could not load the summary."
 *
 * These tests prove:
 *  1. Method-style calls append workspaceId.
 *  2. Extracted/unbound calls still work (closure over ctx, not `this`).
 *  3. Missing/invalid workspace is a safe passthrough (no throw).
 *  4. Source no longer relies on `this.workspace` inside withWorkspace.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..', '..');
const WS_FILE = path.join(ROOT, 'js', 'common', 'workspace.js');
const COMPONENTS_FILE = path.join(ROOT, 'js', 'common', 'components.js');
const DASHBOARD_FILE = path.join(ROOT, 'js', 'dashboard.js');
const MONTHLY_FILE = path.join(ROOT, 'js', 'monthly.js');
const PERIOD_FILE = path.join(ROOT, 'js', 'period.js');

let failures = 0;
function assert(cond, msg) {
	if (!cond) {
		failures += 1;
		process.stderr.write('FAIL: ' + msg + '\n');
	}
}

function loadWorkspace(workspaceJson) {
	const appContent = {
		dataset: {
			bcWorkspace: workspaceJson,
			bcWorkspaces: '[]',
			bcUrls: JSON.stringify({
				transactions: '/apps/budgetcheck/transactions',
				monthly: '/apps/budgetcheck/monthly',
				dashboard: '/apps/budgetcheck/dashboard',
			}),
			bcCanManage: '1',
			bcCanContribute: '1',
			bcCanAdmin: '0',
			bcLocale: 'en',
			bcHtmlLang: 'en',
			bcTimezone: 'Europe/Berlin',
			bcPage: 'dashboard',
		},
	};

	const sandbox = {
		console,
		t: (_app, s) => s,
		document: {
			querySelector(sel) {
				if (String(sel).includes('app-content') || String(sel).includes('[data-bc-scope-month]')) {
					return String(sel).includes('scope-month') ? null : appContent;
				}
				return null;
			},
			getElementById(id) {
				return id === 'app-content' ? appContent : null;
			},
			addEventListener() {},
		},
		window: null,
		localStorage: {
			getItem() { return null; },
			setItem() {},
		},
	};
	sandbox.window = sandbox;
	sandbox.globalThis = sandbox;
	sandbox.window.localStorage = sandbox.localStorage;

	vm.runInNewContext(fs.readFileSync(path.join(ROOT, 'js', 'common', 'bootstrap.js'), 'utf8'), sandbox, { filename: 'bootstrap.js' });
	vm.runInNewContext(fs.readFileSync(WS_FILE, 'utf8'), sandbox, { filename: 'workspace.js' });
	return { ctx: sandbox.window.BudgetCheck.get('Workspace'), appContent, sandbox };
}

// --- Runtime behaviour ---
{
	const { ctx } = loadWorkspace(JSON.stringify({ id: 5, name: 'test', type: 'household' }));
	assert(!!ctx, 'BudgetCheckWorkspace is registered');
	assert(typeof ctx.withWorkspace === 'function', 'withWorkspace is a function');

	const bound = ctx.withWorkspace('/apps/budgetcheck/transactions');
	assert(
		bound === '/apps/budgetcheck/transactions?workspaceId=5',
		'method call appends workspaceId on path without query'
	);

	const withQuery = ctx.withWorkspace('/apps/budgetcheck/monthly?x=1');
	assert(
		withQuery === '/apps/budgetcheck/monthly?x=1&workspaceId=5',
		'method call appends workspaceId with &'
	);

	// Exact production bug shape: extract then invoke unbound.
	const extracted = ctx.withWorkspace;
	let unboundHref = null;
	let threw = null;
	try {
		unboundHref = extracted('/apps/budgetcheck/transactions');
	} catch (err) {
		threw = err;
	}
	assert(threw === null, 'unbound withWorkspace must not throw (was: ' + (threw && threw.message) + ')');
	assert(
		unboundHref === '/apps/budgetcheck/transactions?workspaceId=5',
		'unbound withWorkspace still resolves workspaceId'
	);

	assert(extracted('') === '', 'empty url passthrough');
	const roleValue = ctx.role();
	assert(roleValue === null || roleValue === undefined || typeof roleValue === 'string', 'role() is safe');
}

{
	const { ctx, appContent } = loadWorkspace('null');
	assert(ctx.workspace === null, 'null workspace parses to null');
	assert(ctx.withWorkspace('/apps/budgetcheck/x') === '/apps/budgetcheck/x', 'null workspace passthrough');
	appContent.dataset.bcWorkspace = '';
	assert(ctx.withWorkspace('/y') === '/y', 'empty dataset passthrough');
	appContent.dataset.bcWorkspace = JSON.stringify({ name: 'no-id' });
	assert(ctx.withWorkspace('/z') === '/z', 'missing id passthrough');
}

// --- Source / mutation gates ---
{
	const wsSrc = fs.readFileSync(WS_FILE, 'utf8');
	const withBlock = wsSrc.match(/withWorkspace\s*\(\s*url\s*\)\s*\{[\s\S]*?\n\t\t\},/);
	assert(!!withBlock, 'withWorkspace method body found');
	if (withBlock) {
		assert(!/\bthis\.workspace\b/.test(withBlock[0]), 'withWorkspace must not read this.workspace');
		assert(/\bctx\.workspace\b/.test(withBlock[0]), 'withWorkspace must read ctx.workspace');
	}

	const componentsSrc = fs.readFileSync(COMPONENTS_FILE, 'utf8');
	assert(
		!/const\s+withWorkspace\s*=\s*workspace\s*&&[\s\S]{0,120}?workspace\.withWorkspace/.test(componentsSrc),
		'components must not extract workspace.withWorkspace into a bare const'
	);
	assert(
		/workspace\.withWorkspace\s*\(/.test(componentsSrc),
		'components must call workspace.withWorkspace as a method'
	);

	const dashboardSrc = fs.readFileSync(DASHBOARD_FILE, 'utf8');
	assert(
		/Warnings are recovery UX/.test(dashboardSrc) || /never let a link-building bug wipe/.test(dashboardSrc),
		'dashboard isolates warning rendering from summary load'
	);
	assert(
		/renderWarnings\(warningsSection[\s\S]{0,80}?catch\s*\(\s*warnErr/.test(dashboardSrc)
		|| /try\s*\{\s*renderWarnings\(warningsSection[\s\S]*?catch\s*\(\s*warnErr/.test(dashboardSrc),
		'dashboard wraps renderWarnings in its own try/catch'
	);

	const monthlySrc = fs.readFileSync(MONTHLY_FILE, 'utf8');
	assert(/catch\s*\(\s*warnErr/.test(monthlySrc), 'monthly isolates warning rendering');
	const periodSrc = fs.readFileSync(PERIOD_FILE, 'utf8');
	assert(/catch\s*\(\s*warnErr/.test(periodSrc), 'period isolates warning rendering');
}

if (failures > 0) {
	process.stderr.write(failures + ' workspace-withWorkspace test(s) failed\n');
	process.exit(1);
}
process.stdout.write('workspace-withWorkspace tests: OK\n');
