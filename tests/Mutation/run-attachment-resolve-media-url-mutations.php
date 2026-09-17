<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for attachment resolveMediaUrl (GitHub #20).
 *
 * Proves the JS regression test kills the double-/index.php/ failure mode.
 *
 * Usage (host, from app root or workspace):
 *   php tests/Mutation/run-attachment-resolve-media-url-mutations.php
 *
 * @copyright Copyright (c) 2026, Software by Design GbR
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$source = $appRoot . '/js/common/attachment-gallery.js';
$backup = $source . '.mutation-bak';
$testJs = $appRoot . '/tests/js/attachment-resolve-media-url.test.js';

function run_js_test(string $testJs): int
{
	$cmd = 'node ' . escapeshellarg($testJs);
	passthru($cmd, $code);
	return (int)$code;
}

function restore(string $source, string $backup): void
{
	if (is_file($backup)) {
		file_put_contents($source, (string) file_get_contents($backup));
		unlink($backup);
	}
}

if (!is_file($source) || !is_file($testJs)) {
	fwrite(STDERR, "missing source or test\n");
	exit(1);
}

$orig = (string) file_get_contents($source);

echo "== baseline attachment-resolve-media-url.test.js ==\n";
if (run_js_test($testJs) !== 0) {
	fwrite(STDERR, "baseline failed\n");
	exit(1);
}

$mutations = [
	[
		'name' => 'always call generateUrl (reintroduce #20)',
		'from' => "\t\t// linkToRoute (or absolute same-origin stripped to path) already carries\n"
			. "\t\t// webroot and/or /index.php — do not prefix again.\n"
			. "\t\tif (path.includes(appMarker) && !path.startsWith(appMarker)) {\n"
			. "\t\t\treturn path;\n"
			. "\t\t}\n\n",
		'to' => "\t\t// MUTATED: blind generateUrl (double /index.php/)\n",
	],
	[
		'name' => 'drop already-normalized guard condition',
		'from' => 'if (path.includes(appMarker) && !path.startsWith(appMarker)) {',
		'to' => 'if (false && path.includes(appMarker) && !path.startsWith(appMarker)) {',
	],
];

copy($source, $backup);
$failed = 0;
try {
	foreach ($mutations as $m) {
		$src = (string) file_get_contents($source);
		if (!str_contains($src, $m['from'])) {
			fwrite(STDERR, "mutation needle missing: {$m['name']}\n");
			$failed++;
			file_put_contents($source, $orig);
			continue;
		}
		echo "== mutate: {$m['name']} ==\n";
		file_put_contents($source, str_replace($m['from'], $m['to'], $src));
		$code = run_js_test($testJs);
		file_put_contents($source, $orig);
		if ($code === 0) {
			fwrite(STDERR, "SURVIVED: {$m['name']}\n");
			$failed++;
		} else {
			echo "killed: {$m['name']}\n";
		}
	}
} finally {
	restore($source, $backup);
}

if ($failed !== 0) {
	fwrite(STDERR, "\n{$failed} attachment-resolve-media-url mutation(s) survived.\n");
	exit(1);
}

echo "\nAll attachment-resolve-media-url mutations killed.\n";
exit(0);
