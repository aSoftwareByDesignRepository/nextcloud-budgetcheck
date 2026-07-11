<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\SummaryService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SummaryYearlySavingsAchievedTest extends TestCase
{
	public function testActualTransfersAreNotCappedWhenSummingYearlySavingsAchieved(): void
	{
		$service = new SummaryService(
			$this->createMock(\OCP\IDBConnection::class),
			$this->createMock(\OCA\BudgetCheck\Service\AccessControlService::class),
			$this->createMock(\OCA\BudgetCheck\Service\MoneyService::class),
			$this->createMock(\OCA\BudgetCheck\Service\BudgetService::class),
			$this->createMock(\OCA\BudgetCheck\Service\SavingsTargetService::class),
			$this->createMock(\OCA\BudgetCheck\Service\WorkspaceService::class),
			$this->createMock(\OCA\BudgetCheck\Service\CategoryService::class),
			$this->createMock(\OCA\BudgetCheck\Service\WarningEngine::class),
			$this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class),
			$this->createMock(\OCA\BudgetCheck\Service\TransactionService::class),
		);

		$method = new ReflectionMethod(SummaryService::class, 'yearlySavingsMonthContributions');
		$method->setAccessible(true);

		/** @var array{achievedMinor:int,towardTargetMinor:int} $january */
		$january = $method->invoke($service, 4_000_00, 29_000_00, 0, 0, true);
		/** @var array{achievedMinor:int,towardTargetMinor:int} $february */
		$february = $method->invoke($service, 4_000_00, 4_000_00, 0, 0, true);

		$this->assertSame(29_000_00, $january['achievedMinor']);
		$this->assertSame(4_000_00, $january['towardTargetMinor']);
		$this->assertSame(33_000_00, $january['achievedMinor'] + $february['achievedMinor']);
		$this->assertSame(8_000_00, $january['towardTargetMinor'] + $february['towardTargetMinor']);
	}

	public function testNonTransferWorkspacesUseIncomeSurplusForAchievedAmount(): void
	{
		$service = new SummaryService(
			$this->createMock(\OCP\IDBConnection::class),
			$this->createMock(\OCA\BudgetCheck\Service\AccessControlService::class),
			$this->createMock(\OCA\BudgetCheck\Service\MoneyService::class),
			$this->createMock(\OCA\BudgetCheck\Service\BudgetService::class),
			$this->createMock(\OCA\BudgetCheck\Service\SavingsTargetService::class),
			$this->createMock(\OCA\BudgetCheck\Service\WorkspaceService::class),
			$this->createMock(\OCA\BudgetCheck\Service\CategoryService::class),
			$this->createMock(\OCA\BudgetCheck\Service\WarningEngine::class),
			$this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class),
			$this->createMock(\OCA\BudgetCheck\Service\TransactionService::class),
		);

		$method = new ReflectionMethod(SummaryService::class, 'yearlySavingsMonthContributions');
		$method->setAccessible(true);

		/** @var array{achievedMinor:int,towardTargetMinor:int} $out */
		$out = $method->invoke($service, 4_000_00, 0, 30_000_00, 20_000_00, false);

		$this->assertSame(10_000_00, $out['achievedMinor']);
		$this->assertSame(4_000_00, $out['towardTargetMinor']);
	}
}
