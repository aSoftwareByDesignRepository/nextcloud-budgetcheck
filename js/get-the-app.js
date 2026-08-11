(function () {
	'use strict';

	/**
	 * Get the App is server-rendered. This page module exists so the shared
	 * bootstrap chain stays consistent with other BudgetCheck pages.
	 */
	if (!window.BudgetCheck || typeof window.BudgetCheck.onReady !== 'function') {
		return;
	}
	window.BudgetCheck.onReady(function () {}, {
		required: [],
		optional: [],
	});
})();
