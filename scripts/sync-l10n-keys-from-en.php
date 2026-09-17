<?php

declare(strict_types=1);

/**
 * Rebuild every locale catalog to match en.json key set and order.
 * Existing translations are preserved; missing keys fall back to English.
 * Extra keys (orphans / mangled msgids) are dropped.
 *
 * Emits tab-indented JSON to match the committed base catalogs (en/de/…).
 *
 * Usage: php scripts/sync-l10n-keys-from-en.php
 */

$base = dirname(__DIR__) . '/l10n';
$locales = ['de', 'fr', 'es', 'da', 'nl', 'it', 'pl', 'sv', 'nb', 'pt_BR'];

/**
 * Encode a catalog with tab indentation (Nextcloud / committed style).
 */
function budgetcheck_encode_l10n_catalog(array $data): string
{
	$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
	$translations = $data['translations'] ?? [];
	$pluralForm = $data['pluralForm'] ?? null;

	$lines = ["{\n", "\t\"translations\": {\n"];
	$keys = array_keys($translations);
	$last = count($keys) - 1;
	foreach ($keys as $i => $key) {
		$k = json_encode($key, $flags);
		$v = json_encode($translations[$key], $flags);
		$comma = $i === $last ? '' : ',';
		$lines[] = "\t\t{$k}: {$v}{$comma}\n";
	}
	$lines[] = "\t}";
	if (is_string($pluralForm) && $pluralForm !== '') {
		$lines[] = ",\n\t\"pluralForm\": " . json_encode($pluralForm, $flags) . "\n";
	} else {
		$lines[] = "\n";
	}
	$lines[] = "}\n";
	return implode('', $lines);
}

$en = json_decode((string) file_get_contents($base . '/en.json'), true, 512, JSON_THROW_ON_ERROR);
$enTranslations = $en['translations'] ?? [];
if ($enTranslations === []) {
	fwrite(STDERR, "en.json has no translations\n");
	exit(1);
}

foreach ($locales as $lang) {
	$path = $base . '/' . $lang . '.json';
	$data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
	$existing = $data['translations'] ?? [];
	$merged = [];
	$fallback = 0;
	$dropped = 0;
	foreach ($enTranslations as $key => $enValue) {
		if (array_key_exists($key, $existing)) {
			$merged[$key] = $existing[$key];
		} else {
			$merged[$key] = $enValue;
			$fallback++;
		}
	}
	foreach ($existing as $key => $_) {
		if (!array_key_exists($key, $enTranslations)) {
			$dropped++;
		}
	}
	$data['translations'] = $merged;
	// Keep pluralForm from the locale when present; otherwise inherit en.
	if (!isset($data['pluralForm']) || $data['pluralForm'] === '') {
		$data['pluralForm'] = $en['pluralForm'] ?? $data['pluralForm'] ?? null;
	}
	file_put_contents($path, budgetcheck_encode_l10n_catalog($data));
	echo "Synced {$lang}.json (" . count($merged) . " keys"
		. ($fallback > 0 ? ", {$fallback} English fallbacks" : '')
		. ($dropped > 0 ? ", {$dropped} extras dropped" : '')
		. ")\n";
}

echo "Done.\n";
