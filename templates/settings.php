<?php

/** @var array $_ */
/** @var \OCP\IL10N $l */

$workspace = $_['workspace'] ?? null;
$canManage = !empty($_['canManageWorkspace']);
?>
<?php include __DIR__ . '/common/page-start.php'; ?>

<?php if ($workspace === null): ?>
	<section class="bc-card bc-empty">
		<h2><?php p($l->t('Pick a workspace')); ?></h2>
		<p><?php p($l->t('Select a workspace from the sidebar to open workspace settings.')); ?></p>
	</section>
<?php else: ?>
	<?php if ($workspace !== null): ?>
		<section class="bc-card bc-section" aria-labelledby="bc-ws-meta-title">
			<header class="bc-section__header">
				<div>
					<h2 id="bc-ws-meta-title"><?php p($l->t('Workspace details')); ?></h2>
					<p class="bc-section__sub"><?php p($l->t('Name, currency, timezone, and project window.')); ?></p>
				</div>
			</header>
			<form class="bc-form-grid" data-bc-workspace-form>
				<label class="bc-field">
					<span class="bc-field__label"><?php p($l->t('Name')); ?></span>
					<input type="text" name="name" class="bc-input" maxlength="120" required <?php p($canManage ? '' : 'disabled'); ?>>
				</label>
				<label class="bc-field">
					<span class="bc-field__label"><?php p($l->t('Currency')); ?></span>
					<select name="currencyCode" class="bc-input" data-bc-currency-select required <?php p($canManage ? '' : 'disabled'); ?>></select>
				</label>
				<label class="bc-field">
					<span class="bc-field__label"><?php p($l->t('Timezone')); ?></span>
					<select name="timezone" class="bc-input" data-bc-timezone-select <?php p($canManage ? '' : 'disabled'); ?>></select>
				</label>
				<label class="bc-field">
					<span class="bc-field__label"><?php p($l->t('Large-expense threshold (minor units)')); ?></span>
					<input type="number" min="0" step="1" name="overspendThresholdMinor" class="bc-input" <?php p($canManage ? '' : 'disabled'); ?>>
				</label>
				<?php if ($workspace['type'] === 'household'): ?>
					<label class="bc-field">
						<span class="bc-field__label"><?php p($l->t('Primary planning year')); ?></span>
						<input type="number" name="primaryPlanningYear" class="bc-input" min="1900" max="9999" step="1" required <?php p($canManage ? '' : 'disabled'); ?> aria-describedby="bc-primary-year-hint">
						<span id="bc-primary-year-hint" class="bc-field__hint"><?php p($l->t('Calendar year this household treats as its main planning anchor (budgets and yearly overview).')); ?></span>
					</label>
					<label class="bc-field bc-field--full-width bc-field--boolean">
						<span class="bc-field__label"><?php p($l->t('Monthly planning')); ?></span>
						<span class="bc-boolean-control">
							<input type="checkbox" name="autoCopyBudgetsFromPreviousMonth" value="1" <?php p($canManage ? '' : 'disabled'); ?>>
							<span class="bc-boolean-control__text"><?php p($l->t('Copy last month\'s budget when opening a new month')); ?></span>
						</span>
					</label>
				<?php else: ?>
					<p id="bc-ws-project-date-hint" class="bc-field__hint bc-field__hint--block"><?php p($l->t('Date and month fields use your Nextcloud language. Tables and summaries match. The browser\'s calendar popup may still follow your device language in some setups.')); ?></p>
					<label class="bc-field">
						<span class="bc-field__label"><?php p($l->t('Project start')); ?></span>
						<input type="date" name="projectStartDate" class="bc-input" lang="<?php p($bcHtmlLang); ?>" autocomplete="off" <?php p($canManage ? '' : 'disabled'); ?> aria-describedby="bc-ws-project-date-hint">
					</label>
					<label class="bc-field">
						<span class="bc-field__label"><?php p($l->t('Project end')); ?></span>
						<input type="date" name="projectEndDate" class="bc-input" lang="<?php p($bcHtmlLang); ?>" autocomplete="off" <?php p($canManage ? '' : 'disabled'); ?> aria-describedby="bc-ws-project-date-hint">
					</label>
					<label class="bc-field">
						<span class="bc-field__label"><?php p($l->t('Project cap (minor units, optional)')); ?></span>
						<input type="number" min="0" step="1" name="projectTotalCapMinor" class="bc-input" <?php p($canManage ? '' : 'disabled'); ?>>
					</label>
				<?php endif; ?>
				<?php if ($canManage): ?>
					<div class="bc-form-actions">
						<button type="submit" class="button primary"><?php p($l->t('Save workspace')); ?></button>
					</div>
				<?php endif; ?>
			</form>
		</section>

		<section class="bc-card bc-section" aria-labelledby="bc-tax-title">
			<header class="bc-section__header">
				<div>
					<h2 id="bc-tax-title"><?php p($l->t('Tax mode')); ?></h2>
					<p class="bc-section__sub"><?php p($l->t('Optional: net/gross/VAT entry. Disabled by default.')); ?></p>
				</div>
			</header>
			<form class="bc-form-grid" data-bc-tax-form>
				<label class="bc-field bc-field--full-width bc-field--boolean">
					<span class="bc-field__label"><?php p($l->t('Workspace tax')); ?></span>
					<span class="bc-boolean-control">
						<input type="checkbox" name="taxModeEnabled" value="1" <?php p($canManage ? '' : 'disabled'); ?>>
						<span class="bc-boolean-control__text"><?php p($l->t('Enable tax mode for this workspace')); ?></span>
					</span>
				</label>
				<label class="bc-field">
					<span class="bc-field__label"><?php p($l->t('Budget basis')); ?></span>
					<select name="taxBudgetBasis" class="bc-input" <?php p($canManage ? '' : 'disabled'); ?>>
						<option value="gross"><?php p($l->t('Gross')); ?></option>
						<option value="net"><?php p($l->t('Net')); ?></option>
					</select>
				</label>
				<label class="bc-field">
					<span class="bc-field__label"><?php p($l->t('Default VAT rate')); ?></span>
					<select name="vatPreset" class="bc-input" data-bc-vat-preset <?php p($canManage ? '' : 'disabled'); ?>>
						<option value="0"><?php p($l->t('0%% (none)')); ?></option>
						<option value="500"><?php p($l->t('5%%')); ?></option>
						<option value="700"><?php p($l->t('7%%')); ?></option>
						<option value="1000"><?php p($l->t('10%%')); ?></option>
						<option value="1300"><?php p($l->t('13%%')); ?></option>
						<option value="1500"><?php p($l->t('15%%')); ?></option>
						<option value="1900"><?php p($l->t('19%%')); ?></option>
						<option value="2000"><?php p($l->t('20%%')); ?></option>
						<option value="2100"><?php p($l->t('21%%')); ?></option>
						<option value="2500"><?php p($l->t('25%%')); ?></option>
						<option value="custom"><?php p($l->t('Custom…')); ?></option>
					</select>
				</label>
				<label class="bc-field" data-bc-vat-custom-wrap hidden>
					<span class="bc-field__label"><?php p($l->t('Custom VAT (basis points)')); ?></span>
					<input type="number" min="0" max="5000" step="1" name="defaultVatRateBp" class="bc-input" data-bc-vat-custom <?php p($canManage ? '' : 'disabled'); ?>>
				</label>
				<?php if ($canManage): ?>
					<div class="bc-form-actions">
						<button type="submit" class="button primary"><?php p($l->t('Save tax settings')); ?></button>
					</div>
				<?php endif; ?>
			</form>
		</section>

		<section class="bc-card bc-section" aria-labelledby="bc-categories-title">
			<header class="bc-section__header">
				<div>
					<h2 id="bc-categories-title"><?php p($l->t('Categories')); ?></h2>
					<p class="bc-section__sub"><?php p($l->t('Income and expense categories used for transactions and budgets.')); ?></p>
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
							<th scope="col"><?php p($l->t('Tax handling')); ?></th>
							<th scope="col"><?php p($l->t('Status')); ?></th>
							<?php if ($canManage): ?>
								<th scope="col" class="bc-sr-only"><?php p($l->t('Actions')); ?></th>
							<?php endif; ?>
						</tr>
					</thead>
					<tbody data-bc-category-rows>
						<tr>
							<td colspan="7" class="bc-loading"><?php p($l->t('Loading…')); ?></td>
						</tr>
					</tbody>
				</table>
			</div>
		</section>

		<?php if ($canManage): ?>
			<section class="bc-card bc-section" aria-labelledby="bc-members-title">
				<header class="bc-section__header">
					<div>
						<h2 id="bc-members-title"><?php p($l->t('Members')); ?></h2>
						<p class="bc-section__sub"><?php p($l->t('Manager, contributor, or viewer per workspace.')); ?></p>
					</div>
				</header>
				<div class="bc-member-invite" data-bc-member-invite aria-labelledby="bc-member-invite-title">
					<h3 id="bc-member-invite-title" class="bc-member-invite__title"><?php p($l->t('Invite a member')); ?></h3>
					<p id="bc-member-invite-hint" class="bc-field__hint bc-field__hint--block"><?php p($l->t('Type at least two characters to search.')); ?></p>
					<div class="bc-member-invite__grid">
						<div class="bc-entity-picker bc-member-invite__search">
							<label for="bc-member-invite-q" class="bc-sr-only"><?php p($l->t('Search directory for a user to add')); ?></label>
							<input id="bc-member-invite-q" type="search" class="bc-input bc-entity-picker__q" autocomplete="off" maxlength="120" aria-describedby="bc-member-invite-hint" placeholder="<?php p($l->t('Search directory for a user to add')); ?>">
							<div id="bc-member-invite-suggest" class="bc-entity-picker__suggest" hidden aria-live="polite"></div>
						</div>
						<label class="bc-field bc-member-invite__role">
							<span class="bc-field__label"><?php p($l->t('Role')); ?></span>
							<select id="bc-member-invite-role" class="bc-input" data-bc-member-invite-role>
								<option value="viewer"><?php p($l->t('Viewer')); ?></option>
								<option value="contributor"><?php p($l->t('Contributor')); ?></option>
								<option value="manager"><?php p($l->t('Manager')); ?></option>
							</select>
						</label>
						<button type="button" class="button primary bc-member-invite__submit" data-bc-action="member-invite-submit">
							<?php p($l->t('Add to workspace')); ?>
						</button>
					</div>
					<p class="bc-field__hint" data-bc-member-selected-wrap hidden role="status" aria-live="polite">
						<span data-bc-member-selected></span>
					</p>
				</div>
				<div class="bc-table-scroll" role="region" aria-label="<?php p($l->t('Members')); ?>" tabindex="0">
					<table class="bc-table bc-members-table">
						<thead>
							<tr>
								<th scope="col"><?php p($l->t('User')); ?></th>
								<th scope="col"><?php p($l->t('Role')); ?></th>
								<th scope="col"><?php p($l->t('Added')); ?></th>
								<th scope="col" class="bc-sr-only"><?php p($l->t('Actions')); ?></th>
							</tr>
						</thead>
						<tbody data-bc-member-rows>
							<tr>
								<td colspan="4" class="bc-loading"><?php p($l->t('Loading…')); ?></td>
							</tr>
						</tbody>
					</table>
				</div>
			</section>

			<section class="bc-card bc-section" aria-labelledby="bc-recurring-title">
				<header class="bc-section__header">
					<div>
						<h2 id="bc-recurring-title"><?php p($l->t('Recurring rules')); ?></h2>
						<p class="bc-section__sub"><?php p($l->t('Templates that suggest entries; nothing posts automatically.')); ?></p>
					</div>
					<button type="button" class="button primary" data-bc-action="open-create-recurring">
						<?php p($l->t('New rule')); ?>
					</button>
				</header>
				<div class="bc-table-scroll" role="region" aria-label="<?php p($l->t('Recurring rules')); ?>" tabindex="0">
					<table class="bc-table bc-recurring-table">
						<thead>
							<tr>
								<th scope="col"><?php p($l->t('Title')); ?></th>
								<th scope="col"><?php p($l->t('Direction')); ?></th>
								<th scope="col" class="bc-table__col--num"><?php p($l->t('Amount')); ?></th>
								<th scope="col"><?php p($l->t('Frequency')); ?></th>
								<th scope="col"><?php p($l->t('Next due')); ?></th>
								<th scope="col"><?php p($l->t('Status')); ?></th>
								<th scope="col" class="bc-sr-only"><?php p($l->t('Actions')); ?></th>
							</tr>
						</thead>
						<tbody data-bc-recurring-rows>
							<tr>
								<td colspan="7" class="bc-loading"><?php p($l->t('Loading…')); ?></td>
							</tr>
						</tbody>
					</table>
				</div>
			</section>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ($workspace !== null): ?>
		<section class="bc-card bc-section bc-help-panels" id="bc-help-panels" aria-label="<?php p($l->t('Help and glossary')); ?>">
			<details class="bc-details" id="bc-glossary">
				<summary class="bc-details__summary"><?php p($l->t('Words we use')); ?></summary>
				<div class="bc-details__body">
					<dl class="bc-glossary">
						<dt><?php p($l->t('Household')); ?></dt>
						<dd><?php p($l->t('A workspace for month-by-month income, expenses, budgets, savings targets, and monthly close.')); ?></dd>
						<dt><?php p($l->t('Project')); ?></dt>
						<dd><?php p($l->t('A workspace with start and end dates for bounded spend; no monthly close flow.')); ?></dd>
						<dt><?php p($l->t('Budget left')); ?></dt>
						<dd><?php p($l->t('Planned amount for a category minus spending that counts toward that plan in the month.')); ?></dd>
						<dt><?php p($l->t('Available after savings')); ?></dt>
						<dd><?php p($l->t('Income minus expenses minus the savings target for that month.')); ?></dd>
						<dt><?php p($l->t('Savings target')); ?></dt>
						<dd><?php p($l->t('The amount you plan to set aside (percentage, fixed amount, or the higher of both).')); ?></dd>
						<dt><?php p($l->t('Cap warning')); ?></dt>
						<dd><?php p($l->t('For projects, a reminder when all-time spend approaches or exceeds the optional cap.')); ?></dd>
					</dl>
				</div>
			</details>
			<details class="bc-details" id="bc-spreadsheet-bridge">
				<summary class="bc-details__summary"><?php p($l->t('From spreadsheet to BudgetCheck')); ?></summary>
				<div class="bc-details__body">
					<ul class="bc-bridge-list">
						<li><?php p($l->t('Monthly sheet → log transactions and use the monthly plan for budgets and close.')); ?></li>
						<li><?php p($l->t('Annual overview grid → use the yearly overview for the household story.')); ?></li>
						<li><?php p($l->t('Large one-offs → use a project workspace or flag special transactions in a household workspace.')); ?></li>
					</ul>
					<p class="bc-bridge-note"><?php p($l->t('You can hide this panel permanently; it does not block any action.')); ?></p>
					<button type="button" class="button" data-bc-dismiss-bridge><?php p($l->t('Hide this panel permanently')); ?></button>
				</div>
			</details>
		</section>
	<?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/common/page-end.php'; ?>