(function () {
	'use strict';

	/**
	 * Values that must stay aligned with PHP (e.g. CategoryService constants).
	 *
	 * @typedef {{ GROUP_INTERNAL_UNCATEGORIZED: string }} BudgetCheckConstants
	 */

	/** @type {BudgetCheckConstants} */
	const Constants = {
		/** Same as OCA\BudgetCheck\Service\CategoryService::GROUP_INTERNAL_UNCATEGORIZED */
		GROUP_INTERNAL_UNCATEGORIZED: '_bc_internal_uncategorized',

		/**
		 * @param {string|null|undefined} groupKey
		 * @return {boolean}
		 */
		isInternalUncategorizedGroupKey(groupKey) {
			return groupKey === Constants.GROUP_INTERNAL_UNCATEGORIZED;
		},

		/**
		 * User-facing label for a category group key. Internal system keys are never shown raw.
		 *
		 * @param {string|null|undefined} groupKey
		 * @return {string}
		 */
		categoryGroupKeyLabel(groupKey) {
			if (!groupKey) {
				return '—';
			}
			if (Constants.isInternalUncategorizedGroupKey(groupKey)) {
				return t('budgetcheck', 'Built-in (uncategorized)');
			}
			return String(groupKey);
		},

		/**
		 * Whether the group sub-label should appear in transaction lists (internal bucket is hidden).
		 *
		 * @param {string|null|undefined} groupKey
		 * @return {boolean}
		 */
		shouldShowCategoryGroupBadge(groupKey) {
			return Boolean(groupKey) && !Constants.isInternalUncategorizedGroupKey(groupKey);
		},

		/**
		 * Active categories that accept planned amounts (excludes internal uncategorized bucket).
		 * Expense categories sort before income for stable tables.
		 *
		 * @param {Array<{isActive?: boolean, groupKey?: string|null, type?: string, name?: string}>} categories
		 * @return {Array}
		 */
		budgetableCategories(categories) {
			const hidden = Constants.GROUP_INTERNAL_UNCATEGORIZED;
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

	if (!window.BudgetCheck || typeof window.BudgetCheck.define !== 'function') {
		throw new Error('BudgetCheck bootstrap missing — Constants cannot register');
	}
	window.BudgetCheck.define('Constants', Constants);
})();
