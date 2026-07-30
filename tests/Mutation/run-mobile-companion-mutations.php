<?php

declare(strict_types=1);

/**
 * Mobile companion mutation gauntlet (free Android — no license).
 * Run: php tests/Mutation/run-mobile-companion-mutations.php
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

$mobile = (string)file_get_contents($root . '/lib/Controller/MobileApiController.php');
$caps = (string)file_get_contents($root . '/lib/Capabilities.php');
$routes = (string)file_get_contents($root . '/appinfo/routes.php');
$api = (string)file_get_contents($root . '/lib/Controller/ApiController.php');
$idemp = (string)file_get_contents($root . '/lib/Service/MobileIdempotencyService.php');
$app = (string)file_get_contents($root . '/lib/AppInfo/Application.php');
$mig = (string)file_get_contents($root . '/lib/Migration/Version1020Date20260727120000.php');
$catalog = (string)file_get_contents($root . '/lib/Migration/BudgetCheckTableCatalog.php');
$uninstall = (string)file_get_contents($root . '/lib/Repair/UninstallDropTables.php');

$assert(str_contains($routes, "/api/mobile/v1/bootstrap"), 'route_bootstrap');
$assert(str_contains($routes, 'mobile_api#createTransaction'), 'route_create_tx');
$assert(str_contains($routes, 'mobile_api#deleteTransaction'), 'route_delete_tx');
$assert(str_contains($routes, 'mobile_api#listTransactionAttachments'), 'route_list_attachments');
$assert(str_contains($routes, 'mobile_api#uploadTransactionAttachment'), 'route_upload_attachments');
$assert(str_contains($routes, 'mobile_api#deleteTransactionAttachment'), 'route_delete_attachments');
$assert(str_contains($routes, 'mobile_api#createReceiptSuggestion'), 'route_create_receipt_suggest');
$assert(str_contains($routes, 'mobile_api#getReceiptSuggestion'), 'route_get_receipt_suggest');
$assert(str_contains($routes, 'mobile_api#acceptReceiptSuggestion'), 'route_accept_receipt_suggest');
$assert(str_contains($routes, 'mobile_api#cancelReceiptSuggestion'), 'route_cancel_receipt_suggest');
$assert(str_contains($mobile, 'assertSafeMutationChannel'), 'mutation_channel_guard');
$assert(str_contains($mobile, 'MobileMutationChannel::isSafe'), 'mutation_channel_helper_used');
// Channel + validateId must run INSIDE safe() so failures become JSON 403/422, not uncaught throws.
foreach ([
	'createTransaction',
	'updateTransaction',
	'deleteTransaction',
	'applyRecurringSuggestion',
	'registerPushToken',
	'unregisterPushToken',
	'uploadTransactionAttachment',
	'deleteTransactionAttachment',
	'createReceiptSuggestion',
	'acceptReceiptSuggestion',
	'cancelReceiptSuggestion',
] as $mut) {
	$assert(
		(bool)preg_match(
			'/public function ' . preg_quote($mut, '/') . '\([^)]*\): JSONResponse\s*\{\s*return \$this->safe\(/s',
			$mobile
		),
		'mutation_' . $mut . '_opens_with_safe'
	);
}
$assert(
	!preg_match(
		'/public function (?:create|update|delete)Transaction\([^)]*\): JSONResponse\s*\{\s*(?:\$\w+ = \$this->validateId|\$this->assertSafeMutationChannel)/s',
		$mobile
	),
	'mutation_channel_not_outside_safe'
);
$assert(
	(bool)preg_match(
		'/public function home\(int \$workspaceId\): JSONResponse\s*\{\s*return \$this->safe\(/s',
		$mobile
	),
	'home_validate_inside_safe'
);
$assert(str_contains($mobile, 'MobileErrorCodes::fromInvalidArgument'), 'error_codes_helper_used');
$channel = (string)file_get_contents($root . '/lib/Service/MobileMutationChannel.php');
$assert(str_contains($channel, 'Basic|Bearer'), 'channel_allows_basic_bearer');
$codes = (string)file_get_contents($root . '/lib/Service/MobileErrorCodes.php');
$assert(str_contains($codes, 'MONTH_CLOSED'), 'error_code_month_closed');
$assert(str_contains($codes, 'TAX_DISABLED'), 'error_code_tax_disabled');
$assert(str_contains($mobile, 'NOT_FOUND'), 'not_found_code');
$assert(str_contains($mobile, 'NotFoundException'), 'not_found_exception');
$assert(str_contains($mobile, "'message' => \$message"), 'error_envelope_includes_message');
$assert(str_contains($mobile, 'VERSION_CONFLICT'), 'cas_code_version_conflict');
$assert(str_contains($codes, 'MONTH_CLOSED'), 'closed_month_code');
$assert(str_contains($mobile, 'Idempotency-Key'), 'idempotency_header');
$assert(str_contains($mobile, 'version is required for deletes'), 'delete_version_required');
$assert(str_contains($mobile, 'MobileHomeKpi::dominantKey'), 'home_kpi_helper_used');
$kpi = (string)file_get_contents($root . '/lib/Service/MobileHomeKpi.php');
$assert(str_contains($kpi, 'available_after_savings'), 'household_kpi_key');
$assert(str_contains($kpi, 'spend_vs_cap'), 'project_kpi_key');
$assert(str_contains($kpi, 'spend_to_date'), 'project_spend_to_date_key');
$assert(!str_contains($mobile, 'BDC2'), 'no_bdc2_in_mobile');
$assert(!str_contains($mobile, 'LICENSE_REQUIRED'), 'no_license_required');
$assert(!str_contains($mobile, 'NO_MOBILE_SEAT'), 'no_seat_code');
$assert(str_contains($caps, "'free' => true"), 'capabilities_free');
$assert(str_contains($caps, 'companion.min'), 'capabilities_min');
$assert(str_contains($caps, 'COMPANION_API = 3'), 'companion_api_v3');
$assert(str_contains($caps, 'receiptSuggest'), 'capabilities_receipt_suggest');
$assert(str_contains($mobile, "'receiptSuggest'"), 'bootstrap_receipt_suggest');
$assert(str_contains($app, 'ReceiptSuggestService'), 'receipt_suggest_di');
$assert(str_contains($app, 'registerCapability'), 'capability_registered');
$assert(str_contains($app, 'MobileIdempotencyService'), 'idempotency_di');
$assert(str_contains($idemp, 'IdempotencyMismatchException'), 'idempotency_mismatch');
$assert(str_contains($mig, 'bc_idempotency'), 'migration_idempotency');
$assert(str_contains($mig, 'bc_mobile_push'), 'migration_push');
$assert(str_contains($catalog, 'bc_idempotency'), 'catalog_idempotency');
$assert(str_contains($uninstall, 'bc_idempotency'), 'uninstall_idempotency');
$assert(str_contains($uninstall, 'bc_mobile_push'), 'uninstall_push');
// Web mutations must still require CSRF
$assert(!preg_match('/#\[NoCSRFRequired\]\s*\n\s*public function createTransaction\(/', $api), 'web_create_still_csrf');
$assert(!preg_match('/#\[NoCSRFRequired\]\s*\n\s*public function deleteTransaction\(/', $api), 'web_delete_still_csrf');
// Mobile mutations must allow NoCSRF
$assert((bool)preg_match('/#\[NoCSRFRequired\]\s*\n\s*public function createTransaction\(/', $mobile), 'mobile_create_nocsrf');
$assert((bool)preg_match('/#\[NoCSRFRequired\]\s*\n\s*public function deleteTransaction\(/', $mobile), 'mobile_delete_nocsrf');

$versionFile = trim((string)file_get_contents($root . '/appinfo/version'));
$info = (string)file_get_contents($root . '/appinfo/info.xml');
$pkg = (string)file_get_contents($root . '/package.json');
$assert(preg_match('/<version>' . preg_quote($versionFile, '/') . '<\/version>/', $info) === 1, 'info_xml_version_lock');
$assert(str_contains($pkg, '"version": "' . $versionFile . '"'), 'package_json_version_lock');
$assert(version_compare($versionFile, '1.1.0', '>='), 'companion_version_at_least_1_1_0');

exit($failed === 0 ? 0 : 1);
