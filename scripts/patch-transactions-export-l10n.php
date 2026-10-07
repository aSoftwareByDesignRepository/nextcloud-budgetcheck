#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Translations for the transactions ledger export (CSV / ODS) on the
 * transactions page: two button labels plus the scope hint.
 *
 * Run:
 *   php scripts/patch-transactions-export-l10n.php
 *   php scripts/sync-l10n-keys-from-en.php
 *   php scripts/sync-l10n-variants.php
 *   php scripts/regenerate-l10n-js.php
 */

$base = __DIR__ . '/../l10n';

const KEY_CSV = 'Export CSV';
const KEY_ODS = 'Export ODS';
const KEY_HINT = 'Downloads every booking detail for the transactions your filters currently match — set Range to “All time” for the complete ledger.';

$patch = [
	'en' => [
		KEY_CSV => KEY_CSV,
		KEY_ODS => KEY_ODS,
		KEY_HINT => KEY_HINT,
	],
	'da' => [
		KEY_CSV => 'Eksportér CSV',
		KEY_ODS => 'Eksportér ODS',
		KEY_HINT => 'Downloader alle bogføringsdetaljer for de transaktioner, dine filtre aktuelt matcher — sæt Interval til »Hele perioden« for den komplette kladde.',
	],
	'de' => [
		KEY_CSV => 'CSV exportieren',
		KEY_ODS => 'ODS exportieren',
		KEY_HINT => 'Lädt alle Buchungsdetails für die Buchungen herunter, auf die Ihre Filter aktuell zutreffen — wählen Sie als Zeitraum „Gesamtlaufzeit“ für das vollständige Journal.',
	],
	'es' => [
		KEY_CSV => 'Exportar CSV',
		KEY_ODS => 'Exportar ODS',
		KEY_HINT => 'Descarga todos los detalles de los apuntes que coinciden con tus filtros actuales — elige «Todo el tiempo» en Rango para el libro completo.',
	],
	'fr' => [
		KEY_CSV => 'Exporter en CSV',
		KEY_ODS => 'Exporter en ODS',
		KEY_HINT => 'Télécharge tous les détails des écritures correspondant à vos filtres actuels — choisissez « Tout le temps » dans Période pour le journal complet.',
	],
	'it' => [
		KEY_CSV => 'Esporta CSV',
		KEY_ODS => 'Esporta ODS',
		KEY_HINT => 'Scarica tutti i dettagli delle registrazioni che corrispondono ai filtri attuali — imposta Intervallo su «Sempre» per il registro completo.',
	],
	'nb' => [
		KEY_CSV => 'Eksporter CSV',
		KEY_ODS => 'Eksporter ODS',
		KEY_HINT => 'Laster ned alle bookedetaljer for transaksjonene filtrene dine treffer akkurat nå — sett Periode til «Hele perioden» for hele journalen.',
	],
	'nl' => [
		KEY_CSV => 'CSV exporteren',
		KEY_ODS => 'ODS exporteren',
		KEY_HINT => 'Downloadt alle boekingsdetails van de transacties die je filters momenteel treffen — zet Bereik op „Altijd” voor het complete journaal.',
	],
	'pl' => [
		KEY_CSV => 'Eksportuj CSV',
		KEY_ODS => 'Eksportuj ODS',
		KEY_HINT => 'Pobiera wszystkie szczegóły księgowań pasujących do bieżących filtrów — ustaw Zakres na „Cały okres”, aby pobrać pełny dziennik.',
	],
	'pt_BR' => [
		KEY_CSV => 'Exportar CSV',
		KEY_ODS => 'Exportar ODS',
		KEY_HINT => 'Baixa todos os detalhes dos lançamentos que seus filtros atuais encontram — defina Faixa como «Todo o tempo» para o livro completo.',
	],
	'sv' => [
		KEY_CSV => 'Exportera CSV',
		KEY_ODS => 'Exportera ODS',
		KEY_HINT => 'Laddar ner alla bokföringsdetaljer för transaktionerna som dina filter just nu träffar — sätt Intervall till »Hela perioden« för hela journalen.',
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
