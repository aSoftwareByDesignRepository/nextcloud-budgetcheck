<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\SummaryService;
use OCA\BudgetCheck\Service\TransactionService;
use OCA\BudgetCheck\Service\WorkspaceService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SummaryMonthlyActivityTest extends TestCase
{
	public function testMonthlyActivitySeparatesPlannedFromActualCounts(): void
	{
		$service = $this->summaryService();
		$method = new ReflectionMethod(SummaryService::class, 'monthlyActivity');
		$method->setAccessible(true);

		$rows = [
			$this->row('income', '2026-07-01', false),
			$this->row('expense', '2026-07-15', false),
			$this->row('income', '2026-07-20', true),
			$this->row('expense', '2026-07-25', true),
		];

		/** @var array<string,mixed> $out */
		$out = $method->invoke($service, $rows);

		$this->assertSame(2, $out['count']);
		$this->assertSame(1, $out['incomeCount']);
		$this->assertSame(1, $out['expenseCount']);
		$this->assertSame(2, $out['plannedCount']);
		$this->assertSame(4, $out['ledgerCount']);
		$this->assertSame('2026-07-01', $out['firstDate']);
		$this->assertSame('2026-07-25', $out['lastDate']);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function row(string $direction, string $date, bool $isPlanned): array
	{
		return [
			'booking_date' => $date,
			'direction' => $direction,
			'is_planned' => $isPlanned,
			'is_special' => false,
		];
	}

	private function summaryService(): SummaryService
	{
		return new SummaryService(
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
	}
}
