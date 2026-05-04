(function () {
	'use strict';

	// Workspace context shared across page modules.
	//
	// The page-start template sets the active workspace + URL map onto
	// #app-content as JSON-encoded data attributes. This module hydrates
	// once and exposes helpers so page modules don't keep re-reading dataset
	// attributes themselves.

	function dataset() {
		return document.getElementById('app-content')?.dataset || {};
	}

	function parseJson(value, fallback) {
		if (typeof value !== 'string' || value === '' || value === 'null') return fallback;
		try {
			const parsed = JSON.parse(value);
			return parsed === null ? fallback : parsed;
		} catch (_) {
			return fallback;
		}
	}

	const ds = dataset();

	const workspace = parseJson(ds.bcWorkspace, null);
	const workspaces = parseJson(ds.bcWorkspaces, []);
	const urls = parseJson(ds.bcUrls, {});

	function withWorkspace(url) {
		if (!workspace) return url;
		const sep = url.indexOf('?') === -1 ? '?' : '&';
		return url + sep + 'workspaceId=' + encodeURIComponent(workspace.id);
	}

	function navigateTo(name) {
		const url = urls[name];
		if (!url) return;
		window.location.href = withWorkspace(url);
	}

	function role() {
		return workspace ? workspace.role : null;
	}

	const ctx = {
		workspace,
		workspaces,
		urls,
		canManage: ds.bcCanManage === '1',
		canContribute: ds.bcCanContribute === '1',
		canAdmin: ds.bcCanAdmin === '1',
		locale: ds.bcLocale || 'en',
		htmlLang: ds.bcHtmlLang || 'en',
		timezone: ds.bcTimezone || 'UTC',
		page: ds.bcPage || '',
		role,
		withWorkspace,
		navigateTo,
	};

	// Set up data-bc-link click handlers globally so pages can use
	// `<a data-bc-link="transactions">`.
	document.addEventListener('click', (event) => {
		const trigger = event.target.closest('[data-bc-link]');
		if (!trigger) return;
		const name = trigger.getAttribute('data-bc-link');
		if (!name || !urls[name]) return;
		event.preventDefault();
		ctx.navigateTo(name);
	});

	// Hide quick-start when previously dismissed (per-workspace key).
	document.addEventListener('DOMContentLoaded', () => {
		const dismiss = (key) => {
			const button = document.querySelector(`[data-bc-dismiss-hint="${CSS.escape(key)}"]`);
			if (!button) return;
			const card = button.closest('.bc-card');
			if (!card) return;
			const storageKey = 'bc:hint:' + (workspace ? workspace.id : 'global') + ':' + key;
			if (window.localStorage.getItem(storageKey) === '1') {
				card.hidden = true;
			} else {
				card.hidden = false;
				button.addEventListener('click', () => {
					try { window.localStorage.setItem(storageKey, '1'); } catch (_) { /* private mode */ }
					card.hidden = true;
				});
			}
		};
		dismiss('dashboard_quickstart_v1');

		const D = window.BudgetCheckDates;
		const appRoot = document.getElementById('app-content');
		if (D && typeof D.applyLocaleToTemporalInputs === 'function' && appRoot) {
			D.applyLocaleToTemporalInputs(appRoot, ctx.htmlLang);
		}
	});

	window.BudgetCheckWorkspace = ctx;
})();
