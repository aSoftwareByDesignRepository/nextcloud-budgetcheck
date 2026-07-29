<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Exception\WorkspaceTypeMismatchException;
use OCA\BudgetCheck\Service\AccessControlService;
use OCA\BudgetCheck\Service\AuditLogService;
use OCA\BudgetCheck\Service\SnapshotService;
use OCA\BudgetCheck\Service\SummaryService;
use OCA\BudgetCheck\Service\WorkspaceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Project workspaces must never close/reopen months (§12.3) — hard 422 path.
 */
final class SnapshotServiceTypeGateTest extends TestCase
{
	public function testCloseRejectsProjectWorkspace(): void
	{
		$svc = $this->serviceWithWorkspaceType(WorkspaceService::TYPE_PROJECT);
		$this->expectException(WorkspaceTypeMismatchException::class);
		$svc->close(9, 'alice', '2026-05');
	}

	public function testReopenRejectsProjectWorkspace(): void
	{
		$svc = $this->serviceWithWorkspaceType(WorkspaceService::TYPE_PROJECT);
		$this->expectException(WorkspaceTypeMismatchException::class);
		$svc->reopen(9, 'alice', '2026-05');
	}

	private function serviceWithWorkspaceType(string $type): SnapshotService
	{
		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->method('getForUser')->willReturn([
			'id' => 9,
			'type' => $type,
			'currencyCode' => 'EUR',
		]);

		return new SnapshotService(
			$this->createMock(IDBConnection::class),
			$this->createMock(AccessControlService::class),
			$workspaces,
			$this->createMock(SummaryService::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(AuditLogService::class),
		);
	}
}
