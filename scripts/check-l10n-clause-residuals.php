<?php

declare(strict_types=1);

/**
 * Residual sweep for the l10n_wrong_sense_clause / l10n_dropped_subject_clause
 * defect class (dual r41func/r42vis reject class, healed 2026-11-25).
 *
 * The class covers machine-translated msgstrs that corrupt the clause when the
 * English msgid addresses the user ("you/your"):
 *   - dropped subject after a subordinator  ("før ændrer", "innan importerar")
 *   - verbless/verbless-subject comma splices ("kun personer, tilføjer")
 *   - question/relative word + finite verb, no subject ("hvad forventer")
 *   - modal/auxiliary + verb with no subject ("skal have", "kan merke",
 *     "trenger bidragsyter-", "behöver medarbetar-")
 *   - expletive substitution for the subject ("det er mulig(t) å ikke opprette",
 *     "hvis der eksporterer", "hvor der er" for "where you are")
 *   - dropped sentence-initial 2p subject/possessive ("session er udløbet",
 *     "det månedlige opsparingsmål", "o usuário não tem permissão")
 *   - "det|den <bare noun>" standing in for "your <noun>"
 *     ("det huvudlösenord", "det Nextcloud-sprog", "det banksaldo")
 *   - dropped object where EN requires it ("legge til i" for "add you to")
 *   - pt_BR register flatten ("o usuário" for "you")
 *   - sentence-initial lowercase where the msgid starts uppercase
 *   - stray " ." / " ," left in the msgstr
 *
 * False positives that were adjudicated (lower-case label style, definite-form
 * "your" shorthand, gerund nouns) go into $ALLOW with a reason; every allow
 * line is printed so the sweep stays honest.
 *
 * Exit codes:
 *   0  no un-healed cells
 *   1  residual clause corruption detected (printed to STDERR)
 *
 * Usage:
 *   php scripts/check-l10n-clause-residuals.php
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

$base = __DIR__ . '/../l10n';

$en = json_decode((string) file_get_contents($base . '/en.json'), true, 512, JSON_THROW_ON_ERROR)['translations'] ?? [];

// ---------------------------------------------------------------------------
// Per-cell adjudicated false positives: "msgid" => ["loc|*", "reason"].
// A * row allows the msgid in every catalog; a loc row allows one catalog.
// ---------------------------------------------------------------------------
$ALLOW = [
	// Label-style cells where target-language convention starts lowercase and
	// the msgstr is not a sentence (G1).
	'Every how many months' => ['es*', 'lowercase label, not a sentence'],
	'Find group' => ['es*', 'lowercase label, not a sentence'],
	'Find user' => ['es*', 'lowercase label, not a sentence'],
	'Interval count' => ['es*', 'lowercase label, not a sentence'],
	'Snapshot hash' => ['es*', 'lowercase label, not a sentence'],
	'Spent so far' => ['es*', 'lowercase label, not a sentence'],
	'Direction' => ['ru', 'lowercase label, not a sentence'],
	'Match' => ['ru', 'lowercase label, not a sentence'],
	'Reset' => ['ru', 'lowercase label, not a sentence'],
	'Uses the externalRef column (or transaction ID from your bank). Rows without a reference are always imported.' => ['tr', 'starts with literal API name externalRef'],
	'CSV file' => ['pt*', 'lowercase label, not a sentence'],
	'PDF preview' => ['pt*', 'lowercase label, not a sentence'],
	// "your" -> grammatical definite-form shorthand on labels/noun phrases
	// (adjudicated register, not the corruption class).
	'Your categories' => ['da|nb|sv', 'bare-noun label, grammatical ellipsis of "your"'],
	'Your planning view' => ['da|nb|sv', 'bare-noun label, grammatical ellipsis of "your"'],
	'How much of your yearly savings target was achieved.' => ['da', '"det + adjective + noun" is the grammatical Danish definite'],
	'Pick a calendar year and month. The year list grows with your ledger; your primary planning year anchors the default range.' => ['da', '"det + adjective + noun" is the grammatical Danish definite'],
	// Adjudicated cells whose remaining flag is a legitimate subject + modal
	// or a nominalized "when you save" (definite shorthand).
	'Attach photos or PDF receipts to this booking. Add them below — they upload when you save. Only workspace members can view them.' => ['da|nb|sv', '"kan se" has subject "medlemmer"; "when you save" nominalized per adjudication'],
	'Attach photos, PDF receipts, or XML e-invoices to this booking. Add them below — they upload when you save. Only workspace members can view them.' => ['da|nb|sv', '"kan se" has subject "medlemmer"; "when you save" nominalized per adjudication'],
	'Only members you add. Remove any groups before switching. One manager is enough; if that sole manager account is removed, recovery needs database access.' => ['nb', '"før bytte" is a nominal "before switching", not a dropped-subject clause'],
];

// ---------------------------------------------------------------------------
// Scandinavian signature tables (da / nb / sv only fire these).
// ---------------------------------------------------------------------------
$VERBS_FIN = [
	'da' => 'tilføjer|eksporterer|importerer|fjerner|sletter|ændrer|opretter|åbner|gemmer|bruger|vælger|ser|genåbner|lukker|gendanner|behøver|uploader|henter|deler|redigerer|markerer|klikker|skifter|registrerer|bogfører|skriver|planlægger|angiver|flytter|afslutter|starter|slutter|sætter|indtaster|tager|forventer|inkluderer|lægger|bliver|kommer|finder|gælder|møder|besøger|foretager|udfører|genindlæser|nulstiller|gentager|efterlader|oversætter|sender|modtager|returnerer|afviser|tilhører|falder|vokser|fastlægger|matcher|giver|går|laver|trækker|begynder|springer|stopper|forsvinder|fortsætter|kører|virker|genkender|husker|bevarer|beskytter|dækker|kræver|mangler|rydder|fletter|samler|fører|følger|mærker|ønsker|viser|opdaterer|taster|åbner|henter|overfører|downloader|udelukker|afstemmer|fletter|udvider|opretter|booker',
	'nb' => 'legger|eksporterer|importerer|fjerner|sletter|endrer|oppretter|åpner|lagrer|bruker|velger|ser|gjenåpner|lukker|trenger|laster|henter|deler|redigerer|markerer|klikker|byter|registrerer|bokfører|skriver|planlegger|angir|flytter|avslutter|starter|slutter|merker|setter|taster|tar|forventer|inkluderer|blir|kommer|finner|gjelder|besøker|foretar|utfører|genererer|hopper|vokser|forankrer|matcher|gir|går|lager|drar|begynner|stopper|forsvinner|fortsetter|kjører|fungerer|oppdager|husker|bevarer|beskytter|dekker|krever|mangler|rydder|fletter|samler|fører|følger|liker|viser|oppdaterer|overfører|utelukker|avstemmer|utvider|oppretter|bokfører|åpner',
	'sv' => 'lägger|exporterar|importerar|tar|raderar|ändrar|skapar|öppnar|sparar|använder|väljer|ser|återöppnar|stänger|återställer|behöver|laddar|hämtar|delar|redigerar|markerar|klickar|byter|registrerar|bokför|skriver|anger|planerar|flyttar|avslutar|lämnar|kommer|stoppar|börjar|slutar|märker|sätter|vill|förväntar|inkluderar|blir|hittar|gäller|besöker|utför|genererar|hoppar|matar|loggar|läser|ger|gör|växer|förankrar|matchar|visar|uppdaterar|taggar|börjar|fortsätter|kör|fungerar|känner|identifierar|minns|skyddar|täcker|kräver|saknar|rensar|samlar|för|följer|gillar|tycker|avsätter|drar|släpper|försvinner|tar emot|öppnar|förhandsgranskar|laddar upp|bokar',
];
$VERBS_INF = [
	'da' => 'tilføje|eksportere|importere|fjerne|slette|ændre|oprette|åbne|gemme|bruge|vælge|se|genåbne|lukke|gendanne|uploade|hente|dele|redigere|markere|klikke|skifte|registrere|bogføre|skrive|planlægge|angive|flytte|afslutte|starte|slutte|sætte|indtaste|tage|forvente|inkludere|lægge|blive|komme|finde|besøge|foretage|udføre|nulstille|gentage|oversætte|sende|returnere|afvise|tilhøre|matche|beregne|vise|skjule|bevare|give|lave|trække|begynde|stoppe|forsvinde|fortsætte|køre|virke|genkende|huske|beskytte|dække|kræve|mangle|rydde|samle|føre|følge|mærke|ønske|have|få|kunne|skulle|ville|taste|overføre|downloade|udelukke|afstemme|udvide|booke',
	'nb' => 'legge|eksportere|importere|fjerne|slette|endre|opprette|åpne|lagre|bruke|velge|se|gjenåpne|lukke|laste|hente|dele|redigere|merke|klikke|bytte|registrere|bokføre|skrive|planlegge|angi|flytte|avslutte|starte|slutte|sette|taste|forvente|inkludere|bli|komme|finne|besøke|foreta|utføre|generere|hoppe|vokse|forankre|matche|vise|skjule|bevare|gi|lage|begynne|stoppe|forsvinne|fortsette|kjøre|fungere|oppdage|huske|beskytte|dekke|kreve|mangle|rydde|samle|føre|følge|ville|ha|få|kunne|skulle|taste|overføre|utelukke|avstemme|utvide|bokføre',
	'sv' => 'lägga|exportera|importera|radera|ändra|skapa|öppna|spara|använda|välja|se|återöppna|stänga|återställa|ladda|hämta|dela|redigera|markera|klicka|byta|registrera|bokföra|skriva|ange|planera|flytta|avsluta|lämna|komma|stoppa|börja|sluta|märka|sätta|vilja|förvänta|inkludera|bli|hitta|besöka|utföra|generera|hoppa|mata|logga|läsa|ge|göra|växa|förankra|matcha|visa|dölja|bevara|gå|släppa|försvinna|fortsätta|köra|fungera|känna|identifiera|minnas|skydda|täcka|kräva|sakna|rensa|samla|föra|följa|ha|få|kunna|skola|tagga|avsätta|öppna|förhandsgranska|boka',
];
$SUBJ = [
	'da' => 'hvis|når|før|inden|efter|mens|fordi|medmindre|uanset|selv(?:\s+om)?',
	'nb' => 'hvis|når|før|innen|etter|mens|fordi|med\s+mindre|uansett|selv(?:\s+om)?|dersom',
	'sv' => 'om|när|innan|efter|medan|utan|eftersom|förrän|ifall|såvida|så\s+länge|innan',
];
$QWORD = [
	'da' => 'hvad|hvem|hvor|hvornår|hvordan|hvilken|hvilke|hvilket|uanset\s+hvor',
	'nb' => 'hva|hvem|hvor|hvordan|hvilken|hvilke|hva\s+enn|uansett\s+hvor',
	'sv' => 'vad|vem|var|hur|vilket|vilka|var\s+än',
];
$MODALS = [
	'da' => 'kan|skal|må|får|bør|burde|skulle|trenger|behøver|vil',
	'nb' => 'kan|skal|må|får|bør|burde|skulle|trenger|behøver|vil',
	'sv' => 'kan|ska|måste|får|bör|borde|skulle|behöver|vill',
];
$NEG = 'ikke|inte|aldri|aldrig|kun|bare|bara|også|alltid|nå|nu|fortsatt|fortfarande|stadig|ofte|ofta|vel|gjerne|gärna|heller|igjen|igen|åter';
$POSS = 'du|dig|deg|din|dine|ditt|dina|dit|jer|jeres|dere|deres|ert|edert|er';
// Adjective-looking second words legitimately following det|den (definite
// "det + adj + noun" is grammatical) — not bare-noun possessive drops.
$DET_ADJ = 'primære|primæra|årlige|årliga|vanlige|vanliga|samlede|totala?|samme|hele|egen|egne|egna|første|neste|næste|följende|gjeldende|nuværende|nåværende|aktuella?|aktuelle|valgte|valde|valda|officielle|offisielle|officiella|månedlige|månatliga|nødvendige|nödvändiga|påkrevde|önskede|ønskede|andre|andra|begge|båda|højeste|högsta|høyeste|eneste|enda|riktige|riktiga|ekte|rette|store|stora|små|nya|nye|gamle|gamla|eldre|äldre|direkte|siste|sidste|sista|faste|fleire|flera|flere|daglige|månedlige|mulige|muliga|mest|fleste|vigtigste|enkle|enkla|fælles|gemensamme|gemensamma|sædvanlige|vanliga|seneste|senaste|klare|klara|frie|gratis|egen|egne|egentlige|egentliga|dobbelte|dubbla|fulde|fulla|hele|halve|halva|lokale|korte|lange|långa|brede|breda|smalle|smala|dyre|billige|billiga|svære|svåra|lette|lätta|lette|hurtige|snabba|langsomme|långsamma|tavse|tysta|vilde|vilda|rette|rette|klare|varme|varma|kolde|kalla|hårde|hårda|bløde|mjuka|faste|flydende|flytande|tørre|torra|våde|våta|glade|ledige|lediga|optagne|upptagna|ledige|åbne|öppna|lukkede|stängda|private|offentlige|offentliga|interne|interna|eksterne|externa|normale|sædvanlige|vanliga|normale|personlige|personliga|egen|egne|egna|andre|første|sidste|sista|næste|neste|forrige|förra|tidligere|tidigare|kommende|kommande|planlagte|planlagda|aktive|inaktive|inaktiva|permanente|midlertidige|tillfälliga|endelige|slutliga|opprinnelige|ursprungliga|nødvendige|tilgjengelige|tillgängliga|relevante|nyttige|sanne|ekte|äkta|falske|komplette|kompletta|komplette|ekstra|ekstra|sikre|trygge|sikre|frie|bundne|bundna|løse|løsa|sanne|rette|rette|første|sidste|sista|forrige|förra|tidligere|tidigare|nærmeste|närmaste|første|enkle|enkleste|enklast|nye|nyeste|nyeste|nyaste|eldste|ældste|ældste|hele|hele|halve|halva|dobbelte|dubbla|trippel|sanne|rette';

$SCAND = ['da', 'nb', 'sv'];
$twoP = '/\b(?:' . $POSS . ')\b/iu';

$violations = [];
$allowed = [];

$files = glob($base . '/*.json') ?: [];
sort($files);
foreach ($files as $file) {
	$loc = basename($file, '.json');
	if ($loc === 'en') {
		continue;
	}
	$data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
	$cat = $data['translations'] ?? [];
	$isScand = in_array($loc, $SCAND, true);
	$isPtBr = $loc === 'pt_BR';

	foreach ($cat as $mid => $ms) {
		if (!is_string($ms) || $ms === '' || !array_key_exists($mid, $en)) {
			continue;
		}
		$msgid = (string) $mid;
		$youRe = '/\byou\b|\byour\b/i';
		$hasYou = (bool) preg_match($youRe, $msgid);
		$hasMarker = (bool) preg_match($twoP, $ms);
		$hits = [];

		// G1: EN starts uppercase, msgstr starts lowercase (label cells are in ALLOW).
		if (preg_match('/^\p{Lu}/u', $msgid) && preg_match('/^\p{Ll}/u', $ms)) {
			$hits[] = 'lowercase-start';
		}

		// S-TRAIL: stray space before sentence punctuation.
		if (preg_match('/\s[.,]\s*$|\s[.,]\s/u', $ms)) {
			$hits[] = 'stray-space-punct';
		}

		if ($isScand) {
			$fin = $VERBS_FIN[$loc];
			$inf = $VERBS_INF[$loc];
			$verbRe = '(?:' . $fin . '|' . $inf . ')';

			// S-AUX: msgid addresses the user, msgstr opens with a non-2p token.
			if (preg_match('/^(?:You|Your)\b/', $msgid) && !$hasMarker
				&& !preg_match('/^(?:Du|Din|Dine|Ditt|Dina|Dit|Jer|Jeres|Deres?|Ert|Er)\b/u', $ms)
				&& preg_match('/^\p{L}/u', $ms)) {
				$hits[] = 'dropped-sent-initial-2p';
			}

			if ($hasYou) {
				$finRe = '(?:' . $VERBS_FIN[$loc] . ')';
				// S-SUB: subordinator (+ optional negation/expletive) + verb, no subject.
				if (preg_match('/\b(?:' . $SUBJ[$loc] . ')\s+(?:(?:' . $NEG . ')\s+)*(?:der|det)?\s*' . $verbRe . '\b/u', $ms)) {
					$hits[] = 'dropped-subject-after-subordinator';
				}
				// S-COMMA: comma splice into a FINITE verb whose clause segment carries
				// no subject — the corruption shape "personer, tilføjer" / "datoer,
				// angiver." V2 clauses ("fjernes, kræver gendannelse" — subject
				// follows the verb) and relative commas ("…, der ikke …, ser det")
				// are excluded by the segment/follow-word guards.
				if (preg_match_all('/,\s*(' . $finRe . ')\s*(\S*)/u', $ms, $cm, PREG_OFFSET_CAPTURE)) {
					$followRe = '/^(?:som|der|at|å|att|til|till|i|på|av|af|for|för|med|fra|från|inn|inden|efter|over|under|mot|mod|enn|än|og|och|eller|men|hvis|om|når|när|så|da|då|ikke|inte|aldri|aldrig|kun|bare|bara|også|alltid|nå|nu|her|där|der|hit|dit|ned|opp|op|ut|ud|inn|fram|frem|seg|sig|oss|os|sin|sitt|sine|min|mitt|mine|din|ditt|dine|jeres|deres|vår|vårt|våre|hans|hennes|dets|dess|den|det|dette|denne|detta|dessa|disse|de|dem|en|et|ei|ett|noe|noget|något|noen|nogen|någon|hver|varje|hvert|alle|alla|alt|allt|ingen|inget|inga|mye|meget|mycket|få|mer|mere|mera|mest|flest|flesta|færre|färre|selv|själv|jo|ju|vel|gjerne|gärna|heller|nemlig|nämligen|virkelig|egentlig|egentligen|faktisk|faktiskt|heldigvis|derfor|därför|likevel|ändå|sammen|tillsammans|videre|igjen|igen|åter|tilbake|tilbage|akkurat|precis|nettopp|snart|ofte|ofta|altid|allerede|redan|verkligen|sikkert|säkert|måske|kanskje|kanske|muligens|eventuellt|eventuelt|også|ikke|inte|ud|ud|ned|ind|inn|ute|ude|hjemme|hjem|borte|borta|væk|bort|igjen|igen|åter|frem|fram)$/iu';
					foreach ($cm[1] as $idx => $cap) {
						$vStart = $cap[1];
						$follow = $cm[2][$idx][0] ?? '';
						$followOk = $follow === '' || preg_match('/^[.,;:]/u', $follow)
							|| preg_match($followRe, rtrim($follow, '.,;:')) === 1;
						if (!$followOk) {
							continue;
						}
						$segStart = max((int) strrpos(substr($ms, 0, $vStart), '.'),
							(int) strrpos(substr($ms, 0, $vStart), '!'),
							(int) strrpos(substr($ms, 0, $vStart), '?'),
							(int) strrpos(substr($ms, 0, $vStart), ';'),
							(int) strrpos(substr($ms, 0, $vStart), "\n"), 0);
						$seg = substr($ms, $segStart, $vStart - $segStart);
						if (preg_match('/\b(?:der|som|du)\b/iu', $seg)) {
							continue;
						}
						$hits[] = 'comma-splice-dropped-subject';
						break;
					}
				}
				// S-QW: question/relative word + verb, no subject ("hvad forventer").
				if (preg_match('/\b(?:' . $QWORD[$loc] . ')\s+(?:(?:' . $NEG . ')\s+)*(?:' . $verbRe . '|er|är|finnes|finns|var)\b/u', $ms)) {
					$hits[] = 'dropped-subject-after-qword';
				}
				// Expletive "hvor der er" / "var det finns" standing in for "where you are".
				if (preg_match('/\b(?:hvor|var)\s+(?:der|det)\s+(?:er|är|var|finnes|finns)\b/u', $ms)) {
					$hits[] = 'expletive-where-you-are';
				}
				// S-MODAL: modal/aux + verb, no 2p marker anywhere in the cell.
				if (!$hasMarker && preg_match('/\b(?:' . $MODALS[$loc] . ')\s+(?:(?:' . $NEG . ')\s+)*' . $verbRe . '\b/u', $ms)) {
					$hits[] = 'modal-verb-dropped-subject';
				}
				// S-MULIG: "det er mulig(t)/möjligt (at|å|att)" standing in for "you (can|cannot)".
				if (preg_match('/\bdet\s+(?:er|är)\s+(?:muligt|mulig|möjligt)\s+(?:at|å|att)\b/u', $ms)) {
					$hits[] = 'expletive-mulig-for-you';
				}
				// S-DETNOUN: "det|den <bare noun>" standing in for "your <noun>".
				// Legit "den <definite-noun>" ("den dagen" = "that day") carries the
				// -en/-et/-an/-ene/-erna suffix and is excluded; adjectives and
				// function words after det|den are excluded too.
				if (!$hasMarker && preg_match('/\byour\b/i', $msgid)
					&& preg_match_all('/\b(?:det|den)\s+([\p{L}][\p{L}-]*)/u', $ms, $mm)) {
					foreach ($mm[1] as $w) {
						$lw = mb_strtolower($w);
						if (preg_match('/^(?:' . $DET_ADJ . ')$/u', $lw)
							|| preg_match('/(?:en|et|an|ene|erne|erna|na)$/u', $lw)
							|| preg_match('/^(?:här|her|där|der|och|og|eller|att|at|å|som|for|til|i|på|af|av|med|fra|från|inn|ut|ned|opp|op|än|enn|hvis|om|när|når|du|jer|de|dem|det|den|sig|seg|er|är|var|har|hade|hatt|kan|skal|ska|vil|vill|må|bör|inte|ikke|åter|igen|dit|hit|fram|frem|någon|noen|nogen|något|noe|noget|varje|hver|hvert|alt|allt|alla|alle|andra|andre|ingen|inget|intet|mer|mera|mere|mycket|mye|meget|bara|kun|bare|också|også|alltid|fortsatt|fortfarande|redan|allerede|snart|ofte|ofta|aldrig|aldri|ibland|mot|mod|utan|uten|uden|mellan|mellom|mellem|bak|bag|framför|foran|ovan|oven|under|bredvid|hos|trots|tross|sedan|siden|deretter|därefter|derefter|dernæst|dermed|därmed|derfor|därför|derpå|därpå|derfra|därifrån|derved|deraf|derudover|derover|derinde|derude|deroppe|dernede|derhenne|derhen|derimod|derom|dessutom|dessuten)$/u', $lw)) {
							continue;
						}
						$hits[] = 'det-den-bare-noun-for-your';
						break;
					}
				}
				// S-NVIL: definite noun + vil(l) where EN says "you want".
				if (preg_match('/\byou\s+want\b/i', $msgid)
					&& preg_match('/\b\p{L}+(?:en|et)\s+(?:vil|vill)\b/u', $ms)) {
					$hits[] = 'noun-vil-for-you-want';
				}
			}
		}

		if ($isPtBr && $hasYou && preg_match('/\bo\s+usuário\b/u', $ms)) {
			$hits[] = 'register-flatten-o-usuario';
		}

		if ($hits === []) {
			continue;
		}

		$allowHit = null;
		if (isset($ALLOW[$msgid])) {
			[$alLoc, $reason] = $ALLOW[$msgid];
			$locMatch = $alLoc === '*' || $alLoc === $loc
				|| (str_ends_with($alLoc, '*') && str_starts_with($loc, rtrim($alLoc, '*')))
				|| in_array($loc, explode('|', $alLoc), true);
			if ($locMatch) {
				$allowHit = $reason;
			}
		}
		if ($allowHit !== null) {
			$allowed[] = [$loc, $msgid, implode(',', $hits), $allowHit];
		} else {
			$violations[] = [$loc, $msgid, implode(',', $hits), $ms];
		}
	}
}

foreach ($allowed as [$loc, $mid, $tags, $reason]) {
	fwrite(STDOUT, "ALLOW [$loc] ($tags) $reason\n      msgid: " . mb_substr($mid, 0, 90) . "\n");
}
if ($violations !== []) {
	fwrite(STDERR, "\nResidual l10n_wrong_sense_clause cells (" . count($violations) . "):\n\n");
	foreach ($violations as [$loc, $mid, $tags, $ms]) {
		fwrite(STDERR, "[$loc] ($tags)\n  msgid : $mid\n  msgstr: $ms\n\n");
	}
	exit(1);
}
fwrite(STDOUT, 'OK — no residual l10n_wrong_sense_clause cells (' . count($allowed) . " adjudicated allows).\n");
exit(0);
