<?php

declare(strict_types=1);

/**
 * Verifies that all locale JSON files contain exactly the same set of msgid keys.
 *
 * Exit codes:
 *   0  parity OK
 *   1  drift detected (printed to STDERR)
 *
 * Usage:
 *   php scripts/check-l10n-parity.php
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

$base = __DIR__ . '/../l10n';
$localeFiles = ['en', 'de', 'fr', 'es', 'da', 'nl', 'it', 'pl', 'sv', 'nb', 'pt', 'pt_BR', 'zh_CN', 'zh_TW', 'tr'];

// Guard the roster's input domain both ways: a shipped base catalog
// l10n/<locale>.json that is not rostered must FAIL (silent-skip
// blindness — zh_TW shipped unrostered), and a rostered locale whose
// catalog is missing FAILS in the load loop below. Generated regional
// variants (sync-l10n-variants.php) are derived copies verified by that
// script's own --check, so they are exempted here — a newly shipped
// catalog still FATALs until it is classified base-or-variant.
$generatedVariants = [
	'en_GB', 'de_DE',
	'es_419', 'es_AR', 'es_CL', 'es_CO', 'es_CR', 'es_DO', 'es_EC', 'es_GT',
	'es_HN', 'es_MX', 'es_NI', 'es_PA', 'es_PE', 'es_PR', 'es_PY', 'es_SV',
	'es_UY',
];
$shipped = [];
foreach (glob($base . '/*.json') ?: [] as $jsonFile) {
	$loc = basename($jsonFile, '.json');
	if (!in_array($loc, $generatedVariants, true)) {
		$shipped[] = $loc;
	}
}
$unrostered = array_values(array_diff($shipped, $localeFiles));
if ($unrostered !== []) {
	sort($unrostered);
	fwrite(STDERR, 'Shipped catalogs missing from parity roster: ' . implode(', ', $unrostered) . "\n");
	exit(1);
}

$catalogs = [];

foreach ($localeFiles as $lang) {
	$path = $base . '/' . $lang . '.json';
	if (!is_file($path)) {
		fwrite(STDERR, "Missing locale file: $path\n");
		exit(1);
	}
	$catalogs[$lang] = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

$enKeys = array_keys($catalogs['en']['translations'] ?? []);
$enKeysSorted = $enKeys;
sort($enKeysSorted);
$ok = true;

// Catch PHP-escape leaks into msgids (e.g. Copy last month\'s …) — those never
// match $l->t('…\'s…') and become silent orphan extras.
foreach ($localeFiles as $lang) {
	foreach (array_keys($catalogs[$lang]['translations'] ?? []) as $key) {
		if (str_contains($key, "\\'")) {
			$ok = false;
			fwrite(STDERR, "Mangled msgid in {$lang}.json (literal backslash-apostrophe): {$key}\n");
		}
	}
}

foreach (array_diff($localeFiles, ['en']) as $lang) {
	$langKeys = array_keys($catalogs[$lang]['translations'] ?? []);
	$missing = array_values(array_diff($enKeysSorted, $langKeys));
	$extra = array_values(array_diff($langKeys, $enKeysSorted));
	sort($missing);
	sort($extra);
	if ($missing !== []) {
		$ok = false;
		fwrite(STDERR, "Keys missing in {$lang}.json (" . count($missing) . "):\n");
		foreach ($missing as $key) {
			fwrite(STDERR, "  - {$key}\n");
		}
	}
	if ($extra !== []) {
		$ok = false;
		fwrite(STDERR, "Extra keys in {$lang}.json (" . count($extra) . "):\n");
		foreach ($extra as $key) {
			fwrite(STDERR, "  - {$key}\n");
		}
	}
	if ($langKeys !== $enKeys) {
		$ok = false;
		fwrite(STDERR, "Key order mismatch in {$lang}.json (same keys but different order than en.json).\n");
	}
}

if (!$ok) {
	fwrite(STDERR, "\nl10n parity check FAILED.\n");
	exit(1);
}

echo 'l10n parity OK (' . count($enKeys) . ' keys, ' . implode('/', $localeFiles) . ").\n";
exit(0);
