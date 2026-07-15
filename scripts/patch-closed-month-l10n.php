#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Translations for the closed-month write-lock messages (issue #12 hardening).
 * Also migrates the reworded planned-forecast hint in nb.json to its new key.
 *
 * Run:
 *   php scripts/patch-closed-month-l10n.php
 *   php scripts/sync-l10n-keys-from-en.php
 *   php scripts/sync-l10n-variants.php
 *   php scripts/regenerate-l10n-js.php
 */

$base = __DIR__ . '/../l10n';

const KEY_CREATE = 'This booking falls into a closed month. Reopen the month before adding transactions.';
const KEY_MOVE = 'The new booking date falls into a closed month. Reopen that month first.';
const KEY_EDIT = 'This transaction belongs to a closed month. Reopen the month before editing.';
const KEY_BUDGET = 'Month is closed. Reopen it before changing budget targets.';

$patch = [
	'en' => [
		KEY_CREATE => KEY_CREATE,
		KEY_MOVE => KEY_MOVE,
		KEY_EDIT => KEY_EDIT,
		KEY_BUDGET => KEY_BUDGET,
	],
	'da' => [
		KEY_CREATE => 'Denne postering falder i en lukket måned. Genåbn måneden, før du tilføjer posteringer.',
		KEY_MOVE => 'Den nye posteringsdato falder i en lukket måned. Genåbn den måned først.',
		KEY_EDIT => 'Denne postering hører til en lukket måned. Genåbn måneden, før du redigerer.',
		KEY_BUDGET => 'Måneden er lukket. Genåbn den, før du ændrer budgetmål.',
	],
	'de' => [
		KEY_CREATE => 'Diese Buchung fällt in einen abgeschlossenen Monat. Öffne den Monat wieder, bevor du Buchungen hinzufügst.',
		KEY_MOVE => 'Das neue Buchungsdatum fällt in einen abgeschlossenen Monat. Öffne diesen Monat zuerst wieder.',
		KEY_EDIT => 'Diese Buchung gehört zu einem abgeschlossenen Monat. Öffne den Monat wieder, bevor du sie bearbeitest.',
		KEY_BUDGET => 'Der Monat ist abgeschlossen. Öffne ihn wieder, bevor du Budgetziele änderst.',
	],
	'es' => [
		KEY_CREATE => 'Este movimiento cae en un mes cerrado. Reabre el mes antes de añadir movimientos.',
		KEY_MOVE => 'La nueva fecha del movimiento cae en un mes cerrado. Reabre ese mes primero.',
		KEY_EDIT => 'Este movimiento pertenece a un mes cerrado. Reabre el mes antes de editar.',
		KEY_BUDGET => 'El mes está cerrado. Reábrelo antes de cambiar los objetivos de presupuesto.',
	],
	'fr' => [
		KEY_CREATE => 'Cette écriture tombe dans un mois clôturé. Rouvrez le mois avant d\'ajouter des écritures.',
		KEY_MOVE => 'La nouvelle date d\'écriture tombe dans un mois clôturé. Rouvrez d\'abord ce mois.',
		KEY_EDIT => 'Cette écriture appartient à un mois clôturé. Rouvrez le mois avant de la modifier.',
		KEY_BUDGET => 'Le mois est clôturé. Rouvrez-le avant de modifier les objectifs budgétaires.',
	],
	'it' => [
		KEY_CREATE => 'Questa registrazione ricade in un mese chiuso. Riapri il mese prima di aggiungere movimenti.',
		KEY_MOVE => 'La nuova data di registrazione ricade in un mese chiuso. Riapri prima quel mese.',
		KEY_EDIT => 'Questo movimento appartiene a un mese chiuso. Riapri il mese prima di modificarlo.',
		KEY_BUDGET => 'Il mese è chiuso. Riaprilo prima di modificare gli obiettivi di budget.',
	],
	'nb' => [
		KEY_CREATE => 'Denne posteringen havner i en lukket måned. Gjenåpne måneden før du legger til posteringer.',
		KEY_MOVE => 'Den nye posteringsdatoen havner i en lukket måned. Gjenåpne den måneden først.',
		KEY_EDIT => 'Denne posteringen tilhører en lukket måned. Gjenåpne måneden før du redigerer.',
		KEY_BUDGET => 'Måneden er lukket. Gjenåpne den før du endrer budsjettmål.',
	],
	'nl' => [
		KEY_CREATE => 'Deze boeking valt in een afgesloten maand. Heropen de maand voordat je boekingen toevoegt.',
		KEY_MOVE => 'De nieuwe boekingsdatum valt in een afgesloten maand. Heropen die maand eerst.',
		KEY_EDIT => 'Deze transactie hoort bij een afgesloten maand. Heropen de maand voordat je bewerkt.',
		KEY_BUDGET => 'De maand is afgesloten. Heropen deze voordat je budgetdoelen wijzigt.',
	],
	'pl' => [
		KEY_CREATE => 'Ta pozycja przypada na zamknięty miesiąc. Otwórz ponownie miesiąc przed dodaniem transakcji.',
		KEY_MOVE => 'Nowa data księgowania przypada na zamknięty miesiąc. Najpierw otwórz ponownie ten miesiąc.',
		KEY_EDIT => 'Ta transakcja należy do zamkniętego miesiąca. Otwórz ponownie miesiąc przed edycją.',
		KEY_BUDGET => 'Miesiąc jest zamknięty. Otwórz go ponownie przed zmianą celów budżetowych.',
	],
	'sv' => [
		KEY_CREATE => 'Den här bokningen hamnar i en stängd månad. Öppna månaden igen innan du lägger till transaktioner.',
		KEY_MOVE => 'Det nya bokningsdatumet hamnar i en stängd månad. Öppna den månaden igen först.',
		KEY_EDIT => 'Den här transaktionen tillhör en stängd månad. Öppna månaden igen innan du redigerar.',
		KEY_BUDGET => 'Månaden är stängd. Öppna den igen innan du ändrar budgetmål.',
	],
];

// nb.json still carries the pre-1.0.23 wording of the planned-forecast hint;
// move the translation onto the current key so sync-l10n-keys can drop the
// stale one without losing the Norwegian text.
$nbStaleKey = 'Budget targets and ledger placeholders for the month. These are not included in actual cash flow above—they show what you expect before real bookings arrive.';
$nbCurrentKey = 'Budget targets and planned ledger placeholders for the month. These are not included in actual cash flow above—they show what you expect before real bookings arrive.';

function loadCatalog(string $path): array
{
	return json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

function saveCatalog(string $path, array $data): void
{
	$encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	if ($encoded === false) {
		fwrite(STDERR, "Failed to encode {$path}\n");
		exit(1);
	}
	file_put_contents($path, $encoded . "\n");
}

$nbPath = $base . '/nb.json';
$nb = loadCatalog($nbPath);
if (isset($nb['translations'][$nbStaleKey])) {
	$staleValue = (string)$nb['translations'][$nbStaleKey];
	$currentValue = (string)($nb['translations'][$nbCurrentKey] ?? '');
	if ($currentValue === '' || $currentValue === $nbCurrentKey) {
		$nb['translations'][$nbCurrentKey] = $staleValue;
	}
	unset($nb['translations'][$nbStaleKey]);
	saveCatalog($nbPath, $nb);
	echo "nb.json: migrated stale planned-forecast key.\n";
}

foreach ($patch as $lang => $entries) {
	$path = $base . '/' . $lang . '.json';
	$data = loadCatalog($path);
	$updated = 0;
	foreach ($entries as $key => $value) {
		if (($data['translations'][$key] ?? null) !== $value) {
			$data['translations'][$key] = $value;
			$updated++;
		}
	}
	if ($updated > 0) {
		saveCatalog($path, $data);
	}
	echo sprintf("%s.json: %d key(s) written.\n", $lang, $updated);
}

echo "Done. Now run sync-l10n-keys-from-en.php, sync-l10n-variants.php, regenerate-l10n-js.php.\n";
