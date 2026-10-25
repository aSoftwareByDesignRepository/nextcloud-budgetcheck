<?php
/**
 * Workspace settings sub-page: Categorization rules (manager only).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

$canManage = !empty($_['canManageWorkspace']);
?>
<section class="bc-card bc-section" aria-labelledby="bc-catrules-title">
	<header class="bc-section__header">
		<div>
			<h2 id="bc-catrules-title" class="bc-sr-only"><?php p($l->t('Categorization rules')); ?></h2>
			<p class="bc-section__sub"><?php p($l->t('Rules auto-fill the category on imported bookings and offer suggestions in the editor. The first matching rule wins — set the order with priority.')); ?></p>
		</div>
		<?php if ($canManage): ?>
			<button type="button" class="button primary" data-bc-action="open-create-rule">
				<?php p($l->t('New rule')); ?>
			</button>
		<?php endif; ?>
	</header>
	<div class="bc-table-scroll" role="region" aria-label="<?php p($l->t('Categorization rules')); ?>" tabindex="0">
		<table class="bc-table bc-catrules-table">
			<thead>
				<tr>
					<th scope="col"><?php p($l->t('Priority')); ?></th>
					<th scope="col"><?php p($l->t('Field')); ?></th>
					<th scope="col"><?php p($l->t('Match')); ?></th>
					<th scope="col"><?php p($l->t('Pattern')); ?></th>
					<th scope="col"><?php p($l->t('Direction')); ?></th>
					<th scope="col"><?php p($l->t('Category')); ?></th>
					<th scope="col"><?php p($l->t('Hits')); ?></th>
					<th scope="col"><?php p($l->t('Status')); ?></th>
					<?php if ($canManage): ?>
						<th scope="col" class="bc-sr-only"><?php p($l->t('Actions')); ?></th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody data-bc-catrule-rows>
				<tr>
					<td colspan="9" class="bc-loading"><?php p($l->t('Loading…')); ?></td>
				</tr>
			</tbody>
		</table>
	</div>
	<?php if ($canManage): ?>
		<footer class="bc-section__footer">
			<button type="button" class="button" data-bc-action="backfill-rules-preview">
				<?php p($l->t('Preview apply to existing bookings')); ?>
			</button>
			<a class="button" href="#" data-bc-rules-export download>
				<?php p($l->t('Export rules (CSV)')); ?>
			</a>
			<p class="bc-section__control-hint" data-bc-backfill-result></p>
		</footer>
	<?php endif; ?>
</section>
