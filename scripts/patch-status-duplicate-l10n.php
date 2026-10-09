#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Translation for the booking-status duplicate-name rejection added with the
 * workspace-write-lock hardening (service-layer uniqueness check that runs
 * under the row lock instead of relying on the DB unique index → 500).
 *
 * Run:
 *   php scripts/patch-status-duplicate-l10n.php
 *   php scripts/sync-l10n-keys-from-en.php
 *   php scripts/sync-l10n-variants.php
 *   php scripts/regenerate-l10n-js.php
 */

$base = __DIR__ . '/../l10n';

const KEY = 'A booking status with this name already exists.';

$patch = [
	'en' => KEY,
	'da' => 'Der findes allerede en bookingstatus med dette navn.',
	'de' => 'Ein Buchungsstatus mit diesem Namen existiert bereits.',
	'es' => 'Ya existe un estado de registro con este nombre.',
	'fr' => 'Un statut de saisie portant ce nom existe déjà.',
	'it' => 'Esiste già uno stato di registrazione con questo nome.',
	'nb' => 'Det finnes allerede en bokføringsstatus med dette navnet.',
	'nl' => 'Er bestaat al een boekingsstatus met deze naam.',
	'pl' => 'Status księgowania o tej nazwie już istnieje.',
	'pt_BR' => 'Já existe um status de lançamento com este nome.',
	'sv' => 'Det finns redan en bokföringsstatus med detta namn.',
];

foreach ($patch as $locale => $value) {
	$file = $base . '/' . $locale . '.json';
	$src = (string) file_get_contents($file);
	$data = json_decode($src, true, 512, JSON_THROW_ON_ERROR);
	$data['translations'][KEY] = $value;
	$json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
	// Preserve each catalog's indentation convention (en.json: 4 spaces,
	// locale files: tabs) so the diff stays a single added line.
	if (str_starts_with(explode("\n", $src)[1], "\t")) {
		$json = preg_replace_callback('/^((?:    )+)/m', static fn (array $mm): string => str_repeat("\t", intdiv(strlen($mm[1]), 4)), $json);
	}
	file_put_contents($file, $json);
	fwrite(STDOUT, $locale . ': +1 string' . "\n");
}
