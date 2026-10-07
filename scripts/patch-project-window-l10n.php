#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Translations for the project-window recovery fix: the bookingDate-outside-
 * project-window rejection now carries a `fields.bookingDate` hint (rendered
 * inline next to the date control via CheckFieldErrors) plus the existing
 * top-level message, which is shown as toast and previously was not a msgid
 * at all.
 *
 * Run:
 *   php scripts/patch-project-window-l10n.php
 *   php scripts/sync-l10n-keys-from-en.php
 *   php scripts/sync-l10n-variants.php
 *   php scripts/regenerate-l10n-js.php
 */

$base = __DIR__ . '/../l10n';

const KEY_WINDOW = 'bookingDate must lie inside the project date window.';
const KEY_HINT = 'Pick a date inside the project period, or extend the project period in workspace settings.';

$patch = [
	'en' => [
		KEY_WINDOW => KEY_WINDOW,
		KEY_HINT => KEY_HINT,
	],
	'da' => [
		KEY_WINDOW => 'Bogføringsdatoen skal ligge inden for projektperioden.',
		KEY_HINT => 'Vælg en dato inden for projektperioden, eller forlæng projektperioden i arbejdsområdets indstillinger.',
	],
	'de' => [
		KEY_WINDOW => 'Das Buchungsdatum muss innerhalb des Projektzeitraums liegen.',
		KEY_HINT => 'Wählen Sie ein Datum innerhalb des Projektzeitraums oder verlängern Sie den Projektzeitraum in den Arbeitsbereichseinstellungen.',
	],
	'es' => [
		KEY_WINDOW => 'La fecha de registro debe estar dentro del período del proyecto.',
		KEY_HINT => 'Elija una fecha dentro del período del proyecto o amplíe el período del proyecto en los ajustes del espacio de trabajo.',
	],
	'fr' => [
		KEY_WINDOW => 'La date d’écriture doit se situer dans la période du projet.',
		KEY_HINT => 'Choisissez une date comprise dans la période du projet ou prolongez la période du projet dans les paramètres de l’espace de travail.',
	],
	'it' => [
		KEY_WINDOW => 'La data di registrazione deve rientrare nel periodo del progetto.',
		KEY_HINT => 'Scelga una data compresa nel periodo del progetto o estenda il periodo del progetto nelle impostazioni dello spazio di lavoro.',
	],
	'nb' => [
		KEY_WINDOW => 'Bokføringsdatoen må ligge innenfor prosjektperioden.',
		KEY_HINT => 'Velg en dato innenfor prosjektperioden, eller forleng prosjektperioden i innstillingene for arbeidsområdet.',
	],
	'nl' => [
		KEY_WINDOW => 'De boekingsdatum moet binnen de projectperiode liggen.',
		KEY_HINT => 'Kies een datum binnen de projectperiode of verleng de projectperiode in de werkruimte-instellingen.',
	],
	'pl' => [
		KEY_WINDOW => 'Data księgowania musi mieścić się w okresie projektu.',
		KEY_HINT => 'Wybierz datę mieszczącą się w okresie projektu lub wydłuż okres projektu w ustawieniach obszaru roboczego.',
	],
	'pt_BR' => [
		KEY_WINDOW => 'A data de lançamento deve estar dentro do período do projeto.',
		KEY_HINT => 'Escolha uma data dentro do período do projeto ou estenda o período do projeto nas configurações do espaço de trabalho.',
	],
	'sv' => [
		KEY_WINDOW => 'Bokföringsdatumet måste ligga inom projektperioden.',
		KEY_HINT => 'Välj ett datum inom projektperioden eller förläng projektperioden i arbetsytans inställningar.',
	],
];

foreach ($patch as $locale => $entries) {
	$file = $base . '/' . $locale . '.json';
	$data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
	foreach ($entries as $key => $value) {
		$data['translations'][$key] = $value;
	}
	file_put_contents(
		$file,
		json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
	);
	fwrite(STDOUT, $locale . ': +' . count($entries) . " strings\n");
}
