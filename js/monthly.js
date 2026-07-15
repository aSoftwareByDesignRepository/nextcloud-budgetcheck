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
	let includeSpecials = false;
	const SpecialsView = window.BudgetCheckSpecialsView;
	/** @type {{ id: number, type: string, currencyCode: string } | null} */
	let ws = null;

	document.addEventListener('DOMContentLoaded', () => {
		ws = Ws.workspace;
		if (!ws || ws.type !== 'household') return; // PageController also redirects, but be safe.
		if (SpecialsView) {
			includeSpecials = SpecialsView.getIncludeSpecials(ws.id);
			void SpecialsView.migrateLegacyLocalStorage(ws.id).then(() => {
				includeSpecials = SpecialsView.getIncludeSpecials(ws.id);
				if (state.summary) {
					const grid = document.querySelector('[data-bc-summary-grid]');
					renderSummary(grid, state.summary);
				}
			});
		}
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
		const budgetOverridesBtn = document.querySelector('[data-bc-action="open-month-budget-overrides"]');
		if (closeBtn) closeBtn.addEventListener('click', () => closeMonth());
		if (reopenBtn) reopenBtn.addEventListener('click', () => reopenMonth());
		if (budgetOverridesBtn) budgetOverridesBtn.addEventListener('click', () => openBudgetOverridesModal());
		load();
		wireIncludeSpecialsRefresh();
	});

	function wireIncludeSpecialsRefresh() {
		if (!SpecialsView || !ws) return;
		window.addEventListener('pageshow', (event) => {
			if (!event.persisted) return;
			const next = SpecialsView.refreshIncludeSpecials(ws.id, includeSpecials);
			if (next === includeSpecials) return;
			includeSpecials = next;
			const grid = document.querySelector('[data-bc-summary-grid]');
			if (grid && state.summary) {
				renderSummary(grid, state.summary);
			}
		});
	}

	async function load() {
		const grid = document.querySelector('[data-bc-summary-grid]');
		const period = document.querySelector('[data-bc-summary-period]');
		const status = document.querySelector('[data-bc-month-status]');
		const warningsSection = document.querySelector('[data-bc-warnings]');
		const warningsList = document.querySelector('[data-bc-warnings-list]');
		const tbody = document.querySelector('[data-bc-month-budget-rows]');
		const activityGrid = document.querySelector('[data-bc-month-activity-grid]');
		const txRows = document.querySelector('[data-bc-month-transactions-rows]');
		const txLink = document.querySelector('[data-bc-month-transactions-link]');
		grid?.setAttribute('aria-busy', 'true');
		activityGrid?.setAttribute('aria-busy', 'true');
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
			renderActivity(activityGrid, state.summary.activity || null);
			renderMonthTransactions(txRows, state.summary.monthTransactions || []);
			if (txLink && ws) {
				txLink.href = '/index.php/apps/budgetcheck/transactions?workspaceId='
					+ encodeURIComponent(String(ws.id))
					+ '&yearMonth=' + encodeURIComponent(String(state.yearMonth));
			}
			updateActionButtons(state.summary.isClosed);
		} catch (err) {
			Msg.handleApiError(err);
		} finally {
			grid?.setAttribute('aria-busy', 'false');
			activityGrid?.setAttribute('aria-busy', 'false');
		}
	}

	function renderSummary(grid, summary) {
		C.renderHouseholdSummaryTiles(grid, summary, Ws.htmlLang, { includeSpecials });
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
		const everyday = rows.filter((row) => !row.isSavingsTransfer);
		const savings = rows.filter((row) => row.isSavingsTransfer);
		const appendSection = (label, sectionRows) => {
			if (!sectionRows.length) return;
			const sectionRow = C.createElement('tr', { class: 'bc-table__section' });
			sectionRow.appendChild(C.createElement('th', {
				attrs: { colspan: '4', scope: 'colgroup' },
				class: 'bc-table__section-label',
				text: label,
			}));
			tbody.appendChild(sectionRow);
			sectionRows.forEach((row) => tbody.appendChild(renderConsumptionRow(row)));
		};
		appendSection(t('budgetcheck', 'Everyday spending'), everyday);
		appendSection(t('budgetcheck', 'Savings transfers'), savings);
	}

	function renderConsumptionRow(row) {
			const tr = C.createElement('tr');
			const hasBudget = !!row.hasBudget && !!row.planned;
			const actualMinor = Number.parseInt(String(row.actual?.minor ?? 0), 10) || 0;
			const plannedMinor = hasBudget ? (Number.parseInt(String(row.planned?.minor ?? 0), 10) || 0) : null;
			const remainingMinor = hasBudget && row.remaining
				? (Number.parseInt(String(row.remaining.minor ?? 0), 10) || 0)
				: null;
			const direction = String(row.direction || '');
			const categoryLink = C.createElement('a', {
				href: monthlyCategoryLink(row.categoryId),
				text: row.name,
				attrs: { title: t('budgetcheck', 'Open in transactions view') },
			});
			tr.appendChild(C.createElement('td', null, [categoryLink]));
			tr.appendChild(C.createElement('td', {
				class: 'bc-table__col--num',
				text: hasBudget ? Money.formatEnvelope(row.planned, Ws.htmlLang) : t('budgetcheck', 'No target set'),
			}));
			let actualClass = 'bc-table__col--num';
			if (direction === 'income' && actualMinor > 0) {
				actualClass += ' bc-tx-amount--income';
			} else if (direction === 'expense' && actualMinor > 0) {
				actualClass += ' bc-tx-amount--expense';
			}
			tr.appendChild(C.createElement('td', { class: actualClass, text: Money.formatEnvelope(row.actual, Ws.htmlLang) }));
			let remainingClass = 'bc-table__col--num';
			if (remainingMinor !== null) {
				if (direction === 'income') {
					remainingClass += remainingMinor < 0 ? ' bc-tx-amount--income' : ' bc-tx-amount--expense';
				} else {
					remainingClass += remainingMinor < 0 ? ' bc-tx-amount--expense' : ' bc-tx-amount--income';
				}
			}
			tr.appendChild(C.createElement('td', {
				class: remainingClass,
				text: remainingMinor === null
					? '—'
					: Money.formatEnvelope(
						row.remaining || { minor: remainingMinor, currency: ws.currencyCode, decimals: ws.currencyDecimals || 2 },
						Ws.htmlLang
					),
			}));
		return tr;
	}

	function monthlyCategoryLink(categoryId) {
		const wsId = ws ? String(ws.id) : '';
		const ym = String(state.yearMonth || '');
		return '/index.php/apps/budgetcheck/transactions?workspaceId='
			+ encodeURIComponent(wsId)
			+ '&yearMonth=' + encodeURIComponent(ym)
			+ '&categoryId=' + encodeURIComponent(String(categoryId || ''));
	}

	function renderActivity(grid, activity) {
		if (!grid) return;
		grid.replaceChildren();
		if (!activity) {
			grid.appendChild(C.createElement('p', { class: 'bc-loading', text: t('budgetcheck', 'No data available.') }));
			return;
		}
		const first = activity.firstDate ? Dates.formatDisplayDate(activity.firstDate, Ws.htmlLang) : '—';
		const last = activity.lastDate ? Dates.formatDisplayDate(activity.lastDate, Ws.htmlLang) : '—';
		const plannedCount = Number.parseInt(String(activity.plannedCount ?? state.summary?.planned?.ledger?.entryCount ?? 0), 10) || 0;
		const activityTiles = [
			[t('budgetcheck', 'Actual bookings'), String(activity.count || 0), true],
			[t('budgetcheck', 'Income bookings'), String(activity.incomeCount || 0)],
			[t('budgetcheck', 'Expense bookings'), String(activity.expenseCount || 0)],
			[t('budgetcheck', 'Special bookings'), String(activity.specialCount || 0)],
		];
		if (plannedCount > 0) {
			activityTiles.push([t('budgetcheck', 'Planned placeholders'), String(plannedCount)]);
		}
		activityTiles.push(
			[t('budgetcheck', 'First booking'), first],
			[t('budgetcheck', 'Last booking'), last],
		);
		activityTiles.forEach(([label, value, primary]) => {
			grid.appendChild(C.createElement('div', { class: 'bc-summary-tile' + (primary ? ' bc-summary-tile--primary' : '') }, [
				C.createElement('div', { class: 'bc-summary-tile__label', text: label }),
				C.createElement('div', { class: 'bc-summary-tile__value', text: value }),
			]));
		});
	}

	function renderMonthTransactions(tbody, rows) {
		if (!tbody) return;
		tbody.replaceChildren();
		if (!rows.length) {
			tbody.appendChild(C.createElement('tr', null, [
				C.createElement('td', { attrs: { colspan: '5' }, class: 'bc-loading', text: t('budgetcheck', 'No transactions this month.') }),
			]));
			return;
		}
		rows.forEach((row) => {
			const tr = C.createElement('tr', {
				class: row.isPlanned ? 'bc-table__row--planned' : '',
			});
			tr.appendChild(C.createElement('td', { text: Dates.formatDisplayDate(row.date, Ws.htmlLang) }));
			tr.appendChild(C.createElement('td', { text: row.title || ('#' + row.id) }));
			tr.appendChild(C.createElement('td', { text: row.direction === 'income' ? t('budgetcheck', 'Income') : t('budgetcheck', 'Expense') }));
			const amountClass = 'bc-table__col--num ' + (row.direction === 'income' ? 'bc-tx-amount--income' : 'bc-tx-amount--expense');
			tr.appendChild(C.createElement('td', { class: amountClass, text: Money.formatEnvelope(row.amount, Ws.htmlLang) }));
			const tags = [];
			if (row.isPlanned) tags.push(t('budgetcheck', 'Planned'));
			if (row.isSpecial) tags.push(t('budgetcheck', 'Special'));
			tr.appendChild(C.createElement('td', { text: tags.length ? tags.join(', ') : '—' }));
			tbody.appendChild(tr);
		});
	}

	function activeDecimals() {
		if (!ws) return 2;
		return typeof ws.currencyDecimals === 'number' ? ws.currencyDecimals : (ws.currencyCode === 'JPY' ? 0 : 2);
	}

	async function openBudgetOverridesModal() {
		if (!ws || !Ws.canManage) return;
		if (state.summary && state.summary.isClosed) {
			Msg.announce(t('budgetcheck', 'This month is closed. Reopen it to make changes.'), 'warning');
			return;
		}
		let categories = [];
		let budgets = [];
		let defaults = [];
		try {
			const data = await Promise.all([
				Api.get('/apps/budgetcheck/api/categories', { workspaceId: ws.id }),
				Api.get('/apps/budgetcheck/api/budgets', { workspaceId: ws.id, yearMonth: state.yearMonth }),
				Api.get('/apps/budgetcheck/api/budget-defaults', { workspaceId: ws.id }),
			]);
			categories = window.BudgetCheckConstants.budgetableCategories(data[0]?.categories || []);
			budgets = data[1]?.budgets || [];
			defaults = data[2]?.defaults || [];
		} catch (err) {
			Msg.handleApiError(err);
			return;
		}

		const monthPlannedByCategory = new Map();
		budgets.forEach((row) => {
			if (row && Number.isInteger(row.categoryId) && row.planned) {
				monthPlannedByCategory.set(row.categoryId, row.planned);
			}
		});
		const defaultPlannedByCategory = new Map();
		defaults.forEach((row) => {
			if (row && Number.isInteger(row.categoryId) && row.planned) {
				defaultPlannedByCategory.set(row.categoryId, row.planned);
			}
		});
		const actualByCategory = new Map();
		const budgetRows = state.summary?.budget?.byCategory || [];
		budgetRows.forEach((row) => {
			if (row && Number.isInteger(row.categoryId) && row.actual) {
				actualByCategory.set(row.categoryId, row.actual);
			}
		});
		const dirty = new Map();
		const decimals = activeDecimals();

		C.openModal({
			title: t('budgetcheck', 'Monthly budget overrides'),
			primaryLabel: t('budgetcheck', 'Save changes'),
			dialogClass: 'bc-modal__dialog--wide',
			render: () => {
				const form = C.createElement('form', { class: 'bc-form-grid bc-modal__form bc-modal__form--budget-overrides' });
				form.appendChild(C.createElement('p', {
					class: 'bc-field__hint bc-field__hint--block',
					text: t('budgetcheck', 'Set only exceptions for this month. Leave blank to use workspace defaults.'),
				}));
				const scroll = C.createElement('div', { class: 'bc-table-scroll', attrs: { role: 'region', tabindex: '0', 'aria-label': t('budgetcheck', 'Monthly overrides') } });
				const table = C.createElement('table', { class: 'bc-table bc-budget-table' });
				table.appendChild(C.createElement('thead', null, [
					C.createElement('tr', null, [
						C.createElement('th', { attrs: { scope: 'col' }, text: t('budgetcheck', 'Category') }),
						C.createElement('th', { attrs: { scope: 'col' }, class: 'bc-table__col--num', text: t('budgetcheck', 'Default') }),
						C.createElement('th', { attrs: { scope: 'col' }, class: 'bc-table__col--num', text: t('budgetcheck', 'Override') }),
						C.createElement('th', { attrs: { scope: 'col' }, class: 'bc-table__col--num', text: t('budgetcheck', 'Actual') }),
						C.createElement('th', { attrs: { scope: 'col' }, text: t('budgetcheck', 'Action') }),
					]),
				]));
				const tbody = C.createElement('tbody');
				if (!categories.length) {
					tbody.appendChild(C.createElement('tr', null, [
						C.createElement('td', { attrs: { colspan: '5' }, class: 'bc-loading', text: t('budgetcheck', 'Add income or expense categories first.') }),
					]));
				} else {
					let lastType = null;
					categories.forEach((cat) => {
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
						const defaultEnv = defaultPlannedByCategory.get(cat.id) || null;
						const plannedEnv = monthPlannedByCategory.get(cat.id) || null;
						const actualEnv = actualByCategory.get(cat.id) || { minor: 0, currency: ws.currencyCode, decimals };
						const tr = C.createElement('tr');
						tr.appendChild(C.createElement('td', { text: cat.name }));
						tr.appendChild(C.createElement('td', {
							class: 'bc-table__col--num',
							text: defaultEnv ? Money.formatEnvelope(defaultEnv, Ws.htmlLang) : '—',
						}));
						const input = C.createElement('input', {
							type: 'text',
							inputmode: 'decimal',
							class: 'bc-input',
							value: plannedEnv ? (plannedEnv.minor / Math.pow(10, decimals)).toFixed(decimals).replace('.', ',') : '',
							attrs: { 'aria-label': t('budgetcheck', 'Override amount for {category}').replace('{category}', cat.name) },
						});
						input.addEventListener('input', () => {
							dirty.set(cat.id, input.value);
						});
						tr.appendChild(C.createElement('td', { class: 'bc-table__col--num' }, [input]));
						tr.appendChild(C.createElement('td', {
							class: 'bc-table__col--num',
							text: Money.formatEnvelope(actualEnv, Ws.htmlLang),
						}));
						const resetBtn = C.createElement('button', {
							type: 'button',
							class: 'button',
							text: t('budgetcheck', 'Use workspace baseline'),
						});
						resetBtn.addEventListener('click', () => {
							input.value = '';
							dirty.set(cat.id, '');
							Msg.announce(
								t('budgetcheck', 'Monthly exception cleared for {category}.')
									.replace('{category}', cat.name),
								'success',
							);
						});
						tr.appendChild(C.createElement('td', null, [resetBtn]));
						tbody.appendChild(tr);
					});
				}
				table.appendChild(tbody);
				scroll.appendChild(table);
				form.appendChild(scroll);
				form._collect = () => {
					const rows = [];
					dirty.forEach((raw, categoryId) => {
						const value = String(raw || '').trim();
						if (value === '') {
							rows.push({ categoryId, plannedMinor: 0 });
						} else {
							rows.push({ categoryId, plannedMinor: Money.parseHuman(value, decimals) });
						}
					});
					return rows;
				};
				return form;
			},
			onSubmit: async ({ close, body }) => {
				const form = body;
				const rows = form && form._collect ? form._collect() : [];
				if (!rows.length) {
					Msg.announce(t('budgetcheck', 'No changes to save.'), 'success');
					close(true);
					return true;
				}
				try {
					await Api.post('/apps/budgetcheck/api/budgets/bulk-upsert', {
						workspaceId: ws.id,
						yearMonth: state.yearMonth,
						rows,
					});
					Msg.announce(t('budgetcheck', 'Monthly overrides saved.'), 'success');
					await load();
					close(true);
				} catch (err) {
					Msg.handleApiError(err);
					return false;
				}
			},
		});
	}

	function updateActionButtons(isClosed) {
		const closeBtn = document.querySelector('[data-bc-action="close-month"]');
		const reopenBtn = document.querySelector('[data-bc-action="reopen-month"]');
		const overridesBtn = document.querySelector('[data-bc-action="open-month-budget-overrides"]');
		if (closeBtn) {
			closeBtn.disabled = isClosed;
			closeBtn.hidden = isClosed;
		}
		if (reopenBtn) {
			reopenBtn.hidden = !isClosed;
		}
		if (overridesBtn) {
			// Budgets of a closed month are part of the snapshot evidence and
			// therefore read-only until the month is reopened.
			overridesBtn.disabled = isClosed;
			overridesBtn.title = isClosed ? t('budgetcheck', 'This month is closed. Reopen it to make changes.') : '';
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
