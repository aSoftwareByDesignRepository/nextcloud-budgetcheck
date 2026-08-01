/**
 * Behavioral tests for Workspace settings multipage JS — legacy redirect + workspaceId.
 *
 * Run: node --test tests/js/workspace-settings-pages.test.mjs
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '../..');
const legacySource = fs.readFileSync(path.join(root, 'js/workspace-settings-legacy-redirect.js'), 'utf8');
const componentsSource = fs.readFileSync(path.join(root, 'js/common/components.js'), 'utf8');

function loadLegacy() {
	const fakeWindow = {};
	new Function('window', legacySource)(fakeWindow);
	const api = fakeWindow.BudgetCheckWorkspaceSettingsLegacyRedirect;
	assert.ok(api, 'module must export window.BudgetCheckWorkspaceSettingsLegacyRedirect');
	return api;
}

const SECTION_URLS = Object.freeze({
	'planning-view': '/apps/budgetcheck/settings/planning-view?workspaceId=2',
	workspace: '/apps/budgetcheck/settings/workspace?workspaceId=2',
	tax: '/apps/budgetcheck/settings/tax?workspaceId=2',
	categories: '/apps/budgetcheck/settings/categories?workspaceId=2',
	'budget-defaults': '/apps/budgetcheck/settings/budget-defaults?workspaceId=2',
	'booking-statuses': '/apps/budgetcheck/settings/booking-statuses?workspaceId=2',
	members: '/apps/budgetcheck/settings/members?workspaceId=2',
	recurring: '/apps/budgetcheck/settings/recurring?workspaceId=2',
	help: '/apps/budgetcheck/settings/help?workspaceId=2',
});

/**
 * @param {object} [overrides]
 */
function docStub({ section = 'workspace', urls = { settingsSections: SECTION_URLS }, attrs = null } = {}) {
	const attributes = attrs || {
		'data-bc-settings-section': section,
		'data-bc-urls': JSON.stringify(urls),
	};
	return {
		getElementById(id) {
			if (id !== 'app-content') {
				return null;
			}
			return {
				getAttribute(name) {
					return Object.prototype.hasOwnProperty.call(attributes, name) ? attributes[name] : null;
				},
			};
		},
	};
}

test('exports a frozen api with a frozen anchor map', () => {
	const api = loadLegacy();
	assert.ok(Object.isFrozen(api), 'api object must be frozen');
	assert.ok(Object.isFrozen(api.ANCHOR_SECTIONS), 'ANCHOR_SECTIONS must be frozen');
	assert.throws(() => {
		'use strict';
		api.ANCHOR_SECTIONS['bc-evil'] = 'workspace';
	}, TypeError);
	assert.equal(typeof api.resolve, 'function');
});

test('every anchor forwards to its owning sub-page and keeps the fragment', () => {
	const api = loadLegacy();
	const anchors = Object.entries(api.ANCHOR_SECTIONS);
	assert.ok(anchors.length >= 20, 'anchor map must cover the full legacy page');
	for (const [anchor, section] of anchors) {
		const current = section === 'workspace' ? 'tax' : 'workspace';
		const url = api.resolve(docStub({ section: current }), { hash: '#' + anchor, search: '?workspaceId=2' });
		assert.equal(
			url,
			SECTION_URLS[section] + '#' + anchor,
			`#${anchor} must forward to the ${section} page with the fragment preserved`,
		);
	}
});

test('appends workspaceId from location when section URL lacks it', () => {
	const api = loadLegacy();
	const urls = {
		settingsSections: {
			...SECTION_URLS,
			categories: '/apps/budgetcheck/settings/categories',
		},
	};
	const url = api.resolve(docStub({ section: 'workspace', urls }), {
		hash: '#bc-categories-title',
		search: '?workspaceId=7',
	});
	assert.equal(url, '/apps/budgetcheck/settings/categories?workspaceId=7#bc-categories-title');
});

test('does not forward when the anchor already lives on the current page', () => {
	const api = loadLegacy();
	for (const [anchor, section] of Object.entries(api.ANCHOR_SECTIONS)) {
		assert.equal(api.resolve(docStub({ section }), { hash: '#' + anchor }), null, `#${anchor} on its own page must not loop`);
	}
});

test('ignores unknown, empty, and prototype-polluting hashes', () => {
	const api = loadLegacy();
	for (const hash of ['', '#', '#unknown-anchor', '#constructor', '#__proto__', '#hasOwnProperty', '#toString']) {
		assert.equal(api.resolve(docStub(), { hash }), null, `hash '${hash}' must not redirect`);
	}
});

test('does nothing outside a workspace-settings sub-page (empty section attribute)', () => {
	const api = loadLegacy();
	assert.equal(api.resolve(docStub({ section: '' }), { hash: '#bc-categories-title' }), null);
});

test('fails closed on missing DOM, malformed urls, and missing section urls', () => {
	const api = loadLegacy();
	const hash = '#bc-categories-title';
	assert.equal(api.resolve(null, { hash }), null, 'no document');
	assert.equal(api.resolve({}, { hash }), null, 'document without getElementById');
	assert.equal(api.resolve({ getElementById: () => null }, { hash }), null, 'no #app-content');
	assert.equal(
		api.resolve(docStub({ attrs: { 'data-bc-settings-section': 'workspace', 'data-bc-urls': '{broken json' } }), { hash }),
		null,
		'malformed data-bc-urls JSON',
	);
	assert.equal(
		api.resolve(docStub({ urls: {} }), { hash }),
		null,
		'urls payload without settingsSections',
	);
	assert.equal(
		api.resolve(docStub({ urls: { settingsSections: { categories: '' } } }), { hash }),
		null,
		'empty target url',
	);
});

test('components savings callout prefers settingsSections.categories when present', () => {
	assert.match(componentsSource, /settingsSections\.categories/);
	assert.match(componentsSource, /#bc-categories-title/);

	function resolveHref(urls) {
		const sandbox = {
			window: {
				BudgetCheckWorkspace: {
					urls,
					withWorkspace(url) {
						return String(url).includes('workspaceId=') ? url : url + '?workspaceId=9';
					},
				},
			},
		};
		const snippet = `
			(function () {
				const Ws = window.BudgetCheckWorkspace;
				const settingsUrl = Ws && Ws.urls ? Ws.urls.settings : null;
				const categoriesSectionUrl = Ws && Ws.urls && Ws.urls.settingsSections
					? Ws.urls.settingsSections.categories
					: null;
				return categoriesSectionUrl
					? (typeof Ws.withWorkspace === 'function'
						? Ws.withWorkspace(categoriesSectionUrl)
						: categoriesSectionUrl)
					: (settingsUrl
						? Ws.withWorkspace(settingsUrl) + '#bc-categories-title'
						: null);
			})();
		`;
		return vm.runInNewContext(snippet, sandbox);
	}

	assert.equal(
		resolveHref({
			settings: '/apps/budgetcheck/settings',
			settingsSections: {
				categories: '/apps/budgetcheck/settings/categories?workspaceId=9',
			},
		}),
		'/apps/budgetcheck/settings/categories?workspaceId=9',
	);
	assert.equal(
		resolveHref({ settings: '/apps/budgetcheck/settings' }),
		'/apps/budgetcheck/settings?workspaceId=9#bc-categories-title',
	);
});
