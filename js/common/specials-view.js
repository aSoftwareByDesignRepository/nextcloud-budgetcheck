(function () {
	'use strict';

	const STORAGE_PREFIX = 'budgetcheck.includeSpecials.';

	function legacyStorageKey(workspaceId) {
		return STORAGE_PREFIX + String(workspaceId);
	}

	function readWorkspace(workspaceId) {
		const Ws = window.BudgetCheckWorkspace;
		const w = Ws && Ws.workspace;
		if (!w || Number(w.id) !== Number(workspaceId)) {
			return null;
		}
		return w;
	}

	/**
	 * Effective preference: server workspace object first, else exclude specials.
	 *
	 * @param {number} workspaceId
	 * @return {boolean}
	 */
	function getIncludeSpecials(workspaceId) {
		const w = readWorkspace(workspaceId);
		if (w && typeof w.includeSpecialsInTotals === 'boolean') {
			return w.includeSpecialsInTotals;
		}
		return false;
	}

	/**
	 * @param {number} workspaceId
	 * @param {boolean} include
	 * @return {Promise<boolean>}
	 */
	async function setIncludeSpecials(workspaceId, include) {
		const Api = window.BudgetCheckApi;
		const Ws = window.BudgetCheckWorkspace;
		if (!Api) {
			return include;
		}
		const res = await Api.put(
			'/apps/budgetcheck/api/workspaces/' + encodeURIComponent(String(workspaceId)) + '/summary-view-preferences',
			{ includeSpecialsInTotals: !!include },
		);
		const prefs = res && res.preferences ? res.preferences : res;
		const value = !!(prefs && prefs.includeSpecialsInTotals);
		if (Ws && typeof Ws.patchWorkspace === 'function') {
			const patch = {
				includeSpecialsInTotals: value,
				hasIncludeSpecialsUserOverride: true,
			};
			if (res && res.workspace && typeof res.workspace.includeSpecialsInTotalsDefault === 'boolean') {
				patch.includeSpecialsInTotalsDefault = res.workspace.includeSpecialsInTotalsDefault;
			}
			Ws.patchWorkspace(patch);
		}
		return value;
	}

	/**
	 * One-time migration from pre-1.0.17 localStorage toggle values.
	 *
	 * @param {number} workspaceId
	 * @return {Promise<void>}
	 */
	async function migrateLegacyLocalStorage(workspaceId) {
		const w = readWorkspace(workspaceId);
		if (!w || w.hasIncludeSpecialsUserOverride) {
			return;
		}
		let legacy = null;
		try {
			legacy = window.localStorage.getItem(legacyStorageKey(workspaceId));
		} catch (_) {
			return;
		}
		if (legacy === null) {
			return;
		}
		try {
			window.localStorage.removeItem(legacyStorageKey(workspaceId));
		} catch (_) {
			/* ignore */
		}
		try {
			await setIncludeSpecials(workspaceId, legacy === '1');
		} catch (_) {
			/* best effort; page still uses workspace default */
		}
	}

	/**
	 * Pick cash-flow totals for display. Everyday totals exclude special
	 * transactions; withSpecials is the full ledger view.
	 *
	 * @param {object|null|undefined} totals
	 * @param {boolean} includeSpecials
	 * @return {object|null|undefined}
	 */
	function resolveCashFlowTotals(totals, includeSpecials) {
		if (!totals || !includeSpecials || !totals.withSpecials) {
			return totals;
		}
		const merged = Object.assign({}, totals, {
			income: totals.withSpecials.income,
			expense: totals.withSpecials.expense,
			netResult: totals.withSpecials.netResult,
			availableAfterSavings: totals.withSpecials.availableAfterSavings,
		});
		if (totals.withSpecials.tax) {
			merged.tax = totals.withSpecials.tax;
		}
		return merged;
	}

	/**
	 * @param {HTMLElement|null} container
	 * @param {{ workspaceId: number, hasSpecialTransactions?: boolean, onChange: (include: boolean) => void }} options
	 */
	function mountToggle(container, options) {
		const C = window.BudgetCheckComponents;
		const Msg = window.BudgetCheckMessaging;
		if (!container || !C || !options || !options.workspaceId) {
			return null;
		}
		container.replaceChildren();
		const alwaysShow = !!options.alwaysShow;
		if (!alwaysShow && !options.hasSpecialTransactions) {
			container.hidden = true;
			return null;
		}
		container.hidden = false;
		const inputId = options.inputId || ('bc-include-specials-' + String(options.workspaceId) + '-' + Math.random().toString(36).slice(2, 8));
		const hintId = inputId + '-hint';
		let include = getIncludeSpecials(options.workspaceId);
		const label = C.createElement('label', { class: 'bc-field bc-field--boolean bc-specials-toggle' });
		const row = C.createElement('span', { class: 'bc-boolean-control' });
		const input = C.createElement('input', {
			type: 'checkbox',
			id: inputId,
			name: 'includeSpecials',
			value: '1',
		});
		input.checked = include;
		input.addEventListener('change', () => {
			const next = input.checked;
			const previous = include;
			input.disabled = true;
			void setIncludeSpecials(options.workspaceId, next)
				.then((saved) => {
					include = saved;
					input.checked = saved;
					if (Msg && typeof Msg.announce === 'function') {
						Msg.announce(
							saved
								? t('budgetcheck', 'Planning totals now include one-off special transactions.')
								: t('budgetcheck', 'Planning totals now exclude one-off special transactions.'),
							'success',
						);
					}
					if (typeof options.onChange === 'function') {
						options.onChange(saved);
					}
				})
				.catch((err) => {
					input.checked = previous;
					include = previous;
					if (Msg && typeof Msg.handleApiError === 'function') {
						Msg.handleApiError(err);
					}
				})
				.finally(() => {
					input.disabled = false;
				});
		});
		row.appendChild(input);
		row.appendChild(C.createElement('span', { class: 'bc-boolean-control__text', text: t('budgetcheck', 'Include one-off (special) transactions') }));
		label.appendChild(row);
		label.appendChild(C.createElement('span', {
			class: 'bc-field__hint bc-field__hint--block',
			id: hintId,
			text: t(
				'budgetcheck',
				'Everyday totals exclude transactions marked as special. Turn this on to see the full ledger. Your choice is saved to your account.',
			),
		}));
		label.setAttribute('aria-describedby', hintId);
		container.appendChild(label);
		return { input: input, getValue: () => include };
	}

	/**
	 * Re-read the effective preference after bfcache restore or workspace patch.
	 *
	 * @param {number} workspaceId
	 * @param {boolean} current
	 * @return {boolean}
	 */
	function refreshIncludeSpecials(workspaceId, current) {
		const next = getIncludeSpecials(workspaceId);
		return typeof current === 'boolean' && next === current ? current : next;
	}

	if (!window.BudgetCheck || typeof window.BudgetCheck.define !== 'function') {
		throw new Error('BudgetCheck bootstrap missing — SpecialsView cannot register');
	}
	window.BudgetCheck.define('SpecialsView', {
		getIncludeSpecials,
		setIncludeSpecials,
		migrateLegacyLocalStorage,
		resolveCashFlowTotals,
		mountToggle,
		refreshIncludeSpecials,
	});
})();
