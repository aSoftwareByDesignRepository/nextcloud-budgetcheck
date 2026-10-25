<?php
/**
 * Workspace settings sub-page: Notifications (per-user alert preferences).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
?>
<section class="bc-card bc-section" aria-labelledby="bc-alerts-title">
	<header class="bc-section__header">
		<div>
			<h2 id="bc-alerts-title" class="bc-sr-only"><?php p($l->t('Notifications')); ?></h2>
			<p class="bc-section__sub"><?php p($l->t('Choose which BudgetCheck alerts reach your Nextcloud notifications. These settings are yours only — other members pick their own.')); ?></p>
		</div>
	</header>
	<form data-bc-alert-prefs-form novalidate>
		<fieldset class="bc-fieldset">
			<div class="bc-field bc-field--checkbox">
				<input type="hidden" name="alertsEnabled" value="0">
				<input type="checkbox" id="bc-alerts-master" name="alertsEnabled" value="1" data-bc-alert-master>
				<label for="bc-alerts-master"><?php p($l->t('Enable BudgetCheck alerts')); ?></label>
			</div>
			<p class="bc-section__control-hint"><?php p($l->t('The master switch stops every alert type at once.')); ?></p>
		</fieldset>

		<fieldset class="bc-fieldset" data-bc-alert-types>
			<legend class="bc-field__label"><?php p($l->t('Alert types')); ?></legend>
			<div class="bc-field bc-field--checkbox">
				<input type="hidden" name="typeBudget" value="0">
				<input type="checkbox" id="bc-alerts-type-budget" name="typeBudget" value="1" checked>
				<label for="bc-alerts-type-budget"><?php p($l->t('Budget threshold')); ?></label>
			</div>
			<div class="bc-field bc-field--checkbox">
				<input type="hidden" name="typeRecurring" value="0">
				<input type="checkbox" id="bc-alerts-type-recurring" name="typeRecurring" value="1" checked>
				<label for="bc-alerts-type-recurring"><?php p($l->t('Recurring bookings posted')); ?></label>
			</div>
			<div class="bc-field bc-field--checkbox">
				<input type="hidden" name="typeDigest" value="0">
				<input type="checkbox" id="bc-alerts-type-digest" name="typeDigest" value="1" checked>
				<label for="bc-alerts-type-digest"><?php p($l->t('Monthly digest')); ?></label>
			</div>
			<div class="bc-field bc-field--checkbox">
				<input type="hidden" name="typeSavings" value="0">
				<input type="checkbox" id="bc-alerts-type-savings" name="typeSavings" value="1" checked>
				<label for="bc-alerts-type-savings"><?php p($l->t('Savings goal status')); ?></label>
			</div>
		</fieldset>

		<fieldset class="bc-fieldset">
			<div class="bc-field bc-field--inline">
				<label class="bc-field__label" for="bc-alerts-threshold"><?php p($l->t('Budget alert threshold (%%)')); ?></label>
				<input class="bc-input" type="number" id="bc-alerts-threshold" name="thresholdPercent" min="50" max="100" step="1" value="80" data-bc-alert-threshold>
				<p class="bc-field-error" data-bc-field-error="thresholdPercent" hidden></p>
			</div>
			<p class="bc-section__control-hint"><?php p($l->t('Alert when a category budget reaches this percent — and again at 100%%.')); ?></p>
			<div class="bc-field bc-field--inline">
				<label class="bc-field__label" for="bc-alerts-quiet-start"><?php p($l->t('Quiet hours')); ?></label>
				<input class="bc-input" type="time" id="bc-alerts-quiet-start" name="quietStart">
				<span aria-hidden="true">–</span>
				<input class="bc-input" type="time" id="bc-alerts-quiet-end" name="quietEnd" aria-label="<?php p($l->t('Quiet hours end')); ?>">
			</div>
			<p class="bc-section__control-hint"><?php p($l->t('Optional: suppress alerts between these times (your local time).')); ?></p>
		</fieldset>

		<fieldset class="bc-fieldset">
			<div class="bc-field bc-field--checkbox">
				<input type="hidden" name="emailEnabled" value="0">
				<input type="checkbox" id="bc-alerts-email" name="emailEnabled" value="1">
				<label for="bc-alerts-email"><?php p($l->t('Also email me')); ?></label>
			</div>
			<p class="bc-section__control-hint"><?php p($l->t('Sends the alert to the email address on your Nextcloud account. Requires a mail server to be configured.')); ?></p>
		</fieldset>

		<fieldset class="bc-fieldset">
			<div class="bc-field bc-field--checkbox">
				<input type="hidden" name="dashboardWidgetEnabled" value="0">
				<input type="checkbox" id="bc-alerts-widget" name="dashboardWidgetEnabled" value="1" checked>
				<label for="bc-alerts-widget"><?php p($l->t('Show my numbers on the Nextcloud dashboard')); ?></label>
			</div>
			<p class="bc-section__control-hint"><?php p($l->t('Turn off to hide balances on the shared dashboard — for example when presenting.')); ?></p>
		</fieldset>

		<div class="bc-form-actions">
			<button type="submit" class="button primary" data-bc-alert-save><?php p($l->t('Save notification settings')); ?></button>
		</div>
	</form>
</section>
