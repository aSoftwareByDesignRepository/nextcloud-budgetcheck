(function () {
	'use strict';

	/**
	 * Values that must stay aligned with PHP (e.g. CategoryService constants).
	 *
	 * @typedef {{ GROUP_INTERNAL_UNCATEGORIZED: string }} BudgetCheckConstants
	 */

	/** @type {BudgetCheckConstants} */
	window.BudgetCheckConstants = {
		/** Same as OCA\BudgetCheck\Service\CategoryService::GROUP_INTERNAL_UNCATEGORIZED */
		GROUP_INTERNAL_UNCATEGORIZED: '_bc_internal_uncategorized',
	};
})();
