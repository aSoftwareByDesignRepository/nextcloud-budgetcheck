#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Modernise Danish (da): replace formal De/Dem/Deres with du/dig/din/dit.
 * Run: php scripts/patch-da-informal.php && npm run l10n
 */

$jsonPath = __DIR__ . '/../l10n/da.json';
$cat = json_decode((string)file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);

$informal = [
	'Category flag for money moved to savings. Counts toward your savings goal and is excluded from everyday budget saldo, but stays in total expenses.' => 'Markering for penge flyttet til opsparing. Tæller med i dit opsparingsmål og er udelukket fra daglig budgetsaldo, men forbliver i de samlede udgifter.',
	'The amount you plan to set aside (percentage, fixed amount, or the higher of both).' => 'Beløbet, du planlægger at lægge til side (procent, fast beløb eller det højeste af begge).',
	'You can hide this panel permanently; it does not block any action.' => 'Du kan skjule dette panel permanent; det blokerer ingen handling.',
	'Amount in this workspace’s currency. Use your usual decimal separator (dot or comma).' => 'Beløb i dette arbejdsområdes valuta. Brug din sædvanlige decimalseparator (punktum eller komma).',
	'You do not have permission to open app settings.' => 'Du har ikke tilladelse til at åbne app-indstillinger.',
	'You do not have access to BudgetCheck. Your account is not among the users or groups allowed to use this app. Ask a server or app administrator if you need access.' => 'Du har ikke adgang til BudgetCheck. Din konto er ikke blandt de brugere eller grupper, der må bruge denne app. Kontakt en server- eller app-administrator, hvis du har brug for adgang.',
	'Are you sure?' => 'Er du sikker?',
	'Mark your savings category under Settings → Categories to track transfers against this target.' => 'Markér din opsparingskategori under Indstillinger → Kategorier for at følge overførsler mod dette mål.',
	'Transfers in this category count toward your savings goal and are excluded from everyday budget saldo.' => 'Overførsler i denne kategori tæller med i dit opsparingsmål og er udelukket fra daglig budgetsaldo.',
	'Everyday totals exclude transactions marked as special. Turn this on to see the full ledger. Your choice is saved to your account.' => 'Daglige totaler udelukker posteringer markeret som særlige. Slå til for hele hovedbogen. Dit valg gemmes på din konto.',
	'Create your first workspace' => 'Opret dit første arbejdsområde',
	'Every BudgetCheck screen is scoped to one workspace. Choose one in the sidebar, or ask an app administrator to add you to a workspace.' => 'Hver BudgetCheck-skærm er knyttet til ét arbejdsområde. Vælg ét i sidepanelet, eller bed en app-administrator om at tilføje dig til et arbejdsområde.',
	'In project workspaces you can tag a booking with a status (for example in progress or paid). Leave empty if you do not need a workflow step.' => 'I projektarbejdsområder kan du markere en postering med en status (f.eks. i gang eller betalt). Lad feltet være tomt, hvis dit workflow ikke kræver det.',
	'Use the transactions screen to add your first entry.' => 'Brug transaktionsskærmen til at tilføje din første postering.',
	'You are not a member of any workspace yet.' => 'Du er endnu ikke medlem af noget arbejdsområde.',
	'You are not allowed to use BudgetCheck right now.' => 'Du har ikke tilladelse til at bruge BudgetCheck lige nu.',
	'You are not authorized to perform that action.' => 'Du er ikke autoriseret til at udføre den handling.',
	'You do not have access to BudgetCheck. Ask an app administrator to add you to a workspace.' => 'Du har ikke adgang til BudgetCheck. Bed en app-administrator om at tilføje dig til et arbejdsområde.',
	'Your session expired. Please reload and sign in again.' => 'Din session er udløbet. Genindlæs siden og log ind igen.',
	'Date and month fields use your Nextcloud language. Tables and summaries match. The browser\'s calendar popup may still follow your device language in some setups.' => 'Dato- og månedsfelter bruger dit Nextcloud-sprog. Tabeller og oversigter matcher. Browserens kalendervælger kan i nogle opsætninger stadig følge enhedens sprog.',
	'No ledger transactions yet—each month is empty until you post one.' => 'Ingen hovedbogstransaktioner endnu—hver måned er tom, indtil du registrerer én.',
	'Nothing booked for {monthLabel}. That calendar month is before your first ledger booking ({monthFrom}).' => 'Intet bogført for {monthLabel}. Den kalendermåned ligger før din første hovedbogspostering ({monthFrom}).',
	'Nothing booked for {monthLabel}. That calendar month is after your latest ledger booking ({monthTo}).' => 'Intet bogført for {monthLabel}. Den kalendermåned ligger efter din seneste hovedbogspostering ({monthTo}).',
	'Nothing booked for {monthLabel}. You can still plan budgets and use month tools.' => 'Intet bogført for {monthLabel}. Du kan stadig planlægge budgetter og bruge månedsværktøjer.',
	'Pick a calendar year and month. The year list grows with your ledger; your primary planning year anchors the default range.' => 'Vælg et kalenderår og en måned. Årslisten vokser med hovedbogen; dit primære planlægningsår fastlægger standardintervallet.',
	'Add your first booking' => 'Tilføj din første postering',
	'Money that came in this month, summed in minor units (e.g. cents) and shown formatted in your locale.' => 'Penge ind denne måned, summeret i mindste enheder (f.eks. øre) og vist formateret efter din sprogindstilling.',
	'Once you add a booking it will appear here. You can edit, tag, or delete entries from this page.' => 'Når du tilføjer en postering, vises den her. Posteringer kan redigeres, mærkes eller slettes fra denne side.',
	'The total amount you planned to set aside this year.' => 'Det samlede beløb, du planlagde at lægge til side i år.',
	'The total amount you actually set aside this year.' => 'Det samlede beløb, du faktisk lagde til side i år.',
	'What happens when you close this month' => 'Hvad der sker, når du lukker denne måned',
];

$updated = 0;
foreach ($informal as $key => $val) {
	if (!array_key_exists($key, $cat['translations'])) {
		fwrite(STDERR, "missing key: $key\n");
		continue;
	}
	if ($cat['translations'][$key] !== $val) {
		$cat['translations'][$key] = $val;
		$updated++;
	}
}

file_put_contents(
	$jsonPath,
	json_encode($cat, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
);

echo "da informal: updated $updated string(s)\n";
