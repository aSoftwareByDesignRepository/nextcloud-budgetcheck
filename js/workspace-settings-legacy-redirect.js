/**
 * Legacy /settings#anchor → split Workspace settings sub-page forwarding.
 *
 * The old Workspace settings page was one long document with jump anchors. URL
 * fragments are never sent to the server, so stale bookmarks like
 * /settings#bc-categories-title land on the default sub-page. This module
 * forwards them client-side to the owning sub-page, keeping the fragment so
 * the browser still scrolls to the section after navigation.
 *
 * ANCHOR_SECTIONS mirrors OCA\BudgetCheck\Service\WorkspaceSettingsSectionCatalog::LEGACY_ANCHORS —
 * a contract test (tests/js/workspace-settings-pages.test.mjs + tests/Unit) pins both maps.
 *
 * Security: the target URL is read from the server-rendered, HTML-escaped
 * data-bc-urls payload and selected only through the frozen allowlist below;
 * no fragment content ever becomes a URL by itself. workspaceId from the
 * current location is appended when the section URL lacks it.
 */
(function (root) {
	'use strict';

	const ANCHOR_SECTIONS = Object.freeze({
		'bc-summary-view-prefs-title': 'planning-view',
		'bc-ws-meta-title': 'workspace',
		'bc-ws-currency-label': 'workspace',
		'bc-ws-timezone-label': 'workspace',
		'bc-primary-year-hint': 'workspace',
		'bc-summary-default-hint': 'workspace',
		'bc-tax-title': 'tax',
		'bc-categories-title': 'categories',
		'bc-budget-defaults-title': 'budget-defaults',
		'bc-booking-statuses-title': 'booking-statuses',
		'bc-members-title': 'members',
		'bc-member-invite-title': 'members',
		'bc-member-invite-hint': 'members',
		'bc-member-invite-q': 'members',
		'bc-member-invite-suggest': 'members',
		'bc-member-invite-role': 'members',
		'bc-group-invite-title': 'members',
		'bc-group-invite-hint': 'members',
		'bc-group-invite-q': 'members',
		'bc-group-invite-suggest': 'members',
		'bc-group-invite-role': 'members',
		'bc-recurring-title': 'recurring',
		'bc-help-panels': 'help',
		'bc-glossary': 'help',
		'bc-spreadsheet-bridge': 'help',
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
		const currentSection = String(rootEl.getAttribute('data-bc-settings-section') || '');
		// Only forward while on a workspace-settings sub-page, and never when the
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
		const sectionUrl = urls && urls.settingsSections
			? urls.settingsSections[targetSection]
			: null;
		if (typeof sectionUrl !== 'string' || sectionUrl === '') {
			return null;
		}
		return withWorkspaceId(sectionUrl, loc) + '#' + hash;
	}

	const api = Object.freeze({ ANCHOR_SECTIONS, resolve, withWorkspaceId });
	if (root) {
		root.BudgetCheckWorkspaceSettingsLegacyRedirect = api;
	}
})(typeof window !== 'undefined' ? window : null);
