/**
 * Behavioral tests for App settings multipage JS — legacy redirect + merge-on-save.
 *
 * Run: node --test tests/js/app-settings-pages.test.mjs
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '../..');
const legacySource = fs.readFileSync(path.join(root, 'js/app-settings-legacy-redirect.js'), 'utf8');
const appSettingsSource = fs.readFileSync(path.join(root, 'js/app-settings.js'), 'utf8');

function loadLegacy() {
	const fakeWindow = {};
	new Function('window', legacySource)(fakeWindow);
	const api = fakeWindow.BudgetCheckAppSettingsLegacyRedirect;
	assert.ok(api, 'module must export window.BudgetCheckAppSettingsLegacyRedirect');
	return api;
}

function loadMergeHelper() {
	// Extract and evaluate only the merge helper + export without booting onReady.
	const match = appSettingsSource.match(
		/function buildAppPolicySavePayload[\s\S]*?return base;\n\t\}/,
	);
	assert.ok(match, 'buildAppPolicySavePayload must exist in app-settings.js');
	const sandbox = { window: {} };
	vm.runInNewContext(
		match[0] + '\nwindow.BudgetCheckAppSettingsPolicyMerge = { buildAppPolicySavePayload };',
		sandbox,
	);
	return sandbox.window.BudgetCheckAppSettingsPolicyMerge;
}

const SECTION_URLS = Object.freeze({
	access: '/apps/budgetcheck/app-settings/access?workspaceId=2',
	admins: '/apps/budgetcheck/app-settings/admins?workspaceId=2',
	defaults: '/apps/budgetcheck/app-settings/defaults?workspaceId=2',
	support: '/apps/budgetcheck/app-settings/support?workspaceId=2',
});

/**
 * @param {object} [overrides]
 */
function docStub({ section = 'access', urls = { appSettingsSections: SECTION_URLS }, attrs = null } = {}) {
	const attributes = attrs || {
		'data-bc-app-settings-section': section,
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
		api.ANCHOR_SECTIONS['bc-evil'] = 'access';
	}, TypeError);
	assert.equal(typeof api.resolve, 'function');
});

test('every anchor forwards to its owning sub-page and keeps the fragment', () => {
	const api = loadLegacy();
	const anchors = Object.entries(api.ANCHOR_SECTIONS);
	assert.ok(anchors.length >= 11, 'anchor map must cover the full legacy page');
	for (const [anchor, section] of anchors) {
		const current = section === 'access' ? 'admins' : 'access';
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
		appSettingsSections: {
			access: '/apps/budgetcheck/app-settings/access',
			admins: '/apps/budgetcheck/app-settings/admins',
			defaults: '/apps/budgetcheck/app-settings/defaults',
			support: '/apps/budgetcheck/app-settings/support',
		},
	};
	const url = api.resolve(docStub({ section: 'access', urls }), {
		hash: '#bc-policy-admins-q',
		search: '?workspaceId=7',
	});
	assert.equal(url, '/apps/budgetcheck/app-settings/admins?workspaceId=7#bc-policy-admins-q');
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

test('does nothing outside an app-settings sub-page (empty section attribute)', () => {
	const api = loadLegacy();
	assert.equal(api.resolve(docStub({ section: '' }), { hash: '#bc-policy-admins-q' }), null);
});

test('fails closed on missing DOM, malformed urls, and missing section urls', () => {
	const api = loadLegacy();
	const hash = '#bc-policy-admins-q';
	assert.equal(api.resolve(null, { hash }), null, 'no document');
	assert.equal(api.resolve({}, { hash }), null, 'document without getElementById');
	assert.equal(api.resolve({ getElementById: () => null }, { hash }), null, 'no #app-content');
	assert.equal(
		api.resolve(docStub({ attrs: { 'data-bc-app-settings-section': 'access', 'data-bc-urls': '{broken json' } }), { hash }),
		null,
		'malformed data-bc-urls JSON',
	);
	assert.equal(
		api.resolve(docStub({ urls: {} }), { hash }),
		null,
		'urls payload without appSettingsSections',
	);
	assert.equal(
		api.resolve(docStub({ urls: { appSettingsSections: { admins: '' } } }), { hash }),
		null,
		'empty target url',
	);
	assert.equal(
		api.resolve(docStub({ urls: { appSettingsSections: { admins: 42 } } }), { hash }),
		null,
		'non-string target url',
	);
	assert.equal(api.resolve(docStub(), null), null, 'no location');
	assert.equal(api.resolve(docStub(), {}), null, 'location without hash');
});

test('module tolerates a null global root (non-browser environments)', () => {
	assert.doesNotThrow(() => new Function('window', legacySource)(null));
});

test('merge-on-save preserves fields from other scopes', () => {
	const merge = loadMergeHelper();
	const current = {
		appAdminUserIds: ['admin1', 'admin2'],
		accessRestrictionEnabled: true,
		allowedUserIds: ['u1'],
		allowedGroupIds: ['g1'],
		defaultTimezone: 'Europe/Berlin',
		defaultCurrency: 'EUR',
	};
	const accessOnly = merge.buildAppPolicySavePayload(
		'access',
		{
			accessRestrictionEnabled: false,
			allowedUserIds: ['u2'],
			allowedGroupIds: [],
		},
		current,
	);
	assert.deepEqual(accessOnly.appAdminUserIds, ['admin1', 'admin2']);
	assert.equal(accessOnly.accessRestrictionEnabled, false);
	assert.deepEqual(accessOnly.allowedUserIds, ['u2']);
	assert.deepEqual(accessOnly.allowedGroupIds, []);
	assert.equal(accessOnly.defaultTimezone, 'Europe/Berlin');
	assert.equal(accessOnly.defaultCurrency, 'EUR');
	assert.equal(accessOnly.settings_section, 'access');

	const adminsOnly = merge.buildAppPolicySavePayload(
		'admins',
		{ appAdminUserIds: ['admin9'] },
		current,
	);
	assert.deepEqual(adminsOnly.appAdminUserIds, ['admin9']);
	assert.equal(adminsOnly.accessRestrictionEnabled, true);
	assert.deepEqual(adminsOnly.allowedUserIds, ['u1']);
	assert.deepEqual(adminsOnly.allowedGroupIds, ['g1']);
	assert.equal(adminsOnly.defaultCurrency, 'EUR');
	assert.equal(adminsOnly.settings_section, 'admins');

	const defaultsOnly = merge.buildAppPolicySavePayload(
		'defaults',
		{ defaultTimezone: 'America/New_York', defaultCurrency: 'usd' },
		current,
	);
	assert.deepEqual(defaultsOnly.appAdminUserIds, ['admin1', 'admin2']);
	assert.equal(defaultsOnly.accessRestrictionEnabled, true);
	assert.deepEqual(defaultsOnly.allowedUserIds, ['u1']);
	assert.equal(defaultsOnly.defaultTimezone, 'America/New_York');
	assert.equal(defaultsOnly.defaultCurrency, 'USD');
	assert.equal(defaultsOnly.settings_section, 'defaults');
});
