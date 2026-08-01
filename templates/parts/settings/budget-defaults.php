<?php
/**
 * Workspace settings sub-page: Default category budgets (household managers).
 *
 * Controller already redirects non-managers / non-household; soft denial is
 * defense in depth if that gate regresses.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

$canManage = !empty($_['canManageWorkspace']);
?>
<?php if (!$canManage): ?>
	<section class="bc-card bc-empty" role="status">
		<h2><?php p($l->t('Managers only')); ?></h2>
		<p><?php p($l->t('Only workspace managers can change default category budgets.')); ?></p>
	</section>
<?php else: ?>
<section class="bc-card bc-section" aria-labelledby="bc-budget-defaults-title">
	<header class="bc-section__header">
		<div>
			<h2 id="bc-budget-defaults-title" class="bc-sr-only"><?php p($l->t('Default category budgets')); ?></h2>
			<p class="bc-section__sub"><?php p($l->t('Used as baseline for months. You can still override single months in planning.')); ?></p>
		</div>
		<button type="button" class="button primary" data-bc-action="save-budget-defaults" disabled>
			<?php p($l->t('Save changes')); ?>
		</button>
	</header>
	<div class="bc-table-scroll" role="region" aria-label="<?php p($l->t('Default category budgets')); ?>" tabindex="0">
		<table class="bc-table">
			<thead>
				<tr>
					<th scope="col"><?php p($l->t('Category')); ?></th>
					<th scope="col"><?php p($l->t('Direction')); ?></th>
					<th scope="col" class="bc-table__col--num"><?php p($l->t('Default amount')); ?></th>
				</tr>
			</thead>
			<tbody data-bc-budget-default-rows>
				<tr>
					<td colspan="3" class="bc-loading"><?php p($l->t('Loading…')); ?></td>
				</tr>
			</tbody>
		</table>
	</div>
</section>
<?php endif; ?>
