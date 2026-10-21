/**
 * BudgetCheck Dates — adapter over the shared CheckDates module
 * (js/common/check-dates.js, loaded before this file).
 *
 * Contract (audited): date-only `YYYY-MM-DD` is a calendar day — it renders
 * the same in every timezone. Space-separated API datetimes are LOCAL wall
 * time (naiveMode 'local'); ISO strings with T/Z carry an instant.
 */
(function () {
	'use strict';

	const D = window.CheckDates.create({
		prefix: 'bc',
		naiveMode: 'local',
		displayDateStyle: 'numeric',
		attrs: {
			locale: 'lang',
			language: 'lang',
			timezone: 'data-bc-timezone',
		},
	});

	/** @deprecated use formatDisplayDate — kept for older bundles */
	function formatIso(iso, locale) {
		return D.formatDisplayDate(iso, locale);
	}

	/**
	 * Same as currentYearMonth. Prefer calling via
	 * `window.BudgetCheckDates && window.BudgetCheckDates.currentYearMonthSafe()`
	 * from page modules so a missing Dates global never throws on property read.
	 */
	function currentYearMonthSafe() {
		return D.currentYearMonth();
	}

	function tr(text) {
		return typeof window.t === 'function' ? window.t('budgetcheck', text) : text;
	}

	/**
	 * Locale chrome for temporal inputs under `root`, with the picker button's
	 * aria-label translated by default (callers may still override options).
	 */
	function applyLocaleToTemporalInputs(root, options) {
		return D.applyLocaleToTemporalInputs(root, Object.assign({
			pickerLabel: tr('Choose date'),
		}, options || {}));
	}

	/**
	 * When from/to span exactly one calendar month (first through last day), return YYYY-MM.
	 * @returns {string|null}
	 */
	function yearMonthFromDateRange(fromIso, toIso) {
		const from = String(fromIso || '');
		const to = String(toIso || '');
		const head = from.match(/^(\d{4})-(\d{2})-01$/);
		if (!head) return null;
		const y = Number.parseInt(head[1], 10);
		const mo = Number.parseInt(head[2], 10);
		if (!Number.isFinite(y) || !Number.isFinite(mo) || mo < 1 || mo > 12) return null;
		const lastDay = new Date(y, mo, 0).getDate();
		const pad = (n) => (n < 10 ? '0' + n : String(n));
		const expectedTo = y + '-' + pad(mo) + '-' + pad(lastDay);
		return to === expectedTo ? (y + '-' + pad(mo)) : null;
	}

	/**
	 * Human copy for month pickers: workspace booking span + selected month activity.
	 * Expects `summary.ledgerYearMonthSpan` and optional `summary.monthLedger` from monthly-summary or list-budgets (project).
	 *
	 * @param {*} summary
	 * @param {string} yearMonth
	 * @param {string} htmlLang
	 * @returns {{ spanLine: string, monthLine: string }}
	 */
	function monthlyLedgerHelpLines(summary, yearMonth, htmlLang) {
		const tr = typeof window.t === 'function' ? window.t : function (_, s) { return s; };
		const nr = typeof window.n === 'function'
			? window.n
			: function (app, sing, plur, count, vars) {
				const msg = count === 1 ? sing : plur;
				return msg.replace(/%n|\{count\}/g, String(count)).replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? String(vars[k]) : m));
			};
		const APP = 'budgetcheck';
		if (!summary || !summary.ledgerYearMonthSpan) {
			return { spanLine: '', monthLine: '' };
		}
		const span = summary.ledgerYearMonthSpan;
		const ml = summary.monthLedger || {};
		const count = typeof ml.transactionCount === 'number' ? ml.transactionCount : 0;
		const first = span.firstYearMonth || null;
		const last = span.lastYearMonth || null;
		const ymOk = yearMonth && /^\d{4}-(0[1-9]|1[0-2])$/.test(yearMonth);

		let spanLine = '';
		if (first && last) {
			/* Same calendar month twice ("May … through May …") adds nothing next to a single-month picker. */
			if (first !== last) {
				spanLine = tr(APP, 'Ledger bookings in this workspace run from {monthFrom} through {monthTo}.')
					.replace('{monthFrom}', D.formatYearMonth(first, htmlLang))
					.replace('{monthTo}', D.formatYearMonth(last, htmlLang));
			}
		} else {
			spanLine = tr(APP, 'No ledger transactions yet—each month is empty until you post one.');
		}

		if (!ymOk) {
			let monthLine = '';
			if (count === 0) {
				monthLine = tr(APP, 'No bookings in this date range yet.');
			} else {
				monthLine = nr(APP,
					'%n booking falls into this date range. Pick a calendar month to focus one month.',
					'{count} bookings fall into this date range. Pick a calendar month to focus one month.',
					count, { count: count });
			}
			return { spanLine: spanLine, monthLine: monthLine };
		}

		const monthLabel = D.formatYearMonth(yearMonth, htmlLang);

		let monthLine = '';
		if (count === 0) {
			if (first && D.compareYearMonth(yearMonth, first) < 0) {
				monthLine = tr(APP, 'Nothing booked for {monthLabel}. That calendar month is before your first ledger booking ({monthFrom}).')
					.replace('{monthLabel}', monthLabel)
					.replace('{monthFrom}', D.formatYearMonth(first, htmlLang));
			} else if (last && D.compareYearMonth(yearMonth, last) > 0) {
				monthLine = tr(APP, 'Nothing booked for {monthLabel}. That calendar month is after your latest ledger booking ({monthTo}).')
					.replace('{monthLabel}', monthLabel)
					.replace('{monthTo}', D.formatYearMonth(last, htmlLang));
			} else {
				monthLine = tr(APP, 'Nothing booked for {monthLabel}. You can still plan budgets and use month tools.')
					.replace('{monthLabel}', monthLabel);
			}
		} else if (count === 1) {
			monthLine = tr(APP, 'One booking in the ledger for {monthLabel}.').replace('{monthLabel}', monthLabel);
		} else {
			monthLine = tr(APP, '{count} bookings in the ledger for {monthLabel}.')
				.replace('{count}', String(count))
				.replace('{monthLabel}', monthLabel);
		}
		return { spanLine: spanLine, monthLine: monthLine };
	}

	if (!window.BudgetCheck || typeof window.BudgetCheck.define !== 'function') {
		throw new Error('BudgetCheck bootstrap missing — Dates cannot register');
	}
	window.BudgetCheck.define('Dates', {
		normalizeLang: D.normalizeLang,
		isoDate: D.isoDate,
		currentYearMonth: D.currentYearMonth,
		currentYearMonthSafe,
		clampYearMonth: D.clampYearMonth,
		formatYearMonth: D.formatYearMonth,
		formatDisplayDate: D.formatDisplayDate,
		formatDisplayDateTime: D.formatDisplayDateTime,
		formatIso,
		parseDisplayDateToIso: D.parseDisplayDateToIso,
		expectedFormatPattern: D.expectedFormatPattern,
		isIsoCalendarDay: D.isIsoCalendarDay,
		resolveTemporalInputLang: D.resolveTemporalInputLang,
		enrichTemporalHtmlLang: window.CheckDates.enrichTemporalHtmlLang,
		applyLocaleToTemporalInput: D.applyLocaleToTemporalInput,
		applyLocaleToTemporalInputs,
		compareYearMonth: D.compareYearMonth,
		yearMonthFromDateRange,
		monthlyLedgerHelpLines,
	});
})();
