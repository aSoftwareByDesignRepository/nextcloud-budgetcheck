#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * German translations for the payee/rules/analytics/alerts/widget feature set.
 *
 * Run:
 *   php scripts/patch-payee-insights-alerts-l10n.php
 *   php scripts/sync-l10n-variants.php
 *   php scripts/regenerate-l10n-js.php
 */

$base = __DIR__ . '/../l10n';

$de = [
	// Payees
	'Payees' => 'Zahlungsempfänger',
	'Payee' => 'Zahlungsempfänger',
	'New payee' => 'Neuer Zahlungsempfänger',
	'Add payee' => 'Zahlungsempfänger hinzufügen',
	'Edit payee' => 'Zahlungsempfänger bearbeiten',
	'Payee deactivated.' => 'Zahlungsempfänger deaktiviert.',
	'No payees yet — they appear when bookings name a payee.' => 'Noch keine Zahlungsempfänger — sie erscheinen, sobald Buchungen einen Empfänger nennen.',
	'Counterparties on your bookings. Rename or merge variants so reports and rules stay clean.' => 'Geschäftspartner auf Ihren Buchungen. Benennen Sie Varianten um oder führen Sie sie zusammen, damit Berichte und Regeln sauber bleiben.',
	'The counterparty name as it appears on bookings.' => 'Der Name des Geschäftspartners, wie er auf Buchungen erscheint.',
	'Optional — who the money went to or came from. Pick an existing payee or type a new name.' => 'Optional — an wen das Geld ging oder von wem es kam. Wählen Sie einen bestehenden Empfänger oder tippen Sie einen neuen Namen.',
	'Merge' => 'Zusammenführen',
	'Keep as separate payee' => 'Als eigenen Zahlungsempfänger behalten',
	'Merge into: {name}' => 'Zusammenführen in: {name}',
	'Merge this payee into another one — all bookings move and this payee is removed. Cannot be undone.' => 'Diesen Zahlungsempfänger in einen anderen überführen — alle Buchungen werden umgehängt und dieser Empfänger wird entfernt. Kann nicht rückgängig gemacht werden.',
	'Merge {source} into {target}? All bookings move and {source} is removed.' => '{source} in {target} überführen? Alle Buchungen werden umgehängt und {source} wird entfernt.',
	'Bookings' => 'Buchungen',

	// Categorization rules
	'Categorization rules' => 'Kategorisierungsregeln',
	'Rules' => 'Regeln',
	'New rule' => 'Neue Regel',
	'Edit rule' => 'Regel bearbeiten',
	'Rule deactivated.' => 'Regel deaktiviert.',
	'Match field' => 'Prüffeld',
	'Match type' => 'Vergleichsart',
	'Match' => 'Treffer',
	'Pattern' => 'Muster',
	'Priority' => 'Priorität',
	'Field' => 'Feld',
	'Hits' => 'Treffer',
	'Advanced' => 'Erweitert',
	'Any direction' => 'Beliebige Richtung',
	'Income only' => 'Nur Einnahmen',
	'Expense only' => 'Nur Ausgaben',
	'contains' => 'enthält',
	'equals' => 'entspricht',
	'starts with' => 'beginnt mit',
	'Which booking field the pattern is checked against.' => 'Welches Buchungsfeld gegen das Muster geprüft wird.',
	'Literal text matching — case and spacing are normalized automatically.' => 'Wörtlicher Textvergleich — Groß-/Kleinschreibung und Leerzeichen werden automatisch normalisiert.',
	'Text the field must match — for example "REWE" or "salary".' => 'Text, dem das Feld entsprechen muss — zum Beispiel „REWE“ oder „Gehalt“.',
	'Limit the rule to income or expense bookings.' => 'Die Regel auf Einnahmen- oder Ausgabenbuchungen beschränken.',
	'The category applied when the rule matches.' => 'Die Kategorie, die angewendet wird, wenn die Regel zutrifft.',
	'Lower numbers win when several rules could match.' => 'Niedrigere Zahlen gewinnen, wenn mehrere Regeln passen könnten.',
	'Treat pattern as regular expression (advanced)' => 'Muster als regulären Ausdruck behandeln (erweitert)',
	'Rules auto-fill the category on imported bookings and offer suggestions in the editor. The first matching rule wins — set the order with priority.' => 'Regeln füllen die Kategorie bei importierten Buchungen automatisch aus und schlagen sie im Editor vor. Die erste passende Regel gewinnt — die Reihenfolge steuern Sie über die Priorität.',
	'No rules yet. Rules fill in the category on import and offer suggestions in the editor.' => 'Noch keine Regeln. Regeln füllen die Kategorie beim Import aus und schlagen sie im Editor vor.',
	'Preview apply to existing bookings' => 'Vorschau: Auf bestehende Buchungen anwenden',
	'Preview: {matched} of {scanned} uncategorized bookings would be filled. Apply now?' => 'Vorschau: {matched} von {scanned} unkategorisierten Buchungen würden gefüllt. Jetzt anwenden?',
	'Applied rules to {count} bookings.' => 'Regeln auf {count} Buchungen angewendet.',
	'Suggested category: {name} — click to apply' => 'Vorgeschlagene Kategorie: {name} — zum Übernehmen klicken',
	'Actions for rule {title}' => 'Aktionen für Regel {title}',

	// Analytics / Insights
	'Insights' => 'Auswertungen',
	'Where the money goes, and how the month is shaping up.' => 'Wohin das Geld fließt und wie sich der Monat entwickelt.',
	'Month-end forecast' => 'Monatsendprognose',
	'Top payees this month' => 'Top-Zahlungsempfänger diesen Monat',
	'Category trend' => 'Kategorietrend',
	'So far' => 'Bisher',
	'Projected month end' => 'Prognose Monatsende',
	'Budget used (projected)' => 'Budget genutzt (prognostiziert)',
	'No budget planned' => 'Kein Budget geplant',
	'A projection, not a promise: month-to-date pace plus scheduled recurring bookings.' => 'Eine Hochrechnung, kein Versprechen: bisheriges Monatstempo plus geplante Dauerbuchungen.',
	'Not enough entries yet this month to project an outcome.' => 'Noch zu wenige Buchungen in diesem Monat für eine Prognose.',
	'No payees recorded this month yet.' => 'Diesen Monat noch keine Zahlungsempfänger erfasst.',
	'No bookings in this category yet.' => 'Noch keine Buchungen in dieser Kategorie.',
	'Could not load the trend.' => 'Der Trend konnte nicht geladen werden.',

	// Dashboard widget
	'Budget status' => 'Budgetstatus',
	'BudgetCheck data hidden' => 'BudgetCheck-Daten verborgen',
	'Enable in BudgetCheck settings' => 'In den BudgetCheck-Einstellungen aktivieren',
	'No household workspace yet' => 'Noch kein Haushalts-Arbeitsbereich',
	'Create or pick a household workspace in BudgetCheck' => 'Erstellen oder wählen Sie einen Haushalts-Arbeitsbereich in BudgetCheck',
	'Net result (%s)' => 'Nettoergebnis (%s)',
	'Budget consumed' => 'Budgetverbrauch',
	'%s%% used' => '%s %% genutzt',
	'Open BudgetCheck for details.' => 'Öffnen Sie BudgetCheck für Details.',
	'Show my numbers on the Nextcloud dashboard' => 'Meine Zahlen auf dem Nextcloud-Dashboard anzeigen',
	'Turn off to hide balances on the shared dashboard — for example when presenting.' => 'Deaktivieren, um Salden auf dem geteilten Dashboard zu verbergen — zum Beispiel beim Präsentieren.',

	// Alert notifications
	'Notifications' => 'Benachrichtigungen',
	'Alerts' => 'Hinweise',
	'Alert' => 'Hinweis',
	'Alert log' => 'Hinweisprotokoll',
	'Alert types' => 'Hinweisarten',
	'Enable BudgetCheck alerts' => 'BudgetCheck-Hinweise aktivieren',
	'The master switch stops every alert type at once.' => 'Der Hauptschalter stoppt alle Hinweisarten auf einmal.',
	'Budget threshold' => 'Budgetschwelle',
	'Recurring bookings posted' => 'Dauerbuchungen gebucht',
	'Monthly digest' => 'Monatsübersicht',
	'Savings goal status' => 'Status des Sparziels',
	'Budget alert threshold (%)' => 'Budget-Hinweisschwelle (%)',
	'Alert when a category budget reaches this percent — and again at 100%.' => 'Hinweis, wenn ein Kategorienbudget diesen Prozentsatz erreicht — und erneut bei 100 %.',
	'Quiet hours' => 'Ruhezeiten',
	'Quiet hours end' => 'Ende der Ruhezeit',
	'Optional: suppress alerts between these times (your local time).' => 'Optional: Hinweise zwischen diesen Zeiten unterdrücken (Ihre Ortszeit).',
	'Choose which BudgetCheck alerts reach your Nextcloud notifications. These settings are yours only — other members pick their own.' => 'Wählen Sie, welche BudgetCheck-Hinweise Ihre Nextcloud-Benachrichtigungen erreichen. Diese Einstellungen gelten nur für Sie — andere Mitglieder wählen ihre eigenen.',
	'Save notification settings' => 'Benachrichtigungseinstellungen speichern',
	'Notification settings saved.' => 'Benachrichtigungseinstellungen gespeichert.',
	'Alerts BudgetCheck sent to you for this workspace — newest first.' => 'Hinweise, die BudgetCheck Ihnen für diesen Arbeitsbereich gesendet hat — neueste zuerst.',
	'No alerts yet.' => 'Noch keine Hinweise.',
	'Digest' => 'Übersicht',
	'Recurring bookings' => 'Dauerbuchungen',
	'Budget alert' => 'Budgethinweis',
	'Savings update' => 'Sparupdate',
	'BudgetCheck alert' => 'BudgetCheck-Hinweis',
	'A recurring rule failed to post — review the rule.' => 'Eine Dauerregel konnte nicht gebucht werden — prüfen Sie die Regel.',
	'%1$s has reached %2$s%% of the %3$s budget' => '%1$s hat %2$s %% des Budgets %3$s erreicht',
	'%1$s is over its %2$s budget (%3$s%%)' => '%1$s liegt über dem Budget %2$s (%3$s %%)',
	'%1$s: Income %2$s · Expense %3$s · Net %4$s' => '%1$s: Einnahmen %2$s · Ausgaben %3$s · Netto %4$s',
	'%1$s: savings target reached (%2$s of %3$s)' => '%1$s: Sparziel erreicht (%2$s von %3$s)',
	'%1$s: savings target not met (%2$s of %3$s)' => '%1$s: Sparziel nicht erreicht (%2$s von %3$s)',
	'%n recurring booking posted' => '%n Dauerbuchung gebucht',
	'%n recurring bookings posted' => '%n Dauerbuchungen gebucht',

	// Yearly compare leftovers (earlier work, untranslated)
	'Compare with' => 'Vergleichen mit',
	'Categories — year over year' => 'Kategorien — Jahr für Jahr',
	'What changed per category versus the comparison year.' => 'Was sich je Kategorie gegenüber dem Vergleichsjahr geändert hat.',
	'The category comparison could not be loaded.' => 'Der Kategorienvergleich konnte nicht geladen werden.',
	'No bookings in {y} or {c} yet.' => 'Noch keine Buchungen in {y} oder {c}.',
	'No bookings recorded in {c} — all comparison values are zero.' => 'Keine Buchungen in {c} erfasst — alle Vergleichswerte sind null.',
	'Defaults to the previous year. Pick any year to compare against.' => 'Standard ist das Vorjahr. Wählen Sie ein beliebiges Jahr zum Vergleichen.',
	'In the category comparison below: the amount is this year minus the comparison year, the percent is that difference relative to the comparison year. “new” marks categories with bookings only this year, “gone” marks categories with bookings only in the comparison year.' => 'Im Kategorienvergleich unten: Der Betrag ist dieses Jahr minus Vergleichsjahr, der Prozentsatz ist diese Differenz bezogen auf das Vergleichsjahr. „neu“ markiert Kategorien mit Buchungen nur in diesem Jahr, „weg“ markiert Kategorien mit Buchungen nur im Vergleichsjahr.',
	'Δ and %' => 'Δ und %',
	'Change' => 'Veränderung',
	'new' => 'neu',
	'gone' => 'weg',
	'more' => 'mehr',
	'less' => 'weniger',
	'unchanged' => 'unverändert',
	'archived' => 'archiviert',
	'savings transfer' => 'Sparen',
	'Other' => 'Sonstige',
];

$dePath = $base . '/de.json';
$deJson = json_decode((string) file_get_contents($dePath), true, 512, JSON_THROW_ON_ERROR);
$applied = 0;
foreach ($de as $msgid => $msgstr) {
	if (isset($deJson['translations'][$msgid])) {
		if ($deJson['translations'][$msgid] === $msgid || $deJson['translations'][$msgid] === '') {
			$deJson['translations'][$msgid] = $msgstr;
			$applied++;
		}
	} else {
		$deJson['translations'][$msgid] = $msgstr;
		$applied++;
	}
}
file_put_contents($dePath, json_encode($deJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
echo "Applied $applied German translations.\n";
