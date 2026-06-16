<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */

use OCA\BudgetCheck\Service\IconCatalog;

$workspace = $_['workspace'] ?? null;
$canContribute = !empty($_['canContribute']);
$isProject = is_array($workspace) && (string)($workspace['type'] ?? '') === 'project';
?>
<?php include __DIR__ . '/common/page-start.php'; ?>

<?php if ($workspace === null): ?>
	<section class="bc-card bc-empty" aria-labelledby="bc-import-pick-workspace">
		<h2 id="bc-import-pick-workspace"><?php p($l->t('Select a workspace')); ?></h2>
		<p><?php p($l->t('Pick a workspace in the sidebar before importing transactions.')); ?></p>
	</section>
<?php elseif (!$canContribute): ?>
	<section class="bc-card bc-empty" aria-labelledby="bc-import-no-access">
		<h2 id="bc-import-no-access"><?php p($l->t('Import is not available for your role')); ?></h2>
		<p><?php p($l->t('You need contributor or manager access to import transactions into this workspace.')); ?></p>
	</section>
<?php else: ?>
	<section class="bc-card bc-section" aria-labelledby="bc-import-guide-title">
		<header class="bc-section__header">
			<div>
				<h2 id="bc-import-guide-title"><?php p($l->t('How this import works')); ?></h2>
				<p class="bc-section__sub"><?php p($l->t('Upload a CSV from your bank or spreadsheet. You never need numeric category IDs — pick defaults below or use category names from the list.')); ?></p>
			</div>
		</header>
		<ol class="bc-import-steps">
			<li><?php p($l->t('Choose default categories (for bank files without a category column).')); ?></li>
			<li><?php p($l->t('Upload your CSV and click “Validate CSV”.')); ?></li>
			<li><?php p($l->t('Review the preview, then click “Import transactions”.')); ?></li>
		</ol>
	</section>

	<section class="bc-card bc-section bc-import-callout" aria-labelledby="bc-import-bank-title">
		<h2 id="bc-import-bank-title"><?php p($l->t('Importing from your bank?')); ?></h2>
		<p><?php p($l->t('Most bank CSV files only contain date, description, and amount. That is enough:')); ?></p>
		<ul>
			<li><?php p($l->t('Pick a default category in the form below (expense-only bank exports: choose “Treat every row as an expense”).')); ?></li>
			<li><?php p($l->t('If amounts are signed: negative = expense, positive = income. If all amounts are positive, use the direction option below.')); ?></li>
			<li><?php p($l->t('Column names like “Date”, “Description”, and “Amount” are recognised automatically.')); ?></li>
		</ul>
	</section>

	<section class="bc-card bc-section" aria-labelledby="bc-import-categories-title">
		<header class="bc-section__header">
			<div>
				<h2 id="bc-import-categories-title"><?php p($l->t('Your categories')); ?></h2>
				<p class="bc-section__sub"><?php p($l->t('Copy a name into a “category” column, or rely on the default pickers below. IDs are not required.')); ?></p>
			</div>
		</header>
		<div class="bc-table-scroll" role="region" aria-label="<?php p($l->t('Workspace categories')); ?>" tabindex="0">
			<table class="bc-table" data-bc-import-category-table>
				<thead>
					<tr>
						<th scope="col"><?php p($l->t('Category name')); ?></th>
						<th scope="col"><?php p($l->t('Type')); ?></th>
					</tr>
				</thead>
				<tbody data-bc-import-category-rows>
					<tr><td colspan="2" class="bc-loading"><?php p($l->t('Loading…')); ?></td></tr>
				</tbody>
			</table>
		</div>
	</section>

	<section class="bc-card bc-section" aria-labelledby="bc-import-format-title">
		<header class="bc-section__header">
			<div>
				<h2 id="bc-import-format-title"><?php p($l->t('CSV format')); ?></h2>
				<p class="bc-section__sub"><?php p($l->t('Minimum columns: date, title/description, and amount. Category is optional when defaults are set.')); ?></p>
			</div>
			<div class="bc-section__controls">
				<button type="button" class="button" data-bc-import-download-template>
					<?php p($l->t('Download CSV template')); ?>
				</button>
			</div>
		</header>
		<p><strong><?php p($l->t('Required:')); ?></strong> <?php p($l->t('date + title + amount (or signed amount with defaults)')); ?></p>
		<p><strong><?php p($l->t('Optional:')); ?></strong> <code>direction</code>, <code>category</code>, <code>notes</code>, <code>isSpecial</code>, <code>externalRef</code><?php if ($isProject): ?>, <code>bookingStatus</code><?php endif; ?></p>
		<p><strong><?php p($l->t('Recognised date columns:')); ?></strong> <?php p($l->t('bookingDate, date, valuta, Buchungsdatum')); ?></p>
		<p><strong><?php p($l->t('Recognised title columns:')); ?></strong> <?php p($l->t('title, description, memo, Verwendungszweck')); ?></p>
		<p><strong><?php p($l->t('Recognised amount columns:')); ?></strong> <?php p($l->t('amount, value, betrag (semicolon-separated files are supported)')); ?></p>
		<p><strong><?php p($l->t('Recognised direction columns:')); ?></strong> <?php p($l->t('direction, type, Soll/Haben, debit/credit')); ?></p>
	</section>

	<section class="bc-card bc-section" aria-labelledby="bc-import-run-title">
		<header class="bc-section__header">
			<div>
				<h2 id="bc-import-run-title"><?php p($l->t('Run import')); ?></h2>
				<p class="bc-section__sub"><?php p($l->t('Validate first. Import is enabled only after validation succeeds.')); ?></p>
			</div>
		</header>
		<form class="bc-form-grid" data-bc-import-form>
			<fieldset class="bc-fieldset bc-field--full-width">
				<legend class="bc-fieldset__legend"><?php p($l->t('Default categories (no category column in CSV)')); ?></legend>
				<p class="bc-field__hint bc-field__hint--block" id="bc-import-defaults-hint"><?php p($l->t('Required when your file has no category column — typical for bank exports. Expenses use the expense default; income uses the income default.')); ?></p>
				<div class="bc-import-defaults">
					<label class="bc-field">
						<span class="bc-field__label"><?php p($l->t('Default expense category')); ?></span>
						<select class="bc-input" data-bc-import-default-expense aria-describedby="bc-import-defaults-hint"></select>
					</label>
					<label class="bc-field">
						<span class="bc-field__label"><?php p($l->t('Default income category')); ?></span>
						<select class="bc-input" data-bc-import-default-income aria-describedby="bc-import-defaults-hint"></select>
					</label>
					<label class="bc-field">
						<span class="bc-field__label"><?php p($l->t('If there is no direction column')); ?></span>
						<select class="bc-input" data-bc-import-direction-mode aria-describedby="bc-import-defaults-hint">
							<option value="auto"><?php p($l->t('Detect from amount sign (+ income, − expense)')); ?></option>
							<option value="expense"><?php p($l->t('Treat every row as an expense')); ?></option>
							<option value="income"><?php p($l->t('Treat every row as income')); ?></option>
						</select>
					</label>
				</div>
			</fieldset>
			<div class="bc-field bc-field--full-width">
				<span class="bc-field__label" id="bc-import-file-label"><?php p($l->t('CSV file')); ?></span>
				<div class="bc-file-picker" data-bc-import-file-picker>
					<label class="bc-file-picker__surface">
						<input
							type="file"
							id="bc-import-file"
							accept=".csv,text/csv"
							class="bc-file-picker__input"
							data-bc-import-file
							required
							aria-labelledby="bc-import-file-label"
							aria-describedby="bc-import-file-hint bc-import-file-name">
						<span class="bc-file-picker__icon" aria-hidden="true">
							<?php print_unescaped(IconCatalog::render('upload', 'bc-icon--inline')); ?>
						</span>
						<span class="bc-file-picker__copy">
							<span class="bc-file-picker__title"><?php p($l->t('Choose a CSV file')); ?></span>
							<span class="bc-file-picker__sub"><?php p($l->t('or drag and drop here')); ?></span>
						</span>
					</label>
					<p class="bc-file-picker__name" id="bc-import-file-name" data-bc-import-file-name hidden>
						<span class="bc-file-picker__name-label"><?php p($l->t('Selected:')); ?></span>
						<span data-bc-import-file-name-text></span>
					</p>
				</div>
				<span class="bc-field__hint" id="bc-import-file-hint"><?php p($l->t('Maximum 500 rows per import.')); ?></span>
			</div>
			<div class="bc-form-actions">
				<button type="button" class="button" data-bc-import-validate><?php p($l->t('Validate CSV')); ?></button>
				<button type="submit" class="button primary" data-bc-import-commit disabled><?php p($l->t('Import transactions')); ?></button>
			</div>
			<p class="bc-field__hint bc-field__hint--block" data-bc-import-status aria-live="polite"><?php p($l->t('No file validated yet.')); ?></p>
		</form>
		<div class="bc-table-scroll" role="region" aria-label="<?php p($l->t('Import preview')); ?>" tabindex="0" data-bc-import-preview-wrap hidden>
			<table class="bc-table">
				<thead>
					<tr>
						<th scope="col"><?php p($l->t('Date')); ?></th>
						<th scope="col"><?php p($l->t('Title')); ?></th>
						<th scope="col"><?php p($l->t('Direction')); ?></th>
						<th scope="col"><?php p($l->t('Amount')); ?></th>
						<th scope="col"><?php p($l->t('Category')); ?></th>
					</tr>
				</thead>
				<tbody data-bc-import-preview></tbody>
			</table>
		</div>
	</section>
<?php endif; ?>

<?php include __DIR__ . '/common/page-end.php'; ?>
