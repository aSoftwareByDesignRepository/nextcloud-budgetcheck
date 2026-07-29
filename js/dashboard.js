(function () {
	'use strict';

	const Api = window.BudgetCheckApi;
	const Msg = window.BudgetCheckMessaging;
	const C = window.BudgetCheckComponents;
	const Money = window.BudgetCheckMoney;
	const Dates = window.BudgetCheckDates;
	const Ws = window.BudgetCheckWorkspace;

	const dashState = { yearMonth: Dates.currentYearMonth() };
	const LEDGER_PREVIEW_LIMIT = 8;
	/** Guards against a slow response for a previously selected month overwriting the latest one. */
	let dashLoadSeq = 0;
	/** Set on DOMContentLoaded after #app-content exists (see lazy workspace.js). */
	let ws = null;
	let isHousehold = false;
	let dashPeriodPicker = null;
	let lastSummary = null;
	let includeSpecials = false;
	const SpecialsView = window.BudgetCheckSpecialsView;
	const Editor = () => window.BudgetCheckTransactionEditor;

	function pad2(n) {
		return n < 10 ? '0' + n : String(n);
	}

	function lastDayOfMonth(year, monthOneBased) {
		return new Date(year, monthOneBased, 0).getDate();
	}

	function defaultBookingDateForMonth(yearMonth) {
		const ym = String(yearMonth || Dates.currentYearMonth());
		if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(ym)) {
			return Dates.isoDate(new Date());
		}
		const [y, m] = ym.split('-').map(Number);
		const from = ym + '-01';
		const to = ym + '-' + pad2(lastDayOfMonth(y, m));
		const ed = Editor();
		return ed ? ed.defaultBookingDateForRange(from, to) : Dates.isoDate(new Date());
	}

	function patchDashboardReceiptCount(transactionId, count) {
		const txId = Number(transactionId);
		if (!txId) return;
		const n = Math.max(0, Number(count) || 0);
		const TxList = window.BudgetCheckTransactionList;
		const item = document.querySelector('[data-bc-tx-list] [data-bc-tx-id="' + txId + '"]');
		if (!item) return;
		let receiptEl = item.querySelector('.bc-tx-list__receipt');
		if (n <= 0) {
			if (receiptEl) receiptEl.remove();
			return;
		}
		const label = TxList ? TxList.receiptLabel({ attachmentCount: n, hasAttachments: true }) : String(n);
		if (!receiptEl) {
			receiptEl = C.createElement('div', { class: 'bc-tx-list__receipt' });
			const leftCol = item.querySelector('.bc-tx-list__title')?.parentElement || item.firstElementChild;
			if (leftCol) leftCol.appendChild(receiptEl);
		}
		receiptEl.textContent = label;
	}

	function openNewTransactionFromDashboard(yearMonth) {
		const ed = Editor();
		if (!ed || !Ws.canContribute) return;
		ed.open({
			bookingDate: defaultBookingDateForMonth(yearMonth),
			onSaved: () => loadAndRender(dashState.yearMonth),
			onAttachmentsChanged: (payload) => {
				if (!payload || !payload.transactionId) return;
				patchDashboardReceiptCount(payload.transactionId, payload.attachmentCount);
			},
		});
	}

	function initDashboard() {
		if (!Ws || typeof Ws !== 'object') {
			return;
		}
		ws = Ws.workspace;
		if (!ws) {
			return;
		}
		isHousehold = ws.type === 'household';
		if (isHousehold && SpecialsView) {
			includeSpecials = SpecialsView.getIncludeSpecials(ws.id);
			void SpecialsView.migrateLegacyLocalStorage(ws.id).then(() => {
				includeSpecials = SpecialsView.getIncludeSpecials(ws.id);
				if (lastSummary) {
					const grid = document.querySelector('[data-bc-summary-grid]');
					if (grid && C) {
						C.renderHouseholdSummaryTiles(grid, lastSummary, Ws.htmlLang, { includeSpecials });
					}
				}
			}).catch(() => {
				/* migration is best-effort; page keeps server defaults */
			});
		}
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
					renderHouseholdQuickActions(ym);
					loadAndRender(ym);
				},
			});
		}
		if (isHousehold) {
			renderHouseholdQuickActions(dashState.yearMonth);
			if (Ws.canContribute && Editor()) {
				void Editor().preload();
			}
		} else {
			loadRecent();
		}
		loadAndRender(isHousehold ? dashState.yearMonth : null);
		wireIncludeSpecialsRefresh();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initDashboard);
	} else {
		initDashboard();
	}

	function wireIncludeSpecialsRefresh() {
		if (!isHousehold || !SpecialsView || !ws) return;
		window.addEventListener('pageshow', (event) => {
			if (!event.persisted) return;
			const next = SpecialsView.refreshIncludeSpecials(ws.id, includeSpecials);
			if (next === includeSpecials) return;
			includeSpecials = next;
			const grid = document.querySelector('[data-bc-summary-grid]');
			if (grid && lastSummary) {
				C.renderHouseholdSummaryTiles(grid, lastSummary, Ws.htmlLang, { includeSpecials });
			}
		});
	}

	function transactionsUrlForMonth(yearMonth) {
		if (!Ws.urls.transactions) return '#';
		const ym = String(yearMonth || Dates.currentYearMonth());
		return Ws.withWorkspace(Ws.urls.transactions)
			+ '&yearMonth=' + encodeURIComponent(ym);
	}

	function makeDashAction(card) {
		let className = 'bc-dash-action';
		if (card.primary) className += ' bc-dash-action--primary';
		if (card.accent) className += ' bc-dash-action--accent';
		if (card.action === 'new-transaction') className += ' bc-dash-action--new';
		if (card.action) {
			const btn = C.createElement('button', {
				type: 'button',
				class: className,
				attrs: { 'data-bc-dash-action': card.action },
			}, [
				C.createElement('span', { class: 'bc-dash-action__title', text: card.title }),
				C.createElement('span', { class: 'bc-dash-action__hint', text: card.hint }),
			]);
			if (card.action === 'new-transaction') {
				wireNewTransactionButton(btn, card.yearMonth);
			}
			return btn;
		}
		return C.createElement('a', {
			class: className,
			href: card.href,
			attrs: { 'data-bc-dash-action-link': card.linkKind || '' },
		}, [
			C.createElement('span', { class: 'bc-dash-action__title', text: card.title }),
			C.createElement('span', { class: 'bc-dash-action__hint', text: card.hint }),
		]);
	}

	function wireNewTransactionButton(btn, yearMonth) {
		if (!btn || btn.dataset.bcDashActionWired === '1') return;
		btn.dataset.bcDashActionWired = '1';
		btn.addEventListener('click', () => openNewTransactionFromDashboard(yearMonth));
	}

	function quickActionHrefs(yearMonth) {
		const ym = String(yearMonth || dashState.yearMonth || Dates.currentYearMonth());
		return {
			yearMonth: ym,
			transactions: transactionsUrlForMonth(ym),
			monthly: Ws.withWorkspace(Ws.urls.monthly || '#') + '&yearMonth=' + encodeURIComponent(ym),
			yearly: Ws.withWorkspace(Ws.urls.yearly || '#'),
		};
	}

	function renderHouseholdQuickActions(yearMonth) {
		const nav = document.querySelector('[data-bc-dash-actions-nav]');
		if (!nav || !C) return;
		const hrefs = quickActionHrefs(yearMonth);
		const serverLinks = nav.querySelectorAll('[data-bc-dash-action-link]');
		if (serverLinks.length) {
			serverLinks.forEach((link) => {
				const kind = link.getAttribute('data-bc-dash-action-link');
				if (kind === 'transactions') link.href = hrefs.transactions;
				else if (kind === 'monthly') link.href = hrefs.monthly;
				else if (kind === 'yearly') link.href = hrefs.yearly;
			});
			const newBtn = nav.querySelector('[data-bc-dash-action="new-transaction"]');
			if (newBtn) wireNewTransactionButton(newBtn, hrefs.yearMonth);
			nav.classList.toggle('bc-dash-actions-grid--count-3', nav.querySelectorAll('.bc-dash-action').length === 3);
			const ledgerLink = document.querySelector('[data-bc-dash-ledger-link]');
			if (ledgerLink) {
				ledgerLink.href = hrefs.transactions;
				ledgerLink.hidden = false;
			}
			return;
		}
		const cards = [
			{
				href: hrefs.transactions,
				linkKind: 'transactions',
				primary: true,
				title: t('budgetcheck', 'Bookings for this month'),
				hint: t('budgetcheck', 'View and add entries in the ledger.'),
			},
			{
				href: hrefs.monthly,
				linkKind: 'monthly',
				title: t('budgetcheck', 'Open monthly plan'),
				hint: t('budgetcheck', 'Review budgets and monthly close.'),
			},
			{
				href: hrefs.yearly,
				linkKind: 'yearly',
				title: t('budgetcheck', 'Yearly overview'),
				hint: t('budgetcheck', 'See income, expenses, and savings across the year.'),
			},
		];
		if (Ws.canContribute) {
			cards.push({
				action: 'new-transaction',
				accent: true,
				yearMonth: hrefs.yearMonth,
				title: t('budgetcheck', 'New transaction'),
				hint: t('budgetcheck', 'Log a transaction'),
			});
		}
		const frag = document.createDocumentFragment();
		cards.forEach((card) => frag.appendChild(makeDashAction(card)));
		nav.replaceChildren(frag);
		nav.classList.toggle('bc-dash-actions-grid--count-3', cards.length === 3);
		const ledgerLink = document.querySelector('[data-bc-dash-ledger-link]');
		if (ledgerLink) {
			ledgerLink.href = hrefs.transactions;
			ledgerLink.hidden = false;
		}
	}

	async function loadAndRender(yearMonth) {
		const seq = ++dashLoadSeq;
		const grid = document.querySelector('[data-bc-summary-grid]');
		const periodLabel = document.querySelector('[data-bc-summary-period]');
		const warningsSection = document.querySelector('[data-bc-warnings]');
		const warningsList = document.querySelector('[data-bc-warnings-list]');
		if (!grid) return;
		grid.setAttribute('aria-busy', 'true');
		if (isHousehold) {
			setHouseholdLedgerBusy(true);
		}
		try {
			const data = isHousehold
				? await Api.get('/apps/budgetcheck/api/monthly-summary', { workspaceId: ws.id, yearMonth: yearMonth || Dates.currentYearMonth() })
				: await Api.get('/apps/budgetcheck/api/project-period-summary', { workspaceId: ws.id });
			if (seq !== dashLoadSeq) return; // Stale response; the latest request wins.
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
			if (isHousehold) {
				renderHouseholdMonthLedger(summary, yearMonth || Dates.currentYearMonth());
			}
			renderWarnings(warningsSection, warningsList, summary.warnings || []);
		} catch (err) {
			if (seq !== dashLoadSeq) return;
			Msg.handleApiError(err);
			if (C) {
				grid.replaceChildren(C.createElement('p', { class: 'bc-loading', text: t('budgetcheck', 'Could not load the summary.') }));
			}
			if (isHousehold) {
				renderHouseholdMonthLedger(null, yearMonth || Dates.currentYearMonth());
			}
		} finally {
			if (seq === dashLoadSeq) {
				grid.setAttribute('aria-busy', 'false');
				if (isHousehold) {
					setHouseholdLedgerBusy(false);
				}
			}
		}
	}

	function setHouseholdLedgerBusy(busy) {
		const activityGrid = document.querySelector('[data-bc-dash-activity-grid]');
		const tbody = document.querySelector('[data-bc-dash-ledger-rows]');
		activityGrid?.setAttribute('aria-busy', busy ? 'true' : 'false');
		tbody?.setAttribute('aria-busy', busy ? 'true' : 'false');
	}

	function renderHouseholdMonthLedger(summary, yearMonth) {
		const activityGrid = document.querySelector('[data-bc-dash-activity-grid]');
		const tbody = document.querySelector('[data-bc-dash-ledger-rows]');
		const footer = document.querySelector('[data-bc-dash-ledger-footer]');
		if (!activityGrid || !tbody) return;
		setHouseholdLedgerBusy(false);
		if (!summary) {
			activityGrid.replaceChildren(C.createElement('p', { class: 'bc-loading', text: t('budgetcheck', 'Could not load the summary.') }));
			tbody.replaceChildren(C.createElement('tr', null, [
				C.createElement('td', { attrs: { colspan: '3' }, class: 'bc-loading', text: t('budgetcheck', 'Could not load transactions.') }),
			]));
			if (footer) footer.hidden = true;
			return;
		}
		renderDashActivity(activityGrid, summary.activity || null);
		const rows = (summary.monthTransactions || []).slice(0, LEDGER_PREVIEW_LIMIT);
		renderDashLedgerRows(tbody, rows, yearMonth);
		const totalCount = Number.parseInt(String(summary.activity?.count ?? rows.length), 10) || 0;
		if (footer) {
			if (totalCount > LEDGER_PREVIEW_LIMIT) {
				const txHref = transactionsUrlForMonth(yearMonth);
				footer.hidden = false;
				footer.replaceChildren(
					C.createElement('a', {
						class: 'bc-dash-ledger-footer__link',
						href: txHref,
						text: t('budgetcheck', 'Open full ledger ({count})').replace('{count}', String(totalCount)),
					}),
				);
			} else if (totalCount === 0) {
				footer.hidden = false;
				footer.replaceChildren(C.createElement('span', {
					text: t('budgetcheck', 'Use the transactions screen to add your first entry.'),
				}));
			} else {
				footer.hidden = true;
				footer.replaceChildren();
			}
		}
	}

	function renderDashActivity(grid, activity) {
		grid.replaceChildren();
		if (!activity || !activity.count) {
			grid.appendChild(C.createElement('p', { class: 'bc-loading', text: t('budgetcheck', 'No transactions this month.') }));
			return;
		}
		[
			[t('budgetcheck', 'Total bookings'), String(activity.count || 0), true],
			[t('budgetcheck', 'Income bookings'), String(activity.incomeCount || 0)],
			[t('budgetcheck', 'Expense bookings'), String(activity.expenseCount || 0)],
			[t('budgetcheck', 'Special bookings'), String(activity.specialCount || 0)],
		].forEach(([label, value, primary]) => {
			grid.appendChild(C.createElement('div', { class: 'bc-summary-tile' + (primary ? ' bc-summary-tile--primary' : '') }, [
				C.createElement('div', { class: 'bc-summary-tile__label', text: label }),
				C.createElement('div', { class: 'bc-summary-tile__value', text: value }),
			]));
		});
	}

	function renderDashLedgerRows(tbody, rows, yearMonth) {
		tbody.replaceChildren();
		if (!rows.length) {
			tbody.appendChild(C.createElement('tr', null, [
				C.createElement('td', { attrs: { colspan: '3' }, class: 'bc-loading', text: t('budgetcheck', 'No transactions this month.') }),
			]));
			return;
		}
		rows.forEach((row) => {
			const tr = C.createElement('tr');
			tr.appendChild(C.createElement('td', { text: Dates.formatDisplayDate(row.date, Ws.htmlLang) }));
			const titleParts = [row.title || ('#' + row.id)];
			if (row.isSpecial) {
				titleParts.push('(' + t('budgetcheck', 'Special') + ')');
			}
			tr.appendChild(C.createElement('td', { text: titleParts.join(' ') }));
			const amountClass = 'bc-table__col--num ' + (row.direction === 'income' ? 'bc-tx-amount--income' : 'bc-tx-amount--expense');
			const directionPrefix = row.direction === 'income' ? t('budgetcheck', 'Income:') : t('budgetcheck', 'Expense:');
			const amountText = (row.direction === 'income' ? '+' : '−') + ' ' + Money.formatEnvelope(row.amount, Ws.htmlLang);
			tr.appendChild(C.createElement('td', { class: amountClass }, [
				C.createElement('span', { class: 'bc-sr-only', text: directionPrefix + ' ' }),
				document.createTextNode(amountText),
			]));
			tbody.appendChild(tr);
		});
	}

	async function loadRecent() {
		const list = document.querySelector('[data-bc-recent-list]');
		if (!list) return;
		list.setAttribute('aria-busy', 'true');
		try {
			const data = await Api.get('/apps/budgetcheck/api/transactions', {
				workspaceId: ws.id,
				limit: 8,
				offset: 0,
				to: Dates.isoDate(new Date()),
			});
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
		const TxList = window.BudgetCheckTransactionList;
		const meta = TxList
			? TxList.recentListMeta(tx, Ws.htmlLang)
			: { text: Dates.formatDisplayDate(tx.bookingDate, Ws.htmlLang), fullNote: null };
		const metaAttrs = meta.fullNote ? { title: meta.fullNote } : {};
		const directionPrefix = tx.direction === 'income' ? t('budgetcheck', 'Income:') : t('budgetcheck', 'Expense:');
		const amount = Money.formatEnvelope(tx.amount, Ws.htmlLang);
		const metaParts = [meta.text];
		if (tx.entryAmountBasis === 'gross') metaParts.push(t('budgetcheck', 'Gross'));
		if (tx.entryAmountBasis === 'net') metaParts.push(t('budgetcheck', 'Net'));
		if (Number.isInteger(tx.vatRateBp)) metaParts.push(t('budgetcheck', 'VAT {rate}%').replace('{rate}', (tx.vatRateBp / 100).toString()));
		const leftCol = C.createElement('div', null, [
			C.createElement('div', { class: 'bc-tx-list__title', text: tx.title }),
			C.createElement('div', { class: 'bc-tx-list__meta', attrs: metaAttrs, text: metaParts.join(' · ') }),
		]);
		if (TxList && typeof TxList.hasReceipts === 'function' && TxList.hasReceipts(tx)) {
			leftCol.appendChild(C.createElement('div', {
				class: 'bc-tx-list__receipt',
				text: TxList.receiptLabel(tx),
			}));
		}
		if (tx.entryAmountBasis && tx.entryAmountBasis !== 'simple' && tx.net && tx.vat && tx.gross) {
			leftCol.appendChild(C.createElement('div', {
				class: 'bc-tx-list__meta',
				text: t('budgetcheck', 'Net {net} · VAT {vat} · Gross {gross}')
					.replace('{net}', Money.formatEnvelope(tx.net, Ws.htmlLang))
					.replace('{vat}', Money.formatEnvelope(tx.vat, Ws.htmlLang))
					.replace('{gross}', Money.formatEnvelope(tx.gross, Ws.htmlLang)),
			}));
		}
		return C.createElement('li', { class: 'bc-tx-list__item', attrs: { 'data-bc-tx-id': String(tx.id) } }, [
			leftCol,
			C.createElement('div', {
				class: 'bc-tx-list__amount ' + (tx.direction === 'income' ? 'bc-tx-amount--income' : 'bc-tx-amount--expense'),
			}, [
				C.createElement('span', { class: 'bc-sr-only', text: directionPrefix + ' ' }),
				document.createTextNode((tx.direction === 'income' ? '+' : '−') + ' ' + amount),
			]),
		]);
	}

	function renderSummaryGrid(grid, summary) {
		if (isHousehold) {
			lastSummary = summary;
			if (!C || typeof C.renderHouseholdSummaryTiles !== 'function') return;
			C.renderHouseholdSummaryTiles(grid, summary, Ws.htmlLang, { includeSpecials });
			return;
		}
		grid.replaceChildren();
		const totals = summary.totals || {};
		const tiles = [];
		tiles.push(makeTile(t('budgetcheck', 'Income'), totals.income));
		tiles.push(makeTile(t('budgetcheck', 'Expenses'), totals.expense));
		tiles.push(makeTile(t('budgetcheck', 'Net result'), totals.netResult, { primary: true }));
		if (totals.tax && totals.taxBasis) {
			tiles.push(makeTile(t('budgetcheck', 'Tax net total'), totals.tax.net));
			tiles.push(makeTile(t('budgetcheck', 'Tax VAT total'), totals.tax.vat));
			tiles.push(makeTile(
				t('budgetcheck', 'Tax gross total'),
				totals.tax.gross,
				{ hint: t('budgetcheck', 'Budget basis: {basis}').replace('{basis}', totals.taxBasis === 'net' ? t('budgetcheck', 'Net') : t('budgetcheck', 'Gross')) }
			));
		}
		if (summary.allTime && summary.allTime.cap) {
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
			C.createElement('div', { class: 'bc-summary-tile__value' }, C.moneyTileValue(env, Ws.htmlLang)),
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
		C.renderWarningsList(section, list, warnings, Ws);
	}

})();
