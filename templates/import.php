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
			<li><?php p($l->t('Bank “Status” columns are ignored in household workspaces — they will not block your import.')); ?></li>
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
		<p><strong><?php p($l->t('Recognised amount columns:')); ?></strong> <?php p($l->t('amount, value, betrag (comma, semicolon, or tab-separated files are supported)')); ?></p>
		<p><strong><?php p($l->t('Recognised direction columns:')); ?></strong> <?php p($l->t('direction, Soll/Haben, debit/credit (rename a bank “type” column to “direction” if needed)')); ?></p>
		<div class="bc-import-format-note" role="note">
			<p><strong><?php p($l->t('Amount formats')); ?></strong></p>
			<p><?php p($l->t('Both European (1.234,56) and English (1,234.56) number formats are accepted. Spaces as thousands separators also work. Do not put currency symbols in the amount column.')); ?></p>
			<p><?php p($l->t('If you export from LibreOffice or Excel, comma, semicolon, or tab delimiters all work — the importer detects which you used.')); ?></p>
			<p><?php p($l->t('Dates follow your account locale (for example dd.mm.yyyy in German). ISO dates (YYYY-MM-DD) always work.')); ?></p>
			<p><?php p($l->t('Files are read as UTF-8 first. Older bank exports in Windows-1252 or Latin-1 are converted automatically when needed.')); ?></p>
		</div>
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
				<legend class="bc-fieldset__legend"><?php p($l->t('Import options')); ?></legend>
				<p class="bc-field__hint bc-field__hint--block" id="bc-import-defaults-hint"><?php p($l->t('Default categories fill in rows without a category value — typical for bank exports. Expenses use the expense default; income uses the income default.')); ?></p>
				<p class="bc-field__hint bc-field__hint--block"><?php p($l->t('Your import choices are saved to your account for this workspace and sync across devices.')); ?></p>
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
					<div class="bc-import-duplicate-options">
						<label class="bc-field bc-field--checkbox">
							<input type="checkbox" class="checkbox" data-bc-import-skip-duplicates value="1" aria-controls="bc-import-fingerprint-wrap">
							<span class="bc-field__label"><?php p($l->t('Skip duplicate rows')); ?></span>
							<span class="bc-field__hint"><?php p($l->t('Matches by bank reference when your file has a reference column. Enable the option below for files without references.')); ?></span>
						</label>
						<div class="bc-import-duplicate-sub" id="bc-import-fingerprint-wrap" data-bc-import-fingerprint-wrap hidden>
							<label class="bc-field bc-field--checkbox bc-field--checkbox-sub">
								<input type="checkbox" class="checkbox" data-bc-import-skip-fingerprint value="1">
								<span class="bc-field__label"><?php p($l->t('Also skip rows that match date, amount, direction, and title')); ?></span>
								<span class="bc-field__hint"><?php p($l->t('Use when your bank export has no reference column. Only enable if you might import the same file twice.')); ?></span>
							</label>
						</div>
					</div>
				</div>
			</fieldset>
			<div class="bc-field bc-field--full-width">
				<span class="bc-field__label" id="bc-import-file-label"><?php p($l->t('CSV or text file')); ?></span>
				<div class="bc-file-picker" data-bc-import-file-picker>
					<label class="bc-file-picker__surface">
						<input
							type="file"
							id="bc-import-file"
							accept=".csv,.txt,text/csv,text/plain"
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
				<span class="bc-field__hint" id="bc-import-file-hint"><?php p($l->t('Maximum 500 rows per import. Split larger exports into several files — you can validate multiple batches in a row.')); ?></span>
			</div>
			<div class="bc-form-actions">
				<button type="button" class="button" data-bc-import-validate><?php p($l->t('Validate CSV')); ?></button>
				<button type="submit" class="button primary" data-bc-import-commit disabled><?php p($l->t('Import transactions')); ?></button>
			</div>
			<p class="bc-field__hint bc-field__hint--block" data-bc-import-status aria-live="polite"><?php p($l->t('No file validated yet.')); ?></p>
			<div class="bc-import-errors" data-bc-import-errors-wrap hidden>
				<h3 class="bc-import-errors__title" id="bc-import-errors-title" data-bc-import-errors-title><?php p($l->t('Validation errors')); ?></h3>
				<ul class="bc-import-errors__list" data-bc-import-errors aria-labelledby="bc-import-errors-title"></ul>
			</div>
		</form>
		<p class="bc-field__hint bc-import-preview-summary" data-bc-import-preview-summary hidden></p>
		<div class="bc-table-scroll" role="region" aria-label="<?php p($l->t('Import preview')); ?>" tabindex="0" data-bc-import-preview-wrap hidden>
			<table class="bc-table">
				<thead>
					<tr>
						<th scope="col"><?php p($l->t('Line')); ?></th>
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
