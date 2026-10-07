#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Translations for the optional billing period (Abrechnungszeitraum) on
 * project workspaces: two settings labels + hint, plus the bookingDate
 * rejection wording when a billing bound is set.
 *
 * Run:
 *   php scripts/patch-billing-period-l10n.php
 *   php scripts/sync-l10n-keys-from-en.php
 *   php scripts/sync-l10n-variants.php
 *   php scripts/regenerate-l10n-js.php
 */

$base = __DIR__ . '/../l10n';

const KEY_START = 'Billing period start (optional)';
const KEY_END = 'Billing period end (optional)';
const KEY_HINT = 'Bookings must be dated inside the billing period. Leave it empty to use the project period.';
const KEY_WINDOW = 'bookingDate must lie inside the billing period.';
const KEY_FIELD = 'Pick a date inside the billing period, or adjust the billing period in workspace settings.';
const KEY_MONTH = 'This calendar month does not overlap the billing period.';
const KEY_MONTH_FIELD = 'Pick a month that intersects the billing period.';

$patch = [
	'en' => [
		KEY_START => KEY_START,
		KEY_END => KEY_END,
		KEY_HINT => KEY_HINT,
		KEY_WINDOW => KEY_WINDOW,
		KEY_FIELD => KEY_FIELD,
		KEY_MONTH => KEY_MONTH,
		KEY_MONTH_FIELD => KEY_MONTH_FIELD,
	],
	'da' => [
		KEY_START => 'Start for afregningsperiode (valgfri)',
		KEY_END => 'Slut for afregningsperiode (valgfri)',
		KEY_HINT => 'Bogføringer skal være dateret inden for afregningsperioden. Lad den stå tom for at bruge projektperioden.',
		KEY_WINDOW => 'Bogføringsdatoen skal ligge inden for afregningsperioden.',
		KEY_FIELD => 'Vælg en dato inden for afregningsperioden, eller juster afregningsperioden i arbejdsområdets indstillinger.',
		KEY_MONTH => 'Denne kalendermåned overlapper ikke afregningsperioden.',
		KEY_MONTH_FIELD => 'Vælg en måned, der overlapper afregningsperioden.',
	],
	'de' => [
		KEY_START => 'Beginn des Abrechnungszeitraums (optional)',
		KEY_END => 'Ende des Abrechnungszeitraums (optional)',
		KEY_HINT => 'Buchungen müssen innerhalb des Abrechnungszeitraums datiert sein. Lassen Sie die Felder leer, um den Projektzeitraum zu verwenden.',
		KEY_WINDOW => 'Das Buchungsdatum muss innerhalb des Abrechnungszeitraums liegen.',
		KEY_FIELD => 'Wählen Sie ein Datum innerhalb des Abrechnungszeitraums oder passen Sie den Abrechnungszeitraum in den Arbeitsbereichseinstellungen an.',
		KEY_MONTH => 'Dieser Kalendermonat überschneidet sich nicht mit dem Abrechnungszeitraum.',
		KEY_MONTH_FIELD => 'Wählen Sie einen Monat, der sich mit dem Abrechnungszeitraum überschneidet.',
	],
	'es' => [
		KEY_START => 'Inicio del período de facturación (opcional)',
		KEY_END => 'Fin del período de facturación (opcional)',
		KEY_HINT => 'Los registros deben tener una fecha dentro del período de facturación. Déjelo vacío para usar el período del proyecto.',
		KEY_WINDOW => 'La fecha de registro debe estar dentro del período de facturación.',
		KEY_FIELD => 'Elija una fecha dentro del período de facturación o ajuste el período de facturación en los ajustes del espacio de trabajo.',
		KEY_MONTH => 'Este mes natural no se solapa con el período de facturación.',
		KEY_MONTH_FIELD => 'Elija un mes que se solape con el período de facturación.',
	],
	'fr' => [
		KEY_START => 'Début de la période de facturation (facultatif)',
		KEY_END => 'Fin de la période de facturation (facultatif)',
		KEY_HINT => 'Les écritures doivent être datées dans la période de facturation. Laissez vide pour utiliser la période du projet.',
		KEY_WINDOW => 'La date d’écriture doit se situer dans la période de facturation.',
		KEY_FIELD => 'Choisissez une date comprise dans la période de facturation ou ajustez la période de facturation dans les paramètres de l’espace de travail.',
		KEY_MONTH => 'Ce mois civil ne chevauche pas la période de facturation.',
		KEY_MONTH_FIELD => 'Choisissez un mois qui chevauche la période de facturation.',
	],
	'it' => [
		KEY_START => 'Inizio del periodo di fatturazione (facoltativo)',
		KEY_END => 'Fine del periodo di fatturazione (facoltativo)',
		KEY_HINT => 'Le registrazioni devono essere datate entro il periodo di fatturazione. Lasciare vuoto per usare il periodo del progetto.',
		KEY_WINDOW => 'La data di registrazione deve rientrare nel periodo di fatturazione.',
		KEY_FIELD => 'Scelga una data compresa nel periodo di fatturazione o lo modifichi nelle impostazioni dello spazio di lavoro.',
		KEY_MONTH => 'Questo mese di calendario non si sovrappone al periodo di fatturazione.',
		KEY_MONTH_FIELD => 'Scelga un mese che si sovrapponga al periodo di fatturazione.',
	],
	'nb' => [
		KEY_START => 'Start for avregningsperiode (valgfritt)',
		KEY_END => 'Slutt for avregningsperiode (valgfritt)',
		KEY_HINT => 'Bokføringer må være datert innenfor avregningsperioden. La den stå tom for å bruke prosjektperioden.',
		KEY_WINDOW => 'Bokføringsdatoen må ligge innenfor avregningsperioden.',
		KEY_FIELD => 'Velg en dato innenfor avregningsperioden, eller juster avregningsperioden i innstillingene for arbeidsområdet.',
		KEY_MONTH => 'Denne kalendermåneden overlapper ikke avregningsperioden.',
		KEY_MONTH_FIELD => 'Velg en måned som overlapper avregningsperioden.',
	],
	'nl' => [
		KEY_START => 'Begin van de afrekenperiode (optioneel)',
		KEY_END => 'Einde van de afrekenperiode (optioneel)',
		KEY_HINT => 'Boekingen moeten binnen de afrekenperiode vallen. Laat leeg om de projectperiode te gebruiken.',
		KEY_WINDOW => 'De boekingsdatum moet binnen de afrekenperiode liggen.',
		KEY_FIELD => 'Kies een datum binnen de afrekenperiode of pas de afrekenperiode aan in de werkruimte-instellingen.',
		KEY_MONTH => 'Deze kalendermaand overlapt niet met de afrekenperiode.',
		KEY_MONTH_FIELD => 'Kies een maand die de afrekenperiode overlapt.',
	],
	'pl' => [
		KEY_START => 'Początek okresu rozliczeniowego (opcjonalnie)',
		KEY_END => 'Koniec okresu rozliczeniowego (opcjonalnie)',
		KEY_HINT => 'Księgowania muszą mieć datę w okresie rozliczeniowym. Pozostaw puste, aby użyć okresu projektu.',
		KEY_WINDOW => 'Data księgowania musi mieścić się w okresie rozliczeniowym.',
		KEY_FIELD => 'Wybierz datę mieszczącą się w okresie rozliczeniowym lub dostosuj okres rozliczeniowy w ustawieniach obszaru roboczego.',
		KEY_MONTH => 'Ten miesiąc kalendarzowy nie pokrywa się z okresem rozliczeniowym.',
		KEY_MONTH_FIELD => 'Wybierz miesiąc pokrywający się z okresem rozliczeniowym.',
	],
	'pt_BR' => [
		KEY_START => 'Início do período de faturamento (opcional)',
		KEY_END => 'Fim do período de faturamento (opcional)',
		KEY_HINT => 'Os lançamentos devem ter data dentro do período de faturamento. Deixe vazio para usar o período do projeto.',
		KEY_WINDOW => 'A data de lançamento deve estar dentro do período de faturamento.',
		KEY_FIELD => 'Escolha uma data dentro do período de faturamento ou ajuste o período de faturamento nas configurações do espaço de trabalho.',
		KEY_MONTH => 'Este mês não se sobrepõe ao período de faturamento.',
		KEY_MONTH_FIELD => 'Escolha um mês que se sobreponha ao período de faturamento.',
	],
	'sv' => [
		KEY_START => 'Start för avräkningsperiod (valfritt)',
		KEY_END => 'Slut för avräkningsperiod (valfritt)',
		KEY_HINT => 'Bokföringar måste dateras inom avräkningsperioden. Lämna tomt för att använda projektperioden.',
		KEY_WINDOW => 'Bokföringsdatumet måste ligga inom avräkningsperioden.',
		KEY_FIELD => 'Välj ett datum inom avräkningsperioden, eller justera avräkningsperioden i arbetsytans inställningar.',
		KEY_MONTH => 'Den här kalendermånaden överlappar inte avräkningsperioden.',
		KEY_MONTH_FIELD => 'Välj en månad som överlappar avräkningsperioden.',
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
