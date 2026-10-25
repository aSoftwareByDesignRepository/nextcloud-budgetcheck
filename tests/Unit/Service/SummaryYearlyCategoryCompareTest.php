<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\SummaryService;
use OCA\BudgetCheck\Service\TransactionService;
use OCA\BudgetCheck\Service\WorkspaceService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Covers {@see SummaryService::aggregateYearByCategory()} — the pure aggregation
 * heart of yearly-category-compare (spec §9): month bucketing, specials split,
 * tax-basis resolution, planned/deleted/uncategorized exclusion.
 */
final class SummaryYearlyCategoryCompareTest extends TestCase
{
	private function invokeAggregate(array $workspace, array $rows, array $uncatIds = []): array
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
		$method = new ReflectionMethod(SummaryService::class, 'aggregateYearByCategory');
		$method->setAccessible(true);
		/** @var array<int,array<string,mixed>> */
		return $method->invoke($service, $workspace, $rows, $uncatIds);
	}

	/** @return array<string,mixed> */
	private function row(int $cid, int $amount, string $bookingDate, bool $special = false, bool $planned = false, array $tax = []): array
	{
		return [
			'category_id' => $cid,
			'amount_minor' => $amount,
			'booking_date' => $bookingDate,
			'is_special' => $special,
			'is_planned' => $planned,
			'entry_amount_basis' => $tax['basis'] ?? 'simple',
			'net_amount_minor' => $tax['net'] ?? null,
			'gross_amount_minor' => $tax['gross'] ?? null,
		];
	}

	private function household(array $over = []): array
	{
		return array_merge([
			'type' => WorkspaceService::TYPE_HOUSEHOLD,
			'taxModeEnabled' => false,
			'taxBudgetBasis' => 'gross',
		], $over);
	}

	public function testMonthlyBucketingAndSpecialsSplit(): void
	{
		$out = $this->invokeAggregate($this->household(), [
			$this->row(5, 100_00, '2026-01-10'),
			$this->row(5, 200_00, '2026-01-20'),
			$this->row(5, 50_00, '2026-03-05', true),   // special lands in specials, not actual
			$this->row(5, 300_00, '2026-12-31'),
			$this->row(7, 900_00, '2026-06-15'),
		]);

		$this->assertSame(600_00, $out[5]['actual']);
		$this->assertSame(50_00, $out[5]['specials']);
		$this->assertSame(4, $out[5]['count']);        // specials still count as entries
		$this->assertSame(300_00, $out[5]['months'][1]['actual']);
		$this->assertSame(50_00, $out[5]['months'][3]['specials']);
		$this->assertSame(300_00, $out[5]['months'][12]['actual']);
		$this->assertSame(900_00, $out[7]['actual']);
		$this->assertSame(900_00, $out[7]['months'][6]['actual']);
	}

	/**
	 * Regression (live 500 @8afe7e7): a month holding only specials (or only
	 * actuals) must still emit BOTH keys — the compare months-loop reads
	 * $am['actual']/$am['specials'] unconditionally and MoneyService::envelope()
	 * is int-typed, so a missing key crashed the endpoint with TypeError.
	 */
	public function testOneSidedMonthRowsCarryBothKeys(): void
	{
		$out = $this->invokeAggregate($this->household(), [
			$this->row(5, 50_00, '2026-03-05', true),   // specials-only month
			$this->row(5, 100_00, '2026-04-10'),        // actuals-only month
		]);
		$this->assertSame(['actual' => 0, 'specials' => 50_00], $out[5]['months'][3]);
		$this->assertSame(['actual' => 100_00, 'specials' => 0], $out[5]['months'][4]);
	}

	public function testPlannedRowsExcluded(): void
	{
		$out = $this->invokeAggregate($this->household(), [
			$this->row(5, 100_00, '2026-02-01'),
			$this->row(5, 999_00, '2026-02-01', false, true), // planned placeholder
		]);
		$this->assertSame(100_00, $out[5]['actual']);
		$this->assertSame(1, $out[5]['count']);
	}

	public function testUncategorizedExcluded(): void
	{
		$out = $this->invokeAggregate($this->household(), [
			$this->row(5, 100_00, '2026-01-10'),
			$this->row(99, 777_00, '2026-01-10'),
		], [99]);
		$this->assertArrayHasKey(5, $out);
		$this->assertArrayNotHasKey(99, $out);
	}

	public function testTaxNetBasisUsesNetAmount(): void
	{
		$out = $this->invokeAggregate($this->household(['taxModeEnabled' => true, 'taxBudgetBasis' => 'net']), [
			$this->row(5, 119_00, '2026-01-10', false, false, ['basis' => 'gross', 'net' => 100_00, 'gross' => 119_00]),
		]);
		$this->assertSame(100_00, $out[5]['actual']);
	}

	public function testTaxGrossBasisUsesGrossAmount(): void
	{
		$out = $this->invokeAggregate($this->household(['taxModeEnabled' => true, 'taxBudgetBasis' => 'gross']), [
			$this->row(5, 100_00, '2026-01-10', false, false, ['basis' => 'net', 'net' => 100_00, 'gross' => 119_00]),
		]);
		$this->assertSame(119_00, $out[5]['actual']);
	}

	public function testTaxSimpleBasisKeepsPlainAmount(): void
	{
		$out = $this->invokeAggregate($this->household(['taxModeEnabled' => true, 'taxBudgetBasis' => 'net']), [
			$this->row(5, 42_00, '2026-01-10', false, false, ['basis' => TransactionService::BASIS_SIMPLE]),
		]);
		$this->assertSame(42_00, $out[5]['actual']);
	}

	public function testNullTaxFieldsFallBackToPlainAmount(): void
	{
		$out = $this->invokeAggregate($this->household(['taxModeEnabled' => true, 'taxBudgetBasis' => 'net']), [
			$this->row(5, 77_00, '2026-01-10', false, false, ['basis' => 'gross', 'net' => null, 'gross' => 77_00]),
		]);
		$this->assertSame(77_00, $out[5]['actual']);
	}

	public function testEmptyRowsYieldEmptyMap(): void
	{
		$this->assertSame([], $this->invokeAggregate($this->household(), []));
	}
}
