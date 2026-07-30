(function () {
	'use strict';

	/**
	 * Live dependency bag (getters). Never snapshot window.BudgetCheck* at IIFE load.
	 * @type {Record<string, any>}
	 */
	const BC = (window.BudgetCheck && typeof window.BudgetCheck.live === 'function')
		? window.BudgetCheck.live(['Dates'])
		: (function () {
			const fallback = {};
			const map = {'Dates': 'BudgetCheckDates'};
			Object.keys(map).forEach(function (shortName) {
				Object.defineProperty(fallback, shortName, {
					enumerable: true,
					configurable: false,
					get: function () {
						const v = window[map[shortName]];
						return v === undefined ? null : v;
					},
				});
			});
			return fallback;
		}());


	function pad2(n) {
		return n < 10 ? '0' + n : String(n);
	}

	function toYearMonth(y, m) {
		return y + '-' + pad2(m);
	}

	function parseYm(s) {
		const m = String(s || '').match(/^(\d{4})-(\d{2})$/);
		if (!m) return null;
		const y = Number.parseInt(m[1], 10);
		const mo = Number.parseInt(m[2], 10);
		if (!Number.isFinite(y) || !Number.isFinite(mo) || mo < 1 || mo > 12) return null;
		return { y: y, m: mo };
	}

	function effectivePrimaryYear(ws) {
		if (!ws) return new Date().getFullYear();
		if (typeof ws.primaryPlanningYear === 'number' && ws.primaryPlanningYear >= 1900 && ws.primaryPlanningYear <= 9999) {
			return ws.primaryPlanningYear;
		}
		const ca = String(ws.createdAt || '').slice(0, 4);
		const y = Number.parseInt(ca, 10);
		return Number.isFinite(y) && y >= 1900 ? y : new Date().getFullYear();
	}

	function yearRange(ws, ledgerSpan) {
		if (ws && ws.type === 'project' && ws.projectStartDate && ws.projectEndDate) {
			const sy = Number.parseInt(String(ws.projectStartDate).slice(0, 4), 10);
			const ey = Number.parseInt(String(ws.projectEndDate).slice(0, 4), 10);
			if (Number.isFinite(sy) && Number.isFinite(ey)) {
				const yLo = Math.min(sy, ey);
				const yHi = Math.max(sy, ey);
				const years = [];
				for (let y = yHi; y >= yLo; y--) {
					years.push(y);
				}
				return years.length ? years : [new Date().getFullYear()];
			}
		}
		const nowY = new Date().getFullYear();
		const primary = effectivePrimaryYear(ws);
		let yMin = primary;
		let yMax = primary;
		[nowY, primary].forEach((y) => {
			if (Number.isFinite(y)) {
				yMin = Math.min(yMin, y);
				yMax = Math.max(yMax, y);
			}
		});
		if (ledgerSpan && ledgerSpan.firstYearMonth) {
			const fy = Number.parseInt(String(ledgerSpan.firstYearMonth).slice(0, 4), 10);
			if (Number.isFinite(fy)) {
				yMin = Math.min(yMin, fy);
				yMax = Math.max(yMax, fy);
			}
		}
		if (ledgerSpan && ledgerSpan.lastYearMonth) {
			const ly = Number.parseInt(String(ledgerSpan.lastYearMonth).slice(0, 4), 10);
			if (Number.isFinite(ly)) {
				yMin = Math.min(yMin, ly);
				yMax = Math.max(yMax, ly);
			}
		}
		yMin = Math.max(1900, yMin - 2);
		yMax = Math.min(9999, yMax + 3);
		const years = [];
		for (let y = yMax; y >= yMin; y--) {
			years.push(y);
		}
		return years;
	}

	function monthName(m, htmlLang) {
		const lang = BC.Dates && typeof BC.Dates.resolveTemporalInputLang === 'function'
			? BC.Dates.resolveTemporalInputLang(htmlLang)
			: (htmlLang || 'en').replace('_', '-');
		try {
			return new Intl.DateTimeFormat(lang, { month: 'long', timeZone: 'UTC' }).format(new Date(Date.UTC(2000, m - 1, 1)));
		} catch (_) {
			return String(m);
		}
	}

	function projectMonthsInCalendarYear(ws, year) {
		const out = [];
		if (!ws || ws.type !== 'project' || !ws.projectStartDate || !ws.projectEndDate) {
			for (let m = 1; m <= 12; m++) out.push(m);
			return out;
		}
		const pStart = String(ws.projectStartDate);
		const pEnd = String(ws.projectEndDate);
		for (let m = 1; m <= 12; m++) {
			const first = toYearMonth(year, m) + '-01';
			const lastD = new Date(Date.UTC(year, m, 0)).getUTCDate();
			const last = toYearMonth(year, m) + '-' + pad2(lastD);
			if (last < pStart || first > pEnd) {
				continue;
			}
			out.push(m);
		}
		if (!out.length) {
			const sm = String(ws.projectStartDate).match(/^(\d{4})-(\d{2})/);
			if (sm) {
				const y0 = Number.parseInt(sm[1], 10);
				const m0 = Number.parseInt(sm[2], 10);
				if (y0 === year && m0 >= 1 && m0 <= 12) {
					return [m0];
				}
			}
		}
		return out.length ? out : [1];
	}

	/**
	 * @param {HTMLElement} container root with data-bc-household-period
	 * @param {{ workspace: object, htmlLang: string, initialYearMonth: string, onChange: (ym: string) => void, ledgerSpan?: object|null }} opts
	 */
	function wire(container, opts) {
		if (!container || !opts || !opts.workspace) return { getYearMonth: function () { return ''; }, setYearMonth: function () {}, refreshLedgerSpan: function () {}, destroy: function () {} };
		const ws = opts.workspace;
		const variant = container.getAttribute('data-bc-variant') === 'project' ? 'project' : 'household';
		const yearSel = container.querySelector('[data-bc-plan-year]');
		const monthSel = container.querySelector('[data-bc-plan-month]');
		if (!yearSel || !monthSel) {
			return { getYearMonth: function () { return ''; }, setYearMonth: function () {}, refreshLedgerSpan: function () {}, destroy: function () {} };
		}

		let ledgerSpan = opts.ledgerSpan || null;
		let onChangeCb = typeof opts.onChange === 'function' ? opts.onChange : function () {};

		function fillYears() {
			const years = yearRange(ws, ledgerSpan);
			yearSel.replaceChildren();
			years.forEach((y) => {
				const o = document.createElement('option');
				o.value = String(y);
				o.textContent = String(y);
				yearSel.appendChild(o);
			});
		}

		function fillMonthsForYear(y) {
			const allowed = variant === 'project' ? projectMonthsInCalendarYear(ws, y) : [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];
			const prev = monthSel.value;
			monthSel.replaceChildren();
			allowed.forEach((m) => {
				const o = document.createElement('option');
				o.value = pad2(m);
				o.textContent = monthName(m, opts.htmlLang);
				monthSel.appendChild(o);
			});
			if (allowed.includes(Number.parseInt(prev, 10))) {
				monthSel.value = prev;
			} else {
				monthSel.value = pad2(allowed[0]);
			}
		}

		function currentYm() {
			const y = Number.parseInt(yearSel.value, 10);
			const mo = Number.parseInt(monthSel.value, 10);
			if (!Number.isFinite(y) || !Number.isFinite(mo)) {
				if (opts.initialYearMonth) return opts.initialYearMonth;
				return (BC.Dates && typeof BC.Dates.currentYearMonth === 'function')
					? BC.Dates.currentYearMonth()
					: '';
			}
			return toYearMonth(y, mo);
		}

		function syncScopeStrip() {
			const Ws = window.BudgetCheckWorkspace;
			if (Ws && typeof Ws.setScopeMonth === 'function') {
				Ws.setScopeMonth(currentYm());
			}
		}

		function emit() {
			syncScopeStrip();
			onChangeCb(currentYm());
		}

		function setYearMonth(ym) {
			const p = parseYm(ym);
			if (!p) return;
			fillYears();
			if (Array.from(yearSel.options).some((o) => o.value === String(p.y))) {
				yearSel.value = String(p.y);
			} else {
				const o = document.createElement('option');
				o.value = String(p.y);
				o.textContent = String(p.y);
				yearSel.insertBefore(o, yearSel.firstChild);
				yearSel.value = String(p.y);
			}
			fillMonthsForYear(p.y);
			if (Array.from(monthSel.options).some((o) => o.value === pad2(p.m))) {
				monthSel.value = pad2(p.m);
			} else {
				monthSel.selectedIndex = 0;
			}
			syncScopeStrip();
		}

		fillYears();
		const initYm = opts.initialYearMonth
			|| ((BC.Dates && typeof BC.Dates.currentYearMonth === 'function') ? BC.Dates.currentYearMonth() : '');
		const init = parseYm(initYm);
		if (init) {
			if (!Array.from(yearSel.options).some((o) => o.value === String(init.y))) {
				const o = document.createElement('option');
				o.value = String(init.y);
				o.textContent = String(init.y);
				yearSel.insertBefore(o, yearSel.firstChild);
			}
			yearSel.value = String(init.y);
			fillMonthsForYear(init.y);
			if (Array.from(monthSel.options).some((o) => o.value === pad2(init.m))) {
				monthSel.value = pad2(init.m);
			}
		}
		syncScopeStrip();

		const onYear = () => {
			const y = Number.parseInt(yearSel.value, 10);
			if (Number.isFinite(y)) fillMonthsForYear(y);
			emit();
		};
		const onMonth = () => emit();

		yearSel.addEventListener('change', onYear);
		monthSel.addEventListener('change', onMonth);

		return {
			getYearMonth: currentYm,
			setYearMonth: setYearMonth,
			refreshLedgerSpan: function (span) {
				ledgerSpan = span || null;
				const cur = currentYm();
				fillYears();
				setYearMonth(cur);
			},
			destroy: function () {
				yearSel.removeEventListener('change', onYear);
				monthSel.removeEventListener('change', onMonth);
			},
		};
	}

	if (!window.BudgetCheck || typeof window.BudgetCheck.define !== 'function') {
		throw new Error('BudgetCheck bootstrap missing — HouseholdPeriod cannot register');
	}
	window.BudgetCheck.define('HouseholdPeriod', { wire: wire });
})();
