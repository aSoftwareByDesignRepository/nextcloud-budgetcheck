<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for epic production gates (nav, GET read-only, savings math, CSRF).
 */

$root = dirname(__DIR__, 2);
require_once $root . '/vendor/autoload.php';

use OCA\BudgetCheck\Service\SavingsTargetService;

$failures = 0;
function assert_true(bool $cond, string $msg): void
{
	global $failures;
	if (!$cond) {
		fwrite(STDERR, "FAIL: $msg\n");
		$failures++;
	}
}

$page = (string)file_get_contents($root . '/lib/Controller/PageController.php');
assert_true(
	str_contains($page, "'id' => 'budgets'") && !preg_match("/'id' => 'budgets'[^\\n]*'show' => false/", $page),
	'budgets nav must not be hardcoded show=>false'
);
assert_true(
	(bool)preg_match("/'id' => 'budgets'[^\\n]*'show' => \\\$workspace !== null/", $page),
	'budgets nav shows when workspace selected'
);

$api = (string)file_get_contents($root . '/lib/Controller/ApiController.php');
preg_match('/function listWorkspaces\(\): JSONResponse\s*\{.*?return \$this->safe\(function \(string \$userId\): array \{(.*?)\}\);/s', $api, $mList);
assert_true(isset($mList[1]) && !str_contains($mList[1], 'saveFavoriteWorkspaceIds'), 'GET listWorkspaces read-only');
preg_match('/function getWorkspaceFavorites\(\): JSONResponse\s*\{.*?return \$this->safe\(function \(string \$userId\): array \{(.*?)\}\);/s', $api, $mGet);
assert_true(isset($mGet[1]) && !str_contains($mGet[1], 'saveFavoriteWorkspaceIds'), 'GET favorites read-only');
preg_match('/function saveWorkspaceFavorites\(\): JSONResponse\s*\{.*?return \$this->safe\(function \(string \$userId\): array \{(.*?)\}\);/s', $api, $mSave);
assert_true(isset($mSave[1]) && str_contains($mSave[1], 'saveFavoriteWorkspaceIds'), 'PUT favorites writes');

$pageResolve = '';
if (preg_match('/function resolveWorkspace\(\): array\s*\{(.*?)\n\t\}/s', $page, $mPage)) {
	$pageResolve = $mPage[1];
}
assert_true($pageResolve !== '' && !str_contains($pageResolve, 'saveFavoriteWorkspaceIds'), 'page resolveWorkspace GET read-only');

$overview = (string)file_get_contents($root . '/templates/workspace-overview.php');
assert_true(!str_contains($overview, 'data-bc-filter-show-archived'), 'no archived teaser filter');
$overviewJs = (string)file_get_contents($root . '/js/workspace-overview.js');
assert_true(!str_contains($overviewJs, 'showArchived'), 'overview JS has no archived filter state');

$tx = (string)file_get_contents($root . '/lib/Service/TransactionService.php');
assert_true(str_contains($tx, 'version is required for updates'), 'update requires version');
assert_true(str_contains($tx, 'version is required for deletes'), 'delete requires version');

$components = (string)file_get_contents($root . '/js/common/components.js');
assert_true(str_contains($components, 'function renderWarningsList'), 'shared warning recovery helper');
assert_true(str_contains($components, 'renderWarningItem'), 'warning item with recovery link');
$monthly = (string)file_get_contents($root . '/js/monthly.js');
$period = (string)file_get_contents($root . '/js/period.js');
$dashboard = (string)file_get_contents($root . '/js/dashboard.js');
assert_true(str_contains($monthly, 'renderWarningsList'), 'monthly uses shared warnings');
assert_true(str_contains($period, 'renderWarningsList'), 'period uses shared warnings');
assert_true(str_contains($dashboard, 'renderWarningsList'), 'dashboard uses shared warnings');

$svc = (new ReflectionClass(SavingsTargetService::class))->newInstanceWithoutConstructor();
assert_true($svc->computeTargetValue([
	'targetMode' => SavingsTargetService::MODE_HYBRID,
	'targetPercentBp' => 1000,
	'targetMinor' => 5_000,
], 100_000) === 10_000, 'hybrid prefers larger percent');
assert_true($svc->computeTargetValue([
	'targetMode' => SavingsTargetService::MODE_HYBRID,
	'targetPercentBp' => 100,
	'targetMinor' => 5_000,
], 100_000) === 5_000, 'hybrid prefers larger absolute');

if ($failures > 0) {
	fwrite(STDERR, "$failures epic-gate mutation(s) failed\n");
	exit(1);
}
fwrite(STDOUT, "epic-gate mutations killed\n");
