<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Contract: every mutator writing workspace-scoped child rows must hold the
 * exclusive workspace row lock inside a transaction. The schema has no
 * foreign keys — WorkspaceDeletionService cascades child tables while holding
 * the lock, so any un-locked insert can orphan a row onto a deleted
 * workspace, and any service-level uniqueness check (no unique index on
 * active category names) can be bypassed by a concurrent same-name write.
 */
final class WorkspaceChildWriteLockContractTest extends TestCase
{
	private const LIB = '/lib/Service/';

	/** @return iterable<string, array{0:string, 1:string}> */
	public static function lockedMutators(): iterable
	{
		// file => method name (public API that writes workspace-scoped rows)
		yield 'category create' => ['CategoryService.php', 'create'];
		yield 'category update' => ['CategoryService.php', 'update'];
		yield 'category ensureSystemCategoriesForWorkspace' => ['CategoryService.php', 'ensureSystemCategoriesForWorkspace'];
		yield 'booking status create' => ['BookingStatusService.php', 'create'];
		yield 'booking status update' => ['BookingStatusService.php', 'update'];
		yield 'booking status deactivate' => ['BookingStatusService.php', 'deactivate'];
		yield 'savings target save' => ['SavingsTargetService.php', 'save'];
		yield 'recurring rule create' => ['RecurringRuleService.php', 'create'];
		yield 'planned-budget syncMonth' => ['BudgetPlannedService.php', 'syncMonth'];
		yield 'import commit' => ['TransactionImportService.php', 'commit'];
		yield 'workspace addMember' => ['WorkspaceService.php', 'addMember'];
		yield 'workspace addGroupMember' => ['WorkspaceService.php', 'addGroupMember'];
	}

	/** @dataProvider lockedMutators */
	public function testMutatorHoldsWorkspaceRowLockInsideTransaction(string $file, string $method): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . self::LIB . $file);
		$pattern = '/function ' . preg_quote($method, '/') . '\s*\([^)]*\)[^{]*\{(?<body>.*?)\n\t\}/s';
		self::assertMatchesRegularExpression($pattern, $src, "$method body must be extractable in $file");
		$body = (string)$src;
		preg_match($pattern, $body, $m);
		$body = $m['body'];

		$begin = strpos($body, 'beginTransaction');
		$lock = strpos($body, 'WorkspaceRowLock::acquire');
		$commit = strpos($body, '->commit()');
		self::assertNotFalse($begin, "$method must open a transaction");
		self::assertNotFalse($lock, "$method must acquire WorkspaceRowLock");
		self::assertNotFalse($commit, "$method must commit the transaction");
		self::assertLessThan($lock, $begin, "$method: transaction must open before the lock");
		self::assertLessThan($commit, $lock, "$method: lock must be acquired before commit");
	}

	public function testImportCommitGuardsRollbackForNestedTransactions(): void
	{
		// TransactionService::create rolls back internally; under DBAL nested
		// transactions that performs the REAL rollback and resets nesting to 0,
		// so an unconditional rollBack() here would throw noActiveTransaction
		// and mask the structured per-row error response with a 500.
		$src = (string)file_get_contents(dirname(__DIR__, 3) . self::LIB . 'TransactionImportService.php');
		$pattern = '/function commit\s*\([^)]*\)[^{]*\{(?<body>.*?)\n\t\}/s';
		self::assertMatchesRegularExpression($pattern, $src);
		preg_match($pattern, $src, $m);
		self::assertMatchesRegularExpression(
			'/WorkspaceRowLock::acquire.*catch \(\\\\InvalidArgumentException\|AccessDeniedException \$e\).*?if \(\$this->db->inTransaction\(\)\)\s*\{\s*\$this->db->rollBack\(\);/s',
			$m['body'],
			'commit must guard rollBack with inTransaction() — the inner create may already have rolled back',
		);
	}
}
