/**
 * BudgetCheck module bootstrap (producer + consumer contract).
 *
 * Why this exists
 * ---------------
 * Classic IIFE scripts used `window.BudgetCheckX = …` as both publish and
 * consume. Consumers that snapped those globals at IIFE load kept `undefined`
 * forever when script order slipped → production crashes
 * ("Cannot read properties of undefined (reading 'workspace')").
 *
 * Architecture (no bundler required)
 * ----------------------------------
 * - Producers MUST call BudgetCheck.define(name, api). That writes an internal
 *   registry (source of truth) and mirrors onto window[REGISTRY[name]] for
 *   DevTools / accidental legacy reads.
 * - Consumers MUST use get / require / live / onReady — never capture
 *   window.BudgetCheckX at IIFE top for values used after boot.
 * - PageController registers common/bootstrap FIRST so define() always exists.
 *
 * Race / edge cases handled
 * -------------------------
 * - define before bootstrap → throw (fail loud, never silent half-init)
 * - redefine with a different object → throw (duplicate script / order bugs)
 * - get() prefers the registry; window is a mirror, not the authority
 * - onReady runs after DOMContentLoaded so every addScript producer has run
 *
 * @security Missing required deps throw — silent undefined in a finance UI is
 *           a confused-deputy footgun.
 */
(function () {
	'use strict';

	/** @type {Record<string, string>} short name → legacy window global (mirror) */
	const REGISTRY = {
		Api: 'BudgetCheckApi',
		Messaging: 'BudgetCheckMessaging',
		Components: 'BudgetCheckComponents',
		Money: 'BudgetCheckMoney',
		Dates: 'BudgetCheckDates',
		Workspace: 'BudgetCheckWorkspace',
		Constants: 'BudgetCheckConstants',
		Icons: 'BudgetCheckIcons',
		SpecialsView: 'BudgetCheckSpecialsView',
		HouseholdPeriod: 'BudgetCheckHouseholdPeriod',
		TransactionEditor: 'BudgetCheckTransactionEditor',
		TransactionList: 'BudgetCheckTransactionList',
		TransactionAttachments: 'BudgetCheckTransactionAttachments',
		AttachmentGallery: 'BudgetCheckAttachmentGallery',
		EntityPicker: 'BudgetCheckEntityPicker',
		CatalogPickers: 'BudgetCheckCatalogPickers',
	};

	/** @type {Record<string, object|function>} internal source of truth */
	const modules = Object.create(null);

	function globalName(shortName) {
		return REGISTRY[shortName] || null;
	}

	/**
	 * Register a producer module. Canonical publish path.
	 *
	 * @param {string} shortName
	 * @param {object|function} value
	 * @returns {object|function}
	 */
	function define(shortName, value) {
		if (typeof shortName !== 'string' || shortName === '') {
			throw new Error('BudgetCheck.define: name must be a non-empty string');
		}
		const key = globalName(shortName);
		if (!key) {
			throw new Error('BudgetCheck.define: unknown module "' + shortName + '"');
		}
		if (value == null || (typeof value !== 'object' && typeof value !== 'function')) {
			throw new Error('BudgetCheck.define: "' + shortName + '" value must be an object or function');
		}
		if (Object.prototype.hasOwnProperty.call(modules, shortName) && modules[shortName] !== value) {
			throw new Error(
				'BudgetCheck.define: "' + shortName + '" is already registered'
				+ ' (duplicate script load or conflicting producer)'
			);
		}
		modules[shortName] = value;
		// Mirror for DevTools and any transitional window reads — registry remains SoT.
		window[key] = value;
		return value;
	}

	/**
	 * Live read of a registered module (or null).
	 * Prefers the internal registry; falls back to window mirror only if a
	 * legacy producer forgot define() (tests forbid that path in production JS).
	 *
	 * @param {string} shortName
	 * @returns {object|function|null}
	 */
	function get(shortName) {
		const key = globalName(shortName);
		if (!key) {
			throw new Error('BudgetCheck.get: unknown dependency "' + shortName + '"');
		}
		if (Object.prototype.hasOwnProperty.call(modules, shortName)) {
			return modules[shortName];
		}
		const value = window[key];
		return value === undefined ? null : value;
	}

	function has(shortName) {
		const key = globalName(shortName);
		if (!key) {
			return false;
		}
		if (Object.prototype.hasOwnProperty.call(modules, shortName) && modules[shortName] != null) {
			return true;
		}
		return !!window[key];
	}

	/**
	 * @param {string[]} names
	 * @param {{ optional?: string[], strict?: boolean }} [options]
	 * @returns {Record<string, object|function|null>}
	 */
	function requireDeps(names, options) {
		const opts = options || {};
		const optional = Object.create(null);
		(opts.optional || []).forEach((n) => { optional[n] = true; });
		const strict = opts.strict !== false;
		const out = Object.create(null);
		const missing = [];
		const list = Array.isArray(names) ? names : [];

		list.forEach((name) => {
			if (typeof name !== 'string' || name === '') {
				throw new Error('BudgetCheck.require: dependency names must be non-empty strings');
			}
			if (!globalName(name)) {
				throw new Error('BudgetCheck.require: unknown dependency "' + name + '"');
			}
			const value = get(name);
			out[name] = value;
			if (value == null && !optional[name]) {
				missing.push(name);
			}
		});

		if (missing.length && strict) {
			throw new Error(
				'BudgetCheck.require: missing required module(s): '
				+ missing.join(', ')
				+ ' (check PageController script registration order)'
			);
		}
		return out;
	}

	/**
	 * @param {string[]} names
	 * @returns {Record<string, object|function|null>}
	 */
	function live(names) {
		const out = {};
		(Array.isArray(names) ? names : []).forEach((name) => {
			if (!globalName(name)) {
				throw new Error('BudgetCheck.live: unknown dependency "' + name + '"');
			}
			Object.defineProperty(out, name, {
				enumerable: true,
				configurable: false,
				get() {
					return get(name);
				},
			});
		});
		return out;
	}

	/**
	 * @param {(deps: Record<string, object|function|null>) => void} callback
	 * @param {string[]|{ required?: string[], optional?: string[] }} namesOrSpec
	 */
	function onReady(callback, namesOrSpec) {
		if (typeof callback !== 'function') {
			throw new Error('BudgetCheck.onReady: callback must be a function');
		}

		let required;
		let optional;
		if (Array.isArray(namesOrSpec)) {
			required = namesOrSpec.slice();
			optional = [];
		} else {
			const spec = namesOrSpec || {};
			required = Array.isArray(spec.required) ? spec.required.slice() : [];
			optional = Array.isArray(spec.optional) ? spec.optional.slice() : [];
		}

		const run = () => {
			const all = required.concat(optional);
			const deps = requireDeps(all, { optional: optional, strict: true });
			callback(deps);
		};

		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', run, { once: true });
		} else {
			Promise.resolve().then(run);
		}
	}

	window.BudgetCheck = {
		REGISTRY: Object.freeze(Object.assign({}, REGISTRY)),
		define: define,
		get: get,
		has: has,
		require: requireDeps,
		live: live,
		onReady: onReady,
	};
})();
