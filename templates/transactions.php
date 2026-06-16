<?php
/**
 * Transactions ("Ledger") page.
 *
 * Renders only the static skeleton; all dynamic data (rows, KPIs, breakdowns,
 * filter state) is loaded by js/transactions.js after DOMContentLoaded.
 *
 * Section order is intentional and reflects the user's mental model:
 *   1. KPI strip — totals for the active filter scope (income / expenses / net / count).
 *   2. Filter bar — search, quick range presets, category, "more filters" disclosure.
 *   3. Active filter chips — at-a-glance list of what's narrowing the view, removable individually.
 *   4. Ledger card — the table itself (sticky header), with paginated controls inside.
 *   5. Breakdowns — collapsed disclosure with tabs (group / category / month).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\BudgetCheck\Service\IconCatalog;

$workspace = $_['workspace'] ?? null;
$canContribute = !empty($_['canContribute']);
$isProject = is_array($workspace) && (string)($workspace['type'] ?? '') === 'project';
?>
<?php include __DIR__ . '/common/page-start.php'; ?>

<?php if ($workspace === null): ?>
	<section class="bc-card bc-empty" aria-labelledby="bc-tx-pick-workspace">
		<h2 id="bc-tx-pick-workspace"><?php p($l->t('Select a workspace')); ?></h2>
		<p><?php p($l->t('Pick a workspace in the sidebar to view its ledger.')); ?></p>
	</section>
<?php else: ?>
	<div class="bc-tx-page" data-bc-tx-page>

		<!-- ────────────── KPI strip ────────────── -->
		<section class="bc-card bc-section bc-tx-kpis" aria-labelledby="bc-tx-kpis-title">
			<header class="bc-section__header">
				<div>
					<h2 id="bc-tx-kpis-title"><?php p($l->t('Ledger overview')); ?></h2>
					<p class="bc-section__sub" data-bc-tx-window>&nbsp;</p>
				</div>
				<?php if ($canContribute): ?>
					<div class="bc-section__controls">
						<a class="button bc-tx-import-btn" href="#" data-bc-link="import">
							<?php print_unescaped(IconCatalog::render('upload', 'bc-icon--inline')); ?>
							<span><?php p($l->t('Import CSV')); ?></span>
						</a>
						<button type="button" class="button primary bc-tx-new-btn" data-bc-action="open-create-transaction">
							<?php print_unescaped(IconCatalog::render('plus', 'bc-icon--inline')); ?>
							<span><?php p($l->t('New transaction')); ?></span>
						</button>
					</div>
				<?php endif; ?>
			</header>
			<div class="bc-summary-grid bc-tx-kpi-grid" data-bc-tx-kpi-tiles aria-busy="true" aria-live="polite">
				<?php for ($i = 0; $i < 4; $i++): ?>
					<div class="bc-summary-tile bc-tx-kpi-tile bc-tx-kpi-tile--skeleton" aria-hidden="true">
						<div class="bc-summary-tile__label">&nbsp;</div>
						<div class="bc-summary-tile__value">&nbsp;</div>
					</div>
				<?php endfor; ?>
			</div>
		</section>

		<!-- ────────────── Filter bar ────────────── -->
		<section class="bc-card bc-section bc-tx-filters" aria-labelledby="bc-tx-filters-title">
			<header class="bc-section__header">
				<div>
					<h2 id="bc-tx-filters-title"><?php p($l->t('Find transactions')); ?></h2>
					<p class="bc-section__sub"><?php p($l->t('Narrow the ledger by date, category, or text. Filters update the totals and breakdowns above and below.')); ?></p>
				</div>
			</header>

			<form
				class="bc-tx-filterbar"
				role="search"
				aria-label="<?php p($l->t('Transaction filters')); ?>"
				data-bc-tx-filters
				novalidate
			>
				<!-- Primary row: search + quick range + category + more -->
				<div class="bc-tx-filterbar__primary">
					<div class="bc-tx-filterbar__search">
						<label class="bc-sr-only" for="bc-tx-q"><?php p($l->t('Search title or notes')); ?></label>
						<span class="bc-tx-filterbar__search-icon" aria-hidden="true">
							<?php print_unescaped(IconCatalog::render('search')); ?>
						</span>
						<input
							id="bc-tx-q"
							type="search"
							name="q"
							class="bc-input bc-tx-filterbar__search-input"
							autocomplete="off"
							spellcheck="false"
							placeholder="<?php p($l->t('Search title or notes…')); ?>"
							data-bc-filter="q">
						<button
							type="button"
							class="bc-tx-filterbar__search-clear"
							hidden
							data-bc-search-clear
							aria-label="<?php p($l->t('Clear search')); ?>">
							<?php print_unescaped(IconCatalog::render('x')); ?>
						</button>
					</div>

					<label class="bc-field bc-field--inline bc-tx-filterbar__range">
						<span class="bc-field__label"><?php p($l->t('Range')); ?></span>
						<select class="bc-input" data-bc-filter="rangePreset">
							<option value="all"><?php p($l->t('All time')); ?></option>
							<option value="thisMonth"><?php p($l->t('This month')); ?></option>
							<option value="lastMonth"><?php p($l->t('Last month')); ?></option>
							<option value="last30"><?php p($l->t('Last 30 days')); ?></option>
							<option value="ytd"><?php p($l->t('Year to date')); ?></option>
							<option value="last12"><?php p($l->t('Last 12 months')); ?></option>
							<option value="custom"><?php p($l->t('Custom…')); ?></option>
						</select>
					</label>

					<label class="bc-field bc-field--inline bc-tx-filterbar__category">
						<span class="bc-field__label"><?php p($l->t('Category')); ?></span>
						<select name="categoryId" class="bc-input" data-bc-filter="categoryId" data-bc-category-select>
							<option value=""><?php p($l->t('Any')); ?></option>
						</select>
					</label>

					<button
						type="button"
						class="button bc-tx-filterbar__more"
						data-bc-tx-more-toggle
						aria-expanded="false"
						aria-controls="bc-tx-more-panel">
						<?php print_unescaped(IconCatalog::render('settings', 'bc-icon--inline')); ?>
						<span><?php p($l->t('More filters')); ?></span>
						<span class="bc-tx-filterbar__more-count" data-bc-more-count hidden></span>
					</button>
				</div>

				<!-- Advanced panel: dates, group, status, booleans -->
				<div
					id="bc-tx-more-panel"
					class="bc-tx-filterbar__advanced"
					data-bc-tx-more-panel
					hidden>
					<div class="bc-tx-filterbar__advanced-grid">
						<label class="bc-field">
							<span class="bc-field__label"><?php p($l->t('From')); ?></span>
							<input type="date" name="from" class="bc-input" lang="<?php p($bcHtmlLang); ?>" autocomplete="off" data-bc-filter="from">
						</label>
						<label class="bc-field">
							<span class="bc-field__label"><?php p($l->t('To')); ?></span>
							<input type="date" name="to" class="bc-input" lang="<?php p($bcHtmlLang); ?>" autocomplete="off" data-bc-filter="to">
						</label>
						<label class="bc-field">
							<span class="bc-field__label"><?php p($l->t('Group')); ?></span>
							<select name="groupKey" class="bc-input" data-bc-filter="groupKey" data-bc-group-select>
								<option value=""><?php p($l->t('Any')); ?></option>
								<option value="__none__"><?php p($l->t('No group')); ?></option>
							</select>
						</label>
						<?php if ($isProject): ?>
							<label class="bc-field">
								<span class="bc-field__label"><?php p($l->t('Booking status')); ?></span>
								<select name="statusId" class="bc-input" data-bc-filter="statusId" data-bc-status-select>
									<option value=""><?php p($l->t('Any')); ?></option>
								</select>
							</label>
						<?php endif; ?>
						<label class="bc-field bc-field--full-width bc-field--boolean">
							<span class="bc-field__label"><?php p($l->t('Quick toggles')); ?></span>
							<span class="bc-tx-filterbar__toggles">
								<span class="bc-boolean-control bc-boolean-control--filter-row">
									<input type="checkbox" name="isSpecial" value="1" data-bc-filter="isSpecial">
									<span class="bc-boolean-control__text"><?php p($l->t('Specials only')); ?></span>
								</span>
								<span class="bc-boolean-control bc-boolean-control--filter-row">
									<input type="checkbox" name="uncategorized" value="1" data-bc-filter="uncategorized">
									<span class="bc-boolean-control__text"><?php p($l->t('Uncategorized expenses only')); ?></span>
								</span>
							</span>
						</label>
					</div>
					<div class="bc-tx-filterbar__advanced-actions">
						<button type="reset" class="button"><?php p($l->t('Reset filters')); ?></button>
						<button type="submit" class="button primary"><?php p($l->t('Apply filters')); ?></button>
					</div>
				</div>

				<!-- Active filter chips -->
				<div
					class="bc-tx-filterbar__chips"
					data-bc-tx-chips
					aria-label="<?php p($l->t('Active filters')); ?>"
					hidden>
					<span class="bc-tx-filterbar__chips-label"><?php p($l->t('Active filters:')); ?></span>
					<ul class="bc-chip-list" data-bc-tx-chip-list></ul>
					<button type="button" class="button bc-tx-filterbar__clear-all" data-bc-tx-clear-all>
						<?php p($l->t('Clear filters')); ?>
					</button>
				</div>
			</form>
		</section>

		<!-- ────────────── Ledger ────────────── -->
		<section class="bc-card bc-section bc-tx-ledger" aria-labelledby="bc-tx-list-title">
			<header class="bc-section__header">
				<div>
					<h2 id="bc-tx-list-title"><?php p($l->t('Ledger')); ?></h2>
					<p class="bc-section__sub" data-bc-tx-summary aria-live="polite"><?php p($l->t('Loading…')); ?></p>
				</div>
				<div class="bc-section__controls">
					<span class="bc-pill" data-bc-tx-count></span>
				</div>
			</header>

			<div class="bc-tx-ledger__viewport" data-bc-tx-viewport>
				<div class="bc-table-scroll bc-tx-ledger__scroll" role="region" aria-label="<?php p($l->t('Transactions')); ?>" tabindex="0">
					<table class="bc-table bc-tx-table" data-bc-tx-table>
						<thead>
							<tr>
								<th scope="col" class="bc-tx-table__th--date"><?php p($l->t('Date')); ?></th>
								<th scope="col" class="bc-tx-table__th--title"><?php p($l->t('Title')); ?></th>
								<th scope="col" class="bc-tx-table__th--category"><?php p($l->t('Category')); ?></th>
								<th scope="col" class="bc-tx-table__th--amount bc-table__col--num"><?php p($l->t('Amount')); ?></th>
								<?php if ($isProject): ?>
									<th scope="col" class="bc-tx-table__th--status"><?php p($l->t('Status')); ?></th>
								<?php endif; ?>
								<th scope="col" class="bc-tx-table__th--tags"><?php p($l->t('Tags')); ?></th>
								<?php if ($canContribute): ?>
									<th scope="col" class="bc-tx-table__th--actions">
										<span class="bc-sr-only"><?php p($l->t('Actions')); ?></span>
									</th>
								<?php endif; ?>
							</tr>
						</thead>
						<tbody data-bc-tx-rows>
							<tr><td colspan="<?php p(($canContribute ? 6 : 5) + ($isProject ? 1 : 0)); ?>" class="bc-loading"><?php p($l->t('Loading…')); ?></td></tr>
						</tbody>
					</table>
				</div>
				<!-- Slot for inline empty / error states (replaces the table when active) -->
				<div class="bc-tx-ledger__state" data-bc-tx-state hidden></div>
			</div>

			<nav class="bc-pagination bc-tx-pagination" aria-label="<?php p($l->t('Pagination')); ?>" data-bc-tx-pagination></nav>
		</section>

		<!-- ────────────── Breakdowns (collapsible) ────────────── -->
		<section class="bc-card bc-section bc-tx-breakdowns" aria-labelledby="bc-tx-breakdowns-title">
			<header class="bc-section__header">
				<div>
					<h2 id="bc-tx-breakdowns-title"><?php p($l->t('Breakdowns')); ?></h2>
					<p class="bc-section__sub"><?php p($l->t('Group, category and month totals for the current filter result. Click any row to drill down.')); ?></p>
				</div>
				<div class="bc-section__controls">
					<button
						type="button"
						class="button bc-tx-breakdowns__toggle"
						data-bc-breakdowns-toggle
						aria-expanded="false"
						aria-controls="bc-tx-breakdowns-body">
						<span data-bc-breakdowns-toggle-label><?php p($l->t('Show breakdowns')); ?></span>
					</button>
				</div>
			</header>

			<div id="bc-tx-breakdowns-body" class="bc-tx-breakdowns__body" data-bc-breakdowns-body hidden>
				<div role="tablist" class="bc-tx-tabs" aria-label="<?php p($l->t('Breakdown views')); ?>">
					<button
						type="button"
						role="tab"
						id="bc-tx-tab-group"
						class="bc-tx-tabs__tab"
						data-bc-tab="group"
						aria-controls="bc-tx-panel-group"
						aria-selected="true"
						tabindex="0"><?php p($l->t('By group')); ?></button>
					<button
						type="button"
						role="tab"
						id="bc-tx-tab-category"
						class="bc-tx-tabs__tab"
						data-bc-tab="category"
						aria-controls="bc-tx-panel-category"
						aria-selected="false"
						tabindex="-1"><?php p($l->t('By category')); ?></button>
					<button
						type="button"
						role="tab"
						id="bc-tx-tab-month"
						class="bc-tx-tabs__tab"
						data-bc-tab="month"
						aria-controls="bc-tx-panel-month"
						aria-selected="false"
						tabindex="-1"><?php p($l->t('By month')); ?></button>
				</div>

				<div
					id="bc-tx-panel-group"
					role="tabpanel"
					aria-labelledby="bc-tx-tab-group"
					class="bc-tx-tabs__panel"
					data-bc-panel="group">
					<div class="bc-table-scroll" role="region" aria-label="<?php p($l->t('By group')); ?>" tabindex="0">
						<table class="bc-table">
							<thead>
								<tr>
									<th scope="col"><?php p($l->t('Group')); ?></th>
									<th scope="col" class="bc-table__col--num"><?php p($l->t('Income')); ?></th>
									<th scope="col" class="bc-table__col--num"><?php p($l->t('Expenses')); ?></th>
									<th scope="col" class="bc-table__col--num"><?php p($l->t('Net result')); ?></th>
									<th scope="col" class="bc-table__col--num"><?php p($l->t('entries')); ?></th>
								</tr>
							</thead>
							<tbody data-bc-tx-analytics-groups>
								<tr><td colspan="5" class="bc-loading"><?php p($l->t('Loading…')); ?></td></tr>
							</tbody>
						</table>
					</div>
				</div>

				<div
					id="bc-tx-panel-category"
					role="tabpanel"
					aria-labelledby="bc-tx-tab-category"
					class="bc-tx-tabs__panel"
					data-bc-panel="category"
					hidden>
					<div class="bc-table-scroll" role="region" aria-label="<?php p($l->t('By category')); ?>" tabindex="0">
						<table class="bc-table">
							<thead>
								<tr>
									<th scope="col"><?php p($l->t('Category')); ?></th>
									<th scope="col"><?php p($l->t('Group')); ?></th>
									<th scope="col" class="bc-table__col--num"><?php p($l->t('Income')); ?></th>
									<th scope="col" class="bc-table__col--num"><?php p($l->t('Expenses')); ?></th>
									<th scope="col" class="bc-table__col--num"><?php p($l->t('Net result')); ?></th>
									<th scope="col" class="bc-table__col--num"><?php p($l->t('entries')); ?></th>
								</tr>
							</thead>
							<tbody data-bc-tx-analytics-categories>
								<tr><td colspan="6" class="bc-loading"><?php p($l->t('Loading…')); ?></td></tr>
							</tbody>
						</table>
					</div>
				</div>

				<div
					id="bc-tx-panel-month"
					role="tabpanel"
					aria-labelledby="bc-tx-tab-month"
					class="bc-tx-tabs__panel"
					data-bc-panel="month"
					hidden>
					<div class="bc-table-scroll" role="region" aria-label="<?php p($l->t('By month')); ?>" tabindex="0">
						<table class="bc-table">
							<thead>
								<tr>
									<th scope="col"><?php p($l->t('Month')); ?></th>
									<th scope="col" class="bc-table__col--num"><?php p($l->t('Income')); ?></th>
									<th scope="col" class="bc-table__col--num"><?php p($l->t('Expenses')); ?></th>
									<th scope="col" class="bc-table__col--num"><?php p($l->t('Net result')); ?></th>
									<th scope="col" class="bc-table__col--num"><?php p($l->t('entries')); ?></th>
								</tr>
							</thead>
							<tbody data-bc-tx-analytics-months>
								<tr><td colspan="5" class="bc-loading"><?php p($l->t('Loading…')); ?></td></tr>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</section>

	</div>
<?php endif; ?>

<?php include __DIR__ . '/common/page-end.php'; ?>
