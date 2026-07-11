<?php

/** @var array $_ */
/** @var \OCP\IL10N $l */

$workspace = $_['workspace'] ?? null;
$canManage = !empty($_['canManageWorkspace']);
$currencyChangeAllowed = array_key_exists('currencyChangeAllowed', $_)
	? (bool) $_['currencyChangeAllowed']
	: true;
?>
<?php include __DIR__ . '/common/page-start.php'; ?>

<?php if ($workspace === null): ?>
	<section class="bc-card bc-empty">
		<h2><?php p($l->t('Pick a workspace')); ?></h2>
		<p><?php p($l->t('Select a workspace from the sidebar to open workspace settings.')); ?></p>
	</section>
<?php else: ?>
	<?php if ($workspace !== null && $workspace['type'] === 'household'): ?>
		<section class="bc-card bc-section" aria-labelledby="bc-summary-view-prefs-title">
			<header class="bc-section__header">
				<div>
					<h2 id="bc-summary-view-prefs-title"><?php p($l->t('Your planning view')); ?></h2>
					<p class="bc-section__sub"><?php p($l->t('Controls how income and expense totals appear on the dashboard, monthly plan, and yearly overview—for you only.')); ?></p>
				</div>
			</header>
			<div class="bc-specials-toggle-wrap" data-bc-summary-view-prefs></div>
		</section>
	<?php endif; ?>
	<?php if ($workspace !== null): ?>
		<section class="bc-card bc-section" aria-labelledby="bc-ws-meta-title">
			<header class="bc-section__header">
				<div>
					<h2 id="bc-ws-meta-title"><?php p($l->t('Workspace details')); ?></h2>
					<p class="bc-section__sub"><?php p($l->t('Each workspace has its own currency and timezone. They apply to all months and transactions in this workspace.')); ?></p>
				</div>
			</header>
			<form class="bc-form-grid" data-bc-workspace-form>
				<label class="bc-field">
					<span class="bc-field__label"><?php p($l->t('Name')); ?></span>
					<input type="text" name="name" class="bc-input" maxlength="120" required <?php p($canManage ? '' : 'disabled'); ?>>
				</label>
				<div class="bc-field bc-field--catalog">
					<span class="bc-field__label" id="bc-ws-currency-label"><?php p($l->t('Currency')); ?></span>
					<?php
					$pickerId = 'bc-ws-currency';
					$pickerName = 'currencyCode';
					$pickerDefault = (string) ($workspace['currencyCode'] ?? 'EUR');
					$pickerDisabled = !$canManage || !$currencyChangeAllowed;
					$pickerDescribedBy = 'bc-ws-currency-hint';
					include __DIR__ . '/common/bc-currency-picker.php';
					?>
					<p id="bc-ws-currency-hint" class="bc-field__hint"><?php
						if ($currencyChangeAllowed) {
							p($l->t('ISO 4217 code for this workspace. Choose before the first transaction — it cannot be changed later.'));
						} else {
							p($l->t('Locked because this workspace already has transactions. Currency was set when the first entry was recorded.'));
						}
					?></p>
				</div>
				<div class="bc-field bc-field--catalog">
					<span class="bc-field__label" id="bc-ws-timezone-label"><?php p($l->t('Timezone')); ?></span>
					<?php
					$pickerId = 'bc-ws-timezone';
					$pickerName = 'timezone';
					$pickerDefault = (string) ($workspace['timezone'] ?? 'Europe/Berlin');
					$pickerDisabled = !$canManage;
					$pickerDescribedBy = 'bc-ws-timezone-hint';
					include __DIR__ . '/common/bc-timezone-picker.php';
					?>
					<p id="bc-ws-timezone-hint" class="bc-field__hint"><?php p($l->t('IANA timezone for calendar months and due dates in this workspace.')); ?></p>
				</div>
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
					<label class="bc-field bc-field--full-width bc-field--boolean">
						<span class="bc-boolean-control">
							<input type="checkbox" name="generatePlannedFromBudgetsDefault" value="1" <?php p($canManage ? '' : 'disabled'); ?>>
							<span class="bc-boolean-control__text"><?php p($l->t('Generate planned entries when budgets are saved')); ?></span>
						</span>
					</label>
					<fieldset class="bc-fieldset bc-fieldset--mode-group bc-field--full-width">
						<legend class="bc-fieldset__legend"><?php p($l->t('Planning summaries')); ?></legend>
						<p class="bc-field__hint bc-field__hint--block" id="bc-summary-default-hint"><?php p($l->t('Members who have not set a personal preference inherit this default. Each member can change their own choice in “Your planning view” above.')); ?></p>
						<label class="bc-field bc-field--boolean">
							<span class="bc-boolean-control">
								<input type="checkbox" name="includeSpecialsInTotalsDefault" value="1" <?php p($canManage ? '' : 'disabled'); ?> aria-describedby="bc-summary-default-hint">
								<span class="bc-boolean-control__text"><?php p($l->t('Include one-off transactions in totals by default')); ?></span>
							</span>
						</label>
					</fieldset>
					<fieldset class="bc-fieldset bc-fieldset--mode-group bc-field--full-width">
						<legend class="bc-fieldset__legend"><?php p($l->t('Default savings target for new months')); ?></legend>
						<p class="bc-field__hint bc-field__hint--block"><?php p($l->t('If set, months without an explicit savings target inherit this default automatically.')); ?></p>
						<label class="bc-field bc-field--radio">
							<input type="radio" name="defaultSavingsTargetMode" value="">
							<span><?php p($l->t('No target set')); ?></span>
						</label>
						<label class="bc-field bc-field--radio">
							<input type="radio" name="defaultSavingsTargetMode" value="percentage">
							<span><?php p($l->t('Percentage of income')); ?></span>
						</label>
						<label class="bc-field bc-field--radio">
							<input type="radio" name="defaultSavingsTargetMode" value="absolute">
							<span><?php p($l->t('Absolute amount')); ?></span>
						</label>
						<label class="bc-field bc-field--radio">
							<input type="radio" name="defaultSavingsTargetMode" value="hybrid">
							<span><?php p($l->t('Hybrid (max of both)')); ?></span>
						</label>
						<div class="bc-form-grid">
							<label class="bc-field" data-bc-default-savings-percent-wrap hidden>
								<span class="bc-field__label"><?php p($l->t('Percentage (0–100)')); ?></span>
								<input type="number" min="0" max="100" step="1" name="defaultSavingsTargetPercent" class="bc-input" <?php p($canManage ? '' : 'disabled'); ?>>
							</label>
							<label class="bc-field" data-bc-default-savings-amount-wrap hidden>
								<span class="bc-field__label"><?php p($l->t('Amount (per month)')); ?></span>
								<input type="text" inputmode="decimal" name="defaultSavingsTargetAmount" class="bc-input" <?php p($canManage ? '' : 'disabled'); ?>>
							</label>
						</div>
					</fieldset>
				<?php else: ?>
					<hr class="bc-form-grid__divider" aria-hidden="true">
					<label class="bc-field">
						<span class="bc-field__label"><?php p($l->t('Project start')); ?></span>
						<input type="date" name="projectStartDate" class="bc-input" lang="<?php p($bcHtmlLang); ?>" autocomplete="off" <?php p($canManage ? '' : 'disabled'); ?>>
					</label>
					<label class="bc-field">
						<span class="bc-field__label"><?php p($l->t('Project end')); ?></span>
						<input type="date" name="projectEndDate" class="bc-input" lang="<?php p($bcHtmlLang); ?>" autocomplete="off" <?php p($canManage ? '' : 'disabled'); ?>>
					</label>
					<label class="bc-field bc-field--full-width">
						<span class="bc-field__label"><?php p($l->t('Project cap (optional)')); ?></span>
						<input type="text" inputmode="decimal" name="projectTotalCapMinor" class="bc-input" <?php p($canManage ? '' : 'disabled'); ?>>
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
					<span class="bc-field__hint"><?php p($l->t('When disabled, new entries are stored without net/VAT/gross split. Existing tax split values are removed automatically when you save disabled mode.')); ?></span>
				</label>
				<label class="bc-field">
					<span class="bc-field__label"><?php p($l->t('Budget basis')); ?></span>
					<select name="taxBudgetBasis" class="bc-input" <?php p($canManage ? '' : 'disabled'); ?>>
						<option value="gross"><?php p($l->t('Gross')); ?></option>
						<option value="net"><?php p($l->t('Net')); ?></option>
					</select>
					<span class="bc-field__hint"><?php p($l->t('This decides which value counts against budgets and project cap when a booking has tax split (net or gross entry).')); ?></span>
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
					<span class="bc-field__hint"><?php p($l->t('Used as default in transaction dialogs when tax entry basis is net or gross.')); ?></span>
				</label>
				<label class="bc-field" data-bc-vat-custom-wrap hidden>
					<span class="bc-field__label"><?php p($l->t('Custom VAT (basis points)')); ?></span>
					<input type="number" min="0" max="5000" step="1" name="defaultVatRateBp" class="bc-input" data-bc-vat-custom <?php p($canManage ? '' : 'disabled'); ?>>
				</label>
				<p class="bc-tax-help bc-field__hint bc-field__hint--block" data-bc-tax-preview-summary>
					<?php p($l->t('If Budget basis is Net, budgets and project cap use net values. If Budget basis is Gross, they use gross values.')); ?>
				</p>
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

		<?php if ($workspace['type'] === 'household' && $canManage): ?>
			<section class="bc-card bc-section" aria-labelledby="bc-budget-defaults-title">
				<header class="bc-section__header">
					<div>
						<h2 id="bc-budget-defaults-title"><?php p($l->t('Default category budgets')); ?></h2>
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

		<?php if ($workspace['type'] === 'project'): ?>
			<section class="bc-card bc-section" aria-labelledby="bc-booking-statuses-title">
				<header class="bc-section__header">
					<div>
						<h2 id="bc-booking-statuses-title"><?php p($l->t('Booking statuses')); ?></h2>
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
		<?php endif; ?>

		<?php if ($canManage): ?>
			<section class="bc-card bc-section" aria-labelledby="bc-members-title">
				<header class="bc-section__header">
					<div>
						<h2 id="bc-members-title"><?php p($l->t('Members')); ?></h2>
						<p class="bc-section__sub"><?php p($l->t('Give people access to this workspace by adding them as a user or by adding a whole group. Each member is a manager, contributor, or viewer.')); ?></p>
					</div>
				</header>

				<div class="bc-member-invite" data-bc-member-invite aria-labelledby="bc-member-invite-title">
					<h3 id="bc-member-invite-title" class="bc-member-invite__title"><?php p($l->t('Add a person')); ?></h3>
					<p id="bc-member-invite-hint" class="bc-field__hint bc-field__hint--block"><?php p($l->t('Type at least two characters to search. Add users one by one, choosing a role for the selected user each time.')); ?></p>
					<div class="bc-member-invite__grid">
						<div class="bc-entity-picker bc-member-invite__search">
							<label for="bc-member-invite-q" class="bc-sr-only"><?php p($l->t('Search directory for a user to add')); ?></label>
							<input id="bc-member-invite-q" type="search" class="bc-input bc-entity-picker__q" autocomplete="off" maxlength="120" aria-describedby="bc-member-invite-hint" placeholder="<?php p($l->t('Search directory for a user to add')); ?>">
							<div id="bc-member-invite-suggest" class="bc-entity-picker__suggest" hidden aria-live="polite"></div>
						</div>
						<label class="bc-field bc-member-invite__role">
							<span class="bc-field__label"><?php p($l->t('Role for selected user')); ?></span>
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
					<div class="bc-member-picked" data-bc-member-selected-wrap hidden role="status" aria-live="polite">
						<p class="bc-member-picked__label"><?php p($l->t('Selected user')); ?></p>
						<div class="bc-member-picked__row">
							<div>
								<p class="bc-member-picked__value" data-bc-member-selected></p>
								<p class="bc-member-picked__meta" data-bc-member-selected-role></p>
							</div>
							<button type="button" class="button bc-member-picked__clear" data-bc-action="member-invite-clear"><?php p($l->t('Clear selection')); ?></button>
						</div>
					</div>
				</div>

				<div class="bc-member-invite" data-bc-group-invite aria-labelledby="bc-group-invite-title">
					<h3 id="bc-group-invite-title" class="bc-member-invite__title"><?php p($l->t('Add a group')); ?></h3>
					<p id="bc-group-invite-hint" class="bc-field__hint bc-field__hint--block"><?php p($l->t('Everyone in the group gets the chosen role. Groups can be contributors or viewers; assign managers to individual people so responsibility stays clear.')); ?></p>
					<div class="bc-member-invite__grid">
						<div class="bc-entity-picker bc-member-invite__search">
							<label for="bc-group-invite-q" class="bc-sr-only"><?php p($l->t('Search directory for a group to add')); ?></label>
							<input id="bc-group-invite-q" type="search" class="bc-input bc-entity-picker__q" autocomplete="off" maxlength="120" aria-describedby="bc-group-invite-hint" placeholder="<?php p($l->t('Search directory for a group to add')); ?>">
							<div id="bc-group-invite-suggest" class="bc-entity-picker__suggest" hidden aria-live="polite"></div>
						</div>
						<label class="bc-field bc-member-invite__role">
							<span class="bc-field__label"><?php p($l->t('Role for selected group')); ?></span>
							<select id="bc-group-invite-role" class="bc-input" data-bc-group-invite-role>
								<option value="viewer"><?php p($l->t('Viewer')); ?></option>
								<option value="contributor"><?php p($l->t('Contributor')); ?></option>
							</select>
						</label>
						<button type="button" class="button primary bc-member-invite__submit" data-bc-action="group-invite-submit">
							<?php p($l->t('Add group to workspace')); ?>
						</button>
					</div>
					<div class="bc-member-picked" data-bc-group-selected-wrap hidden role="status" aria-live="polite">
						<p class="bc-member-picked__label"><?php p($l->t('Selected group')); ?></p>
						<div class="bc-member-picked__row">
							<div>
								<p class="bc-member-picked__value" data-bc-group-selected></p>
								<p class="bc-member-picked__meta" data-bc-group-selected-role></p>
							</div>
							<button type="button" class="button bc-member-picked__clear" data-bc-action="group-invite-clear"><?php p($l->t('Clear selection')); ?></button>
						</div>
					</div>
				</div>

				<div class="bc-table-scroll" role="region" aria-label="<?php p($l->t('Members')); ?>" tabindex="0">
					<table class="bc-table bc-members-table">
						<thead>
							<tr>
								<th scope="col"><?php p($l->t('Member')); ?></th>
								<th scope="col"><?php p($l->t('Type')); ?></th>
								<th scope="col"><?php p($l->t('Role')); ?></th>
								<th scope="col"><?php p($l->t('Added')); ?></th>
								<th scope="col" class="bc-sr-only"><?php p($l->t('Actions')); ?></th>
							</tr>
						</thead>
						<tbody data-bc-member-rows>
							<tr>
								<td colspan="5" class="bc-loading"><?php p($l->t('Loading…')); ?></td>
							</tr>
						</tbody>
					</table>
				</div>
			</section>

			<section class="bc-card bc-section" aria-labelledby="bc-recurring-title">
				<header class="bc-section__header">
					<div>
						<h2 id="bc-recurring-title"><?php p($l->t('Recurring rules')); ?></h2>
						<p class="bc-section__sub"><?php p($l->t('Repeating income or expenses. Generate creates planned ledger entries; a matching import removes the plan automatically.')); ?></p>
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
								<th scope="col"><?php p($l->t('End date')); ?></th>
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
						<dt><?php p($l->t('Category group')); ?></dt>
						<dd><?php p($l->t('Optional tag shared by a few related categories—for rolled-up totals in transaction analytics and filters. It is not a bank hierarchy; use Notes for vendor or payee names.')); ?></dd>
						<dt><?php p($l->t('Notes')); ?></dt>
						<dd><?php p($l->t('Free text on a transaction, searchable from the transactions page. Ideal for bank vendor names when categories stay broad.')); ?></dd>
						<dt><?php p($l->t('Savings target')); ?></dt>
						<dd><?php p($l->t('The amount you plan to set aside (percentage, fixed amount, or the higher of both).')); ?></dd>
						<dt><?php p($l->t('Savings achieved')); ?></dt>
						<dd><?php p($l->t('When a category is marked as savings transfer, progress uses those bookings. Otherwise it uses income minus everyday expenses.')); ?></dd>
						<dt><?php p($l->t('Savings transfer')); ?></dt>
						<dd><?php p($l->t('Category flag for money moved to savings. Counts toward your savings goal and is excluded from everyday budget saldo, but stays in total expenses.')); ?></dd>
						<dt><?php p($l->t('Planned entry')); ?></dt>
						<dd><?php p($l->t('A placeholder booking from Generate on a recurring rule or from category budget targets. Recurring placeholders match amount; budget placeholders match category (any amount). A real booking in the same or neighbouring month removes the plan.')); ?></dd>
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