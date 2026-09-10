<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for workspace hard-delete (issue #19).
 *
 * Usage (from app root):
 *   php tests/Mutation/run-workspace-delete-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$phpFilter = 'WorkspaceDeletionContractTest|WorkspaceDeletionServiceTest|WorkspaceDeleteIntegrationTest|WorkspaceSettingsTemplateRenderTest';

function run_php_tests(string $appRoot, string $filter): int {
	$nextcloudRoot = dirname($appRoot, 2);
	$dockerRunner = $nextcloudRoot . '/docker/run-app-phpunit.sh';
	if (!is_file('/.dockerenv') && is_file($dockerRunner)) {
		passthru(escapeshellarg($dockerRunner) . ' budgetcheck --filter ' . escapeshellarg($filter), $code);
		return (int) $code;
	}
	if (!is_file('/.dockerenv')) {
		$composeDir = $nextcloudRoot;
		if (is_file($composeDir . '/compose.yaml') || is_file($composeDir . '/docker-compose.yml')) {
			$cmd = 'cd ' . escapeshellarg($composeDir)
				. ' && docker compose exec -u www-data -w /var/www/html/custom_apps/budgetcheck nextcloud'
				. ' php -d opcache.enable_cli=0 -d opcache.enable=0 vendor/bin/phpunit -c phpunit.xml --filter '
				. escapeshellarg($filter);
			passthru($cmd, $code);
			return (int) $code;
		}
	}
	$phpunit = $appRoot . '/vendor/bin/phpunit';
	if (!is_file($phpunit)) {
		$phpunit = 'phpunit';
	}
	passthru(
		'php -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($filter),
		$code,
	);
	return (int) $code;
}

/**
 * @return bool true when the mutation was killed
 */
function mutation_killed(string $appRoot, string $phpFilter, bool $includeNode = false): bool {
	if ($includeNode) {
		passthru('cd ' . escapeshellarg($appRoot) . ' && node --test tests/js/workspace-delete.test.mjs', $nodeCode);
		if ((int)$nodeCode !== 0) {
			return true;
		}
	}
	return run_php_tests($appRoot, $phpFilter) !== 0;
}

$mutations = [
	'drop_confirm_hash_equals' => [
		'file' => 'lib/Service/WorkspaceDeletionService.php',
		'from' => 'return hash_equals($expectedNorm, $providedNorm);',
		'to' => 'return true;',
		'node' => false,
	],
	'drop_manager_gate' => [
		'file' => 'lib/Service/WorkspaceDeletionService.php',
		'from' => '$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_MANAGER);',
		'to' => '// mutated: no manager gate',
		'node' => false,
	],
	'drop_row_lock' => [
		'file' => 'lib/Service/WorkspaceDeletionService.php',
		'from' => 'WorkspaceRowLock::acquire($this->db, $workspaceId);',
		'to' => '// mutated: no row lock',
		'node' => false,
	],
	'drop_csrf_delete_route_rate' => [
		'file' => 'lib/Controller/ApiController.php',
		'from' => "\$this->rateLimit->assertAllowed(\$userId, 'workspace_delete', 5, 3600);",
		'to' => '// mutated: no delete rate limit',
		'node' => false,
	],
	'drop_ui_typed_confirm' => [
		'file' => 'js/settings.js',
		'from' => "await Api.del('/apps/budgetcheck/api/workspaces/' + workspaceId, { confirmName });",
		'to' => "await Api.del('/apps/budgetcheck/api/workspaces/' + workspaceId, { confirmName: 'x' });",
		'node' => true,
	],
];

$failed = [];
foreach ($mutations as $name => $m) {
	$path = $appRoot . '/' . $m['file'];
	$original = (string)file_get_contents($path);
	if (!str_contains($original, $m['from'])) {
		fwrite(STDERR, "SKIP {$name}: needle not found in {$m['file']}\n");
		$failed[] = $name . ' (needle missing)';
		continue;
	}
	$mutated = str_replace($m['from'], $m['to'], $original, $count);
	if ($count < 1) {
		$failed[] = $name . ' (replace failed)';
		continue;
	}
	file_put_contents($path, $mutated);
	echo "Mutating {$name}...\n";
	$killed = mutation_killed($appRoot, $phpFilter, !empty($m['node']));
	file_put_contents($path, $original);
	if (!$killed) {
		fwrite(STDERR, "SURVIVED {$name}\n");
		$failed[] = $name;
	} else {
		echo "Killed {$name}\n";
	}
}

if ($failed !== []) {
	fwrite(STDERR, "Mutation survivors: " . implode(', ', $failed) . "\n");
	exit(1);
}
echo "All workspace-delete mutations killed.\n";
exit(0);
