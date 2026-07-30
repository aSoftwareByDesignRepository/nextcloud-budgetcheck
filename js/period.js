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


	function pageInit() {
		if (!Ws || typeof Ws !== 'object') return;
		const ws = Ws.workspace;
		if (!ws || ws.type !== 'project') return;
		const exportBtn = document.querySelector('[data-bc-period-export]');
		if (exportBtn) {
			exportBtn.addEventListener('click', () => exportWorkbook(ws, exportBtn));
		}
		void load(ws);
	}

	async function load(ws) {
		const grid = document.querySelector('[data-bc-summary-grid]');
		const period = document.querySelector('[data-bc-summary-period]');
		grid?.setAttribute('aria-busy', 'true');
		let summary = null;
		try {
			const data = await Api.get('/apps/budgetcheck/api/project-period-summary', { workspaceId: ws.id });
			summary = data.summary;
			renderSummary(grid, summary);
			if (period) period.textContent = formatPeriod(summary);
			const ledgerEl = document.querySelector('[data-bc-period-ledger-help]');
			C.renderMonthlyLedgerHelp(ledgerEl, summary, '', Ws.htmlLang);
			renderCap(summary);
			renderSpecials(summary.specials || []);
		} catch (err) {
			Msg.handleApiError(err);
		} finally {
			grid?.setAttribute('aria-busy', 'false');
		}
		if (summary) {
			try {
				renderWarnings(summary.warnings || []);
			} catch (warnErr) {
				Msg.handleApiError(warnErr);
				const section = document.querySelector('[data-bc-warnings]');
				if (section) section.hidden = true;
			}
		}
	}

	function renderSummary(grid, summary) {
		if (!grid) return;
		grid.replaceChildren();
		const totals = summary.totals || {};
		const makeTile = (label, env, primary) => C.createElement('div', { class: 'bc-summary-tile' + (primary ? ' bc-summary-tile--primary' : '') }, [
			C.createElement('div', { class: 'bc-summary-tile__label', text: label }),
			C.createElement('div', { class: 'bc-summary-tile__value' }, C.moneyTileValue(env, Ws.htmlLang)),
		]);
		[
			[t('budgetcheck', 'Income'), totals.income],
			[t('budgetcheck', 'Expenses'), totals.expense],
			[t('budgetcheck', 'Net result'), totals.netResult, true],
		].forEach(([label, env, primary]) => {
			grid.appendChild(makeTile(label, env, primary));
		});
		if (totals.tax && totals.taxBasis) {
			[
				[t('budgetcheck', 'Tax net total'), totals.tax.net],
				[t('budgetcheck', 'Tax VAT total'), totals.tax.vat],
				[t('budgetcheck', 'Tax gross total'), totals.tax.gross],
			].forEach(([label, env]) => {
				grid.appendChild(makeTile(label, env));
			});
		}
		if (summary.allTime) {
			grid.appendChild(makeTile(t('budgetcheck', 'All-time spend'), summary.allTime.expense));
			if (summary.allTime.cap) {
				grid.appendChild(makeTile(t('budgetcheck', 'Project cap'), summary.allTime.cap));
				grid.appendChild(makeTile(t('budgetcheck', 'Remaining headroom'), summary.allTime.remainingHeadroom));
			}
		}
	}

	function renderCap(summary) {
		const section = document.querySelector('[data-bc-cap]');
		const block = document.querySelector('[data-bc-cap-block]');
		if (!section || !block) return;
		const cap = summary.allTime && summary.allTime.cap;
		if (!cap || !cap.minor) {
			section.hidden = true;
			block.replaceChildren();
			return;
		}
		section.hidden = false;
		const used = (summary.allTime.expense?.minor || 0);
		const ratio = cap.minor > 0 ? Math.min(2, used / cap.minor) : 0;
		const pct = Math.min(100, Math.round(ratio * 100));
		const tone = ratio > 1 ? 'critical' : (ratio >= 0.9 ? 'warning' : '');
		block.replaceChildren();
		const bar = C.createElement('div', { class: 'bc-cap__bar' + (tone ? ' bc-cap--' + tone : '') });
		bar.appendChild(C.createElement('div', { class: 'bc-cap__bar-fill', attrs: { style: 'width:' + Math.min(100, pct) + '%' } }));
		block.appendChild(bar);
		const legend = C.createElement('div', { class: 'bc-cap__legend' });
		legend.appendChild(C.createElement('span', { text: t('budgetcheck', 'Used: {used}').replace('{used}', Money.formatEnvelope(summary.allTime.expense, Ws.htmlLang)) }));
		legend.appendChild(C.createElement('span', { text: t('budgetcheck', 'Cap: {cap}').replace('{cap}', Money.formatEnvelope(cap, Ws.htmlLang)) }));
		legend.appendChild(C.createElement('span', { text: t('budgetcheck', 'Headroom: {headroom}').replace('{headroom}', Money.formatEnvelope(summary.allTime.remainingHeadroom, Ws.htmlLang)) }));
		block.appendChild(legend);
	}

	function renderWarnings(warnings) {
		const section = document.querySelector('[data-bc-warnings]');
		const list = document.querySelector('[data-bc-warnings-list]');
		C.renderWarningsList(section, list, warnings, Ws);
	}

	function renderSpecials(specials) {
		const section = document.querySelector('[data-bc-specials]');
		const list = document.querySelector('[data-bc-specials-list]');
		if (!section || !list) return;
		if (!specials.length) { section.hidden = true; list.replaceChildren(); return; }
		section.hidden = false;
		list.replaceChildren();
		specials.forEach((s) => {
			list.appendChild(C.createElement('li', { class: 'bc-tx-list__item' }, [
				C.createElement('div', null, [
					C.createElement('div', { class: 'bc-tx-list__title', text: s.title }),
					C.createElement('div', { class: 'bc-tx-list__meta', text: Dates.formatDisplayDate(s.date, Ws.htmlLang) + ' · ' + (s.direction === 'income' ? t('budgetcheck', 'Income') : t('budgetcheck', 'Expense')) }),
				]),
				C.createElement('div', {
					class: 'bc-tx-list__amount ' + (s.direction === 'income' ? 'bc-tx-amount--income' : 'bc-tx-amount--expense'),
					text: (s.direction === 'income' ? '+' : '−') + ' ' + Money.formatEnvelope(s.amount, Ws.htmlLang),
				}),
			]));
		});
	}

	function formatPeriod(summary) {
		if (summary.window) {
			return Dates.formatDisplayDate(summary.window.from, Ws.htmlLang) + ' – ' + Dates.formatDisplayDate(summary.window.to, Ws.htmlLang);
		}
		return '';
	}

	async function exportWorkbook(ws, button) {
		if (!ws) return;
		if (button) {
			button.disabled = true;
			button.setAttribute('aria-busy', 'true');
		}
		try {
			const response = await Api.download(
				'/apps/budgetcheck/export/project-period',
				{ workspaceId: ws.id },
				{ headers: { Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' } },
			);
			const blob = await response.blob();
			const name = extractFilename(response) || 'budgetcheck_project_export.xlsx';
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

	function boot(deps) {
		Api = deps.Api;
		Msg = deps.Messaging;
		C = deps.Components;
		Money = deps.Money;
		Dates = deps.Dates;
		Ws = deps.Workspace;
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
		optional: [],
	});

})();
