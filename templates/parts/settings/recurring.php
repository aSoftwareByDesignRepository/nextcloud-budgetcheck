<?php
/**
 * Workspace settings sub-page: Recurring rules.
 *
 * Managers: full CRUD + catch-up.
 * Contributors: catch-up only (Book / Add plan / Add everything due) — no create/edit/delete.
 * Viewers: redirected by PageController; soft denial is defense in depth.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

$canManage = !empty($_['canManageWorkspace']);
$canContribute = !empty($_['canContribute']) || $canManage;
?>
<?php if (!$canContribute): ?>
	<section class="bc-card bc-empty" role="status">
		<h2><?php p($l->t('Contributors and managers only')); ?></h2>
		<p><?php p($l->t('Viewers can see budgets and transactions, but cannot add recurring entries. Ask a manager to promote you if you need this.')); ?></p>
	</section>
<?php else: ?>
<section class="bc-card bc-section" aria-labelledby="bc-recurring-title">
	<header class="bc-section__header bc-recurring-header">
		<div class="bc-recurring-header__text">
			<h2 id="bc-recurring-title"><?php p($l->t('Recurring')); ?></h2>
			<p class="bc-section__sub" id="bc-recurring-lead">
				<?php if ($canManage): ?>
					<?php p($l->t('Things that happen again and again — rent, salary, subscriptions. Set them once. BudgetCheck adds them when the day comes.')); ?>
				<?php else: ?>
					<?php p($l->t('Add what is due today. Managers create and edit the rules; you press the button when money should land on Transactions.')); ?>
				<?php endif; ?>
			</p>
		</div>
		<div class="bc-recurring-header__actions">
			<button type="button" class="button" data-bc-action="generate-due-recurring" aria-describedby="bc-recurring-lead">
				<?php p($l->t('Add everything due')); ?>
			</button>
			<?php if ($canManage): ?>
				<button type="button" class="button primary" data-bc-action="open-create-recurring">
					<?php p($l->t('New rule')); ?>
				</button>
			<?php endif; ?>
		</div>
	</header>

	<?php if ($canManage): ?>
	<div class="bc-callout bc-callout--info bc-recurring-howto" role="region" aria-labelledby="bc-recurring-howto-title">
		<p id="bc-recurring-howto-title" class="bc-recurring-howto__title"><?php p($l->t('Two simple choices')); ?></p>
		<dl class="bc-glossary bc-recurring-howto__glossary">
			<div class="bc-glossary__item">
				<dt><?php p($l->t('Book automatically')); ?></dt>
				<dd><?php p($l->t('Best for most people. On the due day, BudgetCheck writes a real transaction. You do not type it every month.')); ?></dd>
			</div>
			<div class="bc-glossary__item">
				<dt><?php p($l->t('Plan for bank import')); ?></dt>
				<dd><?php p($l->t('Shows a reminder on Transactions. When a matching bank import arrives, the reminder disappears. Use this only if you import the same payment from the bank.')); ?></dd>
			</div>
		</dl>
	</div>
	<?php else: ?>
	<div class="bc-callout bc-callout--info bc-recurring-howto" role="region" aria-labelledby="bc-recurring-howto-title">
		<p id="bc-recurring-howto-title" class="bc-recurring-howto__title"><?php p($l->t('How this works for you')); ?></p>
		<p class="bc-callout__hint"><?php p($l->t('Rows marked Due need a click. “Book” writes a real transaction. “Add plan” writes a reminder until a matching bank import arrives. You cannot change the rules — ask a manager for that.')); ?></p>
	</div>
	<?php endif; ?>

	<div class="bc-table-scroll" role="region" aria-label="<?php p($l->t('Recurring rules')); ?>" tabindex="0">
		<table class="bc-table bc-recurring-table">
			<thead>
				<tr>
					<th scope="col"><?php p($l->t('Title')); ?></th>
					<th scope="col"><?php p($l->t('Type')); ?></th>
					<th scope="col" class="bc-table__col--num"><?php p($l->t('Amount')); ?></th>
					<th scope="col"><?php p($l->t('When')); ?></th>
					<th scope="col"><?php p($l->t('Next due')); ?></th>
					<th scope="col"><?php p($l->t('How it posts')); ?></th>
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
