(function () {
	'use strict';

	// Workspace context shared across page modules.
	//
	// The page-start template sets the active workspace + URL map onto
	// #app-content as JSON-encoded data attributes. Values are read **lazily**
	// from the live DOM so script load order never snapshots an empty
	// `#app-content` (which would permanently strand flags like `canAdmin`).

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

	const ctx = {
		get workspace() {
			return parseJson(dataset().bcWorkspace, null);
		},
		get workspaces() {
			return parseJson(dataset().bcWorkspaces, []);
		},
		get urls() {
			return parseJson(dataset().bcUrls, {});
		},
		get canManage() {
			return dataset().bcCanManage === '1';
		},
		get canContribute() {
			return dataset().bcCanContribute === '1';
		},
		get canAdmin() {
			return dataset().bcCanAdmin === '1';
		},
		get locale() {
			return dataset().bcLocale || 'en';
		},
		get htmlLang() {
			return dataset().bcHtmlLang || 'en';
		},
		get timezone() {
			return dataset().bcTimezone || 'UTC';
		},
		get page() {
			return dataset().bcPage || '';
		},
		role() {
			const w = this.workspace;
			return w ? w.role : null;
		},
		withWorkspace(url) {
			const w = this.workspace;
			if (!w) return url;
			const sep = url.indexOf('?') === -1 ? '?' : '&';
			return url + sep + 'workspaceId=' + encodeURIComponent(w.id);
		},
		navigateTo(name) {
			const url = this.urls[name];
			if (!url) return;
			window.location.href = this.withWorkspace(url);
		},
	};

	// Set up data-bc-link click handlers globally so pages can use
	// `<a data-bc-link="transactions">`.
	document.addEventListener('click', (event) => {
		const trigger = event.target.closest('[data-bc-link]');
		if (!trigger) return;
		const name = trigger.getAttribute('data-bc-link');
		if (!name || !ctx.urls[name]) return;
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
			const w = ctx.workspace;
			const storageKey = 'bc:hint:' + (w ? w.id : 'global') + ':' + key;
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
