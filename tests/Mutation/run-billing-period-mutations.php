<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for the billing-period contract (1.4.4 + hardening):
 * a set billing bound must replace the project bound on that side in EVERY
 * place a booking-relevant window is resolved, and date parsers must reject
 * impossible calendar dates instead of silently normalising them.
 * Run: php tests/Mutation/run-billing-period-mutations.php
 */

$root = dirname(__DIR__, 2);
$failed = 0;

$assert = static function (bool $ok, string $label) use (&$failed): void {
	if ($ok) {
		fwrite(STDOUT, "killed {$label}\n");
		return;
	}
	fwrite(STDERR, "SURVIVED {$label}\n");
	$failed++;
};

$tx = (string) file_get_contents($root . '/lib/Service/TransactionService.php');
$ws = (string) file_get_contents($root . '/lib/Service/WorkspaceService.php');
$summary = (string) file_get_contents($root . '/lib/Service/SummaryService.php');
$export = (string) file_get_contents($root . '/lib/Service/HouseholdYearlyExportService.php');
$mig = (string) file_get_contents($root . '/lib/Migration/Version1023Date20261007210000.php');
$tpl = (string) file_get_contents($root . '/templates/parts/settings/workspace.php');
$settingsJs = (string) file_get_contents($root . '/js/settings.js');

// Booking validation: billing bound replaces the project bound per side.
$assert(str_contains($tx, "\$workspace['billingStartDate'] ?? \$workspace['projectStartDate']"), 'tx_window_billing_start_fallback');
$assert(str_contains($tx, "\$workspace['billingEndDate'] ?? \$workspace['projectEndDate']"), 'tx_window_billing_end_fallback');
// The list-query clamp uses the same effective window — else billing
// bookings are unreachable in the ledger.
$assert(str_contains($tx, "\$ws_from = \$workspace['billingStartDate'] ?? \$workspace['projectStartDate']"), 'tx_list_clamp_billing_aware');
// The field error names the billing period when one is configured.
$assert(str_contains($tx, 'inside the billing period'), 'tx_billing_error_wording');

// WorkspaceService: create persists, update resolves effective bounds from
// the SAME request (new project dates beat stored ones), households reject.
$assert(str_contains($ws, "'billing_start_date' => \$qb->createNamedParameter(\$billingStart)"), 'ws_create_persists_billing_start');
$assert(substr_count($ws, "'billingStartDate', 'billingEndDate'") >= 2, 'ws_household_forbidden_list');
$assert(str_contains($ws, "\$updates['billing_start_date'] ?? \$updates['project_start_date'] ?? \$workspace['projectStartDate']"), 'ws_effective_start_uses_new_bounds');
$assert(str_contains($ws, "\$updates['billing_end_date'] ?? \$updates['project_end_date'] ?? \$workspace['projectEndDate']"), 'ws_effective_end_uses_new_bounds');
// Public window helper is billing-aware too — a project-only copy would
// silently disagree with the transaction path.
$assert(str_contains($ws, "\$workspace['billingStartDate'] ?? \$workspace['projectStartDate']"), 'ws_public_window_billing_aware');
// The orphan guard may not pin "project" in its message — billing can be the
// window that moved.
$assert(!str_contains($ws, 'The new project date window would orphan'), 'ws_orphan_message_window_neutral');

// Impossible calendar dates must be rejected, not normalised (2026-02-30
// became 2026-03-02 before checkdate).
$assert(str_contains($tx, 'checkdate((int)$m[2], (int)$m[3], (int)$m[1])'), 'tx_parse_checkdate');
$assert(str_contains($ws, 'checkdate((int)$m[2], (int)$m[3], (int)$m[1])'), 'ws_parse_checkdate');
// Non-project extractProjectFields must return all six slots — the caller
// destructures six; a 4-element return logs Undefined array key warnings on
// every household/private workspace creation.
$assert(str_contains($ws, 'return [null, null, null, null, null, null];'), 'ws_nonproject_returns_six_slots');

// Summary + export: billing-extension bookings must be counted/printed.
$assert(str_contains($summary, "\$workspace['billingStartDate'] ?? \$workspace['projectStartDate']"), 'summary_effective_start');
$assert(str_contains($summary, "\$workspace['billingEndDate'] ?? \$workspace['projectEndDate']"), 'summary_effective_end');
$assert(str_contains($summary, 'does not overlap the billing period'), 'summary_month_billing_wording');
$assert(str_contains($export, "\$workspace['billingStartDate'] ?? \$workspace['projectStartDate']"), 'export_effective_start');
$assert(str_contains($export, "\$workspace['billingEndDate'] ?? \$workspace['projectEndDate']"), 'export_effective_end');

// Schema + UI wiring.
$assert(str_contains($mig, 'billing_start_date'), 'migration_adds_start_column');
$assert(str_contains($mig, 'billing_end_date'), 'migration_adds_end_column');
$assert(str_contains($tpl, 'name="billingStartDate"'), 'template_start_input');
$assert(str_contains($tpl, 'name="billingEndDate"'), 'template_end_input');
$assert(str_contains($settingsJs, 'payload.billingStartDate'), 'js_submits_start');
$assert(str_contains($settingsJs, 'payload.billingEndDate'), 'js_submits_end');

if ($failed > 0) {
	fwrite(STDERR, "billing-period mutations survived: {$failed}\n");
	exit(1);
}
fwrite(STDOUT, "billing-period mutations: all killed\n");
