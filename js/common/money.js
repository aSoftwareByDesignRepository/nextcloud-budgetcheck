(function () {
	'use strict';

	// Client-side money helpers.
	//
	// - All amounts use **minor units** (integer) and a 3-letter currency code.
	// - formatEnvelope({minor, currency, decimal}) renders a locale-aware
	//   currency string. When the server already produced a `display`-shaped
	//   field we use it; otherwise we fall back to Intl.NumberFormat.
	// - parseHuman(value, decimals) mirrors the PHP MoneyService parser so the
	//   user sees identical "1.234,56" vs "1,234.56" tolerance on both sides.

	function envelopeOrZero(currency) {
		return { minor: 0, currency: String(currency || 'EUR'), decimal: '0.00' };
	}

	/**
	 * Resolve decimal places for an envelope. Prefer the explicit `decimals`
	 * int (including 0 for JPY). Fall back to counting digits after the
	 * server-provided `decimal` string so older envelopes without `decimals`
	 * still scale correctly.
	 */
	function envelopeDecimals(e) {
		const d = Number(e && e.decimals);
		if (Number.isFinite(d) && d >= 0 && d <= 8) return d;
		const decimal = e && typeof e.decimal === 'string' ? e.decimal : '';
		const dot = decimal.lastIndexOf('.');
		if (dot !== -1) {
			const frac = decimal.length - dot - 1;
			if (frac >= 0 && frac <= 8) return frac;
		}
		if (decimal !== '' && /^-?\d+$/.test(decimal)) return 0;
		return 2;
	}

	function formatEnvelope(env, locale) {
		const e = env && typeof env === 'object' ? env : envelopeOrZero('EUR');
		const minor = Number(e.minor || 0);
		const decimals = envelopeDecimals(e);
		const code = String(e.currency || 'EUR').toUpperCase();
		const amount = minor / Math.pow(10, decimals);
		const lang = locale || (document.documentElement.lang || 'en');
		try {
			return new Intl.NumberFormat(lang, {
				style: 'currency',
				currency: code,
				minimumFractionDigits: decimals,
				maximumFractionDigits: decimals,
			}).format(amount);
		} catch (_) {
			return amount.toFixed(decimals) + ' ' + code;
		}
	}

	/**
	 * Locale-aware amount **without** currency symbol/code, for summary tiles where
	 * the workspace currency is already shown in the page header. Screen-reader
	 * callers should append the currency via a visually hidden element.
	 */
	function formatEnvelopeValue(env, locale) {
		const e = env && typeof env === 'object' ? env : envelopeOrZero('EUR');
		const minor = Number(e.minor || 0);
		const decimals = envelopeDecimals(e);
		const amount = minor / Math.pow(10, decimals);
		const lang = locale || (document.documentElement.lang || 'en');
		try {
			return new Intl.NumberFormat(lang, {
				minimumFractionDigits: decimals,
				maximumFractionDigits: decimals,
			}).format(amount);
		} catch (_) {
			return amount.toFixed(decimals);
		}
	}

	function resolveDecimals(decimals) {
		const d = Number(decimals);
		return Number.isFinite(d) && d >= 0 && d <= 8 ? d : 2;
	}

	function parseHuman(value, decimals) {
		// `0` is valid (JPY) — never coerce with `decimals || 2`.
		const dec = resolveDecimals(decimals);
		if (typeof value === 'number') {
			if (!Number.isFinite(value) || value < 0) {
				throw new Error(t('budgetcheck', 'Amount must be a positive number.'));
			}
			return Math.round(value * Math.pow(10, dec));
		}
		let str = String(value || '').trim();
		if (str === '') {
			throw new Error(t('budgetcheck', 'Amount is required.'));
		}
		if (str.startsWith('+') || str.startsWith('-')) {
			throw new Error(t('budgetcheck', 'Amount must be positive.'));
		}
		str = str.replace(/[\s\u00A0]/g, '');
		const lastDot = str.lastIndexOf('.');
		const lastComma = str.lastIndexOf(',');
		let normalised = str;
		if (lastDot !== -1 && lastComma !== -1) {
			const decChar = lastDot > lastComma ? '.' : ',';
			const thouChar = decChar === '.' ? ',' : '.';
			normalised = str.split(thouChar).join('').replace(decChar, '.');
		} else if (lastComma !== -1) {
			normalised = str.replace(/,/g, '.');
		}
		if (!/^[0-9]+(?:\.[0-9]+)?$/.test(normalised)) {
			throw new Error(t('budgetcheck', 'Amount is not a valid number.'));
		}
		const [intPart, fracRaw] = normalised.split('.', 2);
		const fracPart = (fracRaw || '').slice(0, dec).padEnd(dec, '0');
		const digits = (intPart + fracPart).replace(/^0+/, '') || '0';
		if (digits.length > 16) {
			throw new Error(t('budgetcheck', 'Amount is too large.'));
		}
		const minor = Number.parseInt(digits, 10);
		if (!Number.isFinite(minor) || minor < 0) {
			throw new Error(t('budgetcheck', 'Amount is not a valid number.'));
		}
		return minor;
	}

	function formatMinor(minor, currency, locale, decimalsOverride) {
		const code = String(currency || 'EUR').toUpperCase();
		const decimals = Number.isInteger(decimalsOverride)
			? decimalsOverride
			: (code === 'JPY' ? 0 : 2);
		return formatEnvelope({ minor, currency: code, decimals }, locale);
	}

	function convertTaxPreview(amountMinor, vatRateBp, basis) {
		const amount = Number(amountMinor || 0);
		const rate = Number(vatRateBp || 0);
		if (basis === 'net') {
			const vat = Math.round((amount * rate) / 10000);
			return { net: amount, vat, gross: amount + vat };
		}
		if (basis === 'gross') {
			const net = Math.round((amount * 10000) / (10000 + rate));
			return { net, vat: amount - net, gross: amount };
		}
		return { net: amount, vat: 0, gross: amount };
	}

	window.BudgetCheckMoney = { formatEnvelope, formatEnvelopeValue, parseHuman, formatMinor, convertTaxPreview, envelopeDecimals };
})();
