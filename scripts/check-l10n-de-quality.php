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

/*
 * Sie-form enforcement (POLICY): German chrome must never address the user
 * with du-forms. Sweep at stem level: pronouns, du-conjugated verbs, and
 * imperative stems used as commands (sentence start or mid-string).
 */
$duFormPatterns = [
	'/\bdu\b/u' => 'pronoun du',
	'/\bdich\b/u' => 'pronoun dich',
	'/\bdir\b/u' => 'pronoun dir',
	'/\bdein\w*\b/iu' => 'pronoun dein',
	'/\beuch\b/iu' => 'pronoun euch',
	'/\beuer\w*\b/iu' => 'pronoun euer',
	'/\b(kannst|darfst|sollst|möchtest|brauchst|musst|hast|bist|willst|könntest|solltest|würdest|machst|findest|siehst|gehst|kommst|wählst|suchst|öffnest|klickst|löschst|lädst|nimmst|gibst|trägst|legst|setzt|stellst|bearbeitest|ergänzt|entfernst|vergibst|verknüpfst|wiederholst|bestätigst|aktivierst|deaktivierst|schließt|versuchst|probierst|importierst|exportierst|benennst|buchst)\b/u' => 'du-conjugated verb',
	// Imperative stems used as commands (capitalized sentence start).
	// Note: 'Suche' excluded — overwhelmingly a noun ("Suche leeren"); covered by pronoun/conjugation checks.
	'/(?:^|[.!?…]\s+)(Wähle|Klicke|Öffne|Gehe|Komm|Gib|Nimm|Lade|Speichere|Lösche|Ändere|Markiere|Lege|Setze|Nutze|Verwende|Trage|Tippe|Ziehe|Prüfe|Stelle|Bearbeite|Ergänze|Entferne|Vergib|Verknüpfe|Wiederhole|Bestätige|Versuche|Aktiviere|Deaktiviere|Schließe|Rufe|Buche|Importiere|Exportiere|Benenne|Probiere|Lies|Hilf|Schreib|Triff|Vergiss|Miss|Wirf)\b/u' => 'du-imperative command',
	// Mid-string lowercase imperative after comma/dann (e.g. "…, dann wähle …").
	'/[,;]\s*(?:dann\s+)?(wähle|klicke|öffne|suche|geh|gib|nimm|lade|speichere|lösche|ändere|markiere|lege|setze|nutze|verwende|trage|tippe|ziehe|prüfe|stelle|bearbeite|ergänze|entferne|vergib|verknüpfe|wiederhole|bestätige|versuche|aktiviere|deaktiviere|schließe|rufe|buche|importiere|exportiere|benenne|probiere)\s/u' => 'mid-string du-imperative',
];

$failures = [];
$bannedHits = [];
$duHits = [];

foreach ($de['translations'] as $key => $value) {
	if (!is_string($value) || !is_string($key)) {
		continue;
	}
	foreach ($bannedSubstrings as $bad) {
		if (str_contains($value, $bad)) {
			$bannedHits[] = [$key, $value, $bad];
		}
	}
	foreach ($duFormPatterns as $pattern => $label) {
		if (preg_match($pattern, $value)) {
			$duHits[] = [$key, $value, $label];
			break;
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
if ($duHits !== []) {
	$ok = false;
	fwrite(STDERR, "German du-form violations (must be Sie-form) (" . count($duHits) . "):\n");
	foreach ($duHits as [$key, $value, $label]) {
		fwrite(STDERR, "  - {$label}: {$value}\n");
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
