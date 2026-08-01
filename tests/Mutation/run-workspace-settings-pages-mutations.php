<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for the split Workspace settings sub-pages (no Infection required).
 *
 * Usage (from app root):
 *   php tests/Mutation/run-workspace-settings-pages-mutations.php
 *
 * @copyright Copyright (c) 2026, Software by Design GbR
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$phpFilter = 'WorkspaceSettingsSectionCatalogTest|WorkspaceSettingsPagesContractTest|WorkspaceSettingsTemplateRenderTest';

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

function run_node_tests(string $appRoot): int {
	passthru('cd ' . escapeshellarg($appRoot) . ' && node --test tests/js/workspace-settings-pages.test.mjs', $code);
	return (int) $code;
}

/**
 * @return bool true when the mutation was killed (at least one suite failed)
 */
function mutation_killed(string $strategy, string $appRoot, string $phpFilter): bool {
	if ($strategy === 'node' || $strategy === 'both') {
		if (run_node_tests($appRoot) !== 0) {
			return true;
		}
		if ($strategy === 'node') {
			return false;
		}
	}
	return run_php_tests($appRoot, $phpFilter) !== 0;
}

$mutations = [
	'catalog_default_to_help' => [
		'file' => 'lib/Service/WorkspaceSettingsSectionCatalog.php',
		'from' => "public const DEFAULT_SECTION = 'workspace';",
		'to' => "public const DEFAULT_SECTION = 'help';",
		'kill' => 'php',
	],
	'catalog_drop_members' => [
		'file' => 'lib/Service/WorkspaceSettingsSectionCatalog.php',
		'from' => "\t\t'members',\n",
		'to' => '',
		'kill' => 'php',
	],
	'catalog_requirement_comma_glue' => [
		'file' => 'lib/Service/WorkspaceSettingsSectionCatalog.php',
		'from' => "return implode('|', self::SECTIONS);",
		'to' => "return implode(',', self::SECTIONS);",
		'kill' => 'php',
	],
	'catalog_retarget_categories_anchor' => [
		'file' => 'lib/Service/WorkspaceSettingsSectionCatalog.php',
		'from' => "'bc-categories-title' => 'categories',",
		'to' => "'bc-categories-title' => 'workspace',",
		'kill' => 'php',
	],
	'catalog_isSection_always_true' => [
		'file' => 'lib/Service/WorkspaceSettingsSectionCatalog.php',
		'from' => 'return in_array($section, self::SECTIONS, true);',
		'to' => 'return true;',
		'kill' => 'php',
	],
	'catalog_visibility_always_true' => [
		'file' => 'lib/Service/WorkspaceSettingsSectionCatalog.php',
		'from' => "\t\treturn match (\$section) {\n\t\t\t'planning-view' => \$isHousehold,",
		'to' => "\t\treturn match (\$section) {\n\t\t\t'planning-view' => true,",
		'kill' => 'php',
	],
	'catalog_default_section_always_workspace' => [
		'file' => 'lib/Service/WorkspaceSettingsSectionCatalog.php',
		'from' => "return \$workspaceType === WorkspaceService::TYPE_HOUSEHOLD\n\t\t\t? 'planning-view'\n\t\t\t: self::DEFAULT_SECTION;",
		'to' => 'return self::DEFAULT_SECTION;',
		'kill' => 'php',
	],
	'dispatcher_fail_closed_removed' => [
		'file' => 'templates/settings.php',
		'from' => "if (!isset(\$bcSettingsSectionFiles[\$bcRequestedSection])) {\n\t\tthrow new \\RuntimeException('BudgetCheck workspace settings: unknown section reached the template dispatcher.');\n\t}\n\tinclude __DIR__ . '/parts/settings/' . \$bcSettingsSectionFiles[\$bcRequestedSection];",
		'to' => "include __DIR__ . '/parts/settings/' . (\$bcSettingsSectionFiles[\$bcRequestedSection] ?? \$bcSettingsSectionFiles['help']);",
		'kill' => 'php',
	],
	'dispatcher_drop_inpage_nav' => [
		'file' => 'templates/settings.php',
		'from' => "include __DIR__ . '/parts/settings-nav.php';\n",
		'to' => '',
		'kill' => 'php',
	],
	'settingsjs_keep_wiring_after_redirect' => [
		'file' => 'js/settings.js',
		'from' => "window.location.replace(redirectUrl);\n\t\t\t\treturn;",
		'to' => 'window.location.replace(redirectUrl);',
		'kill' => 'php',
	],
	'redirectjs_forward_same_section' => [
		'file' => 'js/workspace-settings-legacy-redirect.js',
		'from' => "if (currentSection === '' || currentSection === targetSection) {",
		'to' => "if (currentSection === '') {",
		'kill' => 'node',
	],
	'redirectjs_drop_fragment' => [
		'file' => 'js/workspace-settings-legacy-redirect.js',
		'from' => "return withWorkspaceId(sectionUrl, loc) + '#' + hash;",
		'to' => 'return withWorkspaceId(sectionUrl, loc);',
		'kill' => 'node',
	],
	'redirectjs_forward_outside_settings' => [
		'file' => 'js/workspace-settings-legacy-redirect.js',
		'from' => "const currentSection = String(rootEl.getAttribute('data-bc-settings-section') || '');",
		'to' => "const currentSection = String(rootEl.getAttribute('data-bc-settings-section') || 'x');",
		'kill' => 'node',
	],
	'redirectjs_retarget_categories' => [
		'file' => 'js/workspace-settings-legacy-redirect.js',
		'from' => "'bc-categories-title': 'categories',",
		'to' => "'bc-categories-title': 'workspace',",
		'kill' => 'both',
	],
	'redirectjs_unfreeze_map' => [
		'file' => 'js/workspace-settings-legacy-redirect.js',
		'from' => 'const ANCHOR_SECTIONS = Object.freeze({',
		'to' => 'const ANCHOR_SECTIONS = ({',
		'kill' => 'node',
	],
];

$failedToKill = [];
foreach ($mutations as $name => $mutation) {
	echo "\n== mutation: {$name} ==\n";
	$source = $appRoot . '/' . $mutation['file'];
	$backup = $source . '.mutation-bak';
	$original = file_get_contents($source);
	if ($original === false) {
		fwrite(STDERR, "Cannot read {$mutation['file']}\n");
		exit(1);
	}
	if (!str_contains($original, $mutation['from'])) {
		fwrite(STDERR, "Mutation anchor not found for {$name}\n");
		$failedToKill[] = $name . ' (anchor missing)';
		continue;
	}
	$mutated = str_replace($mutation['from'], $mutation['to'], $original);
	if ($mutated === $original) {
		$failedToKill[] = $name . ' (no effect)';
		continue;
	}
	file_put_contents($backup, $original);
	if (file_put_contents($source, $mutated) === false) {
		fwrite(STDERR, "Cannot write mutated {$mutation['file']}\n");
		$failedToKill[] = $name . ' (write failed)';
		@unlink($backup);
		continue;
	}
	try {
		$killed = mutation_killed($mutation['kill'], $appRoot, $phpFilter);
		if (!$killed) {
			fwrite(STDERR, "SURVIVED: {$name}\n");
			$failedToKill[] = $name;
		} else {
			echo "Killed: {$name}\n";
		}
	} finally {
		file_put_contents($source, file_get_contents($backup));
		@unlink($backup);
	}
}

if ($failedToKill !== []) {
	fwrite(STDERR, "\nMutations not killed:\n- " . implode("\n- ", $failedToKill) . "\n");
	exit(1);
}

echo "\nAll Workspace settings pages mutations killed.\n";
