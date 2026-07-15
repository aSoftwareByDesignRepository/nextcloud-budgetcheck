#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Add translations for the "specific dates" recurring-rule schedules (issue #11)
 * to every base catalog. Variant catalogs are regenerated afterwards via
 * scripts/sync-l10n-variants.php + scripts/regenerate-l10n-js.php.
 *
 * Usage: php scripts/patch-schedule-l10n.php
 */

$base = dirname(__DIR__) . '/l10n';
$languages = ['en', 'de', 'fr', 'es', 'da', 'nl', 'it', 'pl', 'sv', 'nb'];

/** Keys removed everywhere (replaced by the reworded recurring-section subtitle). */
$removedKeys = [
	'Repeating income or expenses. Generate creates planned ledger entries; a matching import removes the plan automatically.',
];

/** @var array<string, array<string, string>> msgid => lang => translation */
$strings = [
	'Repeating income or expenses — on a fixed interval or on specific dates you list. Generate creates planned ledger entries; a matching import removes the plan automatically.' => [
		'de' => 'Wiederkehrende Einnahmen oder Ausgaben – in festen Abständen oder an bestimmten, selbst festgelegten Terminen. Erzeugen erstellt geplante Buchungen; ein passender Import entfernt den Plan automatisch.',
		'fr' => 'Revenus ou dépenses récurrents — à intervalle fixe ou à des dates précises que vous indiquez. Générer crée des écritures planifiées ; un import correspondant supprime automatiquement le plan.',
		'es' => 'Ingresos o gastos recurrentes: con un intervalo fijo o en fechas específicas que usted indica. Generar crea registros planificados; una importación coincidente elimina el plan automáticamente.',
		'da' => 'Gentagne indtægter eller udgifter – med fast interval eller på bestemte datoer, du angiver. Generér opretter planlagte posteringer; en matchende import fjerner planen automatisk.',
		'nl' => 'Terugkerende inkomsten of uitgaven — met een vast interval of op specifieke datums die u opgeeft. Genereren maakt geplande boekingen aan; een overeenkomende import verwijdert de planning automatisch.',
		'it' => "Entrate o uscite ricorrenti — a intervallo fisso o in date specifiche da indicare. Genera crea registrazioni pianificate; un'importazione corrispondente rimuove automaticamente il piano.",
		'pl' => 'Powtarzające się przychody lub wydatki — w stałych odstępach lub w konkretnych, wskazanych datach. Generowanie tworzy planowane pozycje; pasujący import automatycznie usuwa plan.',
		'sv' => 'Återkommande inkomster eller utgifter — med fast intervall eller på specifika datum som du anger. Generera skapar planerade bokningar; en matchande import tar bort planen automatiskt.',
		'nb' => 'Gjentakende inntekter eller utgifter — med fast intervall eller på bestemte datoer du angir. Generer oppretter planlagte posteringer; en matchende import fjerner planen automatisk.',
	],
	'Specific dates' => [
		'de' => 'Bestimmte Termine',
		'fr' => 'Dates précises',
		'es' => 'Fechas específicas',
		'da' => 'Bestemte datoer',
		'nl' => 'Specifieke datums',
		'it' => 'Date specifiche',
		'pl' => 'Konkretne daty',
		'sv' => 'Specifika datum',
		'nb' => 'Bestemte datoer',
	],
	'Specific dates (1 date)' => [
		'de' => 'Bestimmte Termine (1 Termin)',
		'fr' => 'Dates précises (1 date)',
		'es' => 'Fechas específicas (1 fecha)',
		'da' => 'Bestemte datoer (1 dato)',
		'nl' => 'Specifieke datums (1 datum)',
		'it' => 'Date specifiche (1 data)',
		'pl' => 'Konkretne daty (1 data)',
		'sv' => 'Specifika datum (1 datum)',
		'nb' => 'Bestemte datoer (1 dato)',
	],
	'Specific dates ({count} dates)' => [
		'de' => 'Bestimmte Termine ({count} Termine)',
		'fr' => 'Dates précises ({count} dates)',
		'es' => 'Fechas específicas ({count} fechas)',
		'da' => 'Bestemte datoer ({count} datoer)',
		'nl' => 'Specifieke datums ({count} datums)',
		'it' => 'Date specifiche ({count} date)',
		'pl' => 'Konkretne daty ({count} dat)',
		'sv' => 'Specifika datum ({count} datum)',
		'nb' => 'Bestemte datoer ({count} datoer)',
	],
	'Varies (default {amount})' => [
		'de' => 'Variiert (Standard {amount})',
		'fr' => 'Variable (par défaut {amount})',
		'es' => 'Varía (predeterminado {amount})',
		'da' => 'Varierer (standard {amount})',
		'nl' => 'Varieert (standaard {amount})',
		'it' => 'Varia (predefinito {amount})',
		'pl' => 'Zmienna (domyślnie {amount})',
		'sv' => 'Varierar (standard {amount})',
		'nb' => 'Varierer (standard {amount})',
	],
	'Scheduled dates' => [
		'de' => 'Geplante Termine',
		'fr' => 'Dates planifiées',
		'es' => 'Fechas programadas',
		'da' => 'Planlagte datoer',
		'nl' => 'Geplande datums',
		'it' => 'Date pianificate',
		'pl' => 'Zaplanowane daty',
		'sv' => 'Schemalagda datum',
		'nb' => 'Planlagte datoer',
	],
	"Add one row for each date this rule should create a planned booking. Leave a row's amount empty to use the default amount above. Dates can be in any order; they are sorted automatically." => [
		'de' => 'Für jeden Termin, an dem diese Regel eine geplante Buchung erstellen soll, eine Zeile hinzufügen. Den Betrag einer Zeile leer lassen, um den Standardbetrag oben zu verwenden. Die Reihenfolge ist egal; die Termine werden automatisch sortiert.',
		'fr' => "Ajoutez une ligne pour chaque date à laquelle cette règle doit créer une écriture planifiée. Laissez le montant d'une ligne vide pour utiliser le montant par défaut ci-dessus. L'ordre des dates n'a pas d'importance ; elles sont triées automatiquement.",
		'es' => 'Añada una fila por cada fecha en la que esta regla debe crear un registro planificado. Deje el importe de una fila vacío para usar el importe predeterminado de arriba. Las fechas pueden estar en cualquier orden; se ordenan automáticamente.',
		'da' => 'Tilføj en række for hver dato, hvor denne regel skal oprette en planlagt postering. Lad rækkens beløb være tomt for at bruge standardbeløbet ovenfor. Datoerne kan stå i vilkårlig rækkefølge; de sorteres automatisk.',
		'nl' => 'Voeg een rij toe voor elke datum waarop deze regel een geplande boeking moet aanmaken. Laat het bedrag van een rij leeg om het standaardbedrag hierboven te gebruiken. Datums mogen in willekeurige volgorde staan; ze worden automatisch gesorteerd.',
		'it' => "Aggiungere una riga per ogni data in cui questa regola deve creare una registrazione pianificata. Lasciare vuoto l'importo di una riga per usare l'importo predefinito sopra. Le date possono essere in qualsiasi ordine; vengono ordinate automaticamente.",
		'pl' => 'Dodaj wiersz dla każdej daty, w której ta reguła ma utworzyć planowaną pozycję. Pozostaw kwotę wiersza pustą, aby użyć domyślnej kwoty powyżej. Daty mogą być w dowolnej kolejności; są sortowane automatycznie.',
		'sv' => 'Lägg till en rad för varje datum då denna regel ska skapa en planerad bokning. Lämna radens belopp tomt för att använda standardbeloppet ovan. Datumen kan stå i valfri ordning; de sorteras automatiskt.',
		'nb' => 'Legg til en rad for hver dato denne regelen skal opprette en planlagt postering for. La beløpsfeltet stå tomt for å bruke standardbeløpet ovenfor. Datoene kan stå i vilkårlig rekkefølge; de sorteres automatisk.',
	],
	'Add date' => [
		'de' => 'Termin hinzufügen',
		'fr' => 'Ajouter une date',
		'es' => 'Añadir fecha',
		'da' => 'Tilføj dato',
		'nl' => 'Datum toevoegen',
		'it' => 'Aggiungi data',
		'pl' => 'Dodaj datę',
		'sv' => 'Lägg till datum',
		'nb' => 'Legg til dato',
	],
	'1 date scheduled.' => [
		'de' => '1 Termin geplant.',
		'fr' => '1 date planifiée.',
		'es' => '1 fecha programada.',
		'da' => '1 dato planlagt.',
		'nl' => '1 datum gepland.',
		'it' => '1 data pianificata.',
		'pl' => 'Zaplanowano 1 datę.',
		'sv' => '1 datum schemalagt.',
		'nb' => '1 dato planlagt.',
	],
	'{count} dates scheduled.' => [
		'de' => '{count} Termine geplant.',
		'fr' => '{count} dates planifiées.',
		'es' => '{count} fechas programadas.',
		'da' => '{count} datoer planlagt.',
		'nl' => '{count} datums gepland.',
		'it' => '{count} date pianificate.',
		'pl' => 'Zaplanowane daty: {count}.',
		'sv' => '{count} datum schemalagda.',
		'nb' => '{count} datoer planlagt.',
	],
	'Scheduled date' => [
		'de' => 'Geplanter Termin',
		'fr' => 'Date planifiée',
		'es' => 'Fecha programada',
		'da' => 'Planlagt dato',
		'nl' => 'Geplande datum',
		'it' => 'Data pianificata',
		'pl' => 'Zaplanowana data',
		'sv' => 'Schemalagt datum',
		'nb' => 'Planlagt dato',
	],
	'Amount for this date (optional)' => [
		'de' => 'Betrag für diesen Termin (optional)',
		'fr' => 'Montant pour cette date (facultatif)',
		'es' => 'Importe para esta fecha (opcional)',
		'da' => 'Beløb for denne dato (valgfrit)',
		'nl' => 'Bedrag voor deze datum (optioneel)',
		'it' => 'Importo per questa data (facoltativo)',
		'pl' => 'Kwota dla tej daty (opcjonalnie)',
		'sv' => 'Belopp för detta datum (valfritt)',
		'nb' => 'Beløp for denne datoen (valgfritt)',
	],
	'Remove this date' => [
		'de' => 'Diesen Termin entfernen',
		'fr' => 'Supprimer cette date',
		'es' => 'Eliminar esta fecha',
		'da' => 'Fjern denne dato',
		'nl' => 'Deze datum verwijderen',
		'it' => 'Rimuovi questa data',
		'pl' => 'Usuń tę datę',
		'sv' => 'Ta bort detta datum',
		'nb' => 'Fjern denne datoen',
	],
	'Used for scheduled dates that do not set their own amount.' => [
		'de' => 'Wird für geplante Termine verwendet, die keinen eigenen Betrag haben.',
		'fr' => 'Utilisé pour les dates planifiées sans montant propre.',
		'es' => 'Se usa para las fechas programadas que no tienen su propio importe.',
		'da' => 'Bruges til planlagte datoer uden eget beløb.',
		'nl' => 'Gebruikt voor geplande datums zonder eigen bedrag.',
		'it' => 'Usato per le date pianificate senza importo proprio.',
		'pl' => 'Używana dla zaplanowanych dat bez własnej kwoty.',
		'sv' => 'Används för schemalagda datum utan eget belopp.',
		'nb' => 'Brukes for planlagte datoer uten eget beløp.',
	],
	'Every scheduled row needs a valid date.' => [
		'de' => 'Jede Zeile braucht einen gültigen Termin.',
		'fr' => 'Chaque ligne planifiée doit avoir une date valide.',
		'es' => 'Cada fila programada necesita una fecha válida.',
		'da' => 'Hver planlagt række skal have en gyldig dato.',
		'nl' => 'Elke geplande rij heeft een geldige datum nodig.',
		'it' => 'Ogni riga pianificata richiede una data valida.',
		'pl' => 'Każdy zaplanowany wiersz wymaga prawidłowej daty.',
		'sv' => 'Varje schemalagd rad behöver ett giltigt datum.',
		'nb' => 'Hver planlagt rad trenger en gyldig dato.',
	],
	'The date {date} is listed twice.' => [
		'de' => 'Der Termin {date} ist doppelt aufgeführt.',
		'fr' => 'La date {date} apparaît deux fois.',
		'es' => 'La fecha {date} aparece dos veces.',
		'da' => 'Datoen {date} er angivet to gange.',
		'nl' => 'De datum {date} staat er twee keer in.',
		'it' => 'La data {date} è presente due volte.',
		'pl' => 'Data {date} występuje dwukrotnie.',
		'sv' => 'Datumet {date} finns med två gånger.',
		'nb' => 'Datoen {date} er oppført to ganger.',
	],
	'Add at least one scheduled date.' => [
		'de' => 'Bitte mindestens einen Termin hinzufügen.',
		'fr' => 'Ajoutez au moins une date planifiée.',
		'es' => 'Añada al menos una fecha programada.',
		'da' => 'Tilføj mindst én planlagt dato.',
		'nl' => 'Voeg minstens één geplande datum toe.',
		'it' => 'Aggiungere almeno una data pianificata.',
		'pl' => 'Dodaj co najmniej jedną zaplanowaną datę.',
		'sv' => 'Lägg till minst ett schemalagt datum.',
		'nb' => 'Legg til minst én planlagt dato.',
	],
	'No scheduled date on or after the realign date.' => [
		'de' => 'Kein geplanter Termin am oder nach dem Datum zum Neu-Ausrichten.',
		'fr' => 'Aucune date planifiée à partir de la date de réalignement.',
		'es' => 'No hay ninguna fecha programada a partir de la fecha de realineación.',
		'da' => 'Ingen planlagt dato på eller efter justeringsdatoen.',
		'nl' => 'Geen geplande datum op of na de heruitlijningsdatum.',
		'it' => 'Nessuna data pianificata alla data di riallineamento o dopo.',
		'pl' => 'Brak zaplanowanej daty w dniu wyrównania lub po nim.',
		'sv' => 'Inget schemalagt datum på eller efter omjusteringsdatumet.',
		'nb' => 'Ingen planlagt dato på eller etter justeringsdatoen.',
	],
	'When enabled, the next due date jumps to the first scheduled date on or after this day when you save.' => [
		'de' => 'Wenn aktiviert, springt das nächste Fälligkeitsdatum beim Speichern auf den ersten geplanten Termin an oder nach diesem Tag.',
		'fr' => "Si activé, la prochaine échéance passe à la première date planifiée à partir de ce jour lors de l'enregistrement.",
		'es' => 'Si está activado, la próxima fecha de vencimiento salta a la primera fecha programada a partir de este día al guardar.',
		'da' => 'Når aktiveret, springer næste forfaldsdato til den første planlagte dato på eller efter denne dag, når du gemmer.',
		'nl' => 'Indien ingeschakeld, springt de volgende vervaldatum bij het opslaan naar de eerste geplande datum op of na deze dag.',
		'it' => 'Se abilitato, la prossima scadenza passa alla prima data pianificata in questo giorno o successiva al salvataggio.',
		'pl' => 'Po włączeniu następny termin płatności przeskoczy przy zapisie do pierwszej zaplanowanej daty w tym dniu lub później.',
		'sv' => 'När detta är aktiverat hoppar nästa förfallodatum till det första schemalagda datumet på eller efter denna dag när du sparar.',
		'nb' => 'Når aktivert, hopper neste forfallsdato til den første planlagte datoen på eller etter denne dagen når du lagrer.',
	],
	'A schedule can include at most {count} dates.' => [
		'de' => 'Ein Zeitplan kann höchstens {count} Termine enthalten.',
		'fr' => 'Un calendrier peut inclure au maximum {count} dates.',
		'es' => 'Un calendario puede incluir como máximo {count} fechas.',
		'da' => 'En tidsplan kan højst indeholde {count} datoer.',
		'nl' => 'Een planning kan maximaal {count} datums bevatten.',
		'it' => 'Un calendario può includere al massimo {count} date.',
		'pl' => 'Harmonogram może zawierać co najwyżej {count} dat.',
		'sv' => 'Ett schema kan innehålla högst {count} datum.',
		'nb' => 'En plan kan inneholde maks {count} datoer.',
	],
];

foreach ($languages as $lang) {
	$path = $base . '/' . $lang . '.json';
	if (!is_file($path)) {
		fwrite(STDERR, "Missing catalog: $path\n");
		exit(1);
	}
	$catalog = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
	$translations = $catalog['translations'] ?? [];
	$changed = 0;
	foreach ($removedKeys as $key) {
		if (array_key_exists($key, $translations)) {
			unset($translations[$key]);
			$changed++;
		}
	}
	foreach ($strings as $msgid => $byLang) {
		$value = $lang === 'en' ? $msgid : ($byLang[$lang] ?? $msgid);
		if (($translations[$msgid] ?? null) !== $value) {
			$translations[$msgid] = $value;
			$changed++;
		}
	}
	$catalog['translations'] = $translations;
	file_put_contents(
		$path,
		json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
	);
	echo "Patched $lang.json ($changed change(s))\n";
}

echo "Schedule l10n patch OK. Now run: php scripts/sync-l10n-variants.php && php scripts/regenerate-l10n-js.php\n";
