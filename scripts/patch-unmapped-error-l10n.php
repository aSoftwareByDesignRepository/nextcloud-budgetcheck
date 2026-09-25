#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Translations for the unmapped-API-error fallbacks added in the
 * web-unclassified-error-raw-echo fix (visual critic finding): 5xx and
 * unknown statuses now announce localized generic copy instead of echoing
 * raw server messages into the live region.
 *
 * Run:
 *   php scripts/patch-unmapped-error-l10n.php
 *   php scripts/sync-l10n-keys-from-en.php
 *   php scripts/sync-l10n-variants.php
 *   php scripts/regenerate-l10n-js.php
 */

$base = __DIR__ . '/../l10n';

const KEY_SERVER = 'The server could not complete the request. Please try again.';
const KEY_ACTION = 'The action could not be completed. Please try again.';

$patch = [
	'en' => [
		KEY_SERVER => KEY_SERVER,
		KEY_ACTION => KEY_ACTION,
	],
	'da' => [
		KEY_SERVER => 'Serveren kunne ikke gennemføre anmodningen. Prøv igen.',
		KEY_ACTION => 'Handlingen kunne ikke gennemføres. Prøv igen.',
	],
	'de' => [
		KEY_SERVER => 'Der Server konnte die Anfrage nicht abschließen. Bitte versuchen Sie es erneut.',
		KEY_ACTION => 'Die Aktion konnte nicht abgeschlossen werden. Bitte versuchen Sie es erneut.',
	],
	'es' => [
		KEY_SERVER => 'El servidor no pudo completar la solicitud. Inténtalo de nuevo.',
		KEY_ACTION => 'No se pudo completar la acción. Inténtalo de nuevo.',
	],
	'fr' => [
		KEY_SERVER => 'Le serveur n’a pas pu terminer la requête. Veuillez réessayer.',
		KEY_ACTION => 'L’action n’a pas pu aboutir. Veuillez réessayer.',
	],
	'it' => [
		KEY_SERVER => 'Il server non ha potuto completare la richiesta. Riprova.',
		KEY_ACTION => 'L’azione non è stata completata. Riprova.',
	],
	'nb' => [
		KEY_SERVER => 'Serveren kunne ikke fullføre forespørselen. Prøv igjen.',
		KEY_ACTION => 'Handlingen kunne ikke fullføres. Prøv igjen.',
	],
	'nl' => [
		KEY_SERVER => 'De server kon het verzoek niet afronden. Probeer het opnieuw.',
		KEY_ACTION => 'De actie kon niet worden voltooid. Probeer het opnieuw.',
	],
	'pl' => [
		KEY_SERVER => 'Serwer nie mógł zakończyć żądania. Spróbuj ponownie.',
		KEY_ACTION => 'Nie udało się zakończyć działania. Spróbuj ponownie.',
	],
	'pt_BR' => [
		KEY_SERVER => 'O servidor não conseguiu concluir a solicitação. Tente novamente.',
		KEY_ACTION => 'Não foi possível concluir a ação. Tente novamente.',
	],
	'sv' => [
		KEY_SERVER => 'Servern kunde inte slutföra begäran. Försök igen.',
		KEY_ACTION => 'Åtgärden kunde inte slutföras. Försök igen.',
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
