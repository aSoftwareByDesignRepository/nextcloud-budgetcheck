(function () {
	'use strict';

	const Api = window.BudgetCheckApi;
	const Msg = window.BudgetCheckMessaging;
	const C = window.BudgetCheckComponents;
	const Money = window.BudgetCheckMoney;
	const Dates = window.BudgetCheckDates;
	const Ws = window.BudgetCheckWorkspace;

	if (!Ws.workspace) return;
	const ws = Ws.workspace;
	if (ws.type !== 'household') return;

	const state = { year: new Date().getFullYear() };

	document.addEventListener('DOMContentLoaded', () => {
		const yearSelect = document.querySelector('[data-bc-year-picker]');
		if (yearSelect) {
			const yNow = new Date().getFullYear();
			for (let y = yNow + 1; y >= yNow - 15; y--) {
				yearSelect.appendChild(C.createElement('option', { value: String(y), text: String(y) }));
			}
			yearSelect.value = String(state.year);
			yearSelect.addEventListener('change', () => {
				const v = Number.parseInt(yearSelect.value, 10);
				if (Number.isFinite(v) && v >= 1900 && v <= 9999) {
					state.year = v;
					load();
				}
			});
		}
		load();
	});

	async function load() {
		const grid = document.querySelector('[data-bc-summary-grid]');
		const period = document.querySelector('[data-bc-summary-period]');
		const months = document.querySelector('[data-bc-month-cards]');
		grid?.setAttribute('aria-busy', 'true');
		months?.setAttribute('aria-busy', 'true');
		try {
			const data = await Api.get('/apps/budgetcheck/api/yearly-summary', { workspaceId: ws.id, year: state.year });
			const summary = data.summary;
			renderTotals(grid, summary);
			if (period) period.textContent = String(summary.year);
			renderMonths(months, summary);
		} catch (err) {
			Msg.handleApiError(err);
		} finally {
			grid?.setAttribute('aria-busy', 'false');
			months?.setAttribute('aria-busy', 'false');
		}
	}

	function renderTotals(grid, summary) {
		if (!grid) return;
		grid.replaceChildren();
		const totals = summary.totals || {};
		const ratio = totals.savingsAchievementRatio;
		const tiles = [
			[t('budgetcheck', 'Income'), totals.income],
			[t('budgetcheck', 'Expenses'), totals.expense],
			[t('budgetcheck', 'Net result'), totals.netResult, true],
			[t('budgetcheck', 'Savings target'), totals.savingsTarget],
			[t('budgetcheck', 'Savings achieved'), totals.savingsAchieved],
		];
		tiles.forEach(([label, env, primary]) => {
			grid.appendChild(C.createElement('div', { class: 'bc-summary-tile' + (primary ? ' bc-summary-tile--primary' : '') }, [
				C.createElement('div', { class: 'bc-summary-tile__label', text: label }),
				C.createElement('div', { class: 'bc-summary-tile__value', text: env ? Money.formatEnvelope(env, Ws.htmlLang) : '—' }),
			]));
		});
		grid.appendChild(C.createElement('div', { class: 'bc-summary-tile' }, [
			C.createElement('div', { class: 'bc-summary-tile__label', text: t('budgetcheck', 'Savings achievement') }),
			C.createElement('div', {
				class: 'bc-summary-tile__value bc-summary-tile__value--small',
				text: ratio === null || ratio === undefined ? t('budgetcheck', 'No target set') : Math.round(ratio * 100) + '%',
			}),
		]));
		grid.appendChild(C.createElement('div', { class: 'bc-summary-tile' }, [
			C.createElement('div', { class: 'bc-summary-tile__label', text: t('budgetcheck', 'Months over budget') }),
			C.createElement('div', { class: 'bc-summary-tile__value bc-summary-tile__value--small', text: String(totals.overBudgetMonths || 0) }),
		]));
	}

	function renderMonths(container, summary) {
		if (!container) return;
		container.replaceChildren();
		(summary.months || []).forEach((m) => {
			const url = Ws.withWorkspace(Ws.urls.monthly) + '&yearMonth=' + encodeURIComponent(m.yearMonth);
			const klass = ['bc-month-card'];
			if (m.overBudget) klass.push('bc-month-card--over');
			if (m.isClosed) klass.push('bc-month-card--closed');
			const card = C.createElement('a', { href: url, class: klass.join(' ') }, [
				C.createElement('span', { class: 'bc-month-card__name', text: Dates.formatYearMonth(m.yearMonth, Ws.htmlLang) }),
				C.createElement('span', { class: 'bc-month-card__net', text: Money.formatEnvelope(m.netResult, Ws.htmlLang) }),
				C.createElement('span', {
					class: 'bc-month-card__meta',
					text: t('budgetcheck', 'In: {income} · Out: {expense}')
						.replace('{income}', Money.formatEnvelope(m.income, Ws.htmlLang))
						.replace('{expense}', Money.formatEnvelope(m.expense, Ws.htmlLang)),
				}),
				m.isClosed
					? C.createElement('span', { class: 'bc-month-card__meta', text: t('budgetcheck', 'Closed') })
					: null,
			]);
			container.appendChild(C.createElement('li', null, [card]));
		});
	}
})();
