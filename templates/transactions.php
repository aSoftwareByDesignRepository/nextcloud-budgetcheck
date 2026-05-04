<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */

$workspace = $_['workspace'] ?? null;
$canContribute = !empty($_['canContribute']);
?>
<?php include __DIR__ . '/common/page-start.php'; ?>

<?php if ($workspace === null): ?>
	<section class="bc-card bc-empty">
		<h2><?php p($l->t('Select a workspace')); ?></h2>
		<p><?php p($l->t('Pick a workspace in the sidebar to view its ledger.')); ?></p>
	</section>
<?php else: ?>
	<section class="bc-card bc-section" aria-labelledby="bc-tx-filters-title">
		<header class="bc-section__header">
			<div>
				<h2 id="bc-tx-filters-title"><?php p($l->t('Filter transactions')); ?></h2>
				<p class="bc-section__sub"><?php p($l->t('Narrow by date, category, or text. Project workspaces stay clamped to their date window.')); ?></p>
			</div>
			<?php if ($canContribute): ?>
				<button type="button" class="button primary" data-bc-action="open-create-transaction">
					<?php p($l->t('New transaction')); ?>
				</button>
			<?php endif; ?>
		</header>
		<form class="bc-filter-grid" data-bc-tx-filters>
			<p id="bc-tx-date-hint" class="bc-field__hint bc-field__hint--block"><?php p($l->t('Date and month fields use your Nextcloud language. Tables and summaries match. The browser\'s calendar popup may still follow your device language in some setups.')); ?></p>
			<label class="bc-field">
				<span class="bc-field__label"><?php p($l->t('From')); ?></span>
				<input type="date" name="from" class="bc-input" lang="<?php p($bcHtmlLang); ?>" autocomplete="off" data-bc-filter="from" aria-describedby="bc-tx-date-hint">
			</label>
			<label class="bc-field">
				<span class="bc-field__label"><?php p($l->t('To')); ?></span>
				<input type="date" name="to" class="bc-input" lang="<?php p($bcHtmlLang); ?>" autocomplete="off" data-bc-filter="to" aria-describedby="bc-tx-date-hint">
			</label>
			<label class="bc-field">
				<span class="bc-field__label"><?php p($l->t('Category')); ?></span>
				<select name="categoryId" class="bc-input" data-bc-filter="categoryId" data-bc-category-select>
					<option value=""><?php p($l->t('Any')); ?></option>
				</select>
			</label>
			<label class="bc-field">
				<span class="bc-field__label"><?php p($l->t('Search title or notes')); ?></span>
				<input type="search" name="q" class="bc-input" autocomplete="off" data-bc-filter="q" aria-describedby="bc-tx-search-hint">
				<span id="bc-tx-search-hint" class="bc-field__hint"><?php p($l->t('Free text; combine with category or dates to narrow results.')); ?></span>
			</label>
			<div class="bc-filter-bool-row">
				<label class="bc-field">
					<span class="bc-boolean-control bc-boolean-control--filter-row">
						<input type="checkbox" name="isSpecial" value="1" data-bc-filter="isSpecial">
						<span class="bc-boolean-control__text"><?php p($l->t('Specials only')); ?></span>
					</span>
				</label>
				<label class="bc-field">
					<span class="bc-boolean-control bc-boolean-control--filter-row">
						<input type="checkbox" name="uncategorized" value="1" data-bc-filter="uncategorized">
						<span class="bc-boolean-control__text"><?php p($l->t('Uncategorized expenses only')); ?></span>
					</span>
				</label>
			</div>
			<div class="bc-filter-actions">
				<button type="submit" class="button primary"><?php p($l->t('Apply')); ?></button>
				<button type="reset" class="button"><?php p($l->t('Reset')); ?></button>
			</div>
		</form>
	</section>

	<section class="bc-card bc-section" aria-labelledby="bc-tx-list-title">
		<header class="bc-section__header">
			<div>
				<h2 id="bc-tx-list-title"><?php p($l->t('Ledger')); ?></h2>
				<p class="bc-section__sub" data-bc-tx-window></p>
			</div>
			<div class="bc-section__controls">
				<span class="bc-pill" data-bc-tx-count></span>
			</div>
		</header>
		<div class="bc-table-scroll" role="region" aria-label="<?php p($l->t('Transactions')); ?>" tabindex="0">
			<table class="bc-table bc-tx-table" data-bc-tx-table>
				<thead>
					<tr>
						<th scope="col"><?php p($l->t('Date')); ?></th>
						<th scope="col"><?php p($l->t('Title')); ?></th>
						<th scope="col"><?php p($l->t('Category')); ?></th>
						<th scope="col" class="bc-table__col--num"><?php p($l->t('Amount')); ?></th>
						<th scope="col"><?php p($l->t('Direction')); ?></th>
						<th scope="col"><?php p($l->t('Tags')); ?></th>
						<?php if ($canContribute): ?>
							<th scope="col" class="bc-sr-only"><?php p($l->t('Actions')); ?></th>
						<?php endif; ?>
					</tr>
				</thead>
				<tbody data-bc-tx-rows>
					<tr><td colspan="7" class="bc-loading"><?php p($l->t('Loading…')); ?></td></tr>
				</tbody>
			</table>
		</div>
		<nav class="bc-pagination" aria-label="<?php p($l->t('Pagination')); ?>" data-bc-tx-pagination></nav>
	</section>
<?php endif; ?>

<?php include __DIR__ . '/common/page-end.php'; ?>
