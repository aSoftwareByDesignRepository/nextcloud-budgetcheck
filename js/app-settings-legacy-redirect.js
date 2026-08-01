/**
 * Legacy /app-settings#anchor → split sub-page forwarding.
 *
 * The old App settings page was one long document with jump anchors. URL
 * fragments are never sent to the server, so stale bookmarks like
 * /app-settings#bc-policy-admins-q land on the default sub-page. This module
 * forwards them client-side to the owning sub-page, keeping the fragment so
 * the browser still scrolls to the section after navigation.
 *
 * ANCHOR_SECTIONS mirrors OCA\BudgetCheck\Service\AppSettingsSectionCatalog::LEGACY_ANCHORS —
 * a contract test (tests/js/app-settings-pages.test.mjs + tests/Unit) pins both maps.
 *
 * Security: the target URL is read from the server-rendered, HTML-escaped
 * data-bc-urls payload and selected only through the frozen allowlist below;
 * no fragment content ever becomes a URL by itself. workspaceId from the
 * current location is appended when the section URL lacks it.
 */
(function (root) {
	'use strict';

	const ANCHOR_SECTIONS = Object.freeze({
		'bc-app-policy-title': 'access',
		'bc-access-gate-title': 'access',
		'bc-access-restriction-desc': 'access',
		'bc-allowed-users-label': 'access',
		'bc-allowed-groups-label': 'access',
		'bc-app-admin-legend': 'admins',
		'bc-policy-admins-q': 'admins',
		'bc-policy-timezone-label': 'defaults',
		'bc-policy-currency-label': 'defaults',
		'bc-support-us': 'support',
		'bc-support-us-title': 'support',
	});

	/**
	 * Preserve ?workspaceId= from the current location when the section URL
	 * does not already carry it (server URLs usually include it already).
	 *
	 * @param {string} sectionUrl
	 * @param {object} loc
	 * @returns {string}
	 */
	function withWorkspaceId(sectionUrl, loc) {
		try {
			const locSearch = String((loc && loc.search) || '');
			const locParams = new URLSearchParams(
				locSearch.startsWith('?') ? locSearch.slice(1) : locSearch,
			);
			const wid = locParams.get('workspaceId');
			if (!wid || !/^\d+$/.test(wid)) {
				return sectionUrl;
			}
			const qIndex = sectionUrl.indexOf('?');
			const path = qIndex === -1 ? sectionUrl : sectionUrl.slice(0, qIndex);
			const search = qIndex === -1 ? '' : sectionUrl.slice(qIndex + 1);
			const sectionParams = new URLSearchParams(search);
			if (!sectionParams.has('workspaceId')) {
				sectionParams.set('workspaceId', wid);
			}
			const qs = sectionParams.toString();
			return qs ? path + '?' + qs : path;
		} catch (_err) {
			return sectionUrl;
		}
	}

	/**
	 * @param {object} doc document (or a stub exposing getElementById)
	 * @param {object} loc location (or a stub exposing hash / search)
	 * @returns {string|null} absolute-path URL (with fragment) to forward to, or null
	 */
	function resolve(doc, loc) {
		const hash = String((loc && loc.hash) || '').replace(/^#/, '');
		if (!Object.prototype.hasOwnProperty.call(ANCHOR_SECTIONS, hash)) {
			return null;
		}
		const targetSection = ANCHOR_SECTIONS[hash];
		const rootEl = doc && typeof doc.getElementById === 'function'
			? doc.getElementById('app-content')
			: null;
		if (!rootEl || typeof rootEl.getAttribute !== 'function') {
			return null;
		}
		const currentSection = String(rootEl.getAttribute('data-bc-app-settings-section') || '');
		// Only forward while on an app-settings sub-page, and never when the
		// anchor already lives on the current page (native scroll handles that).
		if (currentSection === '' || currentSection === targetSection) {
			return null;
		}
		let urls = null;
		try {
			urls = JSON.parse(String(rootEl.getAttribute('data-bc-urls') || '{}'));
		} catch (_err) {
			return null;
		}
		const sectionUrl = urls && urls.appSettingsSections
			? urls.appSettingsSections[targetSection]
			: null;
		if (typeof sectionUrl !== 'string' || sectionUrl === '') {
			return null;
		}
		return withWorkspaceId(sectionUrl, loc) + '#' + hash;
	}

	const api = Object.freeze({ ANCHOR_SECTIONS, resolve, withWorkspaceId });
	if (root) {
		root.BudgetCheckAppSettingsLegacyRedirect = api;
	}
})(typeof window !== 'undefined' ? window : null);
