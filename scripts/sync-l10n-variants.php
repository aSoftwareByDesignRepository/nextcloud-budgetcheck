<?php

declare(strict_types=1);

/**
 * Generate regional variant catalogs (l10n/{variant}.json) from base catalogs.
 *
 * Why: Nextcloud resolves the UI language **per app** (`Factory::findLanguage($appId)`).
 * When a user picks e.g. "English (British English)" (`en_GB`) and this app only
 * ships `en.json`, the server silently falls back to the browser's
 * `Accept-Language` header — so a Danish browser profile renders the app in
 * Danish while the rest of Nextcloud is in UK English (issue #15). Shipping a
 * real catalog for every core-selectable variant of a language we support makes
 * the account language always win.
 *
 * Variants are derived deterministically from their base catalog:
 *   - en_GB applies curated British spellings to values (keys never change),
 *   - de_DE applies formal German (Sie) via scripts/de-formalize.php,
 *   - all other variants are value-identical copies of the base.
 *
 * The matching .js files are emitted by scripts/regenerate-l10n-js.php, which
 * includes the variant codes in its locale list.
 *
 * Usage:
 *   php scripts/sync-l10n-variants.php           # (re)write variant json files
 *   php scripts/sync-l10n-variants.php --check   # exit 1 when files are missing or stale
 */

$base = dirname(__DIR__) . '/l10n';
$check = in_array('--check', $argv ?? [], true);
require_once __DIR__ . '/de-formalize.php';

/** @var array<string,string> variant code => base catalog code */
$variants = [
	'en_GB' => 'en',
	'de_DE' => 'de',
	'es_419' => 'es',
	'es_AR' => 'es',
	'es_CL' => 'es',
	'es_CO' => 'es',
	'es_CR' => 'es',
	'es_DO' => 'es',
	'es_EC' => 'es',
	'es_GT' => 'es',
	'es_HN' => 'es',
	'es_MX' => 'es',
	'es_NI' => 'es',
	'es_PA' => 'es',
	'es_PE' => 'es',
	'es_PR' => 'es',
	'es_PY' => 'es',
	'es_SV' => 'es',
	'es_UY' => 'es',
];

/**
 * British spellings for en_GB. Applied to translation values only; msgid keys
 * must stay byte-identical to the source strings used in code.
 * "Uncategorized" is covered by the lowercase "categorized" rule (the leading
 * "Un" is preserved).
 */
$enGbReplacements = [
	'categorized' => 'categorised',
	'Categorized' => 'Categorised',
	'authorized' => 'authorised',
	'Authorized' => 'Authorised',
	'recognize' => 'recognise',
	'Recognize' => 'Recognise',
	'favorite' => 'favourite',
	'Favorite' => 'Favourite',
];

/**
 * @param string|list<string> $value
 * @return string|list<string>
 */
$transformValue = static function (string $variant, $value) use ($enGbReplacements) {
	if ($variant === 'en_GB') {
		$apply = static fn (string $s): string => str_replace(
			array_keys($enGbReplacements),
			array_values($enGbReplacements),
			$s
		);
		if (is_array($value)) {
			return array_map($apply, $value);
		}
		return $apply($value);
	}
	if ($variant === 'de_DE') {
		if (is_array($value)) {
			return array_map(static fn (string $s): string => budgetcheck_formalize_german($s), $value);
		}
		return budgetcheck_formalize_german((string)$value);
	}
	return $value;
};

$drift = [];

foreach ($variants as $variant => $baseLang) {
	$basePath = $base . '/' . $baseLang . '.json';
	if (!is_file($basePath)) {
		fwrite(STDERR, "Missing base catalog: {$basePath}\n");
		exit(1);
	}
	$baseCatalog = json_decode((string) file_get_contents($basePath), true, 512, JSON_THROW_ON_ERROR);
	$translations = [];
	foreach (($baseCatalog['translations'] ?? []) as $key => $value) {
		$translations[$key] = $transformValue($variant, $value);
	}
	$variantCatalog = $baseCatalog;
	$variantCatalog['translations'] = $translations;

	$encoded = json_encode($variantCatalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	if ($encoded === false) {
		fwrite(STDERR, "Failed to encode {$variant}.json\n");
		exit(1);
	}
	$expected = $encoded . "\n";
	$variantPath = $base . '/' . $variant . '.json';

	if ($check) {
		if (!is_file($variantPath)) {
			$drift[] = "{$variant}.json is missing";
		} elseif ((string) file_get_contents($variantPath) !== $expected) {
			$drift[] = "{$variant}.json is stale (regenerate with scripts/sync-l10n-variants.php)";
		}
		if (!is_file($base . '/' . $variant . '.js')) {
			$drift[] = "{$variant}.js is missing (regenerate with scripts/regenerate-l10n-js.php)";
		}
		continue;
	}

	file_put_contents($variantPath, $expected);
	echo "Wrote l10n/{$variant}.json (" . count($translations) . " keys, from {$baseLang}.json)\n";
}

if ($check) {
	if ($drift !== []) {
		fwrite(STDERR, "l10n variant check FAILED:\n");
		foreach ($drift as $line) {
			fwrite(STDERR, "  - {$line}\n");
		}
		exit(1);
	}
	echo 'l10n variants OK (' . count($variants) . " variant catalogs match their base).\n";
	exit(0);
}

echo "l10n variant generation OK. Now run: php scripts/regenerate-l10n-js.php\n";
