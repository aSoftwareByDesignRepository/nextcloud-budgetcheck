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

		/**
		 * Active categories that accept planned amounts (excludes internal uncategorized bucket).
		 * Expense categories sort before income for stable tables.
		 *
		 * @param {Array<{isActive?: boolean, groupKey?: string|null, type?: string, name?: string}>} categories
		 * @return {Array}
		 */
		budgetableCategories(categories) {
			const hidden = window.BudgetCheckConstants.GROUP_INTERNAL_UNCATEGORIZED;
			return (categories || [])
				.filter((c) => c && c.isActive && c.groupKey !== hidden)
				.sort((a, b) => {
					const order = (c) => (c.type === 'income' ? 1 : 0);
					const diff = order(a) - order(b);
					if (diff !== 0) {
						return diff;
					}
					return String(a.name || '').localeCompare(String(b.name || ''), undefined, { sensitivity: 'base' });
				});
		},
	};
})();
