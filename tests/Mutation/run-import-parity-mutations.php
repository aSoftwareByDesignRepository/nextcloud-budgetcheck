<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for the import preview/commit parity guard and the
 * recurring-suggestion zero-write guard (dutycheck suggest-fill class).
 *
 * Usage (host, from repo nextcloud/):
 *   php apps/budgetcheck/tests/Mutation/run-import-parity-mutations.php
 *
 * @copyright Copyright (c) 2026, Software by Design GbR
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$appId = basename($appRoot);
$workspaceRoot = dirname($appRoot, 2);

function run_unit_tests(string $appRoot, string $workspaceRoot, string $appId): int
{
	$filter = 'TransactionImportServiceTest|MobileApiControllerRecurringApplyTest';
	$inside = is_file('/var/www/html/lib/base.php');
	if ($inside) {
		$phpunit = is_file($appRoot . '/vendor/bin/phpunit')
			? $appRoot . '/vendor/bin/phpunit'
			: 'phpunit';
		$cmd = 'php -d opcache.enable_cli=0 -d opcache.enable=0 '
			. escapeshellarg($phpunit)
			. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
			. ' --do-not-cache-result'
			. ' --filter ' . escapeshellarg($filter);
	} else {
		$cmd = 'docker compose -f ' . escapeshellarg($workspaceRoot . '/docker-compose.yml')
			. ' exec -u www-data -T nextcloud php -d opcache.enable_cli=0 -d opcache.enable=0 '
			. '/var/www/html/custom_apps/' . $appId . '/vendor/bin/phpunit '
			. '-c /var/www/html/custom_apps/' . $appId . '/phpunit.xml '
			. '--do-not-cache-result '
			. '--filter ' . escapeshellarg($filter);
	}
	passthru($cmd, $code);

	return (int)$code;
}

$targets = [
	$appRoot . '/lib/Service/TransactionImportService.php',
	$appRoot . '/lib/Controller/MobileApiController.php',
];
$originals = [];
$backups = [];
foreach ($targets as $src) {
	if (!is_file($src)) {
		fwrite(STDERR, "missing $src\n");
		exit(1);
	}
	$backups[$src] = $src . '.mutation-bak';
	$originals[$src] = (string)file_get_contents($src);
	copy($src, $backups[$src]);
}

$restoreAll = static function () use ($targets, $originals, $backups): void {
	foreach ($targets as $src) {
		if (is_file($backups[$src])) {
			file_put_contents($src, $originals[$src]);
			unlink($backups[$src]);
		}
	}
};

echo "== baseline TransactionImportServiceTest|MobileApiControllerRecurringApplyTest ==\n";
if (run_unit_tests($appRoot, $workspaceRoot, $appId) !== 0) {
	$restoreAll();
	fwrite(STDERR, "baseline test run failed\n");
	exit(1);
}

$mutations = [
	[
		'file' => $appRoot . '/lib/Service/TransactionImportService.php',
		'name' => 'drop closed-month gate in shared row validation',
		'from' => 'if (isset($ctx[\'closedMonths\'][$ym])) {',
		'to' => 'if (false && isset($ctx[\'closedMonths\'][$ym])) {',
	],
	[
		'file' => $appRoot . '/lib/Service/TransactionImportService.php',
		'name' => 'closed-month lookup never queries snapshots',
		'from' => 'if ($this->snapshots->isMonthClosed($workspaceId, $ym)) {',
		'to' => 'if (false) {',
	],
	[
		'file' => $appRoot . '/lib/Controller/MobileApiController.php',
		'name' => 'zero-write guard accepts empty batch',
		'from' => 'if ((int)($batch[\'count\'] ?? 0) < 1) {',
		'to' => 'if ((int)($batch[\'count\'] ?? 0) < 0) {',
	],
	[
		'file' => $appRoot . '/lib/Controller/MobileApiController.php',
		'name' => 'closed-month message loses MONTH_CLOSED mapping',
		'from' => "? 'Month is closed. Reopen it before generating this due date.'",
		'to' => "? 'Nothing could be generated for this due date.'",
	],
];

$failed = 0;
try {
	foreach ($mutations as $m) {
		$src = file_get_contents($m['file']);
		if ($src === false || !str_contains($src, $m['from'])) {
			fwrite(STDERR, "mutation needle missing: {$m['name']}\n");
			$failed++;
			continue;
		}
		echo "== mutate: {$m['name']} ==\n";
		file_put_contents($m['file'], str_replace($m['from'], $m['to'], $src));
		$code = run_unit_tests($appRoot, $workspaceRoot, $appId);
		file_put_contents($m['file'], $originals[$m['file']]);
		if ($code === 0) {
			fwrite(STDERR, "SURVIVED: {$m['name']}\n");
			$failed++;
		} else {
			echo "killed: {$m['name']}\n";
		}
	}
} finally {
	$restoreAll();
}

if ($failed !== 0) {
	fwrite(STDERR, "\n{$failed} import-parity mutation(s) survived.\n");
	exit(1);
}

echo "\nAll import-parity mutations killed.\n";
exit(0);
