<?php
/**
 * Workspace settings sub-page: Alert log (per-user alert center, NH-04).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
?>
<section class="bc-card bc-section" aria-labelledby="bc-alertlog-title">
	<header class="bc-section__header">
		<div>
			<h2 id="bc-alertlog-title" class="bc-sr-only"><?php p($l->t('Alert log')); ?></h2>
			<p class="bc-section__sub"><?php p($l->t('Alerts BudgetCheck sent to you for this workspace — newest first.')); ?></p>
		</div>
	</header>
	<div class="bc-table-scroll" role="region" aria-label="<?php p($l->t('Alert log')); ?>" tabindex="0">
		<table class="bc-table bc-alertlog-table">
			<thead>
				<tr>
					<th scope="col"><?php p($l->t('When')); ?></th>
					<th scope="col"><?php p($l->t('Type')); ?></th>
					<th scope="col"><?php p($l->t('Alert')); ?></th>
				</tr>
			</thead>
			<tbody data-bc-alertlog-rows>
				<tr>
					<td colspan="3" class="bc-loading"><?php p($l->t('Loading…')); ?></td>
				</tr>
			</tbody>
		</table>
	</div>
</section>
