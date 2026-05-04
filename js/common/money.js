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

	function formatEnvelope(env, locale) {
		const e = env && typeof env === 'object' ? env : envelopeOrZero('EUR');
		const minor = Number(e.minor || 0);
		const decimals = Number(e.decimals || 2);
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

	function parseHuman(value, decimals) {
		const dec = Number(decimals || 2);
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

	window.BudgetCheckMoney = { formatEnvelope, parseHuman };
})();
