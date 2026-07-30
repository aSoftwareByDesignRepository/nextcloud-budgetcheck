<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\WorkspaceRowLock;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Workspace exclusive lock is the serialization point for month-close vs writes.
 */
final class WorkspaceRowLockTest extends TestCase
{
	public function testAcquireLocksWorkspaceRowForUpdate(): void
	{
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->with('id', 7)->willReturn('eq');
		$qb->method('expr')->willReturn($expr);
		$qb->expects($this->once())->method('select')->with('id')->willReturnSelf();
		$qb->expects($this->once())->method('from')->with('bc_workspaces')->willReturnSelf();
		$qb->expects($this->once())->method('where')->willReturnSelf();
		$qb->expects($this->once())->method('forUpdate')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($v) => $v);

		$result = $this->createMock(\OCP\DB\IResult::class);
		$result->method('fetch')->willReturn(['id' => 7]);
		$result->expects($this->once())->method('closeCursor');
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		WorkspaceRowLock::acquire($db, 7);
	}

	public function testAcquireRejectsMissingWorkspace(): void
	{
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$qb->method('expr')->willReturn($expr);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('forUpdate')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($v) => $v);
		$result = $this->createMock(\OCP\DB\IResult::class);
		$result->method('fetch')->willReturn(false);
		$result->method('closeCursor');
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Workspace not found');
		WorkspaceRowLock::acquire($db, 99);
	}

	public function testAcquireRejectsInvalidId(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('getQueryBuilder');
		$this->expectException(\InvalidArgumentException::class);
		WorkspaceRowLock::acquire($db, 0);
	}
}
