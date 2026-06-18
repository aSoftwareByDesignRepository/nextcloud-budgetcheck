<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\SummaryService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SummarySavingsTransferAggregationTest extends TestCase
{
	public function testSavingsTransfersExcludedFromBudgetActualButIncludedInExpenses(): void
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

		$method = new ReflectionMethod(SummaryService::class, 'aggregateMonth');
		$method->setAccessible(true);

		$workspace = ['taxModeEnabled' => false, 'taxBudgetBasis' => 'gross'];
		$rows = [
			[
				'amount_minor' => 30_000_00,
				'direction' => 'income',
				'category_id' => 1,
				'is_special' => false,
				'entry_amount_basis' => 'simple',
				'net_amount_minor' => null,
				'gross_amount_minor' => null,
				'title' => 'Salary',
			],
			[
				'amount_minor' => 5_000_00,
				'direction' => 'expense',
				'category_id' => 2,
				'is_special' => false,
				'entry_amount_basis' => 'simple',
				'net_amount_minor' => null,
				'gross_amount_minor' => null,
				'title' => 'Groceries',
			],
			[
				'amount_minor' => 16_000_00,
				'direction' => 'expense',
				'category_id' => 9,
				'is_special' => false,
				'entry_amount_basis' => 'simple',
				'net_amount_minor' => null,
				'gross_amount_minor' => null,
				'title' => 'To savings',
			],
		];

		/** @var array<string,mixed> $out */
		$out = $method->invoke($service, $workspace, $rows, [], [9]);

		$this->assertSame(30_000_00, $out['totalIncomeMinor']);
		$this->assertSame(21_000_00, $out['totalExpenseMinor']);
		$this->assertSame(5_000_00, $out['budgetedActualMinor']);
		$this->assertSame(16_000_00, $out['savingsTransferredMinor']);
	}
}
