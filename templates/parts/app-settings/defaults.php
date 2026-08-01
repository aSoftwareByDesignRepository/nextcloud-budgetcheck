<?php
/**
 * App settings sub-page: Defaults for new workspaces.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
?>
<section class="bc-card bc-section" aria-labelledby="bc-policy-defaults-legend">
	<form class="bc-form-grid" data-bc-app-policy-form data-bc-app-policy-scope="defaults">
		<fieldset class="bc-fieldset bc-fieldset--defaults">
			<legend id="bc-policy-defaults-legend" class="bc-fieldset__legend"><?php p($l->t('Defaults for new workspaces')); ?></legend>
			<p class="bc-field__hint bc-field__hint--block"><?php p($l->t('These values pre-fill the form when an app administrator creates a workspace. They do not change existing workspaces.')); ?></p>
			<div class="bc-field bc-field--catalog">
				<span class="bc-field__label" id="bc-policy-timezone-label"><?php p($l->t('Default timezone')); ?></span>
				<?php
				$pickerId = 'bc-policy-timezone';
				$pickerName = 'defaultTimezone';
				$pickerDefault = 'Europe/Berlin';
				$pickerDisabled = false;
				$pickerDescribedBy = 'bc-policy-timezone-hint';
				include dirname(__DIR__, 2) . '/common/bc-timezone-picker.php';
				?>
				<p id="bc-policy-timezone-hint" class="bc-field__hint"><?php p($l->t('Pre-selected when someone creates a new workspace.')); ?></p>
			</div>
			<div class="bc-field bc-field--catalog">
				<span class="bc-field__label" id="bc-policy-currency-label"><?php p($l->t('Default currency')); ?></span>
				<?php
				$pickerId = 'bc-policy-currency';
				$pickerName = 'defaultCurrency';
				$pickerDefault = 'EUR';
				$pickerDisabled = false;
				$pickerDescribedBy = 'bc-policy-currency-hint';
				include dirname(__DIR__, 2) . '/common/bc-currency-picker.php';
				?>
				<p id="bc-policy-currency-hint" class="bc-field__hint"><?php p($l->t('Pre-selected when someone creates a new workspace.')); ?></p>
			</div>
		</fieldset>
		<div class="bc-form-actions">
			<button type="submit" class="button primary"><?php p($l->t('Save app policy')); ?></button>
		</div>
	</form>
</section>
