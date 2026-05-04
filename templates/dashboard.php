<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */

$workspace = $_['workspace'] ?? null;
$canManage = !empty($_['canManageWorkspace']);
$canContribute = !empty($_['canContribute']);
?>
<?php include __DIR__ . '/common/page-start.php'; ?>

<?php if ($workspace === null): ?>
	<section class="bc-card bc-empty" aria-labelledby="bc-empty-title">
		<h2 id="bc-empty-title"><?php p($l->t('Pick or create a workspace to get started')); ?></h2>
		<p><?php p($l->t('Every BudgetCheck screen is scoped to one workspace. Choose one in the sidebar, or ask an app administrator to add you to a workspace.')); ?></p>
	</section>
<?php else: ?>
	<section class="bc-card bc-empty bc-empty--quickstart" id="bc-quickstart" hidden aria-labelledby="bc-quickstart-title">
		<header class="bc-section__header">
			<div>
				<h2 id="bc-quickstart-title"><?php p($l->t('Quick start')); ?></h2>
				<p class="bc-section__sub"><?php p($l->t('Three short steps to make this workspace useful.')); ?></p>
			</div>
			<button type="button" class="bc-hint-dismiss" data-bc-dismiss-hint="dashboard_quickstart_v1" aria-describedby="bc-quickstart-title">
				<?php p($l->t('Hide tips')); ?>
			</button>
		</header>
		<ol class="bc-quickstart">
			<li class="bc-quickstart__item" data-step="categories">
				<strong><?php p($l->t('Add categories')); ?></strong>
				<p><?php p($l->t('Categories pin transactions to budgets. Start with rent, groceries, and salary.')); ?></p>
				<a class="button" href="#" data-bc-link="settings"><?php p($l->t('Open settings')); ?></a>
			</li>
			<li class="bc-quickstart__item" data-step="transactions">
				<strong><?php p($l->t('Log a transaction')); ?></strong>
				<p><?php p($l->t('A single income or expense is enough to see the dashboard come alive.')); ?></p>
				<a class="button" href="#" data-bc-link="transactions"><?php p($l->t('Open transactions')); ?></a>
			</li>
			<li class="bc-quickstart__item" data-step="<?php p($workspace['type'] === 'household' ? 'monthly' : 'period'); ?>">
				<strong>
					<?php p($workspace['type'] === 'household' ? $l->t('Plan the month') : $l->t('Review the period')); ?>
				</strong>
				<p>
					<?php p($workspace['type'] === 'household'
						? $l->t('Set monthly budgets and a savings target so warnings make sense.')
						: $l->t('Open the period overview to see total spend versus the project cap.')); ?>
				</p>
				<a class="button" href="#" data-bc-link="<?php p($workspace['type'] === 'household' ? 'monthly' : 'period'); ?>">
					<?php p($workspace['type'] === 'household' ? $l->t('Open monthly plan') : $l->t('Open period overview')); ?>
				</a>
			</li>
		</ol>
	</section>

	<section class="bc-card bc-section" aria-labelledby="bc-summary-title" data-bc-summary>
		<header class="bc-section__header">
			<div>
				<h2 id="bc-summary-title">
					<?php p($workspace['type'] === 'household' ? $l->t('This month at a glance') : $l->t('Project at a glance')); ?>
				</h2>
				<p class="bc-section__sub" data-bc-summary-period></p>
				<?php if ($workspace['type'] === 'household'): ?>
				<div class="bc-ledger-help" data-bc-dash-ledger-help hidden aria-live="polite"></div>
				<?php endif; ?>
			</div>
			<?php if ($workspace['type'] === 'household'): ?>
				<div class="bc-section__controls bc-section__controls--stack bc-period-picker-wrap" data-bc-household-period data-bc-variant="household">
					<fieldset class="bc-fieldset bc-period-picker" aria-describedby="bc-dash-month-hint">
						<legend class="bc-fieldset__legend"><?php p($l->t('Summary month')); ?></legend>
						<div class="bc-period-picker__row">
							<label class="bc-field bc-field--inline" for="bc-dash-plan-year">
								<span class="bc-field__label"><?php p($l->t('Year')); ?></span>
								<select id="bc-dash-plan-year" class="bc-input" data-bc-plan-year></select>
							</label>
							<label class="bc-field bc-field--inline" for="bc-dash-plan-month">
								<span class="bc-field__label"><?php p($l->t('Month')); ?></span>
								<select id="bc-dash-plan-month" class="bc-input" data-bc-plan-month></select>
							</label>
						</div>
					</fieldset>
					<p id="bc-dash-month-hint" class="bc-section__control-hint"><?php p($l->t('Pick a calendar year and month for this snapshot.')); ?></p>
				</div>
			<?php endif; ?>
		</header>
		<div class="bc-summary-grid" data-bc-summary-grid aria-busy="true">
			<p class="bc-loading"><?php p($l->t('Loading summary…')); ?></p>
		</div>
	</section>

	<section class="bc-card bc-section" aria-labelledby="bc-warnings-title" data-bc-warnings hidden>
		<header class="bc-section__header">
			<div>
				<h2 id="bc-warnings-title"><?php p($l->t('Things to look at')); ?></h2>
				<p class="bc-section__sub"><?php p($l->t('Each warning links to the screen that fixes it.')); ?></p>
			</div>
		</header>
		<ul class="bc-warnings" data-bc-warnings-list></ul>
	</section>

	<section class="bc-card bc-section" aria-labelledby="bc-recent-title" data-bc-recent>
		<header class="bc-section__header">
			<div>
				<h2 id="bc-recent-title"><?php p($l->t('Recent transactions')); ?></h2>
				<p class="bc-section__sub"><?php p($l->t('The newest entries in this workspace.')); ?></p>
			</div>
			<?php if ($canContribute): ?>
				<a class="button primary" href="#" data-bc-link="transactions"><?php p($l->t('Open transactions')); ?></a>
			<?php endif; ?>
		</header>
		<ul class="bc-tx-list" data-bc-recent-list aria-busy="true">
			<li class="bc-loading"><?php p($l->t('Loading…')); ?></li>
		</ul>
	</section>
<?php endif; ?>

<?php include __DIR__ . '/common/page-end.php'; ?>
