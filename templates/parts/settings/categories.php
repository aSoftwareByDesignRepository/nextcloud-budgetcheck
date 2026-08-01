<?php
/**
 * Workspace settings sub-page: Categories.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

$canManage = !empty($_['canManageWorkspace']);
?>
<section class="bc-card bc-section" aria-labelledby="bc-categories-title">
	<header class="bc-section__header">
		<div>
			<h2 id="bc-categories-title" class="bc-sr-only"><?php p($l->t('Categories')); ?></h2>
			<p class="bc-section__sub"><?php p($l->t('Name categories for your budget. Group is for a few custom buckets in reports and filters—not bank hierarchy. Notes on transactions work well for vendor names.')); ?></p>
		</div>
		<?php if ($canManage): ?>
			<button type="button" class="button primary" data-bc-action="open-create-category">
				<?php p($l->t('New category')); ?>
			</button>
		<?php endif; ?>
	</header>
	<div class="bc-table-scroll" role="region" aria-label="<?php p($l->t('Categories')); ?>" tabindex="0">
		<table class="bc-table bc-categories-table">
			<thead>
				<tr>
					<th scope="col"><?php p($l->t('Name')); ?></th>
					<th scope="col"><?php p($l->t('Direction')); ?></th>
					<th scope="col"><?php p($l->t('Group')); ?></th>
					<th scope="col"><?php p($l->t('Special')); ?></th>
					<th scope="col"><?php p($l->t('Savings transfer')); ?></th>
					<th scope="col"><?php p($l->t('Tax handling')); ?></th>
					<th scope="col"><?php p($l->t('Status')); ?></th>
					<?php if ($canManage): ?>
						<th scope="col" class="bc-sr-only"><?php p($l->t('Actions')); ?></th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody data-bc-category-rows>
				<tr>
					<td colspan="8" class="bc-loading"><?php p($l->t('Loading…')); ?></td>
				</tr>
			</tbody>
		</table>
	</div>
</section>
