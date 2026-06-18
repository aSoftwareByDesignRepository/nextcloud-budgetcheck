<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */

$workspace = $_['workspace'] ?? null;
?>
<?php include __DIR__ . '/common/page-start.php'; ?>

<?php if ($workspace === null): ?>
	<section class="bc-card bc-empty">
		<h2><?php p($l->t('Select a household workspace')); ?></h2>
		<p><?php p($l->t('Pick a household workspace from the sidebar.')); ?></p>
	</section>
<?php else: ?>
	<section class="bc-card bc-section" aria-labelledby="bc-year-pick-title">
		<header class="bc-section__header">
			<div>
				<h2 id="bc-year-pick-title"><?php p($l->t('Pick a year')); ?></h2>
				<p class="bc-section__sub"><?php p($l->t('Compare income, expense, and savings month by month.')); ?></p>
			</div>
			<div class="bc-section__controls bc-section__controls--stack">
				<label class="bc-field bc-field--inline" for="bc-yearly-year">
					<span class="bc-field__label"><?php p($l->t('Year')); ?></span>
					<select id="bc-yearly-year" class="bc-input" data-bc-year-picker aria-describedby="bc-yearly-year-hint"></select>
				</label>
				<p id="bc-yearly-year-hint" class="bc-section__control-hint"><?php p($l->t('Choose the calendar year for the grid below. Each month card opens that month in the monthly plan.')); ?></p>
			</div>
		</header>
	</section>

	<section class="bc-card bc-section" aria-labelledby="bc-year-totals-title" data-bc-summary>
		<header class="bc-section__header">
			<div>
				<h2 id="bc-year-totals-title"><?php p($l->t('Annual totals')); ?></h2>
				<p class="bc-section__sub" data-bc-summary-period></p>
			</div>
			<div class="bc-section__controls bc-section__controls--stack bc-section__controls--yearly-help">
				<div class="bc-specials-toggle-wrap" data-bc-specials-toggle hidden></div>
				<button
					type="button"
					class="button"
					data-bc-yearly-summary-help
					aria-describedby="bc-year-totals-help-hint">
					<?php p($l->t('How to read this')); ?>
				</button>
				<p id="bc-year-totals-help-hint" class="bc-section__control-hint">
					<?php p($l->t('Open a plain-language explanation of each value and what it means for this year.')); ?>
				</p>
			</div>
		</header>
		<div class="bc-summary-grid" data-bc-summary-grid aria-busy="true">
			<p class="bc-loading"><?php p($l->t('Loading…')); ?></p>
		</div>
	</section>

	<section class="bc-card bc-section" aria-labelledby="bc-year-months-title">
		<header class="bc-section__header">
			<div>
				<h2 id="bc-year-months-title"><?php p($l->t('Months')); ?></h2>
				<p class="bc-section__sub"><?php p($l->t('Each card links to the monthly plan.')); ?></p>
			</div>
		</header>
		<ul class="bc-month-grid" data-bc-month-cards aria-busy="true"></ul>
	</section>

	<section class="bc-card bc-section" aria-labelledby="bc-year-export-title">
		<header class="bc-section__header">
			<div>
				<h2 id="bc-year-export-title"><?php p($l->t('Export')); ?></h2>
				<p class="bc-section__sub"><?php p($l->t('Download the selected year as an Excel workbook.')); ?></p>
			</div>
			<div class="bc-section__controls bc-section__controls--stack">
				<button
					type="button"
					class="button primary"
					data-bc-yearly-export
					aria-describedby="bc-year-export-hint">
					<?php p($l->t('Export yearly workbook (Excel)')); ?>
				</button>
				<p id="bc-year-export-hint" class="bc-section__control-hint">
					<?php p($l->t('Downloads one overview sheet and one monthly booking sheet for each month in this year.')); ?>
				</p>
			</div>
		</header>
	</section>
<?php endif; ?>

<?php include __DIR__ . '/common/page-end.php'; ?>
