<?php
/**
 * Workspace settings sub-page: Workspace details (household + project branches).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @var string $bcHtmlLang
 */

$workspace = $_['workspace'] ?? null;
$canManage = !empty($_['canManageWorkspace']);
$canManagePrivacy = array_key_exists('canManagePrivacy', $_)
	? (bool) $_['canManagePrivacy']
	: $canManage;
$currencyChangeAllowed = array_key_exists('currencyChangeAllowed', $_)
	? (bool) $_['currencyChangeAllowed']
	: true;
if ($workspace === null) {
	return;
}
$bcHtmlLang = $bcHtmlLang ?? (string)(($_['clientHints']['htmlLang'] ?? null) ?: 'en');
?>
<section class="bc-card bc-section" aria-labelledby="bc-ws-meta-title">
	<header class="bc-section__header">
		<div>
			<h2 id="bc-ws-meta-title" class="bc-sr-only"><?php p($l->t('Workspace details')); ?></h2>
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
			include __DIR__ . '/../../common/bc-currency-picker.php';
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
			include __DIR__ . '/../../common/bc-timezone-picker.php';
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
				<?php
				$bcPlanningViewUrl = (string) (($_['urls']['settingsSections'] ?? [])['planning-view'] ?? '');
				?>
				<p class="bc-field__hint bc-field__hint--block" id="bc-summary-default-hint">
					<?php p($l->t('Members who have not set a personal preference inherit this default. Each member can change their own choice on')); ?>
					<?php if ($bcPlanningViewUrl !== '' && $bcPlanningViewUrl !== '#'): ?>
						<a class="bc-inline-link" href="<?php p($bcPlanningViewUrl); ?>"><?php p($l->t('Your planning view')); ?></a>.
					<?php else: ?>
						<?php p($l->t('Your planning view')); ?>.
					<?php endif; ?>
				</p>
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
					<input type="radio" name="defaultSavingsTargetMode" value="" <?php p($canManage ? '' : 'disabled'); ?>>
					<span><?php p($l->t('No target set')); ?></span>
				</label>
				<label class="bc-field bc-field--radio">
					<input type="radio" name="defaultSavingsTargetMode" value="percentage" <?php p($canManage ? '' : 'disabled'); ?>>
					<span><?php p($l->t('Percentage of income')); ?></span>
				</label>
				<label class="bc-field bc-field--radio">
					<input type="radio" name="defaultSavingsTargetMode" value="absolute" <?php p($canManage ? '' : 'disabled'); ?>>
					<span><?php p($l->t('Absolute amount')); ?></span>
				</label>
				<label class="bc-field bc-field--radio">
					<input type="radio" name="defaultSavingsTargetMode" value="hybrid" <?php p($canManage ? '' : 'disabled'); ?>>
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
		<fieldset class="bc-fieldset bc-fieldset--mode-group bc-field--full-width bc-privacy-fieldset" data-bc-privacy-fieldset>
			<legend class="bc-fieldset__legend"><?php p($l->t('Who can see this workspace')); ?></legend>
			<div class="bc-callout bc-callout--info bc-privacy-disclosure" role="note" id="bc-privacy-disclosure">
				<p><?php p($l->t('Private means only people you add as members can open this workspace in BudgetCheck. Nextcloud and BudgetCheck administrators who are not members cannot see it in the app.')); ?></p>
				<p class="bc-callout__hint"><?php p($l->t('People with direct database or server access may still read stored data. This is not end-to-end encryption.')); ?></p>
			</div>
			<label class="bc-field bc-field--radio">
				<input type="radio" name="privacyMode" value="standard" data-bc-privacy-mode <?php p($canManagePrivacy ? '' : 'disabled'); ?> aria-describedby="bc-privacy-disclosure bc-privacy-standard-hint">
				<span>
					<strong><?php p($l->t('Standard')); ?></strong>
					<span class="bc-field__hint bc-field__hint--block" id="bc-privacy-standard-hint"><?php p($l->t('App administrators can see and manage this workspace for recovery.')); ?></span>
				</span>
			</label>
			<label class="bc-field bc-field--radio">
				<input type="radio" name="privacyMode" value="private" data-bc-privacy-mode <?php p($canManagePrivacy ? '' : 'disabled'); ?> aria-describedby="bc-privacy-disclosure bc-privacy-private-hint">
				<span>
					<strong><?php p($l->t('Private')); ?></strong>
					<span class="bc-field__hint bc-field__hint--block" id="bc-privacy-private-hint"><?php p($l->t('Only members you add. Remove any groups before switching. One manager is enough; if that sole manager account is removed, recovery needs database access.')); ?></span>
				</span>
			</label>
			<?php if ($canManage && !$canManagePrivacy): ?>
				<p class="bc-field__hint bc-field__hint--block" id="bc-privacy-manager-only" role="status">
					<?php p($l->t('Only people added as individual managers can change privacy. App-admin access alone is not enough.')); ?>
				</p>
			<?php endif; ?>
		</fieldset>
		<?php if ($canManage): ?>
			<div class="bc-form-actions">
				<button type="submit" class="button primary"><?php p($l->t('Save workspace')); ?></button>
			</div>
		<?php endif; ?>
	</form>
</section>
