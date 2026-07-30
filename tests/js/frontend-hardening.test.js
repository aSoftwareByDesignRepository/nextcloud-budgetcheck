#!/usr/bin/env node
/**
 * Static + behavioural hardening gate for BudgetCheck frontend.
 *
 * Catches the same *class* of bugs as the dashboard "reading 'workspace'" crash:
 *  - Unbound method extraction of withWorkspace
 *  - Eager Dates.currentYearMonth() on a possibly-undefined Dates capture
 *  - Unsafe Ws.urls.<screen> reads before withWorkspace / navigation
 *  - Workspace-create navigation without a dashboard URL fallback
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..', '..');
const JS_DIR = path.join(ROOT, 'js');

let failures = 0;
function assert(cond, msg) {
	if (!cond) {
		failures += 1;
		process.stderr.write('FAIL: ' + msg + '\n');
	}
}

function listJs(dir) {
	const out = [];
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		const full = path.join(dir, entry.name);
		if (entry.isDirectory()) out.push(...listJs(full));
		else if (entry.name.endsWith('.js')) out.push(full);
	}
	return out;
}

function rel(file) {
	return path.relative(ROOT, file);
}

// --- Static scans ---
{
	const files = listJs(JS_DIR);
	for (const file of files) {
		const text = fs.readFileSync(file, 'utf8');
		const lines = text.split(/\r?\n/);

		// Forbidden: extract withWorkspace into a bare const (the original crash shape).
		assert(
			!/const\s+withWorkspace\s*=\s*[\w.]+\.withWorkspace/.test(text),
			rel(file) + ': must not extract .withWorkspace into a bare const'
		);

		// Forbidden: this.workspace inside withWorkspace body (workspace.js only — check globally for method form).
		if (file.endsWith(path.join('common', 'workspace.js')) || file.endsWith('common/workspace.js')) {
			const block = text.match(/withWorkspace\s*\(\s*url\s*\)\s*\{[\s\S]*?\n\t\t\},/);
			assert(!!block, 'workspace.js withWorkspace body present');
			if (block) {
				assert(!/\bthis\.workspace\b/.test(block[0]), 'withWorkspace must not use this.workspace');
				assert(/\bctx\.workspace\b/.test(block[0]), 'withWorkspace must use ctx.workspace');
			}
			assert(
				/urls\.dashboard[\s\S]{0,120}\/apps\/budgetcheck\/dashboard/.test(text)
				|| /dashboard'\)\s*\?\s*String\(ctx\.urls\.dashboard\)[\s\S]{0,80}\/apps\/budgetcheck\/dashboard/.test(text)
				|| /ctx\.urls && ctx\.urls\.dashboard[\s\S]{0,200}\/apps\/budgetcheck\/dashboard/.test(text),
				'workspace create must fall back when urls.dashboard is missing'
			);
		}

		// Eager Dates.currentYearMonth() at module top-level state init is fragile.
		// Allow calls inside functions; flag the classic crash pattern:
		//   const state = { yearMonth: Dates.currentYearMonth()
		if (/yearMonth:\s*Dates\.currentYearMonth\s*\(/.test(text)) {
			assert(false, rel(file) + ': use initialYearMonth()/currentYearMonthSafe() instead of bare Dates.currentYearMonth() in state init');
		}

		// Unsafe: Ws.urls.monthly / .transactions without guarding urls object in withWorkspace call sites.
		// Allow patterns that already null-check: Ws.urls && Ws.urls.X  or  (Ws.urls || {}).X  or urls.X after local urls=
		for (let i = 0; i < lines.length; i += 1) {
			const line = lines[i].replace(/\/\/.*$/, '');
			if (/withWorkspace\s*\(\s*Ws\.urls\.(monthly|transactions|yearly|dashboard|settings)\s*\)/.test(line)) {
				// Direct Ws.urls.X inside withWorkspace — only OK if same line has || fallback to '#'
				if (!/\|\|/.test(line)) {
					assert(false, rel(file) + ':' + (i + 1) + ': withWorkspace(Ws.urls.*) needs a null-safe urls guard or fallback');
				}
			}
		}
	}

	const dash = fs.readFileSync(path.join(JS_DIR, 'dashboard.js'), 'utf8');
	assert(/function initialYearMonth\s*\(/.test(dash), 'dashboard defines initialYearMonth');
	assert(/!Ws\.urls \|\| !Ws\.urls\.transactions|!Ws \|\| !Ws\.urls \|\| !Ws\.urls\.transactions/.test(dash), 'dashboard guards Ws.urls before transactions access');

	const monthly = fs.readFileSync(path.join(JS_DIR, 'monthly.js'), 'utf8');
	assert(/function initialYearMonth\s*\(/.test(monthly), 'monthly defines initialYearMonth');

	const budgets = fs.readFileSync(path.join(JS_DIR, 'budgets.js'), 'utf8');
	assert(/function initialYearMonth\s*\(/.test(budgets), 'budgets defines initialYearMonth');

	const yearly = fs.readFileSync(path.join(JS_DIR, 'yearly.js'), 'utf8');
	assert(/Ws && Ws\.urls && Ws\.urls\.monthly/.test(yearly), 'yearly guards monthly URL');

	const imp = fs.readFileSync(path.join(JS_DIR, 'import.js'), 'utf8');
	assert(/Ws\.urls && Ws\.urls\.transactions/.test(imp), 'import guards transactions URL after commit');

	const appCss = fs.readFileSync(path.join(ROOT, 'css', 'app.css'), 'utf8');
	assert(/#app-content\.bc-app\s*\{[\s\S]*?overflow-x:\s*clip/.test(appCss), 'app shell clips horizontal overflow for small viewports');

	// Bootstrap contract: never snapshot window.BudgetCheck* at IIFE top of page scripts.
	const pageScripts = [
		'dashboard.js', 'monthly.js', 'yearly.js', 'period.js', 'budgets.js',
		'transactions.js', 'import.js', 'settings.js', 'workspace-overview.js', 'app-settings.js',
	];
	const iifeTopSnapshot = /^\tconst\s+\w+\s*=\s*window\.BudgetCheck(?:Api|Messaging|Components|Money|Dates|Workspace|Constants|SpecialsView|EntityPicker|CatalogPickers)\s*;/m;
	for (const name of pageScripts) {
		const text = fs.readFileSync(path.join(JS_DIR, name), 'utf8');
		assert(!iifeTopSnapshot.test(text), name + ': must not snapshot window.BudgetCheck* at IIFE top');
		assert(/BudgetCheck\.onReady\s*\(/.test(text), name + ': must boot via BudgetCheck.onReady');
	}

	const pageController = fs.readFileSync(path.join(ROOT, 'lib', 'Controller', 'PageController.php'), 'utf8');
	const scriptBlock = pageController.match(/function registerFrontEndAssets[\s\S]*?\n\t\}/);
	assert(!!scriptBlock, 'PageController registerFrontEndAssets present');
	if (scriptBlock) {
		const firstScript = scriptBlock[0].match(/Util::addScript\([^;]+\)/);
		assert(
			!!firstScript && /common\/bootstrap/.test(firstScript[0]),
			'bootstrap.js must be the first Util::addScript in registerFrontEndAssets'
		);
	}

	const sharedLiveConsumers = [
		'common/household-period-controls.js',
		'common/entity-picker.js',
		'common/catalog-pickers.js',
		'common/attachment-gallery.js',
		'common/transaction-attachments.js',
		'common/transaction-editor.js',
	];
	for (const relPath of sharedLiveConsumers) {
		const text = fs.readFileSync(path.join(JS_DIR, ...relPath.split('/')), 'utf8');
		assert(/BudgetCheck\.live\s*\(/.test(text), relPath + ': shared libs must use BudgetCheck.live()');
		assert(!iifeTopSnapshot.test(text), relPath + ': must not snapshot window.BudgetCheck* at IIFE top');
	}

	const bootstrapSrc = fs.readFileSync(path.join(JS_DIR, 'common', 'bootstrap.js'), 'utf8');
	assert(/function onReady/.test(bootstrapSrc), 'bootstrap exports onReady');
	assert(/function requireDeps|function require\b/.test(bootstrapSrc) || /require:\s*requireDeps/.test(bootstrapSrc), 'bootstrap exports require');
	assert(/function live/.test(bootstrapSrc), 'bootstrap exports live');
	assert(/function define/.test(bootstrapSrc), 'bootstrap exports define');

	// Producers must publish via BudgetCheck.define — never bare window.BudgetCheckX = …
	const producers = [
		['api.js', 'Api'],
		['messaging.js', 'Messaging'],
		['components.js', 'Components'],
		['money.js', 'Money'],
		['dates.js', 'Dates'],
		['workspace.js', 'Workspace'],
		['constants.js', 'Constants'],
		['icons.js', 'Icons'],
		['specials-view.js', 'SpecialsView'],
		['household-period-controls.js', 'HouseholdPeriod'],
		['transaction-editor.js', 'TransactionEditor'],
		['transaction-list.js', 'TransactionList'],
		['transaction-attachments.js', 'TransactionAttachments'],
		['attachment-gallery.js', 'AttachmentGallery'],
		['entity-picker.js', 'EntityPicker'],
		['catalog-pickers.js', 'CatalogPickers'],
	];
	const bareAssign = /window\.BudgetCheck(?:Api|Messaging|Components|Money|Dates|Workspace|Constants|Icons|SpecialsView|HouseholdPeriod|TransactionEditor|TransactionList|TransactionAttachments|AttachmentGallery|EntityPicker|CatalogPickers)\s*=/;
	for (const [file, shortName] of producers) {
		const text = fs.readFileSync(path.join(JS_DIR, 'common', file), 'utf8');
		assert(
			new RegExp("BudgetCheck\\.define\\(\\s*['\"]" + shortName + "['\"]").test(text),
			file + ': must BudgetCheck.define(\'' + shortName + '\')'
		);
		assert(!bareAssign.test(text), file + ': must not bare-assign window.BudgetCheck*');
		assert(
			/bootstrap missing/.test(text),
			file + ': must fail loud when bootstrap is missing'
		);
	}
}

// --- Runtime: Dates safe accessor + withWorkspace url edge cases ---
{
	const appContent = {
		dataset: {
			bcWorkspace: JSON.stringify({ id: 9, name: 'A', type: 'household' }),
			bcWorkspaces: '[]',
			bcUrls: JSON.stringify({}),
			bcCanManage: '1',
			bcCanContribute: '1',
			bcCanAdmin: '0',
			bcLocale: 'en',
			bcHtmlLang: 'en',
			bcTimezone: 'UTC',
			bcPage: 'dashboard',
		},
	};
	const sandbox = {
		console,
		t: (_a, s) => s,
		document: {
			querySelector(sel) { return String(sel).includes('app-content') ? appContent : null; },
			getElementById(id) { return id === 'app-content' ? appContent : null; },
			addEventListener() {},
			documentElement: { lang: 'en' },
		},
		window: null,
		Date,
		Math,
		JSON,
		String,
		Number,
		Array,
		Object,
		encodeURIComponent,
		localStorage: { getItem() { return null; }, setItem() {} },
	};
	sandbox.window = sandbox;
	sandbox.globalThis = sandbox;

	// Producers require BudgetCheck.define — bootstrap must load first.
	vm.runInNewContext(fs.readFileSync(path.join(JS_DIR, 'common', 'bootstrap.js'), 'utf8'), sandbox, { filename: 'bootstrap.js' });
	vm.runInNewContext(fs.readFileSync(path.join(JS_DIR, 'common', 'dates.js'), 'utf8'), sandbox, { filename: 'dates.js' });
	vm.runInNewContext(fs.readFileSync(path.join(JS_DIR, 'common', 'workspace.js'), 'utf8'), sandbox, { filename: 'workspace.js' });

	const D = sandbox.window.BudgetCheck.get('Dates');
	const Ws = sandbox.window.BudgetCheck.get('Workspace');
	assert(!!D && typeof D.currentYearMonthSafe === 'function', 'currentYearMonthSafe exported via define');
	assert(/^\d{4}-(0[1-9]|1[0-2])$/.test(D.currentYearMonthSafe()), 'currentYearMonthSafe returns YYYY-MM');

	// Missing screen URL → empty / passthrough, never "undefined?workspaceId="
	assert(Ws.withWorkspace(undefined) === '', 'withWorkspace(undefined) → empty');
	assert(Ws.withWorkspace(null) === '', 'withWorkspace(null) → empty');
	assert(Ws.withWorkspace(Ws.urls.transactions) === '', 'missing urls.transactions → empty (not undefined…)');

	appContent.dataset.bcUrls = JSON.stringify({ transactions: '/apps/budgetcheck/transactions' });
	assert(
		Ws.withWorkspace(Ws.urls.transactions) === '/apps/budgetcheck/transactions?workspaceId=9',
		'present url appends workspaceId'
	);

	// Unbound extraction still works
	const extracted = Ws.withWorkspace;
	assert(extracted('/x') === '/x?workspaceId=9', 'unbound withWorkspace still scoped');

	// Registry remains authoritative if the window mirror is deleted
	delete sandbox.window.BudgetCheckDates;
	assert(sandbox.window.BudgetCheck.get('Dates') === D, 'Dates registry survives window wipe');
}

// --- Messaging: closed-month copy stays warning-level (no destructive reload) ---
{
	const appContent = {
		dataset: {
			bcWorkspace: JSON.stringify({ id: 1, type: 'household', currencyCode: 'EUR' }),
			bcWorkspaces: '[]',
			bcUrls: '{}',
			bcCanManage: '1',
			bcCanContribute: '1',
			bcCanAdmin: '0',
			bcLocale: 'en',
			bcTimezone: 'UTC',
			bcPage: 'dashboard',
		},
	};
	const announcements = [];
	const sandbox = {
		console,
		t: (_a, s) => s,
		document: {
			querySelector() { return null; },
			getElementById(id) {
				if (id === 'app-content') return appContent;
				if (id === 'bc-live-region' || id === 'bc-alert-region') {
					return { textContent: '' };
				}
				return null;
			},
			createElement(tag) {
				const el = {
					tagName: String(tag).toUpperCase(),
					className: '',
					textContent: '',
					childNodes: [],
					setAttribute() {},
					appendChild(child) { this.childNodes.push(child); return child; },
					addEventListener() {},
					remove() {},
					parentNode: null,
				};
				return el;
			},
			body: {
				appendChild(node) {
					announcements.push(node);
					return node;
				},
				contains() { return true; },
			},
			addEventListener() {},
			documentElement: { lang: 'en' },
		},
		window: { setTimeout(fn) { fn(); }, location: { reload() { announcements.push('reload'); } } },
		Date,
		Math,
		JSON,
		String,
		Number,
		Array,
		Object,
	};
	sandbox.window.document = sandbox.document;
	sandbox.window.BudgetCheck = undefined;
	sandbox.globalThis = sandbox;
	vm.runInNewContext(fs.readFileSync(path.join(JS_DIR, 'common', 'bootstrap.js'), 'utf8'), sandbox, { filename: 'bootstrap.js' });
	vm.runInNewContext(fs.readFileSync(path.join(JS_DIR, 'common', 'messaging.js'), 'utf8'), sandbox, { filename: 'messaging.js' });
	const Msg = sandbox.window.BudgetCheck.get('Messaging');
	assert(!!Msg && typeof Msg.handleApiError === 'function', 'Messaging.handleApiError exported');
	Msg.handleApiError({ status: 400, message: 'This booking falls into a closed month. Reopen the month before adding transactions.' });
	assert(!announcements.includes('reload'), 'closed-month errors must not force a full page reload');
	const container = announcements.find((n) => n && typeof n.className === 'string' && n.className.indexOf('bc-toasts') !== -1);
	assert(!!container, 'toast container created for closed-month warning');
	const toast = container && Array.isArray(container.childNodes)
		? container.childNodes.find((n) => n && typeof n.className === 'string' && n.className.indexOf('bc-toast--warning') !== -1)
		: null;
	assert(!!toast, 'closed-month errors surface as warning toasts');
}

if (failures > 0) {
	process.stderr.write(failures + ' frontend-hardening test(s) failed\n');
	process.exit(1);
}
process.stdout.write('frontend-hardening tests: OK\n');
