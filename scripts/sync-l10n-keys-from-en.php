<?php

declare(strict_types=1);

/**
 * Rebuild every locale catalog to match en.json key set and order.
 * Existing translations are preserved; missing keys fall back to English.
 *
 * Usage: php scripts/sync-l10n-keys-from-en.php
 */

$base = dirname(__DIR__) . '/l10n';
$locales = ['de', 'fr', 'es', 'da', 'nl', 'it', 'pl', 'sv', 'nb'];

$en = json_decode((string) file_get_contents($base . '/en.json'), true, 512, JSON_THROW_ON_ERROR);
$enTranslations = $en['translations'] ?? [];

foreach ($locales as $lang) {
	$path = $base . '/' . $lang . '.json';
	$data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
	$existing = $data['translations'] ?? [];
	$merged = [];
	foreach ($enTranslations as $key => $enValue) {
		$merged[$key] = $existing[$key] ?? $enValue;
	}
	$data['translations'] = $merged;
	$encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	if ($encoded === false) {
		fwrite(STDERR, "Failed to encode {$lang}.json\n");
		exit(1);
	}
	file_put_contents($path, $encoded . "\n");
	echo "Synced {$lang}.json (" . count($merged) . " keys)\n";
}

echo "Done.\n";
