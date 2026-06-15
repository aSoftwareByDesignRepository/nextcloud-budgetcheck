<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\AccessControlService;
use OCA\BudgetCheck\Service\AuditLogService;
use OCA\BudgetCheck\Service\CategoryService;
use OCA\BudgetCheck\Service\MoneyService;
use OCA\BudgetCheck\Service\CurrencyCatalog;
use OCA\BudgetCheck\Service\TimezoneCatalog;
use OCA\BudgetCheck\Service\WorkspaceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

final class WorkspaceServiceUpdatePayloadTest extends TestCase
{
	public function testHouseholdWorkspaceAcceptsDefaultSavingsFields(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->expects($this->once())
			->method('ensureMinimumRole');

		$service = $this->getMockBuilder(WorkspaceService::class)
			->setConstructorArgs([
				$this->createMock(IDBConnection::class),
				$access,
				$this->createMock(ITimeFactory::class),
				$this->createMock(AuditLogService::class),
				$this->createMock(TimezoneCatalog::class),
				$this->createMock(CurrencyCatalog::class),
				$this->createMock(IUserManager::class),
				$this->createMock(CategoryService::class),
				$this->createMock(MoneyService::class),
				$this->createMock(IGroupManager::class),
			])
			->onlyMethods(['getForUser'])
			->getMock();

		$workspace = [
			'id' => 4,
			'type' => WorkspaceService::TYPE_HOUSEHOLD,
			'currencyCode' => 'EUR',
			'defaultSavingsTargetMode' => 'percentage',
			'defaultSavingsTargetPercentBp' => 1000,
			'defaultSavingsTargetMinor' => null,
		];
		$service->method('getForUser')->willReturn($workspace);

		$result = $service->updateWorkspace(4, 'alice', [
			'defaultSavingsTargetMode' => 'percentage',
			'defaultSavingsTargetPercentBp' => 1000,
			'defaultSavingsTargetMinor' => null,
		]);

		$this->assertSame($workspace, $result);
	}

	public function testProjectWorkspaceRejectsDefaultSavingsFields(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->expects($this->once())
			->method('ensureMinimumRole');

		$service = $this->getMockBuilder(WorkspaceService::class)
			->setConstructorArgs([
				$this->createMock(IDBConnection::class),
				$access,
				$this->createMock(ITimeFactory::class),
				$this->createMock(AuditLogService::class),
				$this->createMock(TimezoneCatalog::class),
				$this->createMock(CurrencyCatalog::class),
				$this->createMock(IUserManager::class),
				$this->createMock(CategoryService::class),
				$this->createMock(MoneyService::class),
				$this->createMock(IGroupManager::class),
			])
			->onlyMethods(['getForUser'])
			->getMock();

		$service->method('getForUser')->willReturn([
			'id' => 5,
			'type' => WorkspaceService::TYPE_PROJECT,
			'currencyCode' => 'EUR',
			'defaultSavingsTargetMode' => null,
			'defaultSavingsTargetPercentBp' => null,
			'defaultSavingsTargetMinor' => null,
		]);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Project workspaces do not accept default savings target fields.');
		$service->updateWorkspace(5, 'alice', [
			'defaultSavingsTargetMode' => 'percentage',
			'defaultSavingsTargetPercentBp' => 500,
		]);
	}
}

