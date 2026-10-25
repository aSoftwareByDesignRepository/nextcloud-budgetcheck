<?php
/**
 * Workspace settings sub-page: Payees.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

$canManage = !empty($_['canManageWorkspace']);
?>
<section class="bc-card bc-section" aria-labelledby="bc-payees-title">
	<header class="bc-section__header">
		<div>
			<h2 id="bc-payees-title" class="bc-sr-only"><?php p($l->t('Payees')); ?></h2>
			<p class="bc-section__sub"><?php p($l->t('Counterparties on your bookings. Rename or merge variants so reports and rules stay clean.')); ?></p>
		</div>
		<?php if ($canManage): ?>
			<button type="button" class="button primary" data-bc-action="open-create-payee">
				<?php p($l->t('New payee')); ?>
			</button>
		<?php endif; ?>
	</header>
	<div class="bc-table-scroll" role="region" aria-label="<?php p($l->t('Payees')); ?>" tabindex="0">
		<table class="bc-table bc-payees-table">
			<thead>
				<tr>
					<th scope="col"><?php p($l->t('Name')); ?></th>
					<th scope="col"><?php p($l->t('Bookings')); ?></th>
					<th scope="col"><?php p($l->t('Status')); ?></th>
					<?php if ($canManage): ?>
						<th scope="col" class="bc-sr-only"><?php p($l->t('Actions')); ?></th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody data-bc-payee-rows>
				<tr>
					<td colspan="4" class="bc-loading"><?php p($l->t('Loading…')); ?></td>
				</tr>
			</tbody>
		</table>
	</div>
</section>
