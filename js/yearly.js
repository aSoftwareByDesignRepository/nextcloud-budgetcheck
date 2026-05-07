(function () {
	'use strict';

	const Api = window.BudgetCheckApi;
	const Msg = window.BudgetCheckMessaging;
	const C = window.BudgetCheckComponents;
	const Money = window.BudgetCheckMoney;
	const Dates = window.BudgetCheckDates;
	const Ws = window.BudgetCheckWorkspace;

	const state = { year: new Date().getFullYear() };
	/** @type {{ id: number, type: string } | null} */
	let ws = null;
	/** @type {any | null} */
	let lastSummary = null;

	document.addEventListener('DOMContentLoaded', () => {
		ws = Ws.workspace;
		if (!ws || ws.type !== 'household') return;
		const helpBtn = document.querySelector('[data-bc-yearly-summary-help]');
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
		if (helpBtn) {
			helpBtn.addEventListener('click', () => openSummaryHelpModal());
		}
		load();
	});

	async function load() {
		const grid = document.querySelector('[data-bc-summary-grid]');
		const period = document.querySelector('[data-bc-summary-period]');
		const months = document.querySelector('[data-bc-month-cards]');
		grid?.setAttribute('aria-busy', 'true');
		months?.setAttribute('aria-busy', 'true');
		if (!ws) return;
		try {
			const data = await Api.get('/apps/budgetcheck/api/yearly-summary', { workspaceId: ws.id, year: state.year });
			const summary = normalizeSummary(data && data.summary ? data.summary : null);
			lastSummary = summary;
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
		const ratio = normalizeRatio(totals.savingsAchievementRatio);
		const tiles = [
			[t('budgetcheck', 'Income'), totals.income],
			[t('budgetcheck', 'Expenses'), totals.expense],
			[t('budgetcheck', 'Net result'), totals.netResult, true],
			[t('budgetcheck', 'Savings target'), totals.savingsTarget],
			[t('budgetcheck', 'Savings achieved'), totals.savingsAchieved],
			[t('budgetcheck', 'Budget saldo'), totals.budgetSaldo, true],
			[t('budgetcheck', 'Not spent (under budget)'), totals.budgetUnspent],
			[t('budgetcheck', 'Overspent (over budget)'), totals.budgetOverspent],
		];
		tiles.forEach(([label, env, primary]) => {
			grid.appendChild(C.createElement('div', { class: 'bc-summary-tile' + (primary ? ' bc-summary-tile--primary' : '') }, [
				C.createElement('div', { class: 'bc-summary-tile__label', text: label }),
				C.createElement('div', { class: 'bc-summary-tile__value', text: formatEnv(env) }),
			]));
		});
		grid.appendChild(C.createElement('div', { class: 'bc-summary-tile' }, [
			C.createElement('div', { class: 'bc-summary-tile__label', text: t('budgetcheck', 'Savings achievement') }),
			C.createElement('div', {
				class: 'bc-summary-tile__value bc-summary-tile__value--small',
				text: ratio === null ? t('budgetcheck', 'No target set') : Math.round(ratio * 100) + '%',
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
				C.createElement('span', {
					class: 'bc-month-card__meta',
					text: t('budgetcheck', 'Budget saldo: {saldo}')
						.replace('{saldo}', Money.formatEnvelope(m.budget?.saldo, Ws.htmlLang)),
				}),
				m.isClosed
					? C.createElement('span', { class: 'bc-month-card__meta', text: t('budgetcheck', 'Closed') })
					: null,
			]);
			container.appendChild(C.createElement('li', null, [card]));
		});
	}

	function openSummaryHelpModal() {
		const summary = lastSummary || normalizeSummary(null);
		const totals = summary.totals || {};
		const ratio = normalizeRatio(totals.savingsAchievementRatio);
		const ratioText = ratio === null
			? t('budgetcheck', 'No target set')
			: Math.round(ratio * 100) + '%';
		const rows = [
			{
				label: t('budgetcheck', 'Income'),
				value: formatEnv(totals.income),
				desc: t('budgetcheck', 'Total money received in this year.'),
			},
			{
				label: t('budgetcheck', 'Expenses'),
				value: formatEnv(totals.expense),
				desc: t('budgetcheck', 'Total money spent in this year.'),
			},
			{
				label: t('budgetcheck', 'Net result'),
				value: formatEnv(totals.netResult),
				desc: t('budgetcheck', 'Income minus expenses across the full year.'),
			},
			{
				label: t('budgetcheck', 'Savings target'),
				value: formatEnv(totals.savingsTarget),
				desc: t('budgetcheck', 'The total amount you planned to set aside this year.'),
			},
			{
				label: t('budgetcheck', 'Savings achieved'),
				value: formatEnv(totals.savingsAchieved),
				desc: t('budgetcheck', 'The total amount you actually set aside this year.'),
			},
			{
				label: t('budgetcheck', 'Savings achievement'),
				value: ratioText,
				desc: t('budgetcheck', 'How much of your yearly savings target was achieved.'),
			},
			{
				label: t('budgetcheck', 'Budget saldo'),
				value: formatEnv(totals.budgetSaldo),
				desc: t('budgetcheck', 'Total budget result. Negative means over budget, positive means under budget.'),
			},
			{
				label: t('budgetcheck', 'Not spent (under budget)'),
				value: formatEnv(totals.budgetUnspent),
				desc: t('budgetcheck', 'How much planned budget remained unused.'),
			},
			{
				label: t('budgetcheck', 'Overspent (over budget)'),
				value: formatEnv(totals.budgetOverspent),
				desc: t('budgetcheck', 'How much spending exceeded planned budgets.'),
			},
			{
				label: t('budgetcheck', 'Months over budget'),
				value: String(Number.parseInt(String(totals.overBudgetMonths || 0), 10) || 0),
				desc: t('budgetcheck', 'How many months in this year ended over budget.'),
			},
		];
		C.openModal({
			title: t('budgetcheck', 'Annual totals explained'),
			primaryLabel: t('budgetcheck', 'Close'),
			showCancel: false,
			render: () => C.createElement('div', { class: 'bc-yearly-help' }, [
				C.createElement('p', {
					class: 'bc-yearly-help__intro',
					text: t('budgetcheck', 'Quick reference for the values shown on this page.'),
				}),
				C.createElement('dl', { class: 'bc-yearly-help__list' }, rows.map((row) => C.createElement('div', { class: 'bc-yearly-help__row' }, [
					C.createElement('dt', { class: 'bc-yearly-help__term', text: row.label }),
					C.createElement('dd', { class: 'bc-yearly-help__def' }, [
						C.createElement('div', { class: 'bc-yearly-help__value', text: row.value }),
						C.createElement('div', { class: 'bc-yearly-help__desc', text: row.desc }),
					]),
				]))),
			]),
			onSubmit: () => true,
		});
	}

	function normalizeSummary(summary) {
		const base = summary && typeof summary === 'object' ? summary : {};
		const totals = base.totals && typeof base.totals === 'object' ? base.totals : {};
		const months = Array.isArray(base.months) ? base.months : [];
		return {
			year: Number.parseInt(String(base.year || state.year), 10) || state.year,
			totals: {
				income: normalizeEnv(totals.income),
				expense: normalizeEnv(totals.expense),
				netResult: normalizeEnv(totals.netResult),
				savingsTarget: normalizeEnv(totals.savingsTarget),
				savingsAchieved: normalizeEnv(totals.savingsAchieved),
				budgetSaldo: normalizeEnv(totals.budgetSaldo),
				budgetUnspent: normalizeEnv(totals.budgetUnspent),
				budgetOverspent: normalizeEnv(totals.budgetOverspent),
				savingsAchievementRatio: normalizeRatio(totals.savingsAchievementRatio),
				overBudgetMonths: Number.parseInt(String(totals.overBudgetMonths || 0), 10) || 0,
			},
			months,
		};
	}

	function normalizeEnv(env) {
		if (!env || typeof env !== 'object') return null;
		const minor = Number.parseInt(String(env.minor), 10);
		if (!Number.isFinite(minor)) return null;
		const currency = String(env.currency || ws?.currencyCode || '').trim();
		if (!currency) return null;
		const decimals = Number.parseInt(String(env.decimals), 10);
		return {
			minor,
			currency,
			decimals: Number.isFinite(decimals) && decimals >= 0 && decimals <= 8 ? decimals : 2,
		};
	}

	function normalizeRatio(value) {
		if (value === null || value === undefined) return null;
		const num = Number(value);
		if (!Number.isFinite(num)) return null;
		return Math.max(0, Math.min(10, num));
	}

	function formatEnv(env) {
		return env ? Money.formatEnvelope(env, Ws.htmlLang) : '—';
	}
})();
