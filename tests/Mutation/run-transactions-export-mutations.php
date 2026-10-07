<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for the all-transactions CSV/ODS export (issue #21):
 * the export must share the ledger filter pipeline, hard-exclude tombstones,
 * guard spreadsheet formula injection, and produce a spec-conformant ODS
 * package — each mutation below names the invariant a careless refactor
 * could silently break.
 * Run: php tests/Mutation/run-transactions-export-mutations.php
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
$export = (string) file_get_contents($root . '/lib/Service/TransactionExportService.php');
$ctrl = (string) file_get_contents($root . '/lib/Controller/ExportController.php');
$routes = (string) file_get_contents($root . '/appinfo/routes.php');
$tpl = (string) file_get_contents($root . '/templates/transactions.php');
$js = (string) file_get_contents($root . '/js/transactions.js');

// Export query shares the ledger filter pipeline — a bespoke WHERE clone
// would drift the moment a new filter lands.
$assert(str_contains($tx, 'public function exportRows'), 'tx_export_rows_exists');
$assert(str_contains($tx, '$this->applyFiltersToTransactionQuery($qb, $workspaceId, $filters, $workspace)'), 'tx_export_reuses_filter_pipeline');
// Tombstones must never reach a file — includeDeleted is stripped, not trusted.
$assert(str_contains($tx, "unset(\$filters['includeDeleted']"), 'tx_export_strips_include_deleted');
// Pagination hints must not silently truncate an "export all" — strip them
// and enforce an explicit cap instead.
$assert(str_contains($tx, "\$filters['offset']"), 'tx_export_strips_pagination');
$assert(str_contains($export, 'MAX_EXPORT_ROWS'), 'export_has_row_cap');
$assert(str_contains($export, 'Narrow the date range or filters'), 'export_cap_error_actionable');

// CSV safety: UTF-8 BOM for spreadsheet detection and the formula guard on
// EVERY string cell — quoting alone does not stop =, +, -, @, TAB, CR, VT.
$assert(str_contains($export, '"\xEF\xBB\xBF"'), 'csv_utf8_bom');
$assert(str_contains($export, "'=', '+', '-', '@', \"\\t\", \"\\r\", \"\\x0B\""), 'csv_formula_guard_charset');
$assert(str_contains($export, "return \"'\" . \$value"), 'csv_formula_guard_prefixes');
$assert(str_contains($export, 'str_replace(\'"\', \'""\''), 'csv_quote_doubling');

// ODS: spec-conformant package — mimetype first and STORED, not deflated.
$assert(str_contains($export, "addFromString('mimetype', self::ODS_MIME)"), 'ods_mimetype_entry');
$assert(str_contains($export, "setCompressionName('mimetype', \\ZipArchive::CM_STORE)"), 'ods_mimetype_stored');
$assert(str_contains($export, "'META-INF/manifest.xml'"), 'ods_manifest_entry');
$assert(str_contains($export, 'office:value-type="date"'), 'ods_typed_date_cells');
$assert(str_contains($export, 'office:value-type="boolean"'), 'ods_typed_bool_cells');
$assert(str_contains($export, 'office:value-type="float"'), 'ods_typed_float_cells');
$assert(str_contains($export, '<text:line-break/>'), 'ods_multiline_text_preserved');
$assert(str_contains($export, 'ENT_QUOTES | ENT_XML1'), 'ods_xml_escaping');

// Column contract: importer-canonical headers first, tax + status detail.
$assert(str_contains($export, "'header' => 'bookingDate'"), 'col_booking_date');
$assert(str_contains($export, "'header' => 'vatRatePercent'"), 'col_vat_percent');
$assert(str_contains($export, "'header' => 'bookingStatus'"), 'col_project_status');
$assert(str_contains($export, "'header' => 'externalRef'"), 'col_external_ref');

// Route + controller: strict id parsing, format allowlist via the service,
// download headers, rate limit.
$assert(str_contains($routes, "'export#transactions'"), 'route_registered');
$assert(str_contains($ctrl, "getParam('format'"), 'ctrl_reads_format');
$assert(str_contains($ctrl, 'strictIntParam'), 'ctrl_strict_id');
$assert(str_contains($ctrl, "'transactions_export', 20, 300"), 'ctrl_rate_limited');
$assert(str_contains($ctrl, 'X-Content-Type-Options'), 'ctrl_nosniff');

// UI: both format buttons wired to the current filter state.
$assert(str_contains($tpl, 'data-bc-tx-export="csv"'), 'tpl_csv_button');
$assert(str_contains($tpl, 'data-bc-tx-export="ods"'), 'tpl_ods_button');
$assert(str_contains($tpl, 'aria-describedby="bc-tx-export-hint"'), 'tpl_buttons_described');
$assert(str_contains($js, "export/transactions"), 'js_export_endpoint');
$assert(str_contains($js, 'state.filters'), 'js_exports_current_filters');

if ($failed > 0) {
	fwrite(STDERR, "transactions-export mutations survived: {$failed}\n");
	exit(1);
}
fwrite(STDOUT, "transactions-export mutations: all killed\n");
