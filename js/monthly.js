(function () {
	'use strict';

	const Api = window.BudgetCheckApi;
	const Msg = window.BudgetCheckMessaging;
	const C = window.BudgetCheckComponents;
	const Money = window.BudgetCheckMoney;
	const Dates = window.BudgetCheckDates;
	const Ws = window.BudgetCheckWorkspace;

	const state = { yearMonth: Dates.currentYearMonth(), summary: null };
	let periodPicker = null;
	/** @type {{ id: number, type: string, currencyCode: string } | null} */
	let ws = null;

	document.addEventListener('DOMContentLoaded', () => {
		ws = Ws.workspace;
		if (!ws || ws.type !== 'household') return; // PageController also redirects, but be safe.
		const sp = new URLSearchParams(window.location.search);
		const ym = sp.get('yearMonth');
		if (ym && /^\d{4}-(0[1-9]|1[0-2])$/.test(ym)) state.yearMonth = ym;

		const box = document.querySelector('[data-bc-household-period]');
		const Period = window.BudgetCheckHouseholdPeriod;
		if (box && Period && typeof Period.wire === 'function') {
			periodPicker = Period.wire(box, {
				workspace: ws,
				htmlLang: Ws.htmlLang,
				initialYearMonth: state.yearMonth,
				onChange: (next) => {
					state.yearMonth = next;
					const u = new URL(window.location.href);
					u.searchParams.set('yearMonth', next);
					window.history.replaceState({}, '', u.toString());
					load();
				},
			});
		}
		const closeBtn = document.querySelector('[data-bc-action="close-month"]');
		const reopenBtn = document.querySelector('[data-bc-action="reopen-month"]');
		if (closeBtn) closeBtn.addEventListener('click', () => closeMonth());
		if (reopenBtn) reopenBtn.addEventListener('click', () => reopenMonth());
		load();
	});

	async function load() {
		const grid = document.querySelector('[data-bc-summary-grid]');
		const period = document.querySelector('[data-bc-summary-period]');
		const status = document.querySelector('[data-bc-month-status]');
		const warningsSection = document.querySelector('[data-bc-warnings]');
		const warningsList = document.querySelector('[data-bc-warnings-list]');
		const tbody = document.querySelector('[data-bc-month-budget-rows]');
		grid?.setAttribute('aria-busy', 'true');
		if (!ws) return;
		try {
			const data = await Api.get('/apps/budgetcheck/api/monthly-summary', { workspaceId: ws.id, yearMonth: state.yearMonth });
			state.summary = data.summary;
			renderSummary(grid, state.summary);
			if (period) period.textContent = Dates.formatYearMonth(state.summary.yearMonth, Ws.htmlLang);
			if (status) status.textContent = state.summary.isClosed
				? t('budgetcheck', 'This month is closed. Reopen it to make changes.')
				: t('budgetcheck', 'This month is open. Review totals before closing.');
			if (periodPicker && state.summary.ledgerYearMonthSpan) {
				periodPicker.refreshLedgerSpan(state.summary.ledgerYearMonthSpan);
			}
			const ledgerEl = document.querySelector('[data-bc-monthly-ledger-help]');
			C.renderMonthlyLedgerHelp(ledgerEl, state.summary, state.yearMonth, Ws.htmlLang);
			renderWarnings(warningsSection, warningsList, state.summary.warnings || []);
			renderConsumption(tbody, state.summary.budget || {});
			updateActionButtons(state.summary.isClosed);
		} catch (err) {
			Msg.handleApiError(err);
		} finally {
			grid?.setAttribute('aria-busy', 'false');
		}
	}

	function renderSummary(grid, summary) {
		if (!grid) return;
		grid.replaceChildren();
		const totals = summary.totals || {};
		[
			[t('budgetcheck', 'Income'), totals.income],
			[t('budgetcheck', 'Expenses'), totals.expense],
			[t('budgetcheck', 'Net result'), totals.netResult, true],
			[t('budgetcheck', 'Savings target'), totals.savingsTarget],
			[t('budgetcheck', 'Available after savings'), totals.availableAfterSavings],
		].forEach(([label, env, primary]) => {
			grid.appendChild(C.createElement('div', { class: 'bc-summary-tile' + (primary ? ' bc-summary-tile--primary' : '') }, [
				C.createElement('div', { class: 'bc-summary-tile__label', text: label }),
				C.createElement('div', { class: 'bc-summary-tile__value', text: env ? Money.formatEnvelope(env, Ws.htmlLang) : '—' }),
			]));
		});
		if (totals.tax && totals.taxBasis) {
			[
				[t('budgetcheck', 'Tax net total'), totals.tax.net],
				[t('budgetcheck', 'Tax VAT total'), totals.tax.vat],
				[t('budgetcheck', 'Tax gross total'), totals.tax.gross],
			].forEach(([label, env]) => {
				grid.appendChild(C.createElement('div', { class: 'bc-summary-tile' }, [
					C.createElement('div', { class: 'bc-summary-tile__label', text: label }),
					C.createElement('div', { class: 'bc-summary-tile__value', text: env ? Money.formatEnvelope(env, Ws.htmlLang) : '—' }),
				]));
			});
		}
	}

	function renderWarnings(section, list, warnings) {
		if (!section || !list) return;
		if (!warnings.length) {
			section.hidden = true; list.replaceChildren(); return;
		}
		section.hidden = false;
		list.replaceChildren();
		warnings.forEach((w) => {
			const item = C.createElement('li', { class: 'bc-warning bc-warning--' + (w.severity || 'info') });
			item.appendChild(C.createElement('span', { 'aria-hidden': 'true', text: 'i' }));
			const body = C.createElement('div');
			body.appendChild(C.createElement('div', { class: 'bc-warning__title', text: w.title || w.code }));
			body.appendChild(C.createElement('div', { class: 'bc-warning__message', text: w.message || '' }));
			item.appendChild(body);
			item.appendChild(C.createElement('div'));
			list.appendChild(item);
		});
	}

	function renderConsumption(tbody, budget) {
		if (!tbody || !ws) return;
		tbody.replaceChildren();
		const rows = budget.byCategory || [];
		if (rows.length === 0) {
			tbody.appendChild(C.createElement('tr', null, [
				C.createElement('td', { attrs: { colspan: '4' }, class: 'bc-loading', text: t('budgetcheck', 'No category activity this month.') }),
			]));
			return;
		}
		rows.forEach((row) => {
			const tr = C.createElement('tr');
			tr.appendChild(C.createElement('td', { text: row.name }));
			tr.appendChild(C.createElement('td', { class: 'bc-table__col--num', text: Money.formatEnvelope(row.planned, Ws.htmlLang) }));
			tr.appendChild(C.createElement('td', { class: 'bc-table__col--num', text: Money.formatEnvelope(row.actual, Ws.htmlLang) }));
			const remainingMinor = (row.planned?.minor || 0) - (row.actual?.minor || 0);
			tr.appendChild(C.createElement('td', {
				class: 'bc-table__col--num' + (remainingMinor < 0 ? ' bc-tx-amount--expense' : ''),
				text: Money.formatEnvelope(row.remaining || { minor: remainingMinor, currency: ws.currencyCode, decimals: 2 }, Ws.htmlLang),
			}));
			tbody.appendChild(tr);
		});
	}

	function updateActionButtons(isClosed) {
		const closeBtn = document.querySelector('[data-bc-action="close-month"]');
		const reopenBtn = document.querySelector('[data-bc-action="reopen-month"]');
		if (closeBtn) {
			closeBtn.disabled = isClosed;
			closeBtn.hidden = isClosed;
		}
		if (reopenBtn) {
			reopenBtn.hidden = !isClosed;
		}
	}

	async function closeMonth() {
		if (!ws) return;
		const ok = await C.confirmDialog({
			title: t('budgetcheck', 'Close this month?'),
			body: t('budgetcheck', 'Closing locks the ledger for {month} and stores an immutable snapshot. Reopening requires a manager.').replace('{month}', Dates.formatYearMonth(state.yearMonth, Ws.htmlLang)),
			confirmLabel: t('budgetcheck', 'Yes, close month'),
			danger: true,
		});
		if (!ok) return;
		try {
			await Api.post('/apps/budgetcheck/api/monthly-close', { workspaceId: ws.id, yearMonth: state.yearMonth });
			Msg.announce(t('budgetcheck', 'Month closed and snapshot stored.'), 'success');
			load();
		} catch (err) {
			Msg.handleApiError(err);
		}
	}

	async function reopenMonth() {
		if (!ws) return;
		const ok = await C.confirmDialog({
			title: t('budgetcheck', 'Reopen this month?'),
			body: t('budgetcheck', 'Reopening removes the snapshot and re-enables edits. The action is logged.'),
			confirmLabel: t('budgetcheck', 'Yes, reopen month'),
			danger: true,
		});
		if (!ok) return;
		try {
			await Api.post('/apps/budgetcheck/api/monthly-reopen', { workspaceId: ws.id, yearMonth: state.yearMonth });
			Msg.announce(t('budgetcheck', 'Month reopened.'), 'success');
			load();
		} catch (err) {
			Msg.handleApiError(err);
		}
	}
})();
