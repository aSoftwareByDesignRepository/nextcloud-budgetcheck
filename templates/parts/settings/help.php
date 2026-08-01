<?php
/**
 * Workspace settings sub-page: Help and glossary.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
?>
<section class="bc-card bc-section bc-help-panels" id="bc-help-panels" aria-label="<?php p($l->t('Help and glossary')); ?>">
	<details class="bc-details" id="bc-glossary">
		<summary class="bc-details__summary"><?php p($l->t('Words we use')); ?></summary>
		<div class="bc-details__body">
			<dl class="bc-glossary">
				<dt><?php p($l->t('Household')); ?></dt>
				<dd><?php p($l->t('A workspace for month-by-month income, expenses, budgets, savings targets, and monthly close.')); ?></dd>
				<dt><?php p($l->t('Project')); ?></dt>
				<dd><?php p($l->t('A workspace with start and end dates for bounded spend; no monthly close flow.')); ?></dd>
				<dt><?php p($l->t('Budget left')); ?></dt>
				<dd><?php p($l->t('Planned amount for a category minus spending that counts toward that plan in the month.')); ?></dd>
				<dt><?php p($l->t('Available after savings')); ?></dt>
				<dd><?php p($l->t('Income minus expenses minus the savings target for that month.')); ?></dd>
				<dt><?php p($l->t('Category group')); ?></dt>
				<dd><?php p($l->t('Optional tag shared by a few related categories—for rolled-up totals in transaction analytics and filters. It is not a bank hierarchy; use Notes for vendor or payee names.')); ?></dd>
				<dt><?php p($l->t('Notes')); ?></dt>
				<dd><?php p($l->t('Free text on a transaction, searchable from the transactions page. Ideal for bank vendor names when categories stay broad.')); ?></dd>
				<dt><?php p($l->t('Savings target')); ?></dt>
				<dd><?php p($l->t('The amount you plan to set aside (percentage, fixed amount, or the higher of both).')); ?></dd>
				<dt><?php p($l->t('Savings achieved')); ?></dt>
				<dd><?php p($l->t('When a category is marked as savings transfer, progress uses those bookings. Otherwise it uses income minus everyday expenses.')); ?></dd>
				<dt><?php p($l->t('Savings transfer')); ?></dt>
				<dd><?php p($l->t('Category flag for money moved to savings. Counts toward your savings goal and is excluded from everyday budget saldo, but stays in total expenses.')); ?></dd>
				<dt><?php p($l->t('Planned entry')); ?></dt>
				<dd><?php p($l->t('A placeholder booking from Generate on a recurring rule or from category budget targets. Recurring placeholders match amount; budget placeholders match category (any amount). A real booking in the same or neighbouring month removes the plan.')); ?></dd>
				<dt><?php p($l->t('Cap warning')); ?></dt>
				<dd><?php p($l->t('For projects, a reminder when all-time spend approaches or exceeds the optional cap.')); ?></dd>
			</dl>
		</div>
	</details>
	<details class="bc-details" id="bc-spreadsheet-bridge">
		<summary class="bc-details__summary"><?php p($l->t('From spreadsheet to BudgetCheck')); ?></summary>
		<div class="bc-details__body">
			<ul class="bc-bridge-list">
				<li><?php p($l->t('Monthly sheet → log transactions and use the monthly plan for budgets and close.')); ?></li>
				<li><?php p($l->t('Annual overview grid → use the yearly overview for the household story.')); ?></li>
				<li><?php p($l->t('Large one-offs → use a project workspace or flag special transactions in a household workspace.')); ?></li>
			</ul>
			<p class="bc-bridge-note"><?php p($l->t('You can hide this panel permanently; it does not block any action.')); ?></p>
			<button type="button" class="button" data-bc-dismiss-bridge><?php p($l->t('Hide this panel permanently')); ?></button>
		</div>
	</details>
</section>
