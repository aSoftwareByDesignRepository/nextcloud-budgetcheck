<?php

declare(strict_types=1);

/**
 * Convert informal German (du) UI strings to formal register (Sie).
 *
 * Used when syncing de_DE from de (scripts/sync-l10n-variants.php) and when
 * bringing de.json itself to Sie-form. Idempotent on strings that are already
 * formal. Curated phrase overrides take precedence over regex rules.
 *
 * @internal Exported for unit tests.
 */
function budgetcheck_formalize_german(string $text): string
{
	static $phraseOverrides = null;
	if ($phraseOverrides === null) {
		$phraseOverrides = budgetcheck_de_formal_phrase_overrides();
	}
	if (isset($phraseOverrides[$text])) {
		return $phraseOverrides[$text];
	}

	$out = $text;

	// Multi-word phrases first (order matters).
	$phrases = [
		'Bist du sicher?' => 'Sind Sie sicher?',
		'So liest du das' => 'So lesen Sie das',
		'Deine Planungsansicht' => 'Ihre Planungsansicht',
		'Deine Sitzung ist abgelaufen. Bitte neu laden und erneut anmelden.' => 'Ihre Sitzung ist abgelaufen. Bitte laden Sie die Seite neu und melden Sie sich erneut an.',
		'Bitte ein Mitglied mit Schreibrechten, Buchungen anzulegen. Aufschlüsselungen kannst du dir später trotzdem ansehen.' => 'Bitte bitten Sie ein Mitglied mit Schreibrechten, Buchungen anzulegen. Aufschlüsselungen können Sie sich später trotzdem ansehen.',
		'Jede Ansicht in BudgetCheck ist auf einen Arbeitsbereich beschränkt. Wähle in der Seitenleiste einen aus oder bitte einen App-Administrator, dich zu einem Arbeitsbereich hinzuzufügen.' => 'Jede Ansicht in BudgetCheck ist auf einen Arbeitsbereich beschränkt. Wählen Sie in der Seitenleiste einen aus oder bitten Sie einen App-Administrator, Sie zu einem Arbeitsbereich hinzuzufügen.',
		'Du hast keinen Zugriff auf BudgetCheck. Bitte einen App-Administrator, dich zu einem Arbeitsbereich hinzuzufügen.' => 'Sie haben keinen Zugriff auf BudgetCheck. Bitten Sie einen App-Administrator, Sie zu einem Arbeitsbereich hinzuzufügen.',
		'Du hast keinen Zugriff auf BudgetCheck. Dein Konto gehört nicht zu den Benutzern oder Gruppen, die diese App nutzen dürfen. Wende dich an einen Server- oder App-Administrator.' => 'Sie haben keinen Zugriff auf BudgetCheck. Ihr Konto gehört nicht zu den Benutzern oder Gruppen, die diese App nutzen dürfen. Wenden Sie sich an einen Server- oder App-Administrator.',
		'In wie vielen Monaten dieses Jahres du über Budget warst.' => 'In wie vielen Monaten dieses Jahres Sie über Budget waren.',
		'Wähle oder lege einen Arbeitsbereich an, um zu starten' => 'Wählen Sie einen Arbeitsbereich aus oder legen Sie einen an, um zu starten',
		'Speichern hier erstellt eine monatsbezogene Überschreibung.' => 'Speichern erstellt hier eine monatsbezogene Überschreibung.',
		'Lade …' => 'Wird geladen …',
	];
	foreach ($phrases as $from => $to) {
		$out = str_replace($from, $to, $out);
	}

	// Possessives (longer forms first).
	$possessives = [
		'Deine ' => 'Ihre ',
		'Dein ' => 'Ihr ',
		'Deinen ' => 'Ihren ',
		'deines ' => 'Ihres ',
		'deine ' => 'Ihre ',
		'deinen ' => 'Ihren ',
		'deinem ' => 'Ihrem ',
		'deiner ' => 'Ihrer ',
		'deins ' => 'Ihres ',
		'dein ' => 'Ihr ',
	];
	foreach ($possessives as $from => $to) {
		$out = str_replace($from, $to, $out);
	}

	// Pronoun + verb clusters (before bare "du" replacement).
	$clusters = [
		'kannst du dir' => 'können Sie sich',
		'kannst du' => 'können Sie',
		'darfst du' => 'dürfen Sie',
		'musst du' => 'müssen Sie',
		'brauchst du' => 'brauchen Sie',
		'wirst du' => 'werden Sie',
		'bist du' => 'sind Sie',
		'hast du' => 'haben Sie',
		'warst du' => 'waren Sie',
		'änderst du' => 'ändern Sie',
		'nutzt du' => 'nutzen Sie',
		'legst du' => 'legen Sie',
		'schließt du' => 'schließen Sie',
		'wolltest du' => 'wollten Sie',
		'indem du' => 'indem Sie',
		'wenn du' => 'wenn Sie',
		'bis du' => 'bis Sie',
		'damit du' => 'damit Sie',
		'außer du' => 'außer Sie',
		'bevor du' => 'bevor Sie',
		'Sobald du' => 'Sobald Sie',
		' du ' => ' Sie ',
		' dich ' => ' Sie ',
		' dir ' => ' Ihnen ',
		'Du ' => 'Sie ',
	];

	foreach ($clusters as $from => $to) {
		$out = str_replace($from, $to, $out);
	}

	// Informal imperatives at sentence starts, after stops, and after em dashes.
	$imperatives = [
		'Wähle ' => 'Wählen Sie ',
		'wähle ' => 'wählen Sie ',
		'Gib ' => 'Geben Sie ',
		'Erstelle ' => 'Erstellen Sie ',
		'Verwende ' => 'Verwenden Sie ',
		'Aktiviere ' => 'Aktivieren Sie ',
		'Markiere ' => 'Markieren Sie ',
		'Füge ' => 'Fügen Sie ',
		'Lege ' => 'Legen Sie ',
		'Erfasse ' => 'Erfassen Sie ',
		'Hänge ' => 'Hängen Sie ',
		'Prüfe ' => 'Prüfen Sie ',
		'prüfe ' => 'prüfen Sie ',
		'Tippe ' => 'Tippen Sie ',
		'Klicke ' => 'Klicken Sie ',
		'klicke ' => 'klicken Sie ',
		'Drücke ' => 'Drücken Sie ',
		'Öffne ' => 'Öffnen Sie ',
		'Nutze ' => 'Nutzen Sie ',
		'nutze ' => 'nutzen Sie ',
		'Lade ' => 'Laden Sie ',
		'Benenne ' => 'Benennen Sie ',
		'Probiere ' => 'Probieren Sie ',
		'Versuche ' => 'Versuchen Sie ',
		'versuche ' => 'versuchen Sie ',
		'Trage ' => 'Tragen Sie ',
		'Scanne ' => 'Scannen Sie ',
		'Wende dich ' => 'Wenden Sie sich ',
	];
	foreach ($imperatives as $from => $to) {
		$out = preg_replace('/(^|[.!?]\s+|—\s+|:\s+)' . preg_quote($from, '/') . '/u', '$1' . $to, $out) ?? $out;
		if (str_starts_with($out, $from)) {
			$out = $to . substr($out, strlen($from));
		}
	}

	// Mid-sentence informal verb clusters.
	$mid = [
		' und versuche ' => ' und versuchen Sie ',
		' und klicke ' => ' und klicken Sie ',
		' und prüfe ' => ' und prüfen Sie ',
		' und importiere ' => ' und importieren Sie ',
		' oder nutze ' => ' oder nutzen Sie ',
		' oder setze ' => ' oder setzen Sie ',
		' → nutze ' => ' → nutzen Sie ',
	];
	foreach ($mid as $from => $to) {
		$out = str_replace($from, $to, $out);
	}

	// Remaining verb forms tied to informal "du" subjects.
	// Include punctuation-adjacent forms ("… verwendet hast.") — space-bounded
	// rules alone leave trailing informal verbs in de_DE.
	$verbForms = [
		' du ' => ' Sie ',
		' warst.' => ' waren.',
		' warst,' => ' waren,',
		' hast ' => ' haben ',
		' hast.' => ' haben.',
		' hast,' => ' haben,',
		' hast!' => ' haben!',
		' kannst ' => ' können ',
		' kannst.' => ' können.',
		' kannst,' => ' können,',
		' musst ' => ' müssen ',
		' musst.' => ' müssen.',
		' musst,' => ' müssen,',
		' darfst ' => ' dürfen ',
		' darfst.' => ' dürfen.',
		' darfst,' => ' dürfen,',
		' brauchst ' => ' brauchen ',
		' brauchst.' => ' brauchen.',
		' brauchst,' => ' brauchen,',
		' wirst ' => ' werden ',
		' wirst.' => ' werden.',
		' wirst,' => ' werden,',
		' änderst' => ' ändern',
		' erzeugst' => ' erzeugen',
		' hinzufügst' => ' hinzufügen',
		' bearbeitest' => ' bearbeiten',
		// Do not map bare "nutzt"/"schließt" — those are often 3rd-person.
	];
	foreach ($verbForms as $from => $to) {
		$out = str_replace($from, $to, $out);
	}

	// Trailing informal pronouns.
	$out = preg_replace('/\bdu\b/u', 'Sie', $out) ?? $out;
	$out = preg_replace('/\bdich\b/u', 'Sie', $out) ?? $out;
	$out = preg_replace('/\bdir\b/u', 'Ihnen', $out) ?? $out;

	return $out;
}

/**
 * Curated formal translations keyed by the informal German value from de.json.
 * Used when regex rules cannot produce natural formal German.
 *
 * @return array<string, string>
 */
function budgetcheck_de_formal_phrase_overrides(): array
{
	return [
		'Betrag in der Währung dieses Arbeitsbereichs. Verwende dein übliches Dezimaltrennzeichen (Punkt oder Komma).' => 'Betrag in der Währung dieses Arbeitsbereichs. Verwenden Sie Ihr übliches Dezimaltrennzeichen (Punkt oder Komma).',
		'Kalenderjahr, das dieser Haushalt als Hauptanker für Planung nutzt (Budgets und Jahresübersicht).' => 'Kalenderjahr, das dieser Haushalt als Hauptanker für Planung nutzt (Budgets und Jahresübersicht).',
		'Jahr und Monat mit den Listen unten wählen. Die URL wird beim Wechsel aktualisiert, damit du sie teilen oder als Lesezeichen speichern kannst.' => 'Jahr und Monat mit den Listen unten wählen. Die URL wird beim Wechsel aktualisiert, damit Sie sie teilen oder als Lesezeichen speichern können.',
		'Steuert, wie Einnahmen- und Ausgabensummen auf Dashboard, Monatsplan und Jahresübersicht für dich angezeigt werden.' => 'Steuert, wie Einnahmen- und Ausgabensummen auf Dashboard, Monatsplan und Jahresübersicht für Sie angezeigt werden.',
		'Datums- und Monatsfelder nutzen deine Nextcloud-Sprache. Tabellen und Zusammenfassungen ebenfalls. Der Kalenderdialog des Browsers kann in manchen Umgebungen weiter der Gerätesprache folgen.' => 'Datums- und Monatsfelder nutzen Ihre Nextcloud-Sprache. Tabellen und Zusammenfassungen ebenfalls. Der Kalenderdialog des Browsers kann in manchen Umgebungen weiter der Gerätesprache folgen.',
		'Alltagssummen schließen als „speziell“ markierte Buchungen aus. Aktiviere dies für das vollständige Hauptbuch. Deine Wahl wird in deinem Konto gespeichert.' => 'Alltagssummen schließen als „speziell“ markierte Buchungen aus. Aktivieren Sie dies für das vollständige Hauptbuch. Ihre Wahl wird in Ihrem Konto gespeichert.',
		'Alltagssummen schließen als „speziell“ markierte Buchungen aus. Aktiviere dies, um das vollständige Hauptbuch zu sehen.' => 'Alltagssummen schließen als „speziell“ markierte Buchungen aus. Aktivieren Sie dies, um das vollständige Hauptbuch zu sehen.',
		'Ausgabe bedeutet Geldabgang, Einnahme bedeutet Geldzugang. Die Kategorieliste passt sich an, wenn du das hier änderst.' => 'Ausgabe bedeutet Geldabgang, Einnahme bedeutet Geldzugang. Die Kategorieliste passt sich an, wenn Sie das hier ändern.',
		'Gib Personen Zugriff auf diesen Arbeitsbereich, indem du sie als Benutzer oder eine ganze Gruppe hinzufügst. Jedes Mitglied ist Manager, Mitwirkender oder Betrachter.' => 'Geben Sie Personen Zugriff auf diesen Arbeitsbereich, indem Sie sie als Benutzer oder eine ganze Gruppe hinzufügen. Jedes Mitglied ist Manager, Mitwirkender oder Betrachter.',
		'Haushalte planen ein Kalenderjahr als Einheit. Du kannst den Wert später in den Arbeitsbereichseinstellungen ändern.' => 'Haushalte planen ein Kalenderjahr als Einheit. Sie können den Wert später in den Arbeitsbereichseinstellungen ändern.',
		'In Projekt-Arbeitsbereichen kannst du einen Status setzen (z. B. in Bearbeitung oder bezahlt). Leer lassen, wenn du keinen Workflow-Schritt brauchst.' => 'In Projekt-Arbeitsbereichen können Sie einen Status setzen (z. B. in Bearbeitung oder bezahlt). Leer lassen, wenn Sie keinen Workflow-Schritt benötigen.',
		'Markiere eine Ausgabenkategorie als Sparübertrag, um beiseitegelegtes Geld gegen dein Ziel zu verfolgen.' => 'Markieren Sie eine Ausgabenkategorie als Sparübertrag, um beiseitegelegtes Geld gegen Ihr Ziel zu verfolgen.',
		'Markiere deine Sparkategorie unter Einstellungen → Kategorien, um Überweisungen gegen dieses Ziel zu verfolgen.' => 'Markieren Sie Ihre Sparkategorie unter Einstellungen → Kategorien, um Überweisungen gegen dieses Ziel zu verfolgen.',
		'Mitglieder ohne eigene Einstellung übernehmen diesen Standard. Jede Person kann ihre Wahl oben unter „Deine Planungsansicht“ ändern.' => 'Mitglieder ohne eigene Einstellung übernehmen diesen Standard. Jede Person kann ihre Wahl oben unter „Ihre Planungsansicht“ ändern.',
		'Geld, das diesen Monat eingegangen ist, in Untereinheiten (z. B. Cent) summiert und im Format deiner Region angezeigt.' => 'Geld, das diesen Monat eingegangen ist, in Untereinheiten (z. B. Cent) summiert und im Format Ihrer Region angezeigt.',
		'Noch keine Buchungen im Hauptbuch—jeder Monat bleibt leer, bis du Einträge erfasst.' => 'Noch keine Buchungen im Hauptbuch—jeder Monat bleibt leer, bis Sie Einträge erfassen.',
		'Für {monthLabel} ist nichts gebucht. Dieser Kalendermonat liegt nach deiner letzten Buchung ({monthTo}).' => 'Für {monthLabel} ist nichts gebucht. Dieser Kalendermonat liegt nach Ihrer letzten Buchung ({monthTo}).',
		'Für {monthLabel} ist nichts gebucht. Dieser Kalendermonat liegt vor deiner ersten Buchung ({monthFrom}).' => 'Für {monthLabel} ist nichts gebucht. Dieser Kalendermonat liegt vor Ihrer ersten Buchung ({monthFrom}).',
		'Für {monthLabel} ist nichts gebucht. Budgets und Monatsfunktionen kannst du trotzdem nutzen.' => 'Für {monthLabel} ist nichts gebucht. Budgets und Monatsfunktionen können Sie trotzdem nutzen.',
		'Sobald du eine Buchung anlegst, erscheint sie hier. Auf dieser Seite kannst du Einträge bearbeiten, kennzeichnen oder löschen.' => 'Sobald Sie eine Buchung anlegen, erscheint sie hier. Auf dieser Seite können Sie Einträge bearbeiten, kennzeichnen oder löschen.',
		'Wähle Kalenderjahr und -monat. Die Jahresliste wächst mit deinem Hauptbuch; dein primärer Planungsjahresanker begrenzt den Standardbereich.' => 'Wählen Sie Kalenderjahr und -monat. Die Jahresliste wächst mit Ihrem Hauptbuch; Ihr primärer Planungsjahresanker begrenzt den Standardbereich.',
		'Planungspuffer nach dem Ziel — nicht dein Kontostand.' => 'Planungspuffer nach dem Ziel — nicht Ihr Kontostand.',
		'Der Betrag, den du zurücklegen willst (Prozentsatz, fester Betrag oder das höhere von beiden).' => 'Der Betrag, den Sie zurücklegen möchten (Prozentsatz, fester Betrag oder das höhere von beiden).',
		'Der Gesamtbetrag, den du in diesem Jahr tatsächlich gespart hast.' => 'Der Gesamtbetrag, den Sie in diesem Jahr tatsächlich gespart haben.',
		'Der Gesamtbetrag, den du in diesem Jahr sparen wolltest.' => 'Der Gesamtbetrag, den Sie in diesem Jahr sparen wollten.',
		'Dieser Monat nutzt aktuell das Standard-Sparziel des Arbeitsbereichs. Speichern hier erstellt eine monatsbezogene Überschreibung.' => 'Dieser Monat nutzt aktuell das Standard-Sparziel des Arbeitsbereichs. Speichern erstellt hier eine monatsbezogene Überschreibung.',
		'Verwende einen kurzen Namen, der erklärt, was sich wiederholt.' => 'Verwenden Sie einen kurzen Namen, der erklärt, was sich wiederholt.',
		'Für ungewöhnlich hohe oder einmalige Buchungen. Sie bleiben im Hauptbuch, sind aber von den monatlichen Alltagssummen ausgeschlossen, außer du schließt sie in den Arbeitsbereichseinstellungen ein.' => 'Für ungewöhnlich hohe oder einmalige Buchungen. Sie bleiben im Hauptbuch, sind aber von den monatlichen Alltagssummen ausgeschlossen, außer Sie schließen sie in den Arbeitsbereichseinstellungen ein.',
		'Erfasse deinen ersten Eintrag in der Buchungsansicht.' => 'Erfassen Sie Ihren ersten Eintrag in der Buchungsansicht.',
		'Arbeitsbereichsdetails ansehen und festlegen, wie Planungssummen für dich angezeigt werden.' => 'Arbeitsbereichsdetails ansehen und festlegen, wie Planungssummen für Sie angezeigt werden.',
		'Du bist noch in keinem Arbeitsbereich Mitglied.' => 'Sie sind noch in keinem Arbeitsbereich Mitglied.',
		'Du darfst BudgetCheck im Moment nicht nutzen.' => 'Sie dürfen BudgetCheck im Moment nicht nutzen.',
		'Du bist nicht berechtigt, diese Aktion auszuführen.' => 'Sie sind nicht berechtigt, diese Aktion auszuführen.',
		'Du kannst diesen Bereich dauerhaft ausblenden; er blockiert keine Aktion.' => 'Sie können diesen Bereich dauerhaft ausblenden; er blockiert keine Aktion.',
		'Du hast keine Berechtigung, die App-Einstellungen zu öffnen.' => 'Sie haben keine Berechtigung, die App-Einstellungen zu öffnen.',
		'Dein monatliches Sparziel und wie viel du beiseitegelegt hast.' => 'Ihr monatliches Sparziel und wie viel Sie beiseitegelegt haben.',
		'Deine Planungsansicht und schreibgeschützte Arbeitsbereichsdetails.' => 'Ihre Planungsansicht und schreibgeschützte Arbeitsbereichsdetails.',
		'Wähle eine Kategorie.' => 'Wählen Sie eine Kategorie.',
		'Wähle das Kalenderjahr für die Monatskarten unten. Jede Karte öffnet diesen Monat im Monatsplan.' => 'Wählen Sie das Kalenderjahr für die Monatskarten unten. Jede Karte öffnet diesen Monat im Monatsplan.',
		'Wähle die Kategorie, die zu dieser Buchung passt. Es werden nur Kategorien für die gewählte Richtung angezeigt.' => 'Wählen Sie die Kategorie, die zu dieser Buchung passt. Es werden nur Kategorien für die gewählte Richtung angezeigt.',
		'Erstelle deinen ersten Arbeitsbereich' => 'Erstellen Sie Ihren ersten Arbeitsbereich',
		'Geben Sie einen Prozentsatz zwischen 0 und 100 ein.' => 'Geben Sie einen Prozentsatz zwischen 0 und 100 ein.',
		'Gib einen Prozentsatz zwischen 0 und 100 ein.' => 'Geben Sie einen Prozentsatz zwischen 0 und 100 ein.',
		'Gib einen gültigen USt.-Satz in Basispunkten ein.' => 'Geben Sie einen gültigen USt.-Satz in Basispunkten ein.',
		'Gib einen gültigen Betrag ein, um Netto/USt./Brutto vorzuschauen.' => 'Geben Sie einen gültigen Betrag ein, um Netto/USt./Brutto vorzuschauen.',
		'Gib einen Betrag ein, um Netto/USt./Brutto vorzuschauen.' => 'Geben Sie einen Betrag ein, um Netto/USt./Brutto vorzuschauen.',
		'Gib einen Betrag ein.' => 'Geben Sie einen Betrag ein.',
		'Gib Betrag, Datum und optional einen Titel für Listen ein.' => 'Geben Sie Betrag, Datum und optional einen Titel für Listen ein.',
		'Gib den Betrag pro Wiederholung ein.' => 'Geben Sie den Betrag pro Wiederholung ein.',
		'Gib der Kategorie einen klaren Namen, den alle schnell erkennen.' => 'Geben Sie der Kategorie einen klaren Namen, den alle schnell erkennen.',
		'Wähle Kalenderjahr und -monat. Monate außerhalb von Projektstart und -ende werden ausgeblendet.' => 'Wählen Sie Kalenderjahr und -monat. Monate außerhalb von Projektstart und -ende werden ausgeblendet.',
		'Wähle in der Seitenleiste einen Haushalts-Arbeitsbereich.' => 'Wählen Sie in der Seitenleiste einen Haushalts-Arbeitsbereich.',
		'Wähle einen Haushalts-Arbeitsbereich, um einen Monat zu prüfen und zu schließen.' => 'Wählen Sie einen Haushalts-Arbeitsbereich, um einen Monat zu prüfen und zu schließen.',
		'Wähle einen Haushalts-Arbeitsbereich, um die Jahresübersicht zu sehen.' => 'Wählen Sie einen Haushalts-Arbeitsbereich, um die Jahresübersicht zu sehen.',
		'Wähle in der Seitenleiste ein Projekt aus.' => 'Wählen Sie in der Seitenleiste ein Projekt aus.',
		'Wähle einen Projekt-Arbeitsbereich, um Zeitraum-Summen und Obergrenzen-Warnungen zu sehen.' => 'Wählen Sie einen Projekt-Arbeitsbereich, um Zeitraum-Summen und Obergrenzen-Warnungen zu sehen.',
		'Wähle in der Seitenleiste einen Arbeitsbereich, um seine Budgets zu planen.' => 'Wählen Sie in der Seitenleiste einen Arbeitsbereich, um seine Budgets zu planen.',
		'Wähle in der Seitenleiste einen Arbeitsbereich, um sein Journal anzusehen.' => 'Wählen Sie in der Seitenleiste einen Arbeitsbereich, um sein Journal anzusehen.',
		'Wähle einen Arbeitsbereich, um Einnahmen und Ausgaben zu erfassen.' => 'Wählen Sie einen Arbeitsbereich, um Einnahmen und Ausgaben zu erfassen.',
		'Wähle einen Arbeitsbereich, um Monatsbudgets zu planen.' => 'Wählen Sie einen Arbeitsbereich, um Monatsbudgets zu planen.',
		'Wähle einen Arbeitsbereich, um Einnahmen, Ausgaben und Warnungen zu sehen.' => 'Wählen Sie einen Arbeitsbereich, um Einnahmen, Ausgaben und Warnungen zu sehen.',
		'Wähle in der Seitenleiste einen Arbeitsbereich, um die Arbeitsbereichseinstellungen zu öffnen.' => 'Wählen Sie in der Seitenleiste einen Arbeitsbereich, um die Arbeitsbereichseinstellungen zu öffnen.',
		'Wähle in der Seitenleiste einen Arbeitsbereich, um seine Einstellungen zu sehen.' => 'Wählen Sie in der Seitenleiste einen Arbeitsbereich, um seine Einstellungen zu sehen.',
		'{count} Buchungen in diesem Zeitraum. Wähle einen Kalendermonat, um einen einzelnen Monat zu fokussieren.' => '{count} Buchungen in diesem Zeitraum. Wählen Sie einen Kalendermonat, um einen einzelnen Monat zu fokussieren.',
		'Füge zuerst Einnahmen- oder Ausgabenkategorien hinzu.' => 'Fügen Sie zuerst Einnahmen- oder Ausgabenkategorien hinzu.',
		'Direkt zur passenden Ansicht springen.' => 'Springen Sie direkt zur passenden Ansicht.',
		'Hänge Fotos oder PDF-Belege an diese Buchung an. Füge sie unten hinzu — sie werden beim Speichern hochgeladen. Nur Mitglieder des Arbeitsbereichs können sie sehen.' => 'Hängen Sie Fotos oder PDF-Belege an diese Buchung an. Fügen Sie sie unten hinzu — sie werden beim Speichern hochgeladen. Nur Mitglieder des Arbeitsbereichs können sie sehen.',
		'Hänge Fotos, PDF-Belege oder XML-E-Rechnungen an diese Buchung an. Füge sie unten hinzu — sie werden beim Speichern hochgeladen. Nur Mitglieder des Arbeitsbereichs können sie sehen.' => 'Hängen Sie Fotos, PDF-Belege oder XML-E-Rechnungen an diese Buchung an. Fügen Sie sie unten hinzu — sie werden beim Speichern hochgeladen. Nur Mitglieder des Arbeitsbereichs können sie sehen.',
		'Prüfe die Verbindung und versuche es erneut. Der letzte Versuch wurde nicht abgeschlossen.' => 'Prüfen Sie die Verbindung und versuchen Sie es erneut. Der letzte Versuch wurde nicht abgeschlossen.',
		'Lade den Projektzeitraum als Excel-Arbeitsmappe herunter.' => 'Laden Sie den Projektzeitraum als Excel-Arbeitsmappe herunter.',
		'Lade das ausgewählte Jahr als Excel-Arbeitsmappe herunter.' => 'Laden Sie das ausgewählte Jahr als Excel-Arbeitsmappe herunter.',
		'Gruppen-, Kategorie- und Monatssummen für das aktuelle Filterergebnis. Klicke eine Zeile an, um detaillierter zu filtern.' => 'Gruppen-, Kategorie- und Monatssummen für das aktuelle Filterergebnis. Klicken Sie eine Zeile an, um detaillierter zu filtern.',
		'Lade …' => 'Wird geladen …',
		'Der Monat ist abgeschlossen. Öffne ihn wieder, bevor du Budgetziele änderst.' => 'Der Monat ist abgeschlossen. Öffnen Sie ihn wieder, bevor Sie Budgetziele ändern.',
		'Monat ist abgeschlossen. Öffne ihn erneut, bevor du geplante Einträge erzeugst.' => 'Monat ist abgeschlossen. Öffnen Sie ihn erneut, bevor Sie geplante Einträge erzeugen.',
		'Benenne Kategorien für dein Budget. Gruppen sind für wenige eigene Buckets in Berichten und Filtern — keine Bankhierarchie. Notizen an Buchungen eignen sich gut für Empfängernamen.' => 'Benennen Sie Kategorien für Ihr Budget. Gruppen sind für wenige eigene Buckets in Berichten und Filtern — keine Bankhierarchie. Notizen an Buchungen eignen sich gut für Empfängernamen.',
		'Keine passende Währung. Versuche einen anderen ISO-Code.' => 'Keine passende Währung. Versuchen Sie einen anderen ISO-Code.',
		'Keine passende Zeitzone. Versuche einen Städte- oder Regionsnamen.' => 'Keine passende Zeitzone. Versuchen Sie einen Städte- oder Regionsnamen.',
		'Noch keine Arbeitsbereiche im Schnellzugriff. Nutze „Verwalten“, um Favoriten anzupinnen.' => 'Noch keine Arbeitsbereiche im Schnellzugriff. Nutzen Sie „Verwalten“, um Favoriten anzupinnen.',
		'Öffne eine einfache Erklärung zu jedem Wert und seiner Bedeutung für dieses Jahr.' => 'Öffnen Sie eine einfache Erklärung zu jedem Wert und seiner Bedeutung für dieses Jahr.',
		'Öffne die Projektübersicht, um die Gesamtausgaben gegenüber der Obergrenze zu sehen.' => 'Öffnen Sie die Projektübersicht, um die Gesamtausgaben gegenüber der Obergrenze zu sehen.',
		'Prüfe die Vorschau und klicke dann auf „Buchungen importieren“.' => 'Prüfen Sie die Vorschau und klicken Sie dann auf „Buchungen importieren“.',
		'Zeile {row}: Betrag ist ungültig. Nutze Formate wie 42,50 oder 1.234,56 oder 1234.56 (ohne Währungssymbol).' => 'Zeile {row}: Betrag ist ungültig. Nutzen Sie Formate wie 42,50 oder 1.234,56 oder 1234.56 (ohne Währungssymbol).',
		'Zeile {row}: Datum ist ungültig. Nutze {pattern} oder JJJJ-MM-TT.' => 'Zeile {row}: Datum ist ungültig. Nutzen Sie {pattern} oder JJJJ-MM-TT.',
		'Zeile {row}: unbekannte Kategorie „{name}“. Nutze einen Namen aus der Liste oben.' => 'Zeile {row}: unbekannte Kategorie „{name}“. Nutzen Sie einen Namen aus der Liste oben.',
		'Die ersten {count} Treffer werden angezeigt. Tippe weiter, um die Liste einzugrenzen.' => 'Die ersten {count} Treffer werden angezeigt. Tippen Sie weiter, um die Liste einzugrenzen.',
		'Das neue Buchungsdatum fällt in einen abgeschlossenen Monat. Öffne diesen Monat zuerst wieder.' => 'Das neue Buchungsdatum fällt in einen abgeschlossenen Monat. Öffnen Sie diesen Monat zuerst wieder.',
		'Diese Buchung fällt in einen abgeschlossenen Monat. Öffne den Monat wieder, bevor du Buchungen hinzufügst.' => 'Diese Buchung fällt in einen abgeschlossenen Monat. Öffnen Sie den Monat wieder, bevor Sie Buchungen hinzufügen.',
		'Dieser Monat ist geschlossen. Öffne ihn wieder, um Änderungen vorzunehmen.' => 'Dieser Monat ist geschlossen. Öffnen Sie ihn wieder, um Änderungen vorzunehmen.',
		'Dieser Monat ist offen. Prüfe die Summen vor dem Schließen.' => 'Dieser Monat ist offen. Prüfen Sie die Summen vor dem Schließen.',
		'Diese Buchung gehört zu einem abgeschlossenen Monat. Öffne den Monat wieder, bevor du sie bearbeitest.' => 'Diese Buchung gehört zu einem abgeschlossenen Monat. Öffnen Sie den Monat wieder, bevor Sie sie bearbeiten.',
		'Probiere einen größeren Zeitraum, eine andere Kategorie oder setze die Filter zurück, um alles zu sehen.' => 'Probieren Sie einen größeren Zeitraum, eine andere Kategorie oder setzen Sie die Filter zurück, um alles zu sehen.',
		'Lade eine CSV-Datei hoch, prüfe jede Zeile und importiere sie sicher in einem atomaren Schritt.' => 'Laden Sie eine CSV-Datei hoch, prüfen Sie jede Zeile und importieren Sie sie sicher in einem atomaren Schritt.',
		'Lade eine CSV von deiner Bank oder aus einer Tabelle hoch. Numerische Kategorie-IDs brauchst du nie — wähle unten Standards oder nutze Kategorienamen aus der Liste.' => 'Laden Sie eine CSV von Ihrer Bank oder aus einer Tabelle hoch. Numerische Kategorie-IDs brauchen Sie nie — wählen Sie unten Standards oder nutzen Sie Kategorienamen aus der Liste.',
		'Lade deine CSV hoch und klicke auf „CSV prüfen“.' => 'Laden Sie Ihre CSV hoch und klicken Sie auf „CSV prüfen“.',
		'Prüfe deine CSV vor dem Import.' => 'Prüfen Sie Ihre CSV vor dem Import.',
		'Arbeitsbereichseinstellungen, Mitglieder, Kategorien, Sparziele und Steuermodus werden in BudgetCheck verwaltet und gelten je Arbeitsbereich. Öffne die App, um globale Richtlinie, Verzeichniszugriff und Vorgaben anzupassen.' => 'Arbeitsbereichseinstellungen, Mitglieder, Kategorien, Sparziele und Steuermodus werden in BudgetCheck verwaltet und gelten je Arbeitsbereich. Öffnen Sie die App, um globale Richtlinie, Verzeichniszugriff und Vorgaben anzupassen.',
		'Du brauchst Mitwirkenden- oder Verwalterzugriff, um Buchungen in diesen Arbeitsbereich zu importieren.' => 'Sie brauchen Mitwirkenden- oder Verwalterzugriff, um Buchungen in diesen Arbeitsbereich zu importieren.',
		'Tippe, um alle IANA-Zeitzonen zu durchsuchen.' => 'Tippen Sie, um alle IANA-Zeitzonen zu durchsuchen.',
		'Tippe, um unterstützte Währungen zu durchsuchen.' => 'Tippen Sie, um unterstützte Währungen zu durchsuchen.',
		'Jahresübersicht → nutze die Jahresansicht für die Haushaltsbilanz.' => 'Jahresübersicht → nutzen Sie die Jahresansicht für die Haushaltsbilanz.',
		'Trage einen Namen in eine „category“-Spalte ein oder nutze die Standardauswahl unten. IDs sind nicht nötig.' => 'Tragen Sie einen Namen in eine „category“-Spalte ein oder nutzen Sie die Standardauswahl unten. IDs sind nicht nötig.',
		'Daten folgen der Spracheinstellung deines Kontos (z. B. TT.MM.JJJJ auf Deutsch). ISO-Daten (JJJJ-MM-TT) funktionieren immer.' => 'Daten folgen der Spracheinstellung Ihres Kontos (z. B. TT.MM.JJJJ auf Deutsch). ISO-Daten (JJJJ-MM-TT) funktionieren immer.',
	];
}

/**
 * Returns true when a German UI string still uses informal du register.
 */
function budgetcheck_german_is_informal(string $text): bool
{
	static $pattern = '/\b(du|dich|dir|dein|deine|deinen|deinem|deiner|deins|bist|kannst|musst|hast|wirst|warst|darfst|brauchst|änderst|erzeugst|hinzufügst|bearbeitest)\b|(?:^|[.!?]\s+|—\s+)(Wähle|Gib|Erstelle|Verwende|Aktiviere|Markiere|Füge|Lege|Erfasse|Hänge|Prüfe|Tippe|Klicke|Drücke|Öffne|Nutze|Lade|Benenne|Probiere|Versuche|Trage|Scanne)\s/u';
	return (bool)preg_match($pattern, $text);
}
