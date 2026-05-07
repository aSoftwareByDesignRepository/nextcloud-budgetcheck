<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\AccessControlService;
use OCA\BudgetCheck\Service\AuditLogService;
use OCA\BudgetCheck\Service\BudgetService;
use OCA\BudgetCheck\Service\CategoryService;
use OCA\BudgetCheck\Service\MoneyService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

final class BudgetServiceTableClassifierTest extends TestCase
{
	private BudgetService $service;

	protected function setUp(): void
	{
		$this->service = new BudgetService(
			$this->createMock(IDBConnection::class),
			$this->createMock(AccessControlService::class),
			$this->createMock(MoneyService::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(AuditLogService::class),
			$this->createMock(CategoryService::class),
			$this->createMock(IL10N::class),
		);
	}

	public function testClassifierDetectsMissingBudgetDefaultsTableError(): void
	{
		$method = new \ReflectionMethod(BudgetService::class, 'isMissingBudgetDefaultsTableError');
		$method->setAccessible(true);
		$ex = new \RuntimeException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'nextcloud.oc_bc_budget_defaults' doesn't exist");
		$this->assertTrue($method->invoke($this->service, $ex));
	}

	public function testClassifierRejectsUnrelatedErrors(): void
	{
		$method = new \ReflectionMethod(BudgetService::class, 'isMissingBudgetDefaultsTableError');
		$method->setAccessible(true);
		$ex = new \RuntimeException('SQLSTATE[23000]: Integrity constraint violation');
		$this->assertFalse($method->invoke($this->service, $ex));
	}
}

