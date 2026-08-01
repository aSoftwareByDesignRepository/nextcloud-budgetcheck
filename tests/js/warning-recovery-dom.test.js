#!/usr/bin/env node
/**
 * DOM unit test: warning recovery Open links stay scoped to the workspace.
 *
 * Uses a minimal DOM shim (no jsdom) so createElement/textContent/appendChild
 * behave enough like the browser for components.js recovery rendering.
 */
'use strict';

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

function El(tag) {
	this.tagName = String(tag || 'div').toUpperCase();
	this.children = [];
	this.attributes = {};
	this.className = '';
	this.textContent = '';
	this.hidden = false;
	this.parentNode = null;
	this.dataset = {};
	this.style = {};
}
El.prototype.setAttribute = function (k, v) {
	this.attributes[k] = String(v);
	if (k === 'class') this.className = String(v);
	if (k === 'href') this.href = String(v);
};
El.prototype.getAttribute = function (k) {
	if (k === 'href' && this.href) return this.href;
	return Object.prototype.hasOwnProperty.call(this.attributes, k) ? this.attributes[k] : null;
};
El.prototype.removeAttribute = function (k) {
	delete this.attributes[k];
	if (k === 'href') delete this.href;
};
El.prototype.addEventListener = function () {};
El.prototype.appendChild = function (child) {
	if (child == null) return child;
	if (typeof child === 'string' || typeof child === 'number') {
		this.textContent += String(child);
		return child;
	}
	child.parentNode = this;
	this.children.push(child);
	if (child.nodeType === 3) {
		this.textContent += child.textContent;
	}
	return child;
};
El.prototype.replaceChildren = function (...nodes) {
	this.children = [];
	this.textContent = '';
	nodes.forEach((n) => this.appendChild(n));
};
El.prototype.querySelector = function () { return null; };
El.prototype.querySelectorAll = function () { return []; };
El.prototype.cloneNode = function () {
	const n = new El(this.tagName);
	n.className = this.className;
	n.textContent = this.textContent;
	n.attributes = { ...this.attributes };
	n.href = this.href;
	return n;
};

function makeDocument(appContent) {
	const document = {
		body: new El('body'),
		activeElement: null,
		createElement(tag) { return new El(tag); },
		createTextNode(text) {
			return { nodeType: 3, textContent: String(text), data: String(text) };
		},
		createDocumentFragment() {
			const f = new El('fragment');
			f.nodeType = 11;
			return f;
		},
		querySelector(sel) {
			if (String(sel).includes('app-content')) return appContent;
			return null;
		},
		getElementById(id) {
			return id === 'app-content' ? appContent : null;
		},
		addEventListener() {},
	};
	return document;
}

function collectHrefs(node, out) {
	if (!node) return;
	if (node.tagName === 'A') {
		const href = node.href || (node.attributes && node.attributes.href) || null;
		if (href) out.push(href);
	}
	(node.children || []).forEach((c) => collectHrefs(c, out));
}

{
	try {
		const appContent = new El('div');
		appContent.id = 'app-content';
		appContent.className = 'bc-app';
		appContent.dataset = {
			bcWorkspace: JSON.stringify({ id: 42, name: 'Household', type: 'household' }),
			bcWorkspaces: '[]',
			bcUrls: JSON.stringify({
				transactions: '/apps/budgetcheck/transactions',
				monthly: '/apps/budgetcheck/monthly',
				settings: '/apps/budgetcheck/settings',
			}),
			bcCanManage: '1',
			bcCanContribute: '1',
			bcCanAdmin: '0',
			bcLocale: 'en',
			bcHtmlLang: 'en',
			bcTimezone: 'UTC',
			bcPage: 'dashboard',
		};

		const document = makeDocument(appContent);
		const window = {
			document,
			localStorage: { getItem() { return null; }, setItem() {} },
			t: (_a, s) => s,
		};
		window.window = window;

		const sandbox = {
			window,
			document,
			console,
			t: (_app, s) => s,
			CSS: { escape: (v) => String(v).replace(/["\\]/g, '\\$&') },
			Math,
			JSON,
			String,
			Number,
			Array,
			Object,
			parseInt,
			encodeURIComponent,
		};
		sandbox.globalThis = sandbox;

		vm.runInNewContext(fs.readFileSync(path.join(ROOT, 'js/common/bootstrap.js'), 'utf8'), sandbox, { filename: 'bootstrap.js' });
		vm.runInNewContext(fs.readFileSync(path.join(ROOT, 'js/common/workspace.js'), 'utf8'), sandbox, { filename: 'workspace.js' });
		vm.runInNewContext(fs.readFileSync(path.join(ROOT, 'js/common/components.js'), 'utf8'), sandbox, { filename: 'components.js' });

		const C = sandbox.window.BudgetCheck.get('Components');
		const Ws = sandbox.window.BudgetCheck.get('Workspace');
		assert(!!C && typeof C.renderWarningItem === 'function', 'renderWarningItem exported');
		assert(!!Ws, 'workspace ctx available');

		const warning = {
			severity: 'warning',
			title: 'Uncategorized expenses',
			code: 'uncategorized_expense',
			message: '2 uncategorized expenses remain without a category.',
			recovery: {
				screen: 'transactions',
				params: { filter: 'uncategorized', yearMonth: '2026-07' },
			},
		};

		const item = C.renderWarningItem(warning, Ws);
		const hrefs = [];
		collectHrefs(item, hrefs);
		assert(hrefs.length === 1, 'recovery Open link rendered');
		assert(/workspaceId=42/.test(hrefs[0]), 'recovery href includes workspaceId');
		assert(/filter=uncategorized/.test(hrefs[0]), 'recovery href includes filter param');
		assert(/yearMonth=2026-07/.test(hrefs[0]), 'recovery href includes yearMonth param');

		const extracted = Ws.withWorkspace;
		const unbound = extracted('/apps/budgetcheck/monthly');
		assert(unbound === '/apps/budgetcheck/monthly?workspaceId=42', 'unbound withWorkspace ok for recovery screens');

		const list = document.createElement('ul');
		const section = document.createElement('section');
		C.renderWarningsList(section, list, [warning, {
			severity: 'critical',
			title: 'Available after savings is negative',
			message: 'Income minus expense minus savings target is below zero this month.',
			recovery: { screen: 'monthly', params: { yearMonth: '2026-07' } },
		}], Ws);
		assert(section.hidden === false, 'warnings section visible when warnings exist');
		assert(list.children.length === 2, 'two warning rows rendered');

		const listHrefs = [];
		collectHrefs(list, listHrefs);
		assert(listHrefs.length === 2, 'both recovery Open links present');
		assert(listHrefs.every((h) => /workspaceId=42/.test(h)), 'every recovery link is workspace-scoped');

		C.renderWarningsList(section, list, [], Ws);
		assert(section.hidden === true, 'empty warnings hide the section');

		const noThrow = C.renderWarningItem({ severity: 'info', message: 'noop' }, null);
		assert(!!noThrow, 'null workspace context does not throw');
	} catch (err) {
		failures += 1;
		process.stderr.write('FAIL: exception ' + (err && err.stack ? err.stack : err) + '\n');
	}
}

if (failures > 0) {
	process.stderr.write(failures + ' warning-recovery-dom test(s) failed\n');
	process.exit(1);
}
process.stdout.write('warning-recovery-dom tests: OK\n');
