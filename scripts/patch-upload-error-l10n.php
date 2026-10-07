#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Translations for the actionable upload-error strings added in the
 * attachment-upload diagnostics fix: UPLOAD_ERR_* codes and dropped
 * request bodies (post_max_size) now surface their real reason instead
 * of an unmapped generic toast; HTTP 413 shares the size message.
 *
 * Run:
 *   php scripts/patch-upload-error-l10n.php
 *   php scripts/sync-l10n-keys-from-en.php
 *   php scripts/sync-l10n-variants.php
 *   php scripts/regenerate-l10n-js.php
 */

$base = __DIR__ . '/../l10n';

const KEY_LIMIT = 'The file exceeds the maximum upload size configured on this server.';
const KEY_PARTIAL = 'The upload was interrupted before it completed. Please try again.';
const KEY_NOFILE = 'No file was uploaded.';

$patch = [
	'en' => [
		KEY_LIMIT => KEY_LIMIT,
		KEY_PARTIAL => KEY_PARTIAL,
		KEY_NOFILE => KEY_NOFILE,
	],
	'da' => [
		KEY_LIMIT => 'Filen overskrider den maksimale uploadstørrelse, der er konfigureret på denne server.',
		KEY_PARTIAL => 'Uploaden blev afbrudt, før den var færdig. Prøv igen.',
		KEY_NOFILE => 'Der blev ikke uploadet nogen fil.',
	],
	'de' => [
		KEY_LIMIT => 'Die Datei überschreitet die auf diesem Server konfigurierte maximale Upload-Größe.',
		KEY_PARTIAL => 'Der Upload wurde vor Abschluss unterbrochen. Bitte versuchen Sie es erneut.',
		KEY_NOFILE => 'Es wurde keine Datei hochgeladen.',
	],
	'es' => [
		KEY_LIMIT => 'El archivo supera el tamaño máximo de subida configurado en este servidor.',
		KEY_PARTIAL => 'La subida se interrumpió antes de completarse. Inténtalo de nuevo.',
		KEY_NOFILE => 'No se ha subido ningún archivo.',
	],
	'fr' => [
		KEY_LIMIT => 'Le fichier dépasse la taille maximale de téléversement configurée sur ce serveur.',
		KEY_PARTIAL => 'Le téléversement a été interrompu avant son achèvement. Veuillez réessayer.',
		KEY_NOFILE => 'Aucun fichier n’a été téléversé.',
	],
	'it' => [
		KEY_LIMIT => 'Il file supera la dimensione massima di caricamento configurata su questo server.',
		KEY_PARTIAL => 'Il caricamento è stato interrotto prima del completamento. Riprova.',
		KEY_NOFILE => 'Nessun file è stato caricato.',
	],
	'nb' => [
		KEY_LIMIT => 'Filen overskrider maksimal opplastingsstørrelse konfigurert på denne serveren.',
		KEY_PARTIAL => 'Opplastingen ble avbrutt før den var fullført. Prøv igjen.',
		KEY_NOFILE => 'Ingen fil ble lastet opp.',
	],
	'nl' => [
		KEY_LIMIT => 'Het bestand overschrijdt de maximale uploadgrootte die op deze server is geconfigureerd.',
		KEY_PARTIAL => 'De upload werd onderbroken voordat deze voltooid was. Probeer het opnieuw.',
		KEY_NOFILE => 'Er is geen bestand geüpload.',
	],
	'pl' => [
		KEY_LIMIT => 'Plik przekracza maksymalny rozmiar przesyłania skonfigurowany na tym serwerze.',
		KEY_PARTIAL => 'Przesyłanie zostało przerwane przed zakończeniem. Spróbuj ponownie.',
		KEY_NOFILE => 'Nie przesłano żadnego pliku.',
	],
	'pt_BR' => [
		KEY_LIMIT => 'O arquivo excede o tamanho máximo de upload configurado neste servidor.',
		KEY_PARTIAL => 'O envio foi interrompido antes de ser concluído. Tente novamente.',
		KEY_NOFILE => 'Nenhum arquivo foi enviado.',
	],
	'sv' => [
		KEY_LIMIT => 'Filen överskrider den maximala uppladdningsstorlek som är konfigurerad på den här servern.',
		KEY_PARTIAL => 'Uppladdningen avbröts innan den slutfördes. Försök igen.',
		KEY_NOFILE => 'Ingen fil laddades upp.',
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
