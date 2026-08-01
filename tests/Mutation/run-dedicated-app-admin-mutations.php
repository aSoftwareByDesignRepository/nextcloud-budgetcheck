<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: BudgetCheck dedicated App Admin OR-semantics + picker UI.
 *
 * Usage (Docker from nextcloud/):
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/budgetcheck/tests/Mutation/run-dedicated-app-admin-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}

/**
 * @return list<array{file:string,from:string,to:string,label:string}>
 */
function budget_dedicated_admin_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/lib/Service/AccessControlService.php',
			'from' => 'return $this->isSystemAdmin($userId) || in_array($userId, $this->getAppAdminIds(), true);',
			'to' => 'return $this->isSystemAdmin($userId) && in_array($userId, $this->getAppAdminIds(), true);',
			'label' => 'app_admin_or_becomes_and_narrow',
		],
		[
			'file' => $appRoot . '/lib/Service/AccessControlService.php',
			'from' => 'return $this->isSystemAdmin($userId) || in_array($userId, $this->getAppAdminIds(), true);',
			'to' => 'return $this->isSystemAdmin($userId);',
			'label' => 'drops_dedicated_admin_list',
		],
		[
			'file' => $appRoot . '/templates/parts/app-settings/admins.php',
			'from' => 'id="bc-policy-admins-q"',
			'to' => 'id="bc-policy-admins-query"',
			'label' => 'breaks_app_admin_search_input',
		],
	];
}

function run_phpunit(string $phpunit, string $appRoot): int
{
	$cmd = escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter DedicatedAppAdminContractTest';
	passthru($cmd, $code);
	return (int)$code;
}

$mutations = budget_dedicated_admin_mutations($appRoot);
$failed = 0;
$killed = 0;

echo "Baseline…\n";
if (run_phpunit($phpunit, $appRoot) !== 0) {
	fwrite(STDERR, "Baseline failed — aborting mutations.\n");
	exit(1);
}

foreach ($mutations as $m) {
	$original = (string)file_get_contents($m['file']);
	if (!str_contains($original, $m['from'])) {
		fwrite(STDERR, "SKIP (needle missing): {$m['label']}\n");
		$failed++;
		continue;
	}
	file_put_contents($m['file'], str_replace($m['from'], $m['to'], $original));
	echo "Mutant {$m['label']}…\n";
	$code = run_phpunit($phpunit, $appRoot);
	file_put_contents($m['file'], $original);
	if ($code === 0) {
		fwrite(STDERR, "SURVIVED: {$m['label']}\n");
		$failed++;
	} else {
		echo "Killed: {$m['label']}\n";
		$killed++;
	}
}

echo "Done: killed={$killed} failed={$failed} total=" . count($mutations) . "\n";
exit($failed === 0 ? 0 : 1);
