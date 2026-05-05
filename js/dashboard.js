(function () {
	'use strict';

	const Api = window.BudgetCheckApi;
	const Msg = window.BudgetCheckMessaging;
	const C = window.BudgetCheckComponents;
	const Money = window.BudgetCheckMoney;
	const Dates = window.BudgetCheckDates;
	const Ws = window.BudgetCheckWorkspace;

	const dashState = { yearMonth: Dates.currentYearMonth() };
	/** Set on DOMContentLoaded after #app-content exists (see lazy workspace.js). */
	let ws = null;
	let isHousehold = false;
	let dashPeriodPicker = null;

	document.addEventListener('DOMContentLoaded', () => {
		ws = Ws.workspace;
		if (!ws) {
			return;
		}
		isHousehold = ws.type === 'household';
		const summarySection = document.querySelector('[data-bc-summary]');
		const box = summarySection?.querySelector('[data-bc-household-period]');
		const Period = window.BudgetCheckHouseholdPeriod;
		if (isHousehold && box && Period && typeof Period.wire === 'function') {
			dashPeriodPicker = Period.wire(box, {
				workspace: ws,
				htmlLang: Ws.htmlLang,
				initialYearMonth: dashState.yearMonth,
				onChange: (ym) => {
					dashState.yearMonth = ym;
					loadAndRender(ym);
				},
			});
		}
		loadAndRender(isHousehold ? dashState.yearMonth : null);
		loadRecent();
	});

	async function loadAndRender(yearMonth) {
		const grid = document.querySelector('[data-bc-summary-grid]');
		const periodLabel = document.querySelector('[data-bc-summary-period]');
		const warningsSection = document.querySelector('[data-bc-warnings]');
		const warningsList = document.querySelector('[data-bc-warnings-list]');
		if (!grid) return;
		grid.setAttribute('aria-busy', 'true');
		try {
			const data = isHousehold
				? await Api.get('/apps/budgetcheck/api/monthly-summary', { workspaceId: ws.id, yearMonth: yearMonth || Dates.currentYearMonth() })
				: await Api.get('/apps/budgetcheck/api/project-period-summary', { workspaceId: ws.id });
			const summary = data.summary;
			renderSummaryGrid(grid, summary);
			if (periodLabel) periodLabel.textContent = formatSummaryPeriod(summary);
			const dashLedger = document.querySelector('[data-bc-dash-ledger-help]');
			if (isHousehold && dashLedger) {
				C.renderMonthlyLedgerHelp(dashLedger, summary, yearMonth || Dates.currentYearMonth(), Ws.htmlLang);
			}
			if (isHousehold && dashPeriodPicker && summary.ledgerYearMonthSpan) {
				dashPeriodPicker.refreshLedgerSpan(summary.ledgerYearMonthSpan);
			}
			renderWarnings(warningsSection, warningsList, summary.warnings || []);
		} catch (err) {
			Msg.handleApiError(err);
			grid.replaceChildren(C.createElement('p', { class: 'bc-loading', text: t('budgetcheck', 'Could not load the summary.') }));
		} finally {
			grid.setAttribute('aria-busy', 'false');
		}
	}

	async function loadRecent() {
		const list = document.querySelector('[data-bc-recent-list]');
		if (!list) return;
		list.setAttribute('aria-busy', 'true');
		try {
			const data = await Api.get('/apps/budgetcheck/api/transactions', { workspaceId: ws.id, limit: 8, offset: 0 });
			const items = data.items || [];
			list.replaceChildren();
			if (items.length === 0) {
				list.appendChild(C.createElement('li', { class: 'bc-tx-list__item' }, [
					C.createElement('div', { class: 'bc-tx-list__title', text: t('budgetcheck', 'No transactions yet.') }),
					C.createElement('div', { class: 'bc-tx-list__meta', text: t('budgetcheck', 'Use the transactions screen to add your first entry.') }),
				]));
				return;
			}
			items.forEach((tx) => list.appendChild(renderTxListItem(tx)));
		} catch (err) {
			Msg.handleApiError(err);
			list.replaceChildren(C.createElement('li', { class: 'bc-loading', text: t('budgetcheck', 'Could not load transactions.') }));
		} finally {
			list.setAttribute('aria-busy', 'false');
		}
	}

	function renderTxListItem(tx) {
		const directionLabel = tx.direction === 'income' ? t('budgetcheck', 'Income') : t('budgetcheck', 'Expense');
		const amount = Money.formatEnvelope(tx.amount, Ws.htmlLang);
		return C.createElement('li', { class: 'bc-tx-list__item' }, [
			C.createElement('div', null, [
				C.createElement('div', { class: 'bc-tx-list__title', text: tx.title }),
				C.createElement('div', { class: 'bc-tx-list__meta', text: `${Dates.formatDisplayDate(tx.bookingDate, Ws.htmlLang)} · ${directionLabel}` }),
			]),
			C.createElement('div', {
				class: 'bc-tx-list__amount ' + (tx.direction === 'income' ? 'bc-tx-amount--income' : 'bc-tx-amount--expense'),
				text: (tx.direction === 'income' ? '+' : '−') + ' ' + amount,
			}),
		]);
	}

	function renderSummaryGrid(grid, summary) {
		grid.replaceChildren();
		const totals = summary.totals || {};
		const tiles = [];
		tiles.push(makeTile(t('budgetcheck', 'Income'), totals.income));
		tiles.push(makeTile(t('budgetcheck', 'Expenses'), totals.expense));
		tiles.push(makeTile(t('budgetcheck', 'Net result'), totals.netResult, { primary: true }));
		if (isHousehold) {
			tiles.push(makeTile(t('budgetcheck', 'Savings target'), totals.savingsTarget));
			tiles.push(makeTile(t('budgetcheck', 'Available after savings'), totals.availableAfterSavings, { hint: t('budgetcheck', 'After income − expenses − savings target') }));
		} else if (summary.allTime && summary.allTime.cap) {
			tiles.push(makeTile(t('budgetcheck', 'Cap'), summary.allTime.cap, { hint: t('budgetcheck', 'Project total cap') }));
			tiles.push(makeTile(t('budgetcheck', 'Spent so far'), summary.allTime.expense));
			tiles.push(makeTile(t('budgetcheck', 'Remaining headroom'), summary.allTime.remainingHeadroom, { primary: true }));
		}
		tiles.forEach((node) => grid.appendChild(node));
	}

	function makeTile(label, env, opts) {
		const o = opts || {};
		return C.createElement('div', { class: 'bc-summary-tile' + (o.primary ? ' bc-summary-tile--primary' : '') }, [
			C.createElement('div', { class: 'bc-summary-tile__label', text: label }),
			C.createElement('div', { class: 'bc-summary-tile__value', text: env ? Money.formatEnvelope(env, Ws.htmlLang) : '—' }),
			o.hint ? C.createElement('div', { class: 'bc-summary-tile__hint', text: o.hint }) : null,
		]);
	}

	function formatSummaryPeriod(summary) {
		if (isHousehold) {
			return Dates.formatYearMonth(summary.yearMonth, Ws.htmlLang);
		}
		const win = summary.window || {};
		if (!win.from || !win.to) return '';
		return Dates.formatDisplayDate(win.from, Ws.htmlLang) + ' – ' + Dates.formatDisplayDate(win.to, Ws.htmlLang);
	}

	function renderWarnings(section, list, warnings) {
		if (!section || !list) return;
		if (!warnings.length) {
			section.hidden = true;
			list.replaceChildren();
			return;
		}
		section.hidden = false;
		list.replaceChildren();
		warnings.forEach((w) => list.appendChild(renderWarning(w)));
	}

	function renderWarning(warning) {
		const sev = warning.severity || 'info';
		const item = C.createElement('li', { class: 'bc-warning bc-warning--' + sev });
		item.appendChild(C.createElement('span', { 'aria-hidden': 'true', text: sev === 'critical' ? '!' : (sev === 'warning' ? '⚠' : 'i') }));
		const body = C.createElement('div');
		body.appendChild(C.createElement('div', { class: 'bc-warning__title', text: warning.title || warning.code }));
		body.appendChild(C.createElement('div', { class: 'bc-warning__message', text: warning.message || '' }));
		item.appendChild(body);
		const recovery = warning.recovery;
		if (recovery && recovery.screen && Ws.urls[recovery.screen]) {
			let href = Ws.withWorkspace(Ws.urls[recovery.screen]);
			const params = recovery.params || {};
			Object.entries(params).forEach(([key, value]) => {
				if (value === null || value === undefined || value === '') return;
				href += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(String(value));
			});
			const link = C.createElement('a', { class: 'button', href, text: t('budgetcheck', 'Open') });
			item.appendChild(C.createElement('div', { class: 'bc-warning__action' }, [link]));
		} else {
			item.appendChild(C.createElement('div'));
		}
		return item;
	}

})();
