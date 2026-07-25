<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: BudgetCheck billing facades must fail-closed.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/vendor/autoload.php';

use OCA\BudgetCheck\Public\BillingResult;
use OCA\BudgetCheck\Util\BillingStatus;

$failures = 0;
function assert_true(bool $cond, string $msg): void
{
	global $failures;
	if (!$cond) {
		fwrite(STDERR, "FAIL: $msg\n");
		$failures++;
	}
}

assert_true(BillingStatus::isValid(BillingStatus::OPEN), 'open valid');
assert_true(BillingStatus::isValid(BillingStatus::INVOICED), 'invoiced valid');
assert_true(BillingStatus::isValid(BillingStatus::PAID), 'paid valid');
assert_true(!BillingStatus::isValid(''), 'empty invalid');
assert_true(!BillingStatus::isValid('bogus'), 'bogus invalid');

$write = (string) file_get_contents($root . '/lib/Public/BillingWriteFacade.php');
assert_true(str_contains($write, 'function markItemsPaid'), 'markItemsPaid API');
assert_true(str_contains($write, 'function reopenFromPaid'), 'reopenFromPaid API');
assert_true(str_contains($write, 'BillingStatus::PAID'), 'PAID transitions');

$ok = new BillingResult(2, []);
assert_true($ok->isFullSuccess(), 'full success needs applied>0 and no failed');
$empty = new BillingResult(0, []);
assert_true(!$empty->isFullSuccess(), 'empty is not full success');
assert_true($empty->isEmptySuccess(), 'empty success');

$svc = (string) file_get_contents($root . '/lib/Service/TransactionBillingService.php');
assert_true(str_contains($svc, 'requireFullSuccess'), 'fail-closed full success gate');
assert_true(str_contains($svc, 'not_billable'), 'rejects non-billable');
assert_true(str_contains($svc, 'conflict_updated_at'), 'optimistic updated_at');
assert_true(str_contains($svc, 'is_planned'), 'excludes planned');

$write = (string) file_get_contents($root . '/lib/Public/BillingWriteFacade.php');
assert_true(str_contains($write, 'trustedSiblingApp'), 'trusted sibling flag');
assert_true(str_contains($write, 'Never expose that flag on HTTP'), 'HTTP warning documented');

$read = (string) file_get_contents($root . '/lib/Public/BillingReadFacade.php');
assert_true(str_contains($read, 'Never query bc_*'), 'no direct SQL contract');

$mig = (string) file_get_contents($root . '/lib/Migration/Version1019Date20260724180000.php');
assert_true(str_contains($mig, 'is_billable'), 'migration adds is_billable');
assert_true(str_contains($mig, 'billing_status'), 'migration adds billing_status');
assert_true(str_contains($mig, "'notnull' => false"), 'boolean oracle-safe');

if ($failures > 0) {
	fwrite(STDERR, "$failures billing facade mutation(s) failed\n");
	exit(1);
}
fwrite(STDOUT, "billing facade mutations killed\n");
