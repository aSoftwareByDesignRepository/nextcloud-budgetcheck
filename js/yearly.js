(function () {
	'use strict';

	/** @type {any} */
	let Api;
	/** @type {any} */
	let Msg;
	/** @type {any} */
	let C;
	/** @type {any} */
	let Money;
	/** @type {any} */
	let Dates;
	/** @type {any} */
	let Ws;
	/** @type {any} */
	let SpecialsView;


	const state = { year: new Date().getFullYear(), compareYear: new Date().getFullYear() - 1 };
	/** Guards against a slow response for a previously selected year overwriting the latest one. */
	let loadSeq = 0;
	let cmpSeq = 0;
	/** @type {{ id: number, type: string } | null} */
	let ws = null;
	/** @type {any | null} */
	let lastSummary = null;
	/** @type {any | null} */
	let lastCompare = null;
	let includeSpecials = false;

	function pageInit() {
		if (!Ws || typeof Ws !== 'object') return;
		ws = Ws.workspace;
		if (!ws || ws.type !== 'household') return;
		if (SpecialsView) {
			includeSpecials = SpecialsView.getIncludeSpecials(ws.id);
			void SpecialsView.migrateLegacyLocalStorage(ws.id).then(() => {
				includeSpecials = SpecialsView.getIncludeSpecials(ws.id);
				if (lastSummary) {
					const grid = document.querySelector('[data-bc-summary-grid]');
					const months = document.querySelector('[data-bc-month-cards]');
					renderTotals(grid, lastSummary);
					renderMonths(months, lastSummary);
				}
				const cmpBody = document.querySelector('[data-bc-compare-body]');
				if (cmpBody && lastCompare) {
					renderCompare(cmpBody, lastCompare);
				}
			});
		}
		const helpBtn = document.querySelector('[data-bc-yearly-summary-help]');
		const exportBtn = document.querySelector('[data-bc-yearly-export]');
		const yearSelect = document.querySelector('[data-bc-year-picker]');
		const cmpSelect = document.querySelector('[data-bc-compare-picker]');
		if (yearSelect) {
			const yNow = new Date().getFullYear();
			for (let y = yNow + 1; y >= yNow - 15; y--) {
				yearSelect.appendChild(C.createElement('option', { value: String(y), text: String(y) }));
				cmpSelect?.appendChild(C.createElement('option', { value: String(y), text: String(y) }));
			}
			yearSelect.value = String(state.year);
			yearSelect.addEventListener('change', () => {
				const v = Number.parseInt(yearSelect.value, 10);
				if (Number.isFinite(v) && v >= 1900 && v <= 9999) {
					state.year = v;
					syncComparePicker();
					load();
					loadCompare();
				}
			});
		}
		syncComparePicker();
		cmpSelect?.addEventListener('change', () => {
			const v = Number.parseInt(cmpSelect.value, 10);
			if (Number.isFinite(v) && v >= 1900 && v <= 9999 && v !== state.year) {
				state.compareYear = v;
				loadCompare();
			}
		});
		if (helpBtn) {
			helpBtn.addEventListener('click', () => openSummaryHelpModal());
		}
		if (exportBtn) {
			exportBtn.addEventListener('click', () => exportWorkbook(exportBtn));
		}
		load();
		loadCompare();
		wireIncludeSpecialsRefresh();
	}

	function wireIncludeSpecialsRefresh() {
		if (!SpecialsView || !ws) return;
		window.addEventListener('pageshow', (event) => {
			if (!event.persisted) return;
			const next = SpecialsView.refreshIncludeSpecials(ws.id, includeSpecials);
			if (next === includeSpecials) return;
			includeSpecials = next;
			const grid = document.querySelector('[data-bc-summary-grid]');
			const months = document.querySelector('[data-bc-month-cards]');
			if (lastSummary) {
				if (grid) renderTotals(grid, lastSummary);
				if (months) renderMonths(months, lastSummary);
			}
			const cmpBody = document.querySelector('[data-bc-compare-body]');
			if (cmpBody && lastCompare) {
				renderCompare(cmpBody, lastCompare);
			}
		});
	}

	async function load() {
		const seq = ++loadSeq;
		const grid = document.querySelector('[data-bc-summary-grid]');
		const period = document.querySelector('[data-bc-summary-period]');
		const months = document.querySelector('[data-bc-month-cards]');
		grid?.setAttribute('aria-busy', 'true');
		months?.setAttribute('aria-busy', 'true');
		if (!ws) return;
		try {
			const data = await Api.get('/apps/budgetcheck/api/yearly-summary', { workspaceId: ws.id, year: state.year });
			if (seq !== loadSeq) return; // Stale response; the latest request wins.
			const summary = normalizeSummary(data && data.summary ? data.summary : null);
			lastSummary = summary;
			renderTotals(grid, summary);
			if (period) period.textContent = String(summary.year);
			renderMonths(months, summary);
		} catch (err) {
			if (seq !== loadSeq) return;
			Msg.handleApiError(err);
		} finally {
			if (seq === loadSeq) {
				grid?.setAttribute('aria-busy', 'false');
				months?.setAttribute('aria-busy', 'false');
			}
		}
	}

	/** Keeps the compare picker in a valid state: never offers state.year. */
	function syncComparePicker() {
		const cmpSelect = document.querySelector('[data-bc-compare-picker]');
		if (!cmpSelect) return;
		if (state.compareYear === state.year) {
			state.compareYear = state.year - 1;
		}
		for (const opt of cmpSelect.options) {
			opt.disabled = Number(opt.value) === state.year;
		}
		cmpSelect.value = String(state.compareYear);
	}

	function effMinor(side) {
		if (!side || typeof side !== 'object') return 0;
		const a = Number.parseInt(String(side.actual?.minor ?? 0), 10) || 0;
		const s = Number.parseInt(String(side.specials?.minor ?? 0), 10) || 0;
		return includeSpecials ? a + s : a;
	}

	function effDeltaKind(aEff, bEff) {
		if (bEff === 0) return aEff !== 0 ? 'new' : 'flat';
		if (aEff === 0) return 'removed';
		const d = aEff - bEff;
		return d > 0 ? 'up' : (d < 0 ? 'down' : 'flat');
	}

	async function loadCompare() {
		const seq = ++cmpSeq;
		const body = document.querySelector('[data-bc-compare-body]');
		if (!body) return;
		body.setAttribute('aria-busy', 'true');
		if (!ws) return;
		try {
			const data = await Api.get('/apps/budgetcheck/api/yearly-category-compare', {
				workspaceId: ws.id, year: state.year, compareYear: state.compareYear,
			});
			if (seq !== cmpSeq) return; // stale response — latest request wins
			lastCompare = data && data.compare ? data.compare : null;
			renderCompare(body, lastCompare);
		} catch (err) {
			if (seq !== cmpSeq) return;
			lastCompare = null;
			body.replaceChildren(C.createElement('p', {
				class: 'bc-empty-text',
				text: t('budgetcheck', 'The category comparison could not be loaded.'),
			}));
			Msg.handleApiError(err);
		} finally {
			if (seq === cmpSeq) body.setAttribute('aria-busy', 'false');
		}
	}

	const DELTA_LABELS = {
		up: '▲', down: '▼', flat: '=',
		new: null, removed: null, // text badges instead of arrows
	};

	function deltaCell(cat, aEff, bEff) {
		const d = aEff - bEff;
		const kind = effDeltaKind(aEff, bEff);
		const pct = bEff === 0 ? null : Math.round((d / Math.abs(bEff)) * 1000) / 10;
		const td = C.createElement('td', {
			class: 'bc-compare-delta bc-compare-delta--' + kind,
			attrs: { 'data-cell': t('budgetcheck', 'Change') },
		});
		const label = {
			up: t('budgetcheck', 'more'), down: t('budgetcheck', 'less'),
			flat: t('budgetcheck', 'unchanged'), new: t('budgetcheck', 'new'),
			removed: t('budgetcheck', 'gone'),
		}[kind];
		const parts = [];
		if (kind === 'new' || kind === 'removed') {
			parts.push(C.createElement('span', { class: 'bc-badge bc-badge--' + kind, text: label }));
		}
		parts.push(C.createElement('span', {
			class: 'bc-compare-delta__value',
			text: Money.formatEnvelope({ minor: d, currency: ws.currencyCode }, Ws.htmlLang),
		}));
		parts.push(C.createElement('span', {
			class: 'bc-compare-delta__meta',
			text: pct === null ? label : (DELTA_LABELS[kind] + ' ' + (pct > 0 ? '+' : '') + String(pct).replace('.', ',') + '%'),
		}));
		parts.forEach((el) => td.appendChild(el));
		return td;
	}

	function renderCompare(body, compare) {
		body.replaceChildren();
		const cats = (compare && compare.categories) || [];
		if (cats.length === 0) {
			body.appendChild(C.createElement('div', { class: 'bc-empty' }, [
				C.createElement('p', {
					class: 'bc-empty-text',
					text: t('budgetcheck', 'No bookings in {y} or {c} yet.', { y: String(compare.year), c: String(compare.compareYear) }),
				}),
			]));
			return;
		}
		const hasCompareData = cats.some((c) => effMinor(c.compareYear) !== 0);
		if (!hasCompareData) {
			body.appendChild(C.createElement('p', {
				class: 'bc-section__sub',
				text: t('budgetcheck', 'No bookings recorded in {c} — all comparison values are zero.', { c: String(compare.compareYear) }),
			}));
		}
		// Group by direction: expense first, then income, then the rest.
		const order = ['expense', 'income', ''];
		const labels = { expense: t('budgetcheck', 'Expenses'), income: t('budgetcheck', 'Income'), '': t('budgetcheck', 'Other') };
		const table = C.createElement('table', { class: 'bc-table bc-compare-table' });
		const thead = C.createElement('thead');
		thead.appendChild(C.createElement('tr', {}, [
			C.createElement('th', { attrs: { scope: 'col' }, text: t('budgetcheck', 'Category') }),
			C.createElement('th', { attrs: { scope: 'col' }, class: 'bc-num', text: String(compare.year) }),
			C.createElement('th', { attrs: { scope: 'col' }, class: 'bc-num', text: String(compare.compareYear) }),
			C.createElement('th', { attrs: { scope: 'col' }, class: 'bc-num', text: t('budgetcheck', 'Change') }),
		]));
		table.appendChild(thead);
		const tbody = C.createElement('tbody');
		const maxAbs = Math.max(1, ...cats.map((c) => Math.abs(effMinor(c.year) - effMinor(c.compareYear))));

		for (const dir of order) {
			const rows = cats.filter((c) => (c.direction || '') === dir);
			if (rows.length === 0) continue;
			tbody.appendChild(C.createElement('tr', { class: 'bc-compare-group' }, [
				C.createElement('th', { attrs: { scope: 'rowgroup', colspan: '4' }, text: labels[dir] }),
			]));
			rows.forEach((cat) => tbody.append(...compareRow(cat, maxAbs)));
		}
		table.appendChild(tbody);
		body.appendChild(table);
	}

	/** Category row + hidden 12-month detail row (disclosure pattern). */
	function compareRow(cat, maxAbs) {
		const aEff = effMinor(cat.year);
		const bEff = effMinor(cat.compareYear);
		const detailId = 'bc-cmp-det-' + cat.categoryId;
		const tr = C.createElement('tr', { class: 'bc-compare-row' + (cat.isActive ? '' : ' bc-compare-row--archived') });

		const nameCell = C.createElement('td', { attrs: { 'data-cell': t('budgetcheck', 'Category') } });
		const btn = C.createElement('button', {
			type: 'button',
			class: 'bc-compare-row__toggle',
			attrs: { 'aria-expanded': 'false', 'aria-controls': detailId },
		}, [
			C.createElement('span', { class: 'bc-compare-row__chevron', text: '▸', attrs: { 'aria-hidden': 'true' } }),
			C.createElement('span', { text: cat.name }),
		]);
		btn.addEventListener('click', () => {
			const det = document.getElementById(detailId);
			if (!det) return;
			const open = det.hasAttribute('hidden');
			if (open) det.removeAttribute('hidden'); else det.setAttribute('hidden', '');
			btn.setAttribute('aria-expanded', String(open));
			btn.querySelector('.bc-compare-row__chevron').textContent = open ? '▾' : '▸';
		});
		nameCell.appendChild(btn);
		if (cat.isSavingsTransfer) {
			nameCell.appendChild(C.createElement('span', { class: 'bc-badge', text: t('budgetcheck', 'savings transfer') }));
		}
		if (!cat.isActive) {
			nameCell.appendChild(C.createElement('span', { class: 'bc-badge bc-badge--archived', text: t('budgetcheck', 'archived') }));
		}
		tr.appendChild(nameCell);

		tr.appendChild(C.createElement('td', { class: 'bc-num', attrs: { 'data-cell': String(state.year) } },
			[C.createElement('span', { text: Money.formatEnvelope({ minor: aEff, currency: ws.currencyCode }, Ws.htmlLang) })]));
		tr.appendChild(C.createElement('td', { class: 'bc-num', attrs: { 'data-cell': String(state.compareYear) } },
			[C.createElement('span', { text: Money.formatEnvelope({ minor: bEff, currency: ws.currencyCode }, Ws.htmlLang) })]));
		tr.appendChild(deltaCell(cat, aEff, bEff));

		// Decorative magnitude bar — the numbers above carry the meaning.
		const barTd = C.createElement('td', { class: 'bc-compare-barcell', attrs: { 'aria-hidden': 'true' } });
		const w = Math.round(Math.abs(aEff - bEff) / maxAbs * 100);
		barTd.appendChild(C.createElement('span', { class: 'bc-compare-bar' },
			[C.createElement('span', { class: 'bc-compare-bar__fill', attrs: { style: '--bc-bar-w:' + w + '%' } })]));
		tr.appendChild(barTd);

		const detail = C.createElement('tr', { class: 'bc-compare-detail', attrs: { id: detailId, hidden: '' } });
		const cell = C.createElement('td', { attrs: { colspan: '5' } });
		cell.appendChild(monthGrid(cat));
		detail.appendChild(cell);
		return [tr, detail];
	}

	function monthGrid(cat) {
		const grid = C.createElement('table', { class: 'bc-table bc-compare-months' });
		const head = C.createElement('tr', {}, [
			C.createElement('th', { attrs: { scope: 'col' }, text: t('budgetcheck', 'Month') }),
			C.createElement('th', { attrs: { scope: 'col' }, class: 'bc-num', text: String(state.year) }),
			C.createElement('th', { attrs: { scope: 'col' }, class: 'bc-num', text: String(state.compareYear) }),
			C.createElement('th', { attrs: { scope: 'col' }, class: 'bc-num', text: t('budgetcheck', 'Change') }),
		]);
		grid.appendChild(head);
		const monthNames = [...Array(12)].map((_, i) => Dates.formatYearMonth(
			String(state.year) + '-' + String(i + 1).padStart(2, '0'), Ws.htmlLang));
		(cat.months || []).forEach((m) => {
			const a = effMinor(m.year);
			const b = effMinor(m.compareYear);
			const d = a - b;
			grid.appendChild(C.createElement('tr', {}, [
				C.createElement('th', { attrs: { scope: 'row' }, text: monthNames[(m.month || 1) - 1] || String(m.month) }),
				C.createElement('td', { class: 'bc-num', text: a === 0 && b === 0 ? '–' : Money.formatEnvelope({ minor: a, currency: ws.currencyCode }, Ws.htmlLang) }),
				C.createElement('td', { class: 'bc-num', text: a === 0 && b === 0 ? '–' : Money.formatEnvelope({ minor: b, currency: ws.currencyCode }, Ws.htmlLang) }),
				C.createElement('td', { class: 'bc-num', text: a === 0 && b === 0 ? '–' : Money.formatEnvelope({ minor: d, currency: ws.currencyCode }, Ws.htmlLang) }),
			]));
		});
		return grid;
	}

	function resolveYearTotals(totals) {
		if (!SpecialsView || !includeSpecials) {
			return totals;
		}
		return SpecialsView.resolveCashFlowTotals(totals, true) || totals;
	}

	function resolveMonthTotals(month) {
		if (!includeSpecials || !month.withSpecials) {
			return month;
		}
		return Object.assign({}, month, {
			income: month.withSpecials.income,
			expense: month.withSpecials.expense,
			netResult: month.withSpecials.netResult,
		});
	}

	function renderTotals(grid, summary) {
		if (!grid) return;
		grid.replaceChildren();
		grid.className = 'bc-summary-sections';
		const totals = resolveYearTotals(summary.totals || {});
		const ratio = normalizeRatio(totals.savingsAchievementRatio);

		const makeTile = (label, env, primary) => {
			return C.createElement('div', { class: 'bc-summary-tile' + (primary ? ' bc-summary-tile--primary' : '') }, [
				C.createElement('div', { class: 'bc-summary-tile__label', text: label }),
				C.createElement('div', { class: 'bc-summary-tile__value' }, C.moneyTileValue(env, Ws.htmlLang)),
			]);
		};

		const makeSection = (title, hint, tiles, extraClass, sectionKey) => {
			const titleId = sectionKey ? ('bc-summary-' + sectionKey + '-title') : null;
			const section = C.createElement('section', {
				class: ('bc-summary-section ' + String(extraClass || '')).trim(),
				attrs: titleId ? { 'aria-labelledby': titleId } : {},
			});
			const head = C.createElement('header', { class: 'bc-summary-section__header' });
			head.appendChild(C.createElement('h3', {
				class: 'bc-summary-section__title',
				attrs: titleId ? { id: titleId } : {},
				text: title,
			}));
			if (hint) {
				head.appendChild(C.createElement('p', { class: 'bc-summary-section__hint', text: hint }));
			}
			section.appendChild(head);
			const tileGrid = C.createElement('div', { class: 'bc-summary-grid' });
			tiles.forEach((tile) => tileGrid.appendChild(tile));
			section.appendChild(tileGrid);
			grid.appendChild(section);
		};

		makeSection(
			t('budgetcheck', 'Cash flow'),
			t('budgetcheck', 'Actual bookings only. Planned placeholders are shown separately below.'),
			[
				makeTile(t('budgetcheck', 'Income'), totals.income),
				makeTile(t('budgetcheck', 'Expenses'), totals.expense),
				makeTile(t('budgetcheck', 'Net result'), totals.netResult, true),
			],
			'',
			'cashflow',
		);

		const planned = totals.planned || null;
		const plannedLedger = planned && planned.ledger ? planned.ledger : null;
		const plannedEntryCount = Number.parseInt(String(plannedLedger?.entryCount ?? 0), 10) || 0;
		const incomeTargetMinor = Number.parseInt(String(planned?.incomeTarget?.minor ?? 0), 10) || 0;
		const expenseTargetMinor = Number.parseInt(String(totals.budgetPlanned?.minor ?? 0), 10) || 0;
		if (plannedEntryCount > 0 || incomeTargetMinor > 0 || expenseTargetMinor > 0) {
			const plannedTiles = [];
			if (incomeTargetMinor > 0) {
				plannedTiles.push(makeTile(t('budgetcheck', 'Income target (year)'), planned.incomeTarget));
			}
			if (expenseTargetMinor > 0) {
				plannedTiles.push(makeTile(t('budgetcheck', 'Expense target (year)'), totals.budgetPlanned));
			}
			if (plannedEntryCount > 0) {
				plannedTiles.push(
					makeTile(t('budgetcheck', 'Planned income (year)'), plannedLedger.income),
					makeTile(t('budgetcheck', 'Planned expenses (year)'), plannedLedger.expense),
				);
			}
			makeSection(
				t('budgetcheck', 'Expected (plan)'),
				t('budgetcheck', 'Budget targets and ledger placeholders for this year—not counted in actual cash flow above.'),
				plannedTiles,
				'bc-summary-section--plan',
				'plan',
			);
		}

		const savingsTiles = [
			makeTile(t('budgetcheck', 'Savings target'), totals.savingsTarget),
			makeTile(t('budgetcheck', 'Savings achieved'), totals.savingsAchieved, true),
			C.createElement('div', { class: 'bc-summary-tile' }, [
				C.createElement('div', { class: 'bc-summary-tile__label', text: t('budgetcheck', 'Savings achievement') }),
				C.createElement('div', {
					class: 'bc-summary-tile__value bc-summary-tile__value--small',
					text: ratio === null ? t('budgetcheck', 'No target set') : Math.round(ratio * 100) + '%',
				}),
			]),
		];
		makeSection(
			t('budgetcheck', 'Savings'),
			null,
			savingsTiles,
			'',
			'savings',
		);

		makeSection(
			t('budgetcheck', 'Everyday spending budget'),
			null,
			[
				makeTile(t('budgetcheck', 'Budget balance'), totals.budgetSaldo, true),
				makeTile(t('budgetcheck', 'Not spent (under budget)'), totals.budgetUnspent),
				makeTile(t('budgetcheck', 'Overspent (over budget)'), totals.budgetOverspent),
				C.createElement('div', { class: 'bc-summary-tile' }, [
					C.createElement('div', { class: 'bc-summary-tile__label', text: t('budgetcheck', 'Months over budget') }),
					C.createElement('div', { class: 'bc-summary-tile__value bc-summary-tile__value--small', text: String(totals.overBudgetMonths || 0) }),
				]),
			],
			'',
			'budget',
		);
	}

	function renderMonths(container, summary) {
		if (!container) return;
		container.replaceChildren();
		(summary.months || []).forEach((m) => {
			const display = resolveMonthTotals(m);
			const monthlyBase = Ws && Ws.urls && Ws.urls.monthly ? String(Ws.urls.monthly) : '';
			const url = monthlyBase
				? (Ws.withWorkspace(monthlyBase) + '&yearMonth=' + encodeURIComponent(m.yearMonth))
				: '#';
			const monthLabel = Dates.formatYearMonth(m.yearMonth, Ws.htmlLang);
			const klass = ['bc-month-card'];
			if (m.overBudget) klass.push('bc-month-card--over');
			if (m.isClosed) klass.push('bc-month-card--closed');
			const statusParts = [];
			if (m.overBudget) statusParts.push(t('budgetcheck', 'Over budget'));
			if (m.isClosed) statusParts.push(t('budgetcheck', 'Closed'));
			const ariaLabel = statusParts.length
				? monthLabel + ' — ' + statusParts.join(', ')
				: monthLabel;
			const cardChildren = [
				C.createElement('span', { class: 'bc-month-card__name', text: monthLabel }),
			];
			if (statusParts.length) {
				cardChildren.push(C.createElement('span', { class: 'bc-month-card__badges' }, statusParts.map((label) => C.createElement('span', {
					class: 'bc-month-card__badge',
					text: label,
				}))));
			}
			cardChildren.push(
				C.createElement('span', { class: 'bc-month-card__net', text: Money.formatEnvelope(display.netResult, Ws.htmlLang) }),
				C.createElement('span', {
					class: 'bc-month-card__meta',
					text: t('budgetcheck', 'In: {income} · Out: {expense}')
						.replace('{income}', Money.formatEnvelope(display.income, Ws.htmlLang))
						.replace('{expense}', Money.formatEnvelope(display.expense, Ws.htmlLang)),
				}),
			);
			const monthPlanned = m.planned || null;
			const monthPlannedLedger = monthPlanned && monthPlanned.ledger ? monthPlanned.ledger : null;
			const monthPlannedCount = Number.parseInt(String(monthPlannedLedger?.entryCount ?? 0), 10) || 0;
			const monthIncomeTargetMinor = Number.parseInt(String(monthPlanned?.incomeTarget?.minor ?? 0), 10) || 0;
			const monthExpenseTargetMinor = Number.parseInt(String(m.budget?.plannedTotal?.minor ?? 0), 10) || 0;
			if (monthPlannedCount > 0 || monthIncomeTargetMinor > 0 || monthExpenseTargetMinor > 0) {
				const parts = [];
				if (monthIncomeTargetMinor > 0) {
					parts.push(t('budgetcheck', 'Income target: {amount}')
						.replace('{amount}', Money.formatEnvelope(monthPlanned.incomeTarget, Ws.htmlLang)));
				}
				if (monthExpenseTargetMinor > 0) {
					parts.push(t('budgetcheck', 'Expense target: {amount}')
						.replace('{amount}', Money.formatEnvelope(m.budget.plannedTotal, Ws.htmlLang)));
				}
				if (monthPlannedCount > 0) {
					parts.push(t('budgetcheck', 'Planned: In {income} · Out {expense}')
						.replace('{income}', Money.formatEnvelope(monthPlannedLedger.income, Ws.htmlLang))
						.replace('{expense}', Money.formatEnvelope(monthPlannedLedger.expense, Ws.htmlLang)));
				}
				cardChildren.push(C.createElement('span', {
					class: 'bc-month-card__meta bc-month-card__meta--planned',
					text: parts.join(' · '),
				}));
			}
			cardChildren.push(
				C.createElement('span', {
					class: 'bc-month-card__meta',
					text: t('budgetcheck', 'Budget balance: {saldo}')
						.replace('{saldo}', Money.formatEnvelope(m.budget?.saldo, Ws.htmlLang)),
				}),
			);
			const card = C.createElement('a', {
				href: url,
				class: klass.join(' '),
				attrs: { 'aria-label': ariaLabel },
			}, cardChildren);
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
				label: t('budgetcheck', 'Budget balance'),
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
			{
				label: t('budgetcheck', 'Change'),
				value: t('budgetcheck', 'Δ and %'),
				desc: t('budgetcheck', 'In the category comparison below: the amount is this year minus the comparison year, the percent is that difference relative to the comparison year. “new” marks categories with bookings only this year, “gone” marks categories with bookings only in the comparison year.'),
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

	async function exportWorkbook(button) {
		if (!ws) return;
		if (button) {
			button.disabled = true;
			button.setAttribute('aria-busy', 'true');
		}
		try {
			const response = await Api.download(
				'/apps/budgetcheck/export/household-yearly',
				{ workspaceId: ws.id, year: state.year, compareYear: state.compareYear },
				{ headers: { Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' } },
			);
			const blob = await response.blob();
			const name = extractFilename(response) || ('budgetcheck_' + String(state.year) + '.xlsx');
			const objectUrl = window.URL.createObjectURL(blob);
			const a = document.createElement('a');
			a.href = objectUrl;
			a.download = name;
			document.body.appendChild(a);
			a.click();
			a.remove();
			window.URL.revokeObjectURL(objectUrl);
			Msg.announce(t('budgetcheck', 'Export started.'), 'success');
		} catch (err) {
			Msg.handleApiError(err);
		} finally {
			if (button) {
				button.disabled = false;
				button.removeAttribute('aria-busy');
			}
		}
	}

	function extractFilename(response) {
		const cd = response.headers.get('content-disposition') || '';
		const utf8Match = cd.match(/filename\*=UTF-8''([^;]+)/i);
		if (utf8Match && utf8Match[1]) {
			return decodeURIComponent(utf8Match[1]);
		}
		const asciiMatch = cd.match(/filename="([^"]+)"/i);
		return asciiMatch && asciiMatch[1] ? asciiMatch[1] : null;
	}

	function normalizeSummary(summary) {
		const base = summary && typeof summary === 'object' ? summary : {};
		const totals = base.totals && typeof base.totals === 'object' ? base.totals : {};
		const months = Array.isArray(base.months) ? base.months : [];
		const plannedRaw = totals.planned && typeof totals.planned === 'object' ? totals.planned : null;
		const plannedLedgerRaw = plannedRaw && plannedRaw.ledger ? plannedRaw.ledger : null;
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
				planned: plannedRaw ? {
					incomeTarget: normalizeEnv(plannedRaw.incomeTarget),
					ledger: plannedLedgerRaw ? {
						income: normalizeEnv(plannedLedgerRaw.income),
						expense: normalizeEnv(plannedLedgerRaw.expense),
						netResult: normalizeEnv(plannedLedgerRaw.netResult),
						entryCount: Number.parseInt(String(plannedLedgerRaw.entryCount || 0), 10) || 0,
					} : null,
				} : null,
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
		const Money = window.BudgetCheckMoney;
		// Prefer envelope.decimals (incl. 0); Money.envelopeDecimals also reads
		// the decimal string so older envelopes without decimals stay correct.
		const decimals = (Money && typeof Money.envelopeDecimals === 'function')
			? Money.envelopeDecimals(env)
			: (Number.isFinite(Number(env.decimals)) ? Number(env.decimals) : 2);
		return {
			minor,
			currency,
			decimals,
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

	function boot(deps) {
		Api = deps.Api;
		Msg = deps.Messaging;
		C = deps.Components;
		Money = deps.Money;
		Dates = deps.Dates;
		Ws = deps.Workspace;
		SpecialsView = deps.SpecialsView || null;
		if (typeof state !== 'undefined' && state && Object.prototype.hasOwnProperty.call(state, 'yearMonth') && state.yearMonth == null && typeof initialYearMonth === 'function') {
			state.yearMonth = initialYearMonth();
		}
		pageInit();
	}

	if (!window.BudgetCheck || typeof window.BudgetCheck.onReady !== 'function') {
		return;
	}
	window.BudgetCheck.onReady(boot, {
		required: ['Api', 'Messaging', 'Components', 'Money', 'Dates', 'Workspace'],
		optional: ['SpecialsView', 'HouseholdPeriod'],
	});

})();
