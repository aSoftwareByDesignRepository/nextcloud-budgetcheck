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
assert_true(
	(bool)preg_match('/workspace\.withWorkspace\s*\(/', $components),
	'warning recovery calls withWorkspace as a method'
);
assert_true(
	!preg_match('/const\s+withWorkspace\s*=\s*workspace\s*&&/', $components),
	'warning recovery must not extract withWorkspace unbound'
);

$workspaceJs = (string)file_get_contents($root . '/js/common/workspace.js');
assert_true(
	(bool)preg_match('/withWorkspace\s*\(\s*url\s*\)\s*\{[^}]*ctx\.workspace/s', $workspaceJs),
	'withWorkspace reads ctx.workspace (closure-safe)'
);
assert_true(
	!preg_match('/withWorkspace\s*\(\s*url\s*\)\s*\{[^}]*this\.workspace/s', $workspaceJs),
	'withWorkspace must not depend on this.workspace'
);

$dashboard = (string)file_get_contents($root . '/js/dashboard.js');
assert_true(str_contains($dashboard, 'catch (warnErr)'), 'dashboard isolates warning render failures');
$monthly = (string)file_get_contents($root . '/js/monthly.js');
$period = (string)file_get_contents($root . '/js/period.js');
assert_true(str_contains($monthly, 'renderWarningsList'), 'monthly uses shared warnings');
assert_true(str_contains($period, 'renderWarningsList'), 'period uses shared warnings');
assert_true(str_contains($dashboard, 'renderWarningsList'), 'dashboard uses shared warnings');
assert_true(str_contains($monthly, 'catch (warnErr)'), 'monthly isolates warning render failures');
assert_true(str_contains($period, 'catch (warnErr)'), 'period isolates warning render failures');

$datesJs = (string)file_get_contents($root . '/js/common/dates.js');
assert_true(str_contains($datesJs, 'currentYearMonthSafe'), 'dates exports currentYearMonthSafe');
$dashJs = (string)file_get_contents($root . '/js/dashboard.js');
assert_true(str_contains($dashJs, 'function initialYearMonth'), 'dashboard safe year-month init');
assert_true(
	(bool)preg_match('/!Ws \|\| !Ws\.urls \|\| !Ws\.urls\.transactions/', $dashJs),
	'dashboard null-guards Ws.urls.transactions'
);
$yearlyJs = (string)file_get_contents($root . '/js/yearly.js');
assert_true(str_contains($yearlyJs, 'Ws.urls.monthly'), 'yearly references monthly url');
assert_true(
	(bool)preg_match('/Ws && Ws\.urls && Ws\.urls\.monthly/', $yearlyJs),
	'yearly null-guards monthly url before withWorkspace'
);
$importJs = (string)file_get_contents($root . '/js/import.js');
assert_true(
	(bool)preg_match('/Ws\.urls && Ws\.urls\.transactions/', $importJs),
	'import null-guards transactions url after commit'
);
$wsCreate = (string)file_get_contents($root . '/js/common/workspace.js');
assert_true(
	str_contains($wsCreate, "/apps/budgetcheck/dashboard"),
	'workspace create has dashboard URL fallback'
);
$appCss = (string)file_get_contents($root . '/css/app.css');
assert_true(
	(bool)preg_match('/#app-content\.bc-app\s*\{[^}]*overflow-x:\s*clip/s', $appCss),
	'app shell clips horizontal overflow'
);

$bootstrapJs = (string)file_get_contents($root . '/js/common/bootstrap.js');
assert_true(str_contains($bootstrapJs, 'function onReady'), 'bootstrap onReady present');
assert_true(str_contains($bootstrapJs, 'function live'), 'bootstrap live present');
assert_true(str_contains($bootstrapJs, 'function define'), 'bootstrap define present');
assert_true(str_contains($page, "Util::addScript(Application::APP_ID, 'common/bootstrap')"), 'PageController registers bootstrap');
if (preg_match('/function registerFrontEndAssets\(string \$pageScript\): void \{(.*?)\n\t\}/s', $page, $mAssets)) {
	assert_true(
		(bool)preg_match('/^\s*Util::addScript\([^;]*common\/bootstrap/m', $mAssets[1]),
		'bootstrap is first addScript in registerFrontEndAssets'
	);
} else {
	assert_true(false, 'registerFrontEndAssets body readable');
}
foreach (['dashboard.js', 'monthly.js', 'period.js', 'transactions.js', 'import.js', 'settings.js'] as $pageFile) {
	$src = (string)file_get_contents($root . '/js/' . $pageFile);
	assert_true(str_contains($src, 'BudgetCheck.onReady'), $pageFile . ' boots via onReady');
	assert_true(
		!preg_match('/^\tconst\s+\w+\s*=\s*window\.BudgetCheck(?:Api|Workspace)\s*;/m', $src),
		$pageFile . ' must not IIFE-snapshot BudgetCheckApi/Workspace'
	);
}
$editorJs = (string)file_get_contents($root . '/js/common/transaction-editor.js');
assert_true(str_contains($editorJs, 'BudgetCheck.live'), 'transaction-editor uses live deps');
assert_true(str_contains($editorJs, "BudgetCheck.define('TransactionEditor'"), 'transaction-editor defines itself');
$apiJs = (string)file_get_contents($root . '/js/common/api.js');
assert_true(str_contains($apiJs, "BudgetCheck.define('Api'"), 'Api producer uses define');
assert_true(!preg_match('/window\.BudgetCheckApi\s*=/', $apiJs), 'Api must not bare-assign window.BudgetCheckApi');
$wsJs = (string)file_get_contents($root . '/js/common/workspace.js');
assert_true(str_contains($wsJs, "BudgetCheck.define('Workspace'"), 'Workspace producer uses define');
assert_true(!preg_match('/window\.BudgetCheckWorkspace\s*=/', $wsJs), 'Workspace must not bare-assign window');

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
