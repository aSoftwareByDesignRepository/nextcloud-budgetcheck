#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Translation for the read-only empty state of the attachments grid.
 *
 * Run:
 *   php scripts/patch-attachment-empty-l10n.php
 *   php scripts/sync-l10n-keys-from-en.php
 *   php scripts/sync-l10n-variants.php
 *   php scripts/regenerate-l10n-js.php
 */

$base = __DIR__ . '/../l10n';

const KEY_EMPTY_RO = 'No receipts attached.';

$patch = [
	'en' => [KEY_EMPTY_RO => KEY_EMPTY_RO],
	'da' => [KEY_EMPTY_RO => 'Ingen kvitteringer vedhæftet.'],
	'de' => [KEY_EMPTY_RO => 'Keine Belege angehängt.'],
	'es' => [KEY_EMPTY_RO => 'No hay recibos adjuntos.'],
	'fr' => [KEY_EMPTY_RO => 'Aucun justificatif joint.'],
	'it' => [KEY_EMPTY_RO => 'Nessuna ricevuta allegata.'],
	'nb' => [KEY_EMPTY_RO => 'Ingen kvitteringer vedlagt.'],
	'nl' => [KEY_EMPTY_RO => 'Geen bonnen bijgevoegd.'],
	'pl' => [KEY_EMPTY_RO => 'Brak załączonych paragonów.'],
	'sv' => [KEY_EMPTY_RO => 'Inga kvitton bifogade.'],
];

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
