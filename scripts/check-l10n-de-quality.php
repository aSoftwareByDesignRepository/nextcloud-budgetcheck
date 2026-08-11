<?php

declare(strict_types=1);

/**
 * Fails when German msgstr is still identical to the English msgid for
 * user-facing prose. Prevents regressions like "Available after savings"
 * left in English or calqued awkwardly without a real translation.
 *
 * Allowlist covers brand names, technical tokens, and bilingual column hints.
 */

$base = dirname(__DIR__) . '/l10n';
$en = json_decode((string) file_get_contents($base . '/en.json'), true, 512, JSON_THROW_ON_ERROR);
$de = json_decode((string) file_get_contents($base . '/de.json'), true, 512, JSON_THROW_ON_ERROR);

	$allowExact = [
	'BudgetCheck' => true,
	'BudgetCheck Mobile' => true,
	'InvoiceCheck' => true,
	'Check Partner' => true,
	'Nextcloud' => true,
	'CSV' => true,
	'PDF' => true,
	'API' => true,
	'URL' => true,
	'OCR' => true,
	'IBAN' => true,
	'JSON' => true,
	'OK' => true,
	'ID' => true,
	'Status' => true,
	'Name' => true,
	'Info' => true,
	'Export' => true,
	'Budgets' => true,
	'File' => true,
	'Standard' => true,
	'Optional:' => true,
	'Required:' => true,
	'Selected:' => true,
];

$bannedSubstrings = [
	'Verfügbar nach Sparzielen',
	'Verfügbar nach Sparen',
	'Enger Monat',
	'Knapper Monat',
	'Deckel-Hinweis',
	'Alltagsbudget-Saldo',
];

$failures = [];
$bannedHits = [];

foreach ($de['translations'] as $key => $value) {
	if (!is_string($value) || !is_string($key)) {
		continue;
	}
	foreach ($bannedSubstrings as $bad) {
		if (str_contains($value, $bad)) {
			$bannedHits[] = [$key, $value, $bad];
		}
	}

	$enValue = $en['translations'][$key] ?? $key;
	if (!is_string($enValue) || $value !== $enValue) {
		continue;
	}
	if ($allowExact[$value] ?? false) {
		continue;
	}
	if (!preg_match('/\p{L}/u', $value)) {
		continue;
	}
	if (preg_match('/^\d+\s*%$/', trim($value))) {
		continue;
	}
	// Bilingual / technical column hints
	if (substr_count($value, ',') >= 2 && preg_match('/\b(date|amount|title|direction|betrag|valuta|bookingDate)\b/i', $value)) {
		continue;
	}
	// Very short tokens / codes
	if (mb_strlen($value) <= 3) {
		continue;
	}
	$failures[] = $key;
}

$ok = true;
if ($bannedHits !== []) {
	$ok = false;
	fwrite(STDERR, "Banned awkward German calques still present (" . count($bannedHits) . "):\n");
	foreach ($bannedHits as [$key, $value, $bad]) {
		fwrite(STDERR, "  - contains \"{$bad}\": {$value}\n");
	}
}
if ($failures !== []) {
	$ok = false;
	fwrite(STDERR, "German translations still identical to English (" . count($failures) . "):\n");
	foreach ($failures as $key) {
		fwrite(STDERR, '  - ' . $key . "\n");
	}
}

if (!$ok) {
	fwrite(STDERR, "\nl10n DE quality check FAILED.\n");
	exit(1);
}

echo 'l10n DE quality OK (' . count($de['translations']) . " keys; no banned calques; no leftover English prose).\n";
exit(0);
