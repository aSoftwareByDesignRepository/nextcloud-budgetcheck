<?php
/**
 * Searchable IANA timezone picker shell (hydrated by catalog-pickers.js).
 *
 * @var \OCP\IL10N $l
 * @var string $pickerId id prefix (e.g. bc-ws-timezone)
 * @var string $pickerName form field name on the hidden native select
 * @var string $pickerDefault default IANA zone
 * @var bool $pickerDisabled
 * @var string|null $pickerDescribedBy space-separated ids for aria-describedby
 */
$pickerId = (string) ($pickerId ?? 'bc-timezone');
$pickerName = (string) ($pickerName ?? 'timezone');
$pickerDefault = (string) ($pickerDefault ?? 'Europe/Berlin');
$pickerDisabled = !empty($pickerDisabled);
$pickerDescribedBy = isset($pickerDescribedBy) ? (string) $pickerDescribedBy : '';
$inputId = $pickerId . '-input';
$resultsId = $pickerId . '-results';
$statusId = $pickerId . '-status';
$labelId = $pickerId . '-label';
?>
<div class="bc-catalog-picker bc-catalog-picker--timezone" data-bc-timezone-picker data-default-timezone="<?php p($pickerDefault); ?>">
	<select id="<?php p($pickerId); ?>" name="<?php p($pickerName); ?>" class="bc-catalog-picker__native" tabindex="-1" aria-hidden="true" required<?php if ($pickerDisabled): ?> disabled<?php endif; ?>></select>
	<div class="bc-catalog-picker__control">
		<input
			type="search"
			id="<?php p($inputId); ?>"
			class="bc-input bc-catalog-picker__input"
			role="combobox"
			aria-autocomplete="list"
			aria-expanded="false"
			aria-controls="<?php p($resultsId); ?>"
			aria-labelledby="<?php p($labelId); ?>"
			<?php if ($pickerDescribedBy !== ''): ?>aria-describedby="<?php p($pickerDescribedBy); ?>" <?php endif; ?>
			autocomplete="off"
			spellcheck="false"
			inputmode="search"
			placeholder="<?php p($l->t('Search timezones (e.g. Europe/Berlin or Moscow)')); ?>"
			<?php if ($pickerDisabled): ?>disabled<?php endif; ?>
		>
		<button type="button" class="bc-catalog-picker__clear button" hidden
			aria-label="<?php p($l->t('Clear timezone selection')); ?>"
			<?php if ($pickerDisabled): ?>disabled<?php endif; ?>>×</button>
	</div>
	<ul id="<?php p($resultsId); ?>" class="bc-catalog-picker__results" role="listbox" hidden></ul>
	<p id="<?php p($statusId); ?>" class="bc-catalog-picker__status" role="status" aria-live="polite" aria-atomic="true" hidden></p>
</div>
