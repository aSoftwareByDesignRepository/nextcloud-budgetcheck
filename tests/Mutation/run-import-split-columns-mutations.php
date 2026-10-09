<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for split income/expense CSV columns (GitHub #23).
 *
 * Proves tests/js/import-split-columns.test.js kills the real failure modes:
 * sided-header arbitration, both-filled rejection, amount+split rejection,
 * direction conflict, side-derived direction, dash-as-blank.
 *
 * Usage (host, from app root or workspace):
 *   php tests/Mutation/run-import-split-columns-mutations.php
 *
 * @copyright Copyright (c) 2026, Software by Design GbR
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$source = $appRoot . '/js/import.js';
$backup = $source . '.mutation-bak';
$testJs = $appRoot . '/tests/js/import-split-columns.test.js';

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

echo "== baseline import-split-columns.test.js ==\n";
if (run_js_test($testJs) !== 0) {
	fwrite(STDERR, "baseline failed\n");
	exit(1);
}

$mutations = [
	[
		'name' => 'sided headers always treated as amount columns (breaks legacy debit/credit keyword files)',
		'from' => 'if (columnLooksLikeAmounts(dataRecords, i)) {',
		'to' => 'if (true) {',
	],
	[
		'name' => 'sided headers never treated as amount columns (split import dead)',
		'from' => 'if (columnLooksLikeAmounts(dataRecords, i)) {',
		'to' => 'if (false) {',
	],
	[
		'name' => 'empty sided column still counts as amount column',
		'from' => 'return hasValue;',
		'to' => 'return true;',
	],
	[
		'name' => 'both split cells filled no longer rejected',
		'from' => 'if (expenseFilled && incomeFilled) {',
		'to' => 'if (false) {',
	],
	[
		'name' => 'split side + amount column combo not rejected',
		'from' => 'if (!isBlankAmountCell(amountRaw)) {',
		'to' => 'if (false) {',
	],
	[
		'name' => 'income/expense sides flipped',
		'from' => "splitDirection = incomeFilled ? 'income' : 'expense';",
		'to' => "splitDirection = incomeFilled ? 'expense' : 'income';",
	],
	[
		'name' => 'explicit direction vs split side conflict not rejected',
		'from' => 'if (direction && splitDirection && direction !== splitDirection) {',
		'to' => 'if (false && direction && splitDirection && direction !== splitDirection) {',
	],
	[
		'name' => 'dash placeholder not treated as blank (dash rows become parse errors)',
		'from' => "return v === '' || v === '-' || v === '–' || v === '—';",
		'to' => "return v === '';",
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
	fwrite(STDERR, "\n{$failed} split-columns mutation(s) survived.\n");
	exit(1);
}

echo "\nAll split-columns mutations killed.\n";
exit(0);
