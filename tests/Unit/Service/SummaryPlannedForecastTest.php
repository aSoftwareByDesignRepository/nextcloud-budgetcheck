<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\CategoryService;
use OCA\BudgetCheck\Service\SummaryService;
use OCA\BudgetCheck\Service\TransactionService;
use OCA\BudgetCheck\Service\WorkspaceService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SummaryPlannedForecastTest extends TestCase
{
	public function testAggregatePlannedLedgerSumsPlaceholderRows(): void
	{
		$service = $this->summaryService();
		$method = new ReflectionMethod(SummaryService::class, 'aggregatePlannedLedger');
		$method->setAccessible(true);

		$workspace = ['type' => WorkspaceService::TYPE_HOUSEHOLD];
		$rows = [
			$this->row('income', 40_000_00, false, 1, true),
			$this->row('expense', 12_000_00, false, 2, true),
			$this->row('expense', 5_000_00, false, 3, false),
		];

		/** @var array<string,mixed> $out */
		$out = $method->invoke($service, $workspace, $rows);

		$this->assertSame(40_000_00, $out['incomeMinor']);
		$this->assertSame(12_000_00, $out['expenseMinor']);
		$this->assertSame(28_000_00, $out['netResultMinor']);
		$this->assertSame(2, $out['entryCount']);
	}

	public function testAggregatePlannedLedgerExcludesSpecialTransactions(): void
	{
		$service = $this->summaryService();
		$method = new ReflectionMethod(SummaryService::class, 'aggregatePlannedLedger');
		$method->setAccessible(true);

		$workspace = ['type' => WorkspaceService::TYPE_HOUSEHOLD];
		$rows = [
			$this->row('income', 40_000_00, true, 1, true),
			$this->row('expense', 12_000_00, false, 2, true),
		];

		/** @var array<string,mixed> $out */
		$out = $method->invoke($service, $workspace, $rows);

		$this->assertSame(0, $out['incomeMinor']);
		$this->assertSame(12_000_00, $out['expenseMinor']);
		$this->assertSame(1, $out['entryCount']);
	}

	public function testSumPlannedMapByDirectionIncludesIncomeTargets(): void
	{
		$service = $this->summaryService();
		$method = new ReflectionMethod(SummaryService::class, 'sumPlannedMapByDirection');
		$method->setAccessible(true);

		$plannedMap = [1 => 40_000_00, 2 => 10_000_00];
		$workspaceCategories = [
			1 => ['type' => CategoryService::TYPE_INCOME, 'isActive' => true],
			2 => ['type' => CategoryService::TYPE_EXPENSE, 'isActive' => true],
		];

		$income = $method->invoke(
			$service,
			$plannedMap,
			$workspaceCategories,
			[],
			[],
			CategoryService::TYPE_INCOME,
		);
		$expense = $method->invoke(
			$service,
			$plannedMap,
			$workspaceCategories,
			[],
			[],
			CategoryService::TYPE_EXPENSE,
		);

		$this->assertSame(40_000_00, $income);
		$this->assertSame(10_000_00, $expense);
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
