<?php
/**
 * Workspace settings sub-page: Booking statuses (project only).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

$canManage = !empty($_['canManageWorkspace']);
?>
<section class="bc-card bc-section" aria-labelledby="bc-booking-statuses-title">
	<header class="bc-section__header">
		<div>
			<h2 id="bc-booking-statuses-title" class="bc-sr-only"><?php p($l->t('Booking statuses')); ?></h2>
			<p class="bc-section__sub"><?php p($l->t('Project-only workflow states for bookings (for example Open, In progress, Paid).')); ?></p>
		</div>
		<?php if ($canManage): ?>
			<button type="button" class="button primary" data-bc-action="open-create-booking-status">
				<?php p($l->t('New status')); ?>
			</button>
		<?php endif; ?>
	</header>
	<div class="bc-table-scroll" role="region" aria-label="<?php p($l->t('Booking statuses')); ?>" tabindex="0">
		<table class="bc-table">
			<thead>
				<tr>
					<th scope="col"><?php p($l->t('Name')); ?></th>
					<th scope="col"><?php p($l->t('Order')); ?></th>
					<th scope="col"><?php p($l->t('Status')); ?></th>
					<?php if ($canManage): ?>
						<th scope="col" class="bc-sr-only"><?php p($l->t('Actions')); ?></th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody data-bc-booking-status-rows>
				<tr>
					<td colspan="<?php p($canManage ? '4' : '3'); ?>" class="bc-loading"><?php p($l->t('Loading…')); ?></td>
				</tr>
			</tbody>
		</table>
	</div>
</section>
