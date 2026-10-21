#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Translations for the date-picker button aria-label: `common/check-dates`
 * decorates every input[type="date"] with an explicit labelled picker trigger
 * (`.bc-date-picker`, aria-label "Choose date"). The msgid was never added to
 * the catalogs, so non-English locales exposed the English fallback to screen
 * readers.
 *
 * Run:
 *   php scripts/patch-date-picker-label-l10n.php
 *   php scripts/sync-l10n-keys-from-en.php
 *   php scripts/sync-l10n-variants.php
 *   php scripts/regenerate-l10n-js.php
 */

$base = __DIR__ . '/../l10n';

const KEY_LABEL = 'Choose date';

$patch = [
	'en' => 'Choose date',
	'da' => 'Vælg dato',
	'de' => 'Datum wählen',
	'es' => 'Elegir fecha',
	'fr' => 'Choisir une date',
	'it' => 'Scegli data',
	'nb' => 'Velg dato',
	'nl' => 'Datum kiezen',
	'pl' => 'Wybierz datę',
	'pt' => 'Escolha a data',
	'pt_BR' => 'Escolher data',
	'sv' => 'Välj datum',
	'zh_CN' => '选择日期',
];

foreach ($patch as $locale => $value) {
	$file = $base . '/' . $locale . '.json';
	$data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
	$data['translations'][KEY_LABEL] = $value;
	file_put_contents(
		$file,
		json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
	);
	fwrite(STDOUT, $locale . "\n");
}
