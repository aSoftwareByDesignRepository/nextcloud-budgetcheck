<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\TransactionService;
use OCA\BudgetCheck\Service\WorkspaceService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class TransactionDateWindowTest extends TestCase
{
	public function testHouseholdWithoutBoundsReturnsUnboundedWindow(): void
	{
		$service = $this->service();
		$method = new ReflectionMethod(TransactionService::class, 'resolveDateWindow');
		$method->setAccessible(true);

		/** @var array{0:?string,1:?string} $window */
		$window = $method->invoke($service, [
			'type' => WorkspaceService::TYPE_HOUSEHOLD,
			'timezone' => 'Europe/Copenhagen',
		], []);

		$this->assertNull($window[0]);
		$this->assertNull($window[1]);
	}

	public function testHouseholdHonoursExplicitBounds(): void
	{
		$service = $this->service();
		$method = new ReflectionMethod(TransactionService::class, 'resolveDateWindow');
		$method->setAccessible(true);

		/** @var array{0:?string,1:?string} $window */
		$window = $method->invoke($service, [
			'type' => WorkspaceService::TYPE_HOUSEHOLD,
		], [
			'from' => '2025-05-01',
			'to' => '2026-06-30',
		]);

		$this->assertSame('2025-05-01', $window[0]);
		$this->assertSame('2026-06-30', $window[1]);
	}

	public function testProjectClampsToWorkspaceWindowWhenBoundsOmitted(): void
	{
		$service = $this->service();
		$method = new ReflectionMethod(TransactionService::class, 'resolveDateWindow');
		$method->setAccessible(true);

		/** @var array{0:?string,1:?string} $window */
		$window = $method->invoke($service, [
			'type' => WorkspaceService::TYPE_PROJECT,
			'projectStartDate' => '2024-03-01',
			'projectEndDate' => '2025-12-31',
		], []);

		$this->assertSame('2024-03-01', $window[0]);
		$this->assertSame('2025-12-31', $window[1]);
	}

	private function service(): TransactionService
	{
		return new TransactionService(
			$this->createMock(\OCP\IDBConnection::class),
			$this->createMock(\OCA\BudgetCheck\Service\AccessControlService::class),
			$this->createMock(\OCA\BudgetCheck\Service\MoneyService::class),
			$this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class),
			$this->createMock(\OCA\BudgetCheck\Service\AuditLogService::class),
			$this->createMock(\OCA\BudgetCheck\Service\CategoryService::class),
			$this->createMock(\OCA\BudgetCheck\Service\BookingStatusService::class),
		);
	}
}
