<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Contract: month-close computes evidence under the workspace write lock so
 * concurrent ledger writers cannot widen the close/write TOCTOU window.
 */
final class SnapshotCloseSerializationContractTest extends TestCase
{
	public function testCloseAcquiresWorkspaceLockBeforeSummary(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/SnapshotService.php');
		self::assertStringContainsString('WorkspaceRowLock::acquire', $src);

		self::assertTrue(
			preg_match(
				'/public function close\(int \$workspaceId, string \$userId, string \$yearMonth\): array\s*\{(.*?)\n\tpublic function reopen/s',
				$src,
				$m
			) === 1,
			'close() body must be extractable'
		);
		$body = $m[1];
		$lockPos = strpos($body, 'WorkspaceRowLock::acquire');
		$summaryPos = strpos($body, 'summary->household');
		$beginPos = strpos($body, 'beginTransaction');
		self::assertNotFalse($lockPos, 'close must acquire workspace lock');
		self::assertNotFalse($summaryPos, 'close must compute summary');
		self::assertNotFalse($beginPos, 'close must open a transaction');
		self::assertLessThan($lockPos, $beginPos, 'Transaction must open before lock');
		self::assertLessThan($summaryPos, $lockPos, 'Summary must be computed after the exclusive workspace lock');
		self::assertLessThan($summaryPos, $beginPos, 'Summary must not be computed before the close transaction opens');
	}

	public function testReopenAcquiresWorkspaceLock(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/SnapshotService.php');
		self::assertMatchesRegularExpression(
			'/function reopen\([\s\S]*?WorkspaceRowLock::acquire\([\s\S]*?delete\(\'bc_monthly_snapshots\'/s',
			$src,
		);
	}

	public function testTransactionWritesReCheckClosedUnderLock(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/TransactionService.php');
		self::assertStringContainsString('WorkspaceRowLock::acquire', $src);
		// create path: lock then monthIsClosed re-check before insert
		self::assertMatchesRegularExpression(
			'/WorkspaceRowLock::acquire\(\$this->db, \$workspaceId\);\s*if \(\$this->monthIsClosed\(\$workspaceId, \$ym\)\)/s',
			$src,
		);
	}

	public function testBudgetBulkUpsertReChecksClosedUnderLock(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/BudgetService.php');
		self::assertMatchesRegularExpression(
			'/WorkspaceRowLock::acquire\(\$this->db, \$workspaceId\);\s*if \(\$this->monthIsClosed\(\$workspaceId, \$ym\)\)/s',
			$src,
		);
	}

	public function testAttachmentWritesTakeWorkspaceLock(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/TransactionAttachmentService.php');
		self::assertStringContainsString('WorkspaceRowLock::acquire', $src);
		self::assertStringContainsString('writeStoredContent', $src);
		self::assertStringContainsString('readStoredFile', $src);
	}
}
