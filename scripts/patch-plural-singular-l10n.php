#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Singular msgids for the count-rendered strings that previously shipped an
 * unconditional "{count} <plural noun>" (rendering "1 bookings" / "1 pièces
 * jointes" — wrong in English at n=1 and in French at n=0). Call sites now use
 * n(app, singular, plural, count, vars); the existing "{count}" keys stay as
 * the plural msgids so all current translations are preserved.
 *
 * Run:
 *   php scripts/patch-plural-singular-l10n.php
 *   php scripts/sync-l10n-keys-from-en.php
 *   php scripts/sync-l10n-variants.php
 *   php scripts/regenerate-l10n-js.php
 */

$base = __DIR__ . '/../l10n';

const K_BOOKINGS_STATS = '%n booking (including planned and deleted drafts)';
const K_ATTACHMENTS = '%n attachment';
const K_CLOSED_MONTHS = '%n closed month';
const K_ENTRIES_RULES = 'Added %n entry across {rules} rules.';
const K_RULES_ATTENTION = '%n rule needed attention.';
const K_INVALID_ROWS = 'Validation failed: %n invalid row. First error: {error}';
const K_ROWS_READY_SKIP = 'Validation passed. %n row ready; {skipped} duplicates will be skipped.';
const K_ROWS_READY = 'Validation passed. %n row is ready to import.';
const K_IMPORTED_SKIP = 'Imported %n transaction. Skipped {skipped} duplicates.';
const K_IMPORTED = 'Imported %n transaction successfully.';
const K_BOOKINGS_RANGE = '%n booking falls into this date range. Pick a calendar month to focus one month.';

$patch = [
	'en' => [
		K_BOOKINGS_STATS => K_BOOKINGS_STATS,
		K_ATTACHMENTS => K_ATTACHMENTS,
		K_CLOSED_MONTHS => K_CLOSED_MONTHS,
		K_ENTRIES_RULES => K_ENTRIES_RULES,
		K_RULES_ATTENTION => K_RULES_ATTENTION,
		K_INVALID_ROWS => K_INVALID_ROWS,
		K_ROWS_READY_SKIP => K_ROWS_READY_SKIP,
		K_ROWS_READY => K_ROWS_READY,
		K_IMPORTED_SKIP => K_IMPORTED_SKIP,
		K_IMPORTED => K_IMPORTED,
		K_BOOKINGS_RANGE => K_BOOKINGS_RANGE,
	],
	'da' => [
		K_BOOKINGS_STATS => '%n postering (inkl. planlagte og slettede kladder)',
		K_ATTACHMENTS => '%n vedhæftning',
		K_CLOSED_MONTHS => '%n lukket måned',
		K_ENTRIES_RULES => 'Tilføjede %n post på tværs af {rules} regler.',
		K_RULES_ATTENTION => '%n regel krævede opmærksomhed.',
		K_INVALID_ROWS => 'Validering mislykkedes: %n ugyldig række. Første fejl: {error}',
		K_ROWS_READY_SKIP => 'Validering lykkedes. %n række klar; {skipped} dubletter springes over.',
		K_ROWS_READY => 'Validering lykkedes. %n række er klar til import.',
		K_IMPORTED_SKIP => '%n postering importeret. {skipped} dubletter sprunget over.',
		K_IMPORTED => '%n postering importeret.',
		K_BOOKINGS_RANGE => '%n postering falder i dette datointerval. Vælg en kalendermåned for at fokusere på én måned.',
	],
	'de' => [
		K_BOOKINGS_STATS => '%n Buchung (einschließlich geplanter und gelöschter Entwürfe)',
		K_ATTACHMENTS => '%n Anhang',
		K_CLOSED_MONTHS => '%n abgeschlossener Monat',
		K_ENTRIES_RULES => '%n Eintrag aus {rules} Regeln hinzugefügt.',
		K_RULES_ATTENTION => '%n Regel braucht Aufmerksamkeit.',
		K_INVALID_ROWS => 'Prüfung fehlgeschlagen: %n ungültige Zeile. Erster Fehler: {error}',
		K_ROWS_READY_SKIP => 'Prüfung erfolgreich. %n Zeile bereit; {skipped} Duplikate werden übersprungen.',
		K_ROWS_READY => 'Prüfung erfolgreich. %n Zeile ist bereit zum Import.',
		K_IMPORTED_SKIP => '%n Buchung importiert. {skipped} Duplikate übersprungen.',
		K_IMPORTED => '%n Buchung erfolgreich importiert.',
		K_BOOKINGS_RANGE => '%n Buchung in diesem Zeitraum. Wählen Sie einen Kalendermonat, um einen einzelnen Monat zu fokussieren.',
	],
	'es' => [
		K_BOOKINGS_STATS => '%n anotación (incluidos borradores planificados y eliminados)',
		K_ATTACHMENTS => '%n adjunto',
		K_CLOSED_MONTHS => '%n mes cerrado',
		K_ENTRIES_RULES => 'Se añadió %n entrada en {rules} reglas.',
		K_RULES_ATTENTION => '%n regla necesita atención.',
		K_INVALID_ROWS => 'Validación fallida: %n fila no válida. Primer error: {error}',
		K_ROWS_READY_SKIP => 'Validación correcta. %n fila lista; se omitirán {skipped} duplicados.',
		K_ROWS_READY => 'Validación correcta. %n fila lista para importar.',
		K_IMPORTED_SKIP => '%n movimiento importado. {skipped} duplicados omitidos.',
		K_IMPORTED => '%n movimiento importado.',
		K_BOOKINGS_RANGE => '%n asiento se encuentra en este rango de fechas. Elija un mes calendario para concentrarse en un mes.',
	],
	'fr' => [
		K_BOOKINGS_STATS => '%n écriture (y compris les brouillons planifiés et supprimés)',
		K_ATTACHMENTS => '%n pièce jointe',
		K_CLOSED_MONTHS => '%n mois clôturé',
		K_ENTRIES_RULES => '%n écriture ajoutée pour {rules} règles.',
		K_RULES_ATTENTION => '%n règle nécessite une attention.',
		K_INVALID_ROWS => 'Contrôle échoué : %n ligne non valide. Première erreur : {error}',
		K_ROWS_READY_SKIP => 'Contrôle réussi. %n ligne prête ; {skipped} doublons seront ignorés.',
		K_ROWS_READY => 'Contrôle réussi. %n ligne est prête à importer.',
		K_IMPORTED_SKIP => '%n écriture importée. {skipped} doublons ignorés.',
		K_IMPORTED => '%n écriture importée.',
		K_BOOKINGS_RANGE => '%n écriture se situe dans cette plage de dates. Choisissez un mois civil pour vous concentrer sur un mois.',
	],
	'it' => [
		K_BOOKINGS_STATS => '%n registrazione (incluse bozze pianificate ed eliminate)',
		K_ATTACHMENTS => '%n allegato',
		K_CLOSED_MONTHS => '%n mese chiuso',
		K_ENTRIES_RULES => 'Aggiunta %n voce su {rules} regole.',
		K_RULES_ATTENTION => '%n regola richiede attenzione.',
		K_INVALID_ROWS => 'Convalida non riuscita: %n riga non valida. Primo errore: {error}',
		K_ROWS_READY_SKIP => 'Convalida riuscita. %n riga pronta; {skipped} duplicati verranno saltati.',
		K_ROWS_READY => 'Convalida riuscita. %n riga pronta per l’importazione.',
		K_IMPORTED_SKIP => '%n registrazione importata. {skipped} duplicati saltati.',
		K_IMPORTED => '%n registrazione importata.',
		K_BOOKINGS_RANGE => '%n registrazione rientra in questo intervallo di date. Scelga un mese di calendario per concentrarsi su un mese.',
	],
	'nb' => [
		K_BOOKINGS_STATS => '%n postering (inkludert planlagte og slettede utkast)',
		K_ATTACHMENTS => '%n vedlegg',
		K_CLOSED_MONTHS => '%n lukket måned',
		K_ENTRIES_RULES => 'La til %n post på tvers av {rules} regler.',
		K_RULES_ATTENTION => '%n regel trengte oppmerksomhet.',
		K_INVALID_ROWS => 'Valideringen mislyktes: %n ugyldig rad. Første feil: {error}',
		K_ROWS_READY_SKIP => 'Valideringen lyktes. %n rad klar; {skipped} duplikater hoppes over.',
		K_ROWS_READY => 'Valideringen lyktes. %n rad er klar til import.',
		K_IMPORTED_SKIP => '%n postering importert. Hoppet over {skipped} duplikater.',
		K_IMPORTED => '%n postering importert.',
		K_BOOKINGS_RANGE => '%n postering faller innenfor dette datointervallet. Velg en kalendermåned for å fokusere på én måned.',
	],
	'nl' => [
		K_BOOKINGS_STATS => '%n boeking (inclusief geplande en verwijderde concepten)',
		K_ATTACHMENTS => '%n bijlage',
		K_CLOSED_MONTHS => '%n afgesloten maand',
		K_ENTRIES_RULES => '%n regel toegevoegd over {rules} regels.',
		K_RULES_ATTENTION => '%n regel vroeg aandacht.',
		K_INVALID_ROWS => 'Validatie mislukt: %n ongeldige rij. Eerste fout: {error}',
		K_ROWS_READY_SKIP => 'Validatie geslaagd. %n rij klaar; {skipped} duplicaten worden overgeslagen.',
		K_ROWS_READY => 'Validatie geslaagd. %n rij is klaar om te importeren.',
		K_IMPORTED_SKIP => '%n boeking geïmporteerd. {skipped} duplicaten overgeslagen.',
		K_IMPORTED => '%n boeking geïmporteerd.',
		K_BOOKINGS_RANGE => '%n boeking valt in dit datumbereik. Kies een kalendermaand waar u zich op één maand wilt concentreren.',
	],
	'pl' => [
		K_BOOKINGS_STATS => '%n wpis (w tym zaplanowane i usunięte szkice)',
		K_ATTACHMENTS => '%n załącznik',
		K_CLOSED_MONTHS => '%n zamknięty miesiąc',
		K_ENTRIES_RULES => 'Dodano %n wpis w {rules} regułach.',
		K_RULES_ATTENTION => '%n reguła wymaga uwagi.',
		K_INVALID_ROWS => 'Walidacja nie powiodła się: %n nieprawidłowy wiersz. Pierwszy błąd: {error}',
		K_ROWS_READY_SKIP => 'Walidacja zakończona. %n wiersz gotowy; {skipped} duplikatów zostanie pominiętych.',
		K_ROWS_READY => 'Walidacja zakończona. %n wiersz gotowy do importu.',
		K_IMPORTED_SKIP => 'Zaimportowano %n księgowanie. Pominięto {skipped} duplikatów.',
		K_IMPORTED => 'Zaimportowano %n księgowanie.',
		K_BOOKINGS_RANGE => '%n księgowanie w tym zakresie dat. Wybierz miesiąc kalendarzowy, aby skupić się na jednym miesiącu.',
	],
	'pt_BR' => [
		K_BOOKINGS_STATS => '%n lançamento (incluindo rascunhos planejados e excluídos)',
		K_ATTACHMENTS => '%n anexo',
		K_CLOSED_MONTHS => '%n mês fechado',
		K_ENTRIES_RULES => 'Adicionada %n entrada em {rules} regras.',
		K_RULES_ATTENTION => '%n regra precisou de atenção.',
		K_INVALID_ROWS => 'Falha na validação: %n linha inválida. Primeiro erro: {error}',
		K_ROWS_READY_SKIP => 'Validação aprovada. %n linha pronta; {skipped} duplicatas serão ignoradas.',
		K_ROWS_READY => 'Validação aprovada. %n linha está pronta para importação.',
		K_IMPORTED_SKIP => '%n transação importada. {skipped} duplicatas ignoradas.',
		K_IMPORTED => '%n transação importada com sucesso.',
		K_BOOKINGS_RANGE => '%n reserva se enquadra neste intervalo de datas. Escolha um mês para focar em um mês.',
	],
	'sv' => [
		K_BOOKINGS_STATS => '%n bokföring (inklusive planerade och raderade utkast)',
		K_ATTACHMENTS => '%n bilaga',
		K_CLOSED_MONTHS => '%n stängd månad',
		K_ENTRIES_RULES => 'Lade till %n post över {rules} regler.',
		K_RULES_ATTENTION => '%n regel behövde uppmärksamhet.',
		K_INVALID_ROWS => 'Valideringen misslyckades: %n ogiltig rad. Första felet: {error}',
		K_ROWS_READY_SKIP => 'Valideringen lyckades. %n rad redo; {skipped} dubbletter hoppas över.',
		K_ROWS_READY => 'Valideringen lyckades. %n rad är redo att importeras.',
		K_IMPORTED_SKIP => '%n transaktion importerad. {skipped} dubbletter hoppades över.',
		K_IMPORTED => '%n transaktion importerad.',
		K_BOOKINGS_RANGE => '%n transaktion faller inom det här datumintervallet. Välj en kalendermånad för att fokusera på en månad.',
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
