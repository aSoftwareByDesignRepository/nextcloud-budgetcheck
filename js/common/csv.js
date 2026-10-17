/**
 * BudgetCheck CSV cell safety — JS mirror of
 * TransactionExportService::guardFormula.
 *
 * A cell whose first character is = + - @ TAB CR or VT is interpreted as a
 * live formula by Excel/LibreOffice, so user-controlled values (category
 * names, booking statuses) can inject formulas into downloads. Prefixing a
 * literal apostrophe marks the cell as text; RFC 4180 quoting alone does not.
 */
(function () {
	'use strict';

	const FORMULA_LEAD = /^[=+\-@\t\r\x0B]/;

	function neutralize(value) {
		const s = String(value == null ? '' : value);
		return FORMULA_LEAD.test(s) ? "'" + s : s;
	}

	function cell(value) {
		const s = neutralize(value);
		if (/[",\n\r]/.test(s)) {
			return '"' + s.replace(/"/g, '""') + '"';
		}
		return s;
	}

	window.BudgetCheckCsv = { neutralize, cell };
})();
