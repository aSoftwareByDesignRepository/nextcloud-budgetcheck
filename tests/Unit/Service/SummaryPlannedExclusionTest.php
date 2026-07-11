<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\SummaryService;
use OCA\BudgetCheck\Service\TransactionService;
use OCA\BudgetCheck\Service\WorkspaceService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SummaryPlannedExclusionTest extends TestCase
{
	public function testPlannedRowsAreExcludedFromPlanningTotals(): void
	{
		$service = $this->summaryService();
		$method = new ReflectionMethod(SummaryService::class, 'aggregateMonth');
		$method->setAccessible(true);

		$workspace = [
			'type' => WorkspaceService::TYPE_HOUSEHOLD,
			'taxModeEnabled' => false,
			'taxBudgetBasis' => 'gross',
		];
		$rows = [
			$this->row('income', 30_000_00, false, 1, false),
			$this->row('expense', 5_000_00, false, 2, false),
			$this->row('expense', 12_000_00, false, 3, true),
		];

		/** @var array<string,mixed> $out */
		$out = $method->invoke($service, $workspace, $rows, [], []);

		$this->assertSame(30_000_00, $out['totalIncomeMinor']);
		$this->assertSame(5_000_00, $out['totalExpenseMinor']);
		$this->assertSame(5_000_00, $out['budgetedActualMinor']);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function row(string $direction, int $amountMinor, bool $isSpecial, int $categoryId, bool $isPlanned): array
	{
		return [
			'amount_minor' => $amountMinor,
			'direction' => $direction,
			'category_id' => $categoryId,
			'is_special' => $isSpecial,
			'is_planned' => $isPlanned,
			'entry_amount_basis' => TransactionService::BASIS_SIMPLE,
			'net_amount_minor' => null,
			'gross_amount_minor' => null,
			'title' => 'Row',
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
