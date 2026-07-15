#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Seed planned-forecast demo data for manual QA and integration tests.
 *
 * Usage (from nextcloud tree or Docker):
 *   php custom_apps/budgetcheck/scripts/seed-planned-forecast-demo.php
 *   php custom_apps/budgetcheck/scripts/seed-planned-forecast-demo.php --workspace=5 --user=root
 *   php custom_apps/budgetcheck/scripts/seed-planned-forecast-demo.php --json
 */

use OCA\BudgetCheck\Repair\SeedPlannedForecastDemo;
use OCA\BudgetCheck\Service\BudgetPlannedService;
use OCA\BudgetCheck\Service\BudgetService;
use OCA\BudgetCheck\Service\CategoryService;
use OCA\BudgetCheck\Service\SummaryService;
use OCA\BudgetCheck\Service\WorkspaceService;

$candidates = [
	getenv('NEXTCLOUD_ROOT') ? rtrim((string)getenv('NEXTCLOUD_ROOT'), '/\\') . '/lib/base.php' : null,
	__DIR__ . '/../../../lib/base.php',
	__DIR__ . '/../../lib/base.php',
];
$base = null;
foreach ($candidates as $candidate) {
	if ($candidate !== null && is_file($candidate)) {
		$base = $candidate;
		break;
	}
}
if ($base === null) {
	fwrite(STDERR, "Nextcloud lib/base.php not found. Run inside the Nextcloud container.\n");
	exit(1);
}
require_once $base;

if (!isset(\OC::$server)) {
	fwrite(STDERR, "Nextcloud server container is not available.\n");
	exit(1);
}

$workspaceId = 5;
$userId = 'root';
$asJson = false;
foreach (array_slice($argv, 1) as $arg) {
	if ($arg === '--json') {
		$asJson = true;
		continue;
	}
	if (str_starts_with($arg, '--workspace=')) {
		$workspaceId = (int)substr($arg, 12);
		continue;
	}
	if (str_starts_with($arg, '--user=')) {
		$userId = substr($arg, 7);
	}
}

$server = \OC::$server;
$seeder = new SeedPlannedForecastDemo(
	$server->get(\OCP\IDBConnection::class),
	$server->get(WorkspaceService::class),
	$server->get(CategoryService::class),
	$server->get(BudgetService::class),
	$server->get(BudgetPlannedService::class),
	$server->get(SummaryService::class),
);

try {
	$result = $seeder->run($workspaceId, $userId);
} catch (\Throwable $e) {
	fwrite(STDERR, 'Seed failed: ' . $e->getMessage() . "\n");
	exit(1);
}

if ($asJson) {
	echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
	exit(0);
}

echo "Planned-forecast demo seeded for workspace {$workspaceId} (user {$userId}).\n";
echo "Months: " . implode(', ', $result['months']) . "\n";
echo "Categories: " . json_encode($result['categories']) . "\n";
$july = $result['julySummary'];
echo "July cash flow (actual): income=" . ($july['totals']['incomeMinor'] / 100)
	. " expense=" . ($july['totals']['expenseMinor'] / 100) . " EUR\n";
echo "July expected plan: incomeTarget=" . ($july['planned']['incomeTargetMinor'] / 100)
	. " plannedLedgerIn=" . ($july['planned']['ledgerIncomeMinor'] / 100)
	. " plannedLedgerOut=" . ($july['planned']['ledgerExpenseMinor'] / 100)
	. " placeholders=" . $july['planned']['entryCount'] . "\n";
echo "Open: /apps/budgetcheck/monthly?workspaceId={$workspaceId}&yearMonth=2026-07\n";
