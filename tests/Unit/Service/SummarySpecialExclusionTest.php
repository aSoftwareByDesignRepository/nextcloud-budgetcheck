<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\SummaryService;
use OCA\BudgetCheck\Service\WorkspaceService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SummarySpecialExclusionTest extends TestCase
{
	public function testHouseholdExcludesSpecialsFromPlanningTotals(): void
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
			$this->row('income', 30_000_00, false, 1, 'Salary'),
			$this->row('expense', 5_000_00, false, 2, 'Groceries'),
			$this->row('income', 200_000_00, true, 3, 'Sold car'),
			$this->row('expense', 180_000_00, true, 4, 'Bought car'),
		];

		/** @var array<string,mixed> $out */
		$out = $method->invoke($service, $workspace, $rows, [], []);

		$this->assertSame(30_000_00, $out['totalIncomeMinor']);
		$this->assertSame(5_000_00, $out['totalExpenseMinor']);
		$this->assertSame(25_000_00, $out['netResultMinor']);
		$this->assertSame(230_000_00, $out['ledgerIncomeMinor']);
		$this->assertSame(185_000_00, $out['ledgerExpenseMinor']);
		$this->assertSame(200_000_00, $out['specialIncomeMinor']);
		$this->assertSame(180_000_00, $out['specialExpenseMinor']);
		$this->assertSame(5_000_00, $out['budgetedActualMinor']);
	}

	public function testProjectStillIncludesSpecialsInTotals(): void
	{
		$service = $this->summaryService();
		$method = new ReflectionMethod(SummaryService::class, 'aggregateMonth');
		$method->setAccessible(true);

		$workspace = [
			'type' => WorkspaceService::TYPE_PROJECT,
			'taxModeEnabled' => false,
			'taxBudgetBasis' => 'gross',
		];
		$rows = [
			$this->row('expense', 50_000_00, true, 1, 'Equipment'),
		];

		/** @var array<string,mixed> $out */
		$out = $method->invoke($service, $workspace, $rows, [], []);

		$this->assertSame(50_000_00, $out['totalExpenseMinor']);
		$this->assertSame(50_000_00, $out['ledgerExpenseMinor']);
		$this->assertSame(50_000_00, $out['specialExpenseMinor']);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function row(string $direction, int $amountMinor, bool $isSpecial, int $categoryId, string $title): array
	{
		return [
			'amount_minor' => $amountMinor,
			'direction' => $direction,
			'category_id' => $categoryId,
			'is_special' => $isSpecial,
			'entry_amount_basis' => 'simple',
			'net_amount_minor' => null,
			'gross_amount_minor' => null,
			'title' => $title,
			'booking_date' => '2026-05-15',
			'id' => $categoryId,
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
