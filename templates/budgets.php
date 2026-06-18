<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */

$workspace = $_['workspace'] ?? null;
$canManage = !empty($_['canManageWorkspace']);
?>
<?php include __DIR__ . '/common/page-start.php'; ?>

<?php if ($workspace === null): ?>
	<section class="bc-card bc-empty">
		<h2><?php p($l->t('Select a workspace')); ?></h2>
		<p><?php p($l->t('Pick a workspace in the sidebar to plan its budgets.')); ?></p>
	</section>
<?php else: ?>
	<section class="bc-card bc-section" aria-labelledby="bc-budget-month-title">
		<header class="bc-section__header">
			<div>
				<h2 id="bc-budget-month-title"><?php p($l->t('Pick a month')); ?></h2>
				<p class="bc-section__sub" data-bc-budget-window></p>
				<div class="bc-ledger-help" data-bc-budget-ledger-help hidden aria-live="polite"></div>
			</div>
			<?php $bcHpVar = ($workspace['type'] === 'project') ? 'project' : 'household'; ?>
			<div class="bc-section__controls bc-section__controls--stack bc-period-picker-wrap" data-bc-household-period data-bc-variant="<?php p($bcHpVar); ?>">
				<fieldset class="bc-fieldset bc-period-picker" aria-describedby="bc-budget-month-hint">
					<legend class="bc-fieldset__legend"><?php p($l->t('Budget month')); ?></legend>
					<div class="bc-period-picker__row">
						<label class="bc-field bc-field--inline" for="bc-budget-plan-year">
							<span class="bc-field__label"><?php p($l->t('Year')); ?></span>
							<select id="bc-budget-plan-year" class="bc-input" data-bc-plan-year></select>
						</label>
						<label class="bc-field bc-field--inline" for="bc-budget-plan-month">
							<span class="bc-field__label"><?php p($l->t('Month')); ?></span>
							<select id="bc-budget-plan-month" class="bc-input" data-bc-plan-month></select>
						</label>
					</div>
				</fieldset>
				<p id="bc-budget-month-hint" class="bc-section__control-hint"><?php p($workspace['type'] === 'household'
					? $l->t('Pick a calendar year and month. The year list grows with your ledger; your primary planning year anchors the default range.')
					: $l->t('Pick a calendar year and month. Months outside the project start and end dates are hidden.')); ?></p>
			</div>
		</header>
	</section>

	<section class="bc-card bc-section" aria-labelledby="bc-budget-table-title">
		<header class="bc-section__header">
			<div>
				<h2 id="bc-budget-table-title"><?php p($l->t('Category budgets')); ?></h2>
				<p class="bc-section__sub"><?php p($l->t('Plan a target amount per category. Workspace defaults are prefilled; this page overrides one month.')); ?></p>
			</div>
		</header>
		<div class="bc-table-scroll" role="region" aria-label="<?php p($l->t('Budgets')); ?>" tabindex="0">
			<table class="bc-table bc-budget-table">
				<thead>
					<tr>
						<th scope="col"><?php p($l->t('Category')); ?></th>
						<th scope="col"><?php p($l->t('Direction')); ?></th>
						<th scope="col" class="bc-table__col--num"><?php p($l->t('Planned')); ?></th>
						<th scope="col" class="bc-table__col--num"><?php p($l->t('Actual')); ?></th>
						<th scope="col" class="bc-table__col--num"><?php p($l->t('Remaining')); ?></th>
					</tr>
				</thead>
				<tbody data-bc-budget-rows>
					<tr><td colspan="5" class="bc-loading"><?php p($l->t('Loading…')); ?></td></tr>
				</tbody>
			</table>
		</div>
		<?php if ($canManage): ?>
			<div class="bc-form-actions">
				<button type="button" class="button primary" data-bc-action="save-budgets" disabled>
					<?php p($l->t('Save changes')); ?>
				</button>
			</div>
		<?php endif; ?>
	</section>

	<?php if ($workspace['type'] === 'household'): ?>
		<section class="bc-card bc-section" aria-labelledby="bc-savings-title">
			<header class="bc-section__header">
				<div>
					<h2 id="bc-savings-title"><?php p($l->t('Savings target')); ?></h2>
					<p class="bc-section__sub"><?php p($l->t('A household can save by percentage of income, an absolute amount, or whichever is larger. Progress appears on the dashboard and yearly overview—no need to tag individual transactions.')); ?></p>
				</div>
			</header>
			<form class="bc-form-grid" data-bc-savings-form>
				<div class="bc-savings-layout">
					<div class="bc-savings-layout__modes">
						<fieldset class="bc-fieldset bc-fieldset--mode-group">
							<legend class="bc-fieldset__legend"><?php p($l->t('Mode')); ?></legend>
							<label class="bc-field bc-field--radio">
								<input type="radio" name="targetMode" value="percentage" checked>
								<span><?php p($l->t('Percentage of income')); ?></span>
							</label>
							<label class="bc-field bc-field--radio">
								<input type="radio" name="targetMode" value="absolute">
								<span><?php p($l->t('Absolute amount')); ?></span>
							</label>
							<label class="bc-field bc-field--radio">
								<input type="radio" name="targetMode" value="hybrid">
								<span><?php p($l->t('Hybrid (max of both)')); ?></span>
							</label>
						</fieldset>
					</div>
					<div class="bc-savings-layout__values">
						<label class="bc-field" data-bc-savings-percent-row>
							<span class="bc-field__label"><?php p($l->t('Percentage (0–100)')); ?></span>
							<input type="number" min="0" max="100" step="1" name="targetPercent" class="bc-input">
						</label>
						<label class="bc-field" data-bc-savings-absolute-row hidden>
							<span class="bc-field__label"><?php p($l->t('Amount (per month)')); ?></span>
							<input type="text" inputmode="decimal" name="targetAmount" class="bc-input" aria-label="<?php p($l->t('Monthly savings amount')); ?>">
						</label>
					</div>
				</div>
				<p class="bc-field__hint bc-field__hint--block" data-bc-savings-source hidden></p>
				<?php if ($canManage): ?>
					<div class="bc-form-actions">
						<button type="submit" class="button primary"><?php p($l->t('Save savings target')); ?></button>
					</div>
				<?php endif; ?>
			</form>
		</section>
	<?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/common/page-end.php'; ?>
