(function () {
	'use strict';

	const Api = window.BudgetCheckApi;
	const Msg = window.BudgetCheckMessaging;
	const C = window.BudgetCheckComponents;
	const Money = window.BudgetCheckMoney;
	const Dates = window.BudgetCheckDates;
	const Ws = window.BudgetCheckWorkspace;

	/** @type {{ id: number, type: string, currencyCode: string, currencyDecimals?: number } | null} */
	let ws = null;
	let isHousehold = false;
	let decimals = 2;
	let monthClosed = false;

	const state = {
		yearMonth: Dates.currentYearMonth(),
		categories: [],
		budgets: [],
		budgetDefaults: [],
		summary: null,
		projectLedger: null,
		dirty: new Map(),
	};

	let periodPicker = null;
	/** Guards against slow responses for a previously selected month overwriting the latest one. */
	let loadSeq = 0;
	/** Prevents double-submit on budget/savings writes and their unguarded reloads. */
	let mutationInFlight = false;

	document.addEventListener('DOMContentLoaded', () => {
		ws = Ws.workspace;
		if (!ws) return;
		isHousehold = ws.type === 'household';
		decimals = typeof ws.currencyDecimals === 'number' ? ws.currencyDecimals : (ws.currencyCode === 'JPY' ? 0 : 2);
		const box = document.querySelector('[data-bc-household-period]');
		const Period = window.BudgetCheckHouseholdPeriod;
		if (box && Period && typeof Period.wire === 'function') {
			periodPicker = Period.wire(box, {
				workspace: ws,
				htmlLang: Ws.htmlLang,
				initialYearMonth: state.yearMonth,
				onChange: (ym) => {
					state.yearMonth = ym;
					state.dirty.clear();
					toggleSaveButton(false);
					loadAll();
				},
			});
		}
		document.querySelectorAll('[data-bc-action="save-budgets"]').forEach((btn) => {
			btn.addEventListener('click', () => saveBudgets());
		});
		document.querySelectorAll('[data-bc-action="generate-planned-budgets"]').forEach((btn) => {
			btn.addEventListener('click', () => generatePlannedFromBudgets());
		});
		const generateOnSave = document.querySelector('[data-bc-generate-planned-on-save]');
		if (generateOnSave && ws && ws.generatePlannedFromBudgetsDefault) {
			generateOnSave.checked = true;
		}
		const savingsForm = document.querySelector('[data-bc-savings-form]');
		if (savingsForm) {
			savingsForm.addEventListener('submit', (e) => { e.preventDefault(); saveSavingsTarget(savingsForm); });
			savingsForm.querySelectorAll('input[name="targetMode"]').forEach((r) => {
				r.addEventListener('change', () => updateSavingsModeFields(savingsForm));
			});
			updateSavingsModeFields(savingsForm);
		}
		loadAll();
	});

	async function loadAll() {
		const seq = ++loadSeq;
		await Promise.all([loadCategories(seq), loadBudgets(seq), loadSummary(seq), loadSavings(seq)]);
		if (seq !== loadSeq) return; // A newer month was selected meanwhile; its loadAll() renders.
		if (isHousehold && periodPicker && state.summary && state.summary.ledgerYearMonthSpan) {
			periodPicker.refreshLedgerSpan(state.summary.ledgerYearMonthSpan);
		}
		monthClosed = !!(isHousehold && state.summary && state.summary.isClosed);
		renderRows();
		renderLedgerHelp();
		updateClosedControls();
	}

	/** True when the response belongs to a load that has since been superseded. */
	function isStale(seq) {
		return seq !== undefined && seq !== loadSeq;
	}

	function updateClosedControls() {
		const note = document.querySelector('[data-bc-budget-closed-note]');
		if (note) note.hidden = !monthClosed;
		document.querySelectorAll('[data-bc-action="generate-planned-budgets"]').forEach((btn) => {
			btn.disabled = monthClosed;
		});
		const generateOnSave = document.querySelector('[data-bc-generate-planned-on-save]');
		if (generateOnSave) generateOnSave.disabled = monthClosed;
		if (monthClosed) toggleSaveButton(false);
	}

	async function loadCategories(seq) {
		if (!ws) return;
		try {
			const data = await Api.get('/apps/budgetcheck/api/categories', { workspaceId: ws.id });
			if (isStale(seq)) return;
			state.categories = (data.categories || []).filter((c) => c.isActive);
		} catch (err) {
			if (isStale(seq)) return;
			Msg.handleApiError(err);
		}
	}

	async function loadBudgets(seq) {
		if (!ws) return;
		try {
			const [data, defaults] = await Promise.all([
				Api.get('/apps/budgetcheck/api/budgets', { workspaceId: ws.id, yearMonth: state.yearMonth }),
				Api.get('/apps/budgetcheck/api/budget-defaults', { workspaceId: ws.id }),
			]);
			if (isStale(seq)) return;
			state.budgets = data.budgets || [];
			state.budgetDefaults = defaults.defaults || [];
			state.projectLedger = null;
			if (!isHousehold && data.ledgerYearMonthSpan) {
				state.projectLedger = {
					ledgerYearMonthSpan: data.ledgerYearMonthSpan,
					monthLedger: data.monthLedger || { transactionCount: 0, hasIncomeOrExpense: false },
				};
			}
		} catch (err) {
			if (isStale(seq)) return;
			Msg.handleApiError(err);
		}
	}

	async function loadSummary(seq) {
		if (!isHousehold || !ws) return;
		try {
			const data = await Api.get('/apps/budgetcheck/api/monthly-summary', { workspaceId: ws.id, yearMonth: state.yearMonth });
			if (isStale(seq)) return;
			state.summary = data.summary || null;
		} catch (err) {
			if (isStale(seq)) return;
			state.summary = null;
			if (Number(err.status) !== 422) Msg.handleApiError(err);
		}
	}

	async function loadSavings(seq) {
		if (!isHousehold || !ws) return;
		try {
			const data = await Api.get('/apps/budgetcheck/api/savings-target', { workspaceId: ws.id, yearMonth: state.yearMonth });
			if (isStale(seq)) return;
			renderSavings(data.savingsTarget || null);
		} catch (err) {
			if (isStale(seq)) return;
			if (Number(err.status) !== 422) Msg.handleApiError(err);
		}
	}

	function ledgerHelpSummary() {
		if (isHousehold && state.summary) {
			return state.summary;
		}
		if (!isHousehold && state.projectLedger) {
			return state.projectLedger;
		}
		return null;
	}

	function renderLedgerHelp() {
		const el = document.querySelector('[data-bc-budget-ledger-help]');
		C.renderMonthlyLedgerHelp(el, ledgerHelpSummary(), state.yearMonth, Ws.htmlLang);
	}

	function renderRows() {
		if (!ws) return;
		const tbody = document.querySelector('[data-bc-budget-rows]');
		const window_ = document.querySelector('[data-bc-budget-window]');
		if (window_) window_.textContent = Dates.formatYearMonth(state.yearMonth, Ws.htmlLang);
		if (!tbody) return;
		tbody.replaceChildren();
		const budgetCats = window.BudgetCheckConstants.budgetableCategories(state.categories);
		const actualMap = new Map();
		if (state.summary && state.summary.budget && state.summary.budget.byCategory) {
			state.summary.budget.byCategory.forEach((row) => actualMap.set(row.categoryId, row.actual));
		}
		const plannedMap = new Map();
		state.budgets.forEach((b) => { if (b.categoryId) plannedMap.set(b.categoryId, b.planned); });
		state.budgetDefaults.forEach((b) => {
			if (b.categoryId && !plannedMap.has(b.categoryId)) {
				plannedMap.set(b.categoryId, b.planned);
			}
		});

		if (budgetCats.length === 0) {
			tbody.appendChild(C.createElement('tr', null, [
				C.createElement('td', { attrs: { colspan: '5' }, class: 'bc-loading', text: t('budgetcheck', 'Add income or expense categories first.') }),
			]));
			return;
		}
		let lastType = null;
		budgetCats.forEach((cat) => {
			if (cat.type !== lastType) {
				lastType = cat.type;
				const sectionLabel = cat.type === 'income'
					? t('budgetcheck', 'Income')
					: t('budgetcheck', 'Expenses');
				const sectionRow = C.createElement('tr', { class: 'bc-table__section' });
				sectionRow.appendChild(C.createElement('th', {
					attrs: { colspan: '5', scope: 'colgroup' },
					class: 'bc-table__section-label',
					text: sectionLabel,
				}));
				tbody.appendChild(sectionRow);
			}
			tbody.appendChild(renderRow(cat, plannedMap.get(cat.id), actualMap.get(cat.id)));
		});
	}

	function remainingClassForCategory(cat, remainingMinor) {
		if (remainingMinor === 0) {
			return 'bc-table__col--num';
		}
		if (cat.type === 'income') {
			return 'bc-table__col--num ' + (remainingMinor < 0 ? 'bc-tx-amount--income' : 'bc-tx-amount--expense');
		}
		return 'bc-table__col--num ' + (remainingMinor < 0 ? 'bc-tx-amount--expense' : 'bc-tx-amount--income');
	}

	function renderRow(cat, plannedEnv, actualEnv) {
		const planned = plannedEnv || { minor: 0, currency: ws.currencyCode, decimals };
		const actual = actualEnv || { minor: 0, currency: ws.currencyCode, decimals };
		const remainingMinor = (planned.minor || 0) - (actual.minor || 0);
		const remainingEnv = { minor: remainingMinor, currency: ws.currencyCode, decimals };
		const tr = C.createElement('tr');
		tr.appendChild(C.createElement('td', { text: cat.name }));
		tr.appendChild(C.createElement('td', {
			text: cat.type === 'income' ? t('budgetcheck', 'Income') : t('budgetcheck', 'Expense'),
		}));
		const plannedTd = C.createElement('td', { class: 'bc-table__col--num' });
		const input = C.createElement('input', {
			type: 'text', inputmode: 'decimal', class: 'bc-input',
			value: planned.minor ? (planned.minor / Math.pow(10, decimals)).toFixed(decimals).replace('.', ',') : '',
			'aria-label': t('budgetcheck', 'Planned amount for {category}').replace('{category}', cat.name),
		});
		input.disabled = !Ws.canManage || monthClosed;
		input.addEventListener('input', () => {
			state.dirty.set(cat.id, input.value);
			toggleSaveButton(true);
		});
		plannedTd.appendChild(input);
		tr.appendChild(plannedTd);
		tr.appendChild(C.createElement('td', { class: 'bc-table__col--num', text: Money.formatEnvelope(actual, Ws.htmlLang) }));
		tr.appendChild(C.createElement('td', {
			class: remainingClassForCategory(cat, remainingMinor),
			text: Money.formatEnvelope(remainingEnv, Ws.htmlLang),
		}));
		return tr;
	}

	function toggleSaveButton(enabled) {
		document.querySelectorAll('[data-bc-action="save-budgets"]').forEach((btn) => {
			btn.disabled = !enabled || monthClosed;
		});
	}

	async function saveBudgets() {
		if (!ws || monthClosed || mutationInFlight) return;
		const yearMonth = state.yearMonth;
		const seq = loadSeq;
		const rows = [];
		try {
			state.dirty.forEach((value, categoryId) => {
				const trimmed = String(value || '').trim();
				if (trimmed === '') {
					rows.push({ categoryId, plannedMinor: 0 });
				} else {
					rows.push({ categoryId, plannedMinor: Money.parseHuman(trimmed, decimals) });
				}
			});
		} catch (e) {
			Msg.announce(e.message, 'error');
			return;
		}
		const generatePlanned = !!document.querySelector('[data-bc-generate-planned-on-save]')?.checked;
		mutationInFlight = true;
		toggleSaveButton(false);
		try {
			const res = await Api.post('/apps/budgetcheck/api/budgets/bulk-upsert', {
				workspaceId: ws.id, yearMonth, rows, generatePlanned,
			});
			if (yearMonth !== state.yearMonth || seq !== loadSeq) return;
			Msg.announce(t('budgetcheck', 'Budgets saved.'), 'success');
			announcePlannedSync(res.plannedSync);
			state.dirty.clear();
			await loadAll();
		} catch (err) {
			Msg.handleApiError(err);
			if (yearMonth === state.yearMonth && seq === loadSeq && state.dirty.size > 0) {
				toggleSaveButton(true);
			}
		} finally {
			mutationInFlight = false;
		}
	}

	function announcePlannedSync(sync) {
		if (!sync) return;
		const created = Number.parseInt(String(sync.created ?? 0), 10) || 0;
		const updated = Number.parseInt(String(sync.updated ?? 0), 10) || 0;
		const removed = Number.parseInt(String(sync.removed ?? 0), 10) || 0;
		if (created + updated + removed === 0) {
			return;
		}
		if (created === 1 && updated === 0 && removed === 0) {
			Msg.announce(t('budgetcheck', '1 planned entry created from budget targets.'), 'success');
			return;
		}
		if (created > 0 && updated === 0 && removed === 0) {
			Msg.announce(t('budgetcheck', '{count} planned entries created from budget targets.').replace('{count}', String(created)), 'success');
			return;
		}
		Msg.announce(t('budgetcheck', 'Planned entries synced from budgets.'), 'success');
	}

	async function generatePlannedFromBudgets() {
		if (!ws || monthClosed || mutationInFlight) return;
		const yearMonth = state.yearMonth;
		const seq = loadSeq;
		const btn = document.querySelector('[data-bc-action="generate-planned-budgets"]');
		btn?.setAttribute('aria-busy', 'true');
		if (btn) btn.disabled = true;
		mutationInFlight = true;
		try {
			const res = await Api.post('/apps/budgetcheck/api/budgets/generate-planned', {
				workspaceId: ws.id,
				yearMonth,
			});
			if (yearMonth !== state.yearMonth || seq !== loadSeq) return;
			announcePlannedSync(res.plannedSync);
			await loadAll();
		} catch (err) {
			Msg.handleApiError(err);
		} finally {
			mutationInFlight = false;
			btn?.setAttribute('aria-busy', 'false');
			if (btn) btn.disabled = monthClosed;
		}
	}

	function updateSavingsModeFields(form) {
		const mode = form.querySelector('input[name="targetMode"]:checked')?.value || 'percentage';
		const percentRow = form.querySelector('[data-bc-savings-percent-row]');
		const absoluteRow = form.querySelector('[data-bc-savings-absolute-row]');
		if (percentRow) percentRow.hidden = !(mode === 'percentage' || mode === 'hybrid');
		if (absoluteRow) absoluteRow.hidden = !(mode === 'absolute' || mode === 'hybrid');
	}

	function renderSavings(target) {
		const form = document.querySelector('[data-bc-savings-form]');
		if (!form) return;
		const sourceHint = form.querySelector('[data-bc-savings-source]');
		const mode = (target && target.targetMode) || 'percentage';
		form.querySelectorAll('input[name="targetMode"]').forEach((r) => { r.checked = r.value === mode; });
		const percentInput = form.querySelector('input[name="targetPercent"]');
		if (percentInput) percentInput.value = target && target.targetPercentBp !== null && target.targetPercentBp !== undefined ? String(Math.round(target.targetPercentBp / 100)) : '';
		const absoluteInput = form.querySelector('input[name="targetAmount"]');
		if (absoluteInput) absoluteInput.value = target && target.targetMinor ? (target.targetMinor / Math.pow(10, decimals)).toFixed(decimals).replace('.', ',') : '';
		if (sourceHint) {
			const inherited = !!(target && target.inheritedFromWorkspaceDefault);
			sourceHint.hidden = !inherited;
			if (inherited) {
				sourceHint.textContent = t('budgetcheck', 'This month currently uses the workspace default savings target. Saving here creates a month-specific override.');
			}
		}
		updateSavingsModeFields(form);
	}

	async function saveSavingsTarget(form) {
		if (!ws || mutationInFlight) return;
		const yearMonth = state.yearMonth;
		const seq = loadSeq;
		const mode = form.querySelector('input[name="targetMode"]:checked')?.value || 'percentage';
		const payload = { workspaceId: ws.id, yearMonth, targetMode: mode };
		const percentRaw = form.querySelector('input[name="targetPercent"]').value.trim();
		const absoluteRaw = form.querySelector('input[name="targetAmount"]').value.trim();
		try {
			if (mode === 'percentage' || mode === 'hybrid') {
				if (percentRaw === '') throw new Error(t('budgetcheck', 'Enter a percentage between 0 and 100.'));
				const pct = Number.parseInt(percentRaw, 10);
				if (!Number.isFinite(pct) || pct < 0 || pct > 100) throw new Error(t('budgetcheck', 'Percentage must be between 0 and 100.'));
				payload.targetPercentBp = pct * 100;
			}
			if (mode === 'absolute' || mode === 'hybrid') {
				if (absoluteRaw === '') throw new Error(t('budgetcheck', 'Enter an amount.'));
				payload.targetMinor = Money.parseHuman(absoluteRaw, decimals);
			}
		} catch (e) {
			Msg.announce(e.message, 'error');
			return;
		}
		mutationInFlight = true;
		const submitBtn = form.querySelector('[type="submit"]');
		if (submitBtn) submitBtn.disabled = true;
		try {
			await Api.post('/apps/budgetcheck/api/savings-target', payload);
			if (yearMonth !== state.yearMonth || seq !== loadSeq) return;
			Msg.announce(t('budgetcheck', 'Savings target saved.'), 'success');
			await loadAll();
		} catch (err) {
			Msg.handleApiError(err);
		} finally {
			mutationInFlight = false;
			if (submitBtn) submitBtn.disabled = false;
		}
	}
})();
