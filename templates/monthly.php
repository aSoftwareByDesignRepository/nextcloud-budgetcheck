<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */

$workspace = $_['workspace'] ?? null;
$canManage = !empty($_['canManageWorkspace']);
?>
<?php include __DIR__ . '/common/page-start.php'; ?>

<?php if ($workspace === null): ?>
	<section class="bc-card bc-empty">
		<h2><?php p($l->t('Select a household workspace')); ?></h2>
		<p><?php p($l->t('Pick a household workspace from the sidebar.')); ?></p>
	</section>
<?php else: ?>
	<section class="bc-card bc-section" aria-labelledby="bc-monthly-pick-title">
		<header class="bc-section__header">
			<div>
				<h2 id="bc-monthly-pick-title"><?php p($l->t('Pick the month to review')); ?></h2>
				<p class="bc-section__sub" data-bc-month-status></p>
				<div class="bc-ledger-help" data-bc-monthly-ledger-help hidden aria-live="polite"></div>
			</div>
			<div class="bc-section__controls bc-section__controls--stack bc-period-picker-wrap" data-bc-household-period data-bc-variant="household">
				<fieldset class="bc-fieldset bc-period-picker" aria-describedby="bc-monthly-month-hint">
					<legend class="bc-fieldset__legend"><?php p($l->t('Month to review')); ?></legend>
					<div class="bc-period-picker__row">
						<label class="bc-field bc-field--inline" for="bc-monthly-plan-year">
							<span class="bc-field__label"><?php p($l->t('Year')); ?></span>
							<select id="bc-monthly-plan-year" class="bc-input" data-bc-plan-year></select>
						</label>
						<label class="bc-field bc-field--inline" for="bc-monthly-plan-month">
							<span class="bc-field__label"><?php p($l->t('Month')); ?></span>
							<select id="bc-monthly-plan-month" class="bc-input" data-bc-plan-month></select>
						</label>
					</div>
				</fieldset>
				<p id="bc-monthly-month-hint" class="bc-section__control-hint"><?php p($l->t('Choose year and month with the lists below. The URL updates when you change the month so you can bookmark or share it.')); ?></p>
			</div>
		</header>
	</section>

	<section class="bc-card bc-section" aria-labelledby="bc-month-summary-title" data-bc-summary>
		<header class="bc-section__header">
			<div>
				<h2 id="bc-month-summary-title"><?php p($l->t('Totals')); ?></h2>
				<p class="bc-section__sub"><?php p($l->t('Totals for this month, grouped so cash flow, savings, and everyday budgets are easy to tell apart.')); ?></p>
				<p class="bc-section__sub" data-bc-summary-period></p>
			</div>
		</header>
		<div class="bc-summary-grid" data-bc-summary-grid aria-busy="true">
			<p class="bc-loading"><?php p($l->t('Loading…')); ?></p>
		</div>
	</section>

	<section class="bc-card bc-section" aria-labelledby="bc-month-activity-title">
		<header class="bc-section__header">
			<div>
				<h2 id="bc-month-activity-title"><?php p($l->t('Monthly activity')); ?></h2>
				<p class="bc-section__sub"><?php p($l->t('Actual bookings and planned placeholders counted separately—aligned with the cash-flow tiles above.')); ?></p>
			</div>
		</header>
		<div class="bc-summary-grid" data-bc-month-activity-grid aria-busy="true">
			<p class="bc-loading"><?php p($l->t('Loading…')); ?></p>
		</div>
	</section>

	<section class="bc-card bc-section" aria-labelledby="bc-month-warnings-title" data-bc-warnings hidden>
		<header class="bc-section__header">
			<div>
				<h2 id="bc-month-warnings-title"><?php p($l->t('Things to review before closing')); ?></h2>
			</div>
		</header>
		<ul class="bc-warnings" data-bc-warnings-list></ul>
	</section>

	<section class="bc-card bc-section" aria-labelledby="bc-month-budget-title" data-bc-budget-section>
		<header class="bc-section__header">
			<div>
				<h2 id="bc-month-budget-title"><?php p($l->t('Category consumption')); ?></h2>
				<p class="bc-section__sub"><?php p($l->t('How each category is performing against its plan this month.')); ?></p>
			</div>
			<?php if ($canManage): ?>
				<div class="bc-section__controls">
					<button type="button" class="button primary" data-bc-action="open-month-budget-overrides">
						<?php p($l->t('Edit monthly overrides')); ?>
					</button>
				</div>
			<?php endif; ?>
		</header>
		<div class="bc-table-scroll" role="region" aria-label="<?php p($l->t('Category consumption')); ?>" tabindex="0">
			<table class="bc-table bc-budget-table">
				<thead>
					<tr>
						<th scope="col"><?php p($l->t('Category')); ?></th>
						<th scope="col" class="bc-table__col--num"><?php p($l->t('Planned')); ?></th>
						<th scope="col" class="bc-table__col--num"><?php p($l->t('Actual')); ?></th>
						<th scope="col" class="bc-table__col--num"><?php p($l->t('Remaining')); ?></th>
					</tr>
				</thead>
				<tbody data-bc-month-budget-rows>
					<tr><td colspan="4" class="bc-loading"><?php p($l->t('Loading…')); ?></td></tr>
				</tbody>
			</table>
		</div>
	</section>

	<section class="bc-card bc-section" aria-labelledby="bc-month-transactions-title">
		<header class="bc-section__header">
			<div>
				<h2 id="bc-month-transactions-title"><?php p($l->t('Transactions this month')); ?></h2>
				<p class="bc-section__sub"><?php p($l->t('Complete list of bookings for this month.')); ?></p>
			</div>
			<div class="bc-section__controls">
				<a class="button" data-bc-month-transactions-link href="#"><?php p($l->t('Open in transactions view')); ?></a>
			</div>
		</header>
		<div class="bc-table-scroll" role="region" aria-label="<?php p($l->t('Transactions this month')); ?>" tabindex="0">
			<table class="bc-table">
				<thead>
					<tr>
						<th scope="col"><?php p($l->t('Date')); ?></th>
						<th scope="col"><?php p($l->t('Title')); ?></th>
						<th scope="col"><?php p($l->t('Direction')); ?></th>
						<th scope="col" class="bc-table__col--num"><?php p($l->t('Amount')); ?></th>
						<th scope="col"><?php p($l->t('Tags')); ?></th>
					</tr>
				</thead>
				<tbody data-bc-month-transactions-rows>
					<tr><td colspan="5" class="bc-loading"><?php p($l->t('Loading…')); ?></td></tr>
				</tbody>
			</table>
		</div>
	</section>

	<?php if ($canManage): ?>
		<section class="bc-card bc-section bc-section--close" aria-labelledby="bc-close-title">
			<header class="bc-section__header">
				<div>
					<h2 id="bc-close-title"><?php p($l->t('Close or reopen this month')); ?></h2>
					<p class="bc-section__sub"><?php p($l->t('Closing locks the ledger for this month and stores an immutable snapshot.')); ?></p>
				</div>
			</header>
			<div class="bc-callout bc-callout--info" role="note" aria-labelledby="bc-close-explainer-title">
				<p id="bc-close-explainer-title"><strong><?php p($l->t('What happens when you close this month')); ?></strong></p>
				<ol class="bc-explainer-steps">
					<li><?php p($l->t('All transactions for this month become read-only. New, edit, and delete are blocked until a manager reopens the month.')); ?></li>
					<li><?php p($l->t('A snapshot is stored with totals, budget consumption, savings, and a SHA-256 hash so the audit trail is verifiable.')); ?></li>
					<li><?php p($l->t('The audit log records who closed the month and when.')); ?></li>
				</ol>
				<p class="bc-callout__hint"><?php p($l->t('Reopen is reversible. Use it before recording corrections, then close again to refresh the snapshot.')); ?></p>
			</div>
			<div class="bc-form-actions">
				<button type="button" class="button primary" data-bc-action="close-month" disabled aria-describedby="bc-close-explainer-title">
					<?php p($l->t('Close this month')); ?>
				</button>
				<button type="button" class="button" data-bc-action="reopen-month" hidden aria-describedby="bc-close-explainer-title">
					<?php p($l->t('Reopen this month')); ?>
				</button>
			</div>
		</section>
	<?php endif; ?>

	<section class="bc-card bc-section bc-section--glossary" aria-labelledby="bc-glossary-title">
		<header class="bc-section__header">
			<div>
				<h2 id="bc-glossary-title"><?php p($l->t('Glossary')); ?></h2>
				<p class="bc-section__sub"><?php p($l->t('Quick reference for the values shown on this page.')); ?></p>
			</div>
		</header>
		<dl class="bc-glossary">
			<div class="bc-glossary__item">
				<dt><?php p($l->t('Expected (plan)')); ?></dt>
				<dd><?php p($l->t('Budget targets and planned ledger placeholders for the month. These are not included in actual cash flow above—they show what you expect before real bookings arrive.')); ?></dd>
			</div>
			<div class="bc-glossary__item">
				<dt><?php p($l->t('Income')); ?></dt>
				<dd><?php p($l->t('Money that came in this month, summed in minor units (e.g. cents) and shown formatted in your locale.')); ?></dd>
			</div>
			<div class="bc-glossary__item">
				<dt><?php p($l->t('Expense')); ?></dt>
				<dd><?php p($l->t('Money that went out this month. Special categories like rent or insurance are tracked separately for cap warnings.')); ?></dd>
			</div>
			<div class="bc-glossary__item">
				<dt><?php p($l->t('Savings withheld')); ?></dt>
				<dd><?php p($l->t('Amount reserved by your savings target before everyday spending. Percentage targets reserve a share of net income; absolute targets reserve a fixed amount.')); ?></dd>
			</div>
			<div class="bc-glossary__item">
				<dt><?php p($l->t('Available after savings')); ?></dt>
				<dd><?php p($l->t('Income minus expenses minus savings withheld. Negative values mean the month is over budget; warnings appear above.')); ?></dd>
			</div>
			<div class="bc-glossary__item">
				<dt><?php p($l->t('Snapshot hash')); ?></dt>
				<dd><?php p($l->t('Deterministic SHA-256 over the canonical month totals. Identical input always produces the same hash; auditors compare it to verify integrity.')); ?></dd>
			</div>
		</dl>
	</section>
<?php endif; ?>

<?php include __DIR__ . '/common/page-end.php'; ?>
