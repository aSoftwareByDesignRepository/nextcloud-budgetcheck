(function () {
	'use strict';

	/**
	 * Shared JS translate helper (BudgetCheckL10n.t).
	 *
	 * Catalogs carry the vsprintf '%%' literal escape for the PHP side;
	 * OC.L10N.translate / window.t return catalog bytes verbatim, so every
	 * JS render path must collapse '%%' itself (transfer.js convention).
	 * Route percent-carrying msgids through this helper — never rely on
	 * per-site .replace() after verbatim t().
	 */
	function t(text, vars) {
		var out = (window.OC && OC.L10N && typeof OC.L10N.translate === 'function')
			? OC.L10N.translate('budgetcheck', text)
			: ((typeof window.t === 'function') ? window.t('budgetcheck', text) : text);
		if (Array.isArray(vars) && vars.length) {
			// One ordered pass: '%%' -> '%', each %[N$][sd] consumes the next
			// array item (or the positional index for %N$[sd]).
			var i = 0;
			return out.replace(/%%|%(?:\d+\$)?[sd]/g, function (m) {
				if (m === '%%') return '%';
				var idx = /^%(\d+)\$/.exec(m);
				var v = idx ? vars[+idx[1] - 1] : vars[i++];
				return (v === undefined || v === null) ? '' : String(v);
			});
		}
		// Collapse before {name} substitution so a substituted value containing
		// '%' is never mistaken for an escape.
		out = out.replace(/%%/g, '%');
		if (vars && !Array.isArray(vars)) {
			Object.keys(vars).forEach(function (k) {
				out = out.split('{' + k + '}').join(String(vars[k]));
			});
		}
		return out;
	}

	if (window.BudgetCheck && typeof window.BudgetCheck.define === 'function') {
		window.BudgetCheck.define('L10n', { t: t });
	} else {
		window.BudgetCheckL10n = { t: t };
	}
})();
