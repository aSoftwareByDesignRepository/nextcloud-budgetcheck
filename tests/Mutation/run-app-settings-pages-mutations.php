<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for the split App settings sub-pages (no Infection required).
 *
 * Usage (from app root):
 *   php tests/Mutation/run-app-settings-pages-mutations.php
 *
 * @copyright Copyright (c) 2026, Software by Design GbR
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$phpFilter = 'AppSettingsSectionCatalogTest|AppSettingsPagesContractTest|AppSettingsTemplateRenderTest';

function run_php_tests(string $appRoot, string $filter): int {
	$nextcloudRoot = dirname($appRoot, 2);
	$dockerRunner = $nextcloudRoot . '/docker/run-app-phpunit.sh';
	if (!is_file('/.dockerenv') && is_file($dockerRunner)) {
		passthru(escapeshellarg($dockerRunner) . ' budgetcheck --filter ' . escapeshellarg($filter), $code);
		return (int) $code;
	}
	// Prefer docker compose exec when available (BudgetCheck gauntlet convention).
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
	passthru('cd ' . escapeshellarg($appRoot) . ' && node --test tests/js/app-settings-pages.test.mjs', $code);
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
	'catalog_default_to_support' => [
		'file' => 'lib/Service/AppSettingsSectionCatalog.php',
		'from' => "public const DEFAULT_SECTION = 'access';",
		'to' => "public const DEFAULT_SECTION = 'support';",
		'kill' => 'php',
	],
	'catalog_drop_admins' => [
		'file' => 'lib/Service/AppSettingsSectionCatalog.php',
		'from' => "\t\t'admins',\n",
		'to' => '',
		'kill' => 'php',
	],
	'catalog_requirement_comma_glue' => [
		'file' => 'lib/Service/AppSettingsSectionCatalog.php',
		'from' => "return implode('|', self::SECTIONS);",
		'to' => "return implode(',', self::SECTIONS);",
		'kill' => 'php',
	],
	'catalog_retarget_admins_anchor' => [
		'file' => 'lib/Service/AppSettingsSectionCatalog.php',
		'from' => "'bc-policy-admins-q' => 'admins',",
		'to' => "'bc-policy-admins-q' => 'access',",
		'kill' => 'php',
	],
	'catalog_isSection_always_true' => [
		'file' => 'lib/Service/AppSettingsSectionCatalog.php',
		'from' => 'return in_array($section, self::SECTIONS, true);',
		'to' => 'return true;',
		'kill' => 'php',
	],
	'catalog_label_access_generic' => [
		'file' => 'lib/Service/AppSettingsSectionCatalog.php',
		'from' => "'access' => \$l->t('Access control'),",
		'to' => "'access' => \$l->t('App settings'),",
		'kill' => 'php',
	],
	'catalog_nav_label_access_long' => [
		'file' => 'lib/Service/AppSettingsSectionCatalog.php',
		'from' => "'access' => \$l->t('Access'),",
		'to' => "'access' => \$l->t('Access control'),",
		'kill' => 'php',
	],
	'dispatcher_fail_closed_removed' => [
		'file' => 'templates/app-settings.php',
		'from' => "if (!isset(\$bcAppSettingsSectionFiles[\$bcRequestedSection])) {\n\t\tthrow new \\RuntimeException('BudgetCheck app settings: unknown section reached the template dispatcher.');\n\t}\n\tinclude __DIR__ . '/parts/app-settings/' . \$bcAppSettingsSectionFiles[\$bcRequestedSection];",
		'to' => "include __DIR__ . '/parts/app-settings/' . (\$bcAppSettingsSectionFiles[\$bcRequestedSection] ?? \$bcAppSettingsSectionFiles['support']);",
		'kill' => 'php',
	],
	'dispatcher_drop_inpage_nav' => [
		'file' => 'templates/app-settings.php',
		'from' => "include __DIR__ . '/parts/app-settings-nav.php';\n",
		'to' => '',
		'kill' => 'php',
	],
	'appsettingsjs_keep_wiring_after_redirect' => [
		'file' => 'js/app-settings.js',
		'from' => "window.location.replace(redirectUrl);\n\t\t\t\treturn;",
		'to' => 'window.location.replace(redirectUrl);',
		'kill' => 'php',
	],
	'redirectjs_forward_same_section' => [
		'file' => 'js/app-settings-legacy-redirect.js',
		'from' => "if (currentSection === '' || currentSection === targetSection) {",
		'to' => "if (currentSection === '') {",
		'kill' => 'node',
	],
	'redirectjs_drop_fragment' => [
		'file' => 'js/app-settings-legacy-redirect.js',
		'from' => "return withWorkspaceId(sectionUrl, loc) + '#' + hash;",
		'to' => 'return withWorkspaceId(sectionUrl, loc);',
		'kill' => 'node',
	],
	'redirectjs_forward_outside_settings' => [
		'file' => 'js/app-settings-legacy-redirect.js',
		'from' => "const currentSection = String(rootEl.getAttribute('data-bc-app-settings-section') || '');",
		'to' => "const currentSection = String(rootEl.getAttribute('data-bc-app-settings-section') || 'x');",
		'kill' => 'node',
	],
	'redirectjs_retarget_admins' => [
		'file' => 'js/app-settings-legacy-redirect.js',
		'from' => "'bc-policy-admins-q': 'admins',",
		'to' => "'bc-policy-admins-q': 'access',",
		'kill' => 'both',
	],
	'redirectjs_unfreeze_map' => [
		'file' => 'js/app-settings-legacy-redirect.js',
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

echo "\nAll App settings pages mutations killed.\n";
