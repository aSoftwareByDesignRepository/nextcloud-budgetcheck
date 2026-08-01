<?php
/**
 * Workspace settings sub-page: Recurring rules (managers only).
 *
 * Controller already redirects non-managers; soft denial is defense in depth
 * if that gate regresses.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

$canManage = !empty($_['canManageWorkspace']);
?>
<?php if (!$canManage): ?>
	<section class="bc-card bc-empty" role="status">
		<h2><?php p($l->t('Managers only')); ?></h2>
		<p><?php p($l->t('Only workspace managers can manage recurring rules.')); ?></p>
	</section>
<?php else: ?>
<section class="bc-card bc-section" aria-labelledby="bc-recurring-title">
	<header class="bc-section__header">
		<div>
			<h2 id="bc-recurring-title" class="bc-sr-only"><?php p($l->t('Recurring rules')); ?></h2>
			<p class="bc-section__sub"><?php p($l->t('Repeating income or expenses — on a fixed interval or on specific dates you list. Generate creates planned ledger entries; a matching import removes the plan automatically.')); ?></p>
		</div>
		<button type="button" class="button primary" data-bc-action="open-create-recurring">
			<?php p($l->t('New rule')); ?>
		</button>
	</header>
	<div class="bc-table-scroll" role="region" aria-label="<?php p($l->t('Recurring rules')); ?>" tabindex="0">
		<table class="bc-table bc-recurring-table">
			<thead>
				<tr>
					<th scope="col"><?php p($l->t('Title')); ?></th>
					<th scope="col"><?php p($l->t('Direction')); ?></th>
					<th scope="col" class="bc-table__col--num"><?php p($l->t('Amount')); ?></th>
					<th scope="col"><?php p($l->t('Frequency')); ?></th>
					<th scope="col"><?php p($l->t('Next due')); ?></th>
					<th scope="col"><?php p($l->t('End date')); ?></th>
					<th scope="col"><?php p($l->t('Status')); ?></th>
					<th scope="col" class="bc-sr-only"><?php p($l->t('Actions')); ?></th>
				</tr>
			</thead>
			<tbody data-bc-recurring-rows>
				<tr>
					<td colspan="8" class="bc-loading"><?php p($l->t('Loading…')); ?></td>
				</tr>
			</tbody>
		</table>
	</div>
</section>
<?php endif; ?>
