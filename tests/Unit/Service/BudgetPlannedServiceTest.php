<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\AccessControlService;
use OCA\BudgetCheck\Service\AuditLogService;
use OCA\BudgetCheck\Service\BudgetPlannedService;
use OCA\BudgetCheck\Service\BudgetService;
use OCA\BudgetCheck\Service\CategoryService;
use OCA\BudgetCheck\Service\SnapshotService;
use OCA\BudgetCheck\Service\TransactionService;
use OCA\BudgetCheck\Service\WorkspaceService;
use OCA\BudgetCheck\Exception\WorkspaceTypeMismatchException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class BudgetPlannedServiceTest extends TestCase
{
	public function testSyncMonthRejectsProjectWorkspace(): void
	{
		$service = new BudgetPlannedService(
			$this->createMock(IDBConnection::class),
			$this->createMock(AccessControlService::class),
			$this->createMock(BudgetService::class),
			$this->createMock(CategoryService::class),
			$this->createMock(TransactionService::class),
			$this->createMock(SnapshotService::class),
			$this->createMock(AuditLogService::class),
			$this->createMock(ITimeFactory::class),
		);

		$this->expectException(WorkspaceTypeMismatchException::class);
		$service->syncMonth(1, 'alice', '2026-06', [
			'id' => 1,
			'type' => WorkspaceService::TYPE_PROJECT,
			'currencyCode' => 'EUR',
		]);
	}

	public function testSyncMonthRejectsClosedMonth(): void
	{
		$snapshots = $this->createMock(SnapshotService::class);
		$snapshots->method('isMonthClosed')->willReturn(true);

		$access = $this->createMock(AccessControlService::class);
		$access->expects($this->once())->method('ensureMinimumRole');

		$service = new BudgetPlannedService(
			$this->createMock(IDBConnection::class),
			$access,
			$this->createMock(BudgetService::class),
			$this->createMock(CategoryService::class),
			$this->createMock(TransactionService::class),
			$snapshots,
			$this->createMock(AuditLogService::class),
			$this->createMock(ITimeFactory::class),
		);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Month is closed');
		$service->syncMonth(1, 'alice', '2026-06', [
			'id' => 1,
			'type' => WorkspaceService::TYPE_HOUSEHOLD,
			'currencyCode' => 'EUR',
		]);
	}
}
