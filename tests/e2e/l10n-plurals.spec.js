// @ts-check
const { test, expect } = require('@playwright/test');

/**
 * Proves the runtime n() contract the count-string fix relies on:
 * singular msgid at n=1, plural at n>1, %n and {name} substitution.
 * Guards the n() conversions in settings.js / import.js / dates.js.
 */
test.describe('l10n plurals', () => {
	test('window.n substitutes singular/plural and named vars', async ({ page }) => {
		await page.goto('/apps/budgetcheck/dashboard', { waitUntil: 'domcontentloaded' });
		await page.waitForFunction(() => typeof window.n === 'function');

		const out = await page.evaluate(() => ({
			sing: window.n('budgetcheck', '%n attachment', '{count} attachments', 1, { count: 1 }),
			plur: window.n('budgetcheck', '%n attachment', '{count} attachments', 3, { count: 3 }),
			varsSing: window.n('budgetcheck', 'Added %n entry across {rules} rules.', 'Added {count} entries across {rules} rules.', 1, { count: 1, rules: 2 }),
			varsPlur: window.n('budgetcheck', 'Added %n entry across {rules} rules.', 'Added {count} entries across {rules} rules.', 5, { count: 5, rules: 2 }),
		}));

		expect(out.sing).toBe('1 attachment');
		expect(out.plur).toBe('3 attachments');
		expect(out.varsSing).toBe('Added 1 entry across 2 rules.');
		expect(out.varsPlur).toBe('Added 5 entries across 2 rules.');
	});
});
