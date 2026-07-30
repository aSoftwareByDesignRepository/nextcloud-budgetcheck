<?php

declare(strict_types=1);

/**
 * Close-out mutation gauntlet for BudgetCheck integrity/security contracts.
 * Run: php tests/Mutation/run-closeout-mutations.php
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
$api = (string) file_get_contents($root . '/lib/Controller/ApiController.php');
$jsApi = (string) file_get_contents($root . '/js/common/api.js');
$txJs = (string) file_get_contents($root . '/js/transactions.js');
$versionFile = trim((string) file_get_contents($root . '/appinfo/version'));
$info = (string) file_get_contents($root . '/appinfo/info.xml');
$pkg = (string) file_get_contents($root . '/package.json');

$assert(str_contains($tx, 'version is required for updates'), 'update_version_required_message');
$assert(str_contains($tx, 'version is required for deletes'), 'delete_version_required_message');
$assert(str_contains($tx, '?int $expectedVersion'), 'delete_expected_version_param');
$assert((bool) preg_match('/function delete\([\s\S]*?eq\(\'version\'/', $tx), 'delete_version_cas_predicate');
$assert((bool) preg_match('/function update\([\s\S]*?eq\(\'version\'/', $tx), 'update_version_cas_predicate');
$assert(str_contains($tx, "isNull('deleted_at')"), 'delete_null_deleted_at_cas');
$assert(str_contains($tx, 'WorkspaceRowLock::acquire'), 'workspace_lock_on_writes');
$snap = (string) file_get_contents($root . '/lib/Service/SnapshotService.php');
$assert(str_contains($snap, 'WorkspaceRowLock::acquire'), 'workspace_lock_on_close');
$assert((bool) preg_match('/WorkspaceRowLock::acquire\([\s\S]*?summary->household\(/s', $snap), 'close_summary_under_lock');
$assert(str_contains($api, 'expectedVersion'), 'api_delete_passes_version');
$assert(str_contains($txJs, 'version: tx.version'), 'client_delete_sends_version');
$assert(str_contains($jsApi, 'headers.requesttoken = token'), 'csrf_header_on_mutations');
$assert(str_contains($jsApi, "'DELETE'"), 'csrf_covers_delete');
$assert(str_contains($api, 'cross-workspace IDs'), 'idor_category_comment');
$assert(str_contains($api, 'existence cannot be probed'), 'idor_opaque_owner_lookup');
$assert(!preg_match('/#\[NoCSRFRequired\]\s*\n\s*public function deleteTransaction\(/', $api), 'delete_requires_csrf');
$assert(!preg_match('/#\[NoCSRFRequired\]\s*\n\s*public function createTransaction\(/', $api), 'create_requires_csrf');
$assert(!preg_match('/#\[NoCSRFRequired\]\s*\n\s*public function updateTransaction\(/', $api), 'update_requires_csrf');
$assert(preg_match('/<version>' . preg_quote($versionFile, '/') . '<\/version>/', $info) === 1, 'info_xml_matches_version_file');
$assert(str_contains($pkg, '"version": "' . $versionFile . '"'), 'package_json_matches_version_file');
$page = (string) file_get_contents($root . '/templates/common/page-start.php');
$assert(str_contains($page, 'id="bc-main-content"'), 'main_landmark');
$assert(str_contains($page, 'bc-skip-link') || str_contains($page, 'Skip to main'), 'skip_link');
$overviewJs = (string) file_get_contents($root . '/js/workspace-overview.js');
$assert(!str_contains($overviewJs, 'Archived workspace'), 'no_dead_archived_workspace_cta');
$assert(!str_contains($overviewJs, 'Show archived'), 'no_dead_show_archived_ui');
$assert(is_file($root . '/tests/Unit/Service/TransactionUpdateCasTest.php'), 'update_cas_unit_test_present');
$assert(is_file($root . '/tests/Unit/Service/TransactionDeleteCasTest.php'), 'delete_cas_unit_test_present');

exit($failed === 0 ? 0 : 1);
