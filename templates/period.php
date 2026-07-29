<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */

$workspace = $_['workspace'] ?? null;
?>
<?php include __DIR__ . '/common/page-start.php'; ?>

<?php if ($workspace === null): ?>
	<section class="bc-card bc-empty" aria-labelledby="bc-period-empty-title">
		<h2 id="bc-period-empty-title"><?php p($l->t('Select a project workspace')); ?></h2>
		<p><?php p($l->t('Pick a project from the sidebar.')); ?></p>
	</section>
<?php else: ?>
	<section class="bc-card bc-section" aria-labelledby="bc-period-title" data-bc-summary>
		<header class="bc-section__header">
			<div>
				<h2 id="bc-period-title"><?php p($l->t('Project period')); ?></h2>
				<p class="bc-section__sub" data-bc-summary-period></p>
				<p class="bc-section__control-hint bc-period-project-hint" id="bc-period-window-hint"><?php p($l->t('Totals always cover the full project window set in Settings (start and end dates). There is no separate month filter here—projects are bounded by those dates, not by calendar months.')); ?></p>
				<div class="bc-ledger-help" data-bc-period-ledger-help hidden aria-live="polite"></div>
			</div>
		</header>
		<div class="bc-summary-grid" data-bc-summary-grid aria-busy="true">
			<p class="bc-loading"><?php p($l->t('Loading…')); ?></p>
		</div>
	</section>

	<section class="bc-card bc-section" aria-labelledby="bc-period-cap-title" data-bc-cap hidden>
		<header class="bc-section__header">
			<div>
				<h2 id="bc-period-cap-title"><?php p($l->t('Cap usage')); ?></h2>
				<p class="bc-section__sub"><?php p($l->t('All-time spend versus the configured project cap.')); ?></p>
			</div>
		</header>
		<div class="bc-cap" data-bc-cap-block></div>
	</section>

	<section class="bc-card bc-section" aria-labelledby="bc-period-warnings-title" data-bc-warnings hidden>
		<header class="bc-section__header">
			<div>
				<h2 id="bc-period-warnings-title"><?php p($l->t('Things to look at')); ?></h2>
			</div>
		</header>
		<ul class="bc-warnings" data-bc-warnings-list></ul>
	</section>

	<section class="bc-card bc-section" aria-labelledby="bc-period-specials-title" data-bc-specials hidden>
		<header class="bc-section__header">
			<div>
				<h2 id="bc-period-specials-title"><?php p($l->t('Special items')); ?></h2>
				<p class="bc-section__sub"><?php p($l->t('Large or unusual entries flagged in this period.')); ?></p>
			</div>
		</header>
		<ul class="bc-tx-list" data-bc-specials-list></ul>
	</section>

	<section class="bc-card bc-section" aria-labelledby="bc-period-export-title">
		<header class="bc-section__header">
			<div>
				<h2 id="bc-period-export-title"><?php p($l->t('Export')); ?></h2>
				<p class="bc-section__sub"><?php p($l->t('Download the project period as an Excel workbook.')); ?></p>
			</div>
			<div class="bc-section__controls bc-section__controls--stack">
				<button
					type="button"
					class="button primary"
					data-bc-period-export
					aria-describedby="bc-period-export-hint">
					<?php p($l->t('Export project workbook (Excel)')); ?>
				</button>
				<p id="bc-period-export-hint" class="bc-section__control-hint">
					<?php p($l->t('Downloads project overview, monthly totals, and the full booking list for the configured project window.')); ?>
				</p>
			</div>
		</header>
	</section>
<?php endif; ?>

<?php include __DIR__ . '/common/page-end.php'; ?>
