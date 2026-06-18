<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\MoneyService;
use OCA\BudgetCheck\Service\WarningEngine;
use PHPUnit\Framework\TestCase;

final class WarningEngineTest extends TestCase
{
	private WarningEngine $engine;

	protected function setUp(): void
	{
		$this->engine = new WarningEngine(new MoneyService());
	}

	public function testHouseholdNoBudgetNoWarning(): void
	{
		$out = $this->engine->household($this->monthly(0, 0, null), [
			['categoryId' => 1, 'name' => 'Rent', 'plannedMinor' => 0, 'actualMinor' => 0],
		]);
		$this->assertSame([], $out);
	}

	public function testHouseholdOverBudgetEmitsWarning(): void
	{
		$out = $this->engine->household($this->monthly(0, 0, null), [
			['categoryId' => 5, 'name' => 'Groceries', 'plannedMinor' => 50_000, 'actualMinor' => 60_000],
		]);
		$this->assertCount(1, $out);
		$this->assertSame('budget_overspent', $out[0]['code']);
		$this->assertSame(WarningEngine::SEV_WARNING, $out[0]['severity']);
		$this->assertSame(5, $out[0]['meta']['categoryId']);
	}

	public function testHouseholdIncomeOverTargetDoesNotWarn(): void
	{
		$out = $this->engine->household($this->monthly(0, 0, null), [
			[
				'categoryId' => 3,
				'name' => 'Salary',
				'direction' => 'income',
				'plannedMinor' => 50_000,
				'actualMinor' => 60_000,
			],
		]);
		$this->assertSame([], $out);
	}

	public function testHouseholdNearBudgetEmitsInfo(): void
	{
		// 92% of 50000 = 46000. 0.92 >= 0.9 triggers the near-limit info.
		$out = $this->engine->household($this->monthly(0, 0, null), [
			['categoryId' => 7, 'name' => 'Energy', 'plannedMinor' => 50_000, 'actualMinor' => 46_000],
		]);
		$this->assertCount(1, $out);
		$this->assertSame('budget_near_limit', $out[0]['code']);
		$this->assertSame(WarningEngine::SEV_INFO, $out[0]['severity']);
	}

	public function testHouseholdNegativeAvailableEmitsCritical(): void
	{
		$out = $this->engine->household($this->monthly(0, 0, null, availableAfterSavingsMinor: -1), []);
		$this->assertCount(1, $out);
		$this->assertSame('available_after_savings_negative', $out[0]['code']);
		$this->assertSame(WarningEngine::SEV_CRITICAL, $out[0]['severity']);
	}

	public function testLargeSpecialOverThresholdEmitsInfo(): void
	{
		$monthly = $this->monthly(150_000, 'Surgery bill', 100_000);
		$out = $this->engine->household($monthly, []);
		$this->assertCount(1, $out);
		$this->assertSame('large_special_expense', $out[0]['code']);
	}

	public function testLargeSpecialUnderThresholdSilent(): void
	{
		$monthly = $this->monthly(99_999, 'Surgery bill', 100_000);
		$this->assertSame([], $this->engine->household($monthly, []));
	}

	public function testProjectCapExceededIsCritical(): void
	{
		$out = $this->engine->project(allTimeSpendMinor: 110_000, capMinor: 100_000);
		$this->assertCount(1, $out);
		$this->assertSame('project_cap_exceeded', $out[0]['code']);
		$this->assertSame(WarningEngine::SEV_CRITICAL, $out[0]['severity']);
	}

	public function testProjectCapNearIsWarning(): void
	{
		$out = $this->engine->project(allTimeSpendMinor: 90_000, capMinor: 100_000);
		$this->assertCount(1, $out);
		$this->assertSame('project_cap_near', $out[0]['code']);
		$this->assertSame(WarningEngine::SEV_WARNING, $out[0]['severity']);
	}

	public function testProjectNoCapNoWarning(): void
	{
		$this->assertSame([], $this->engine->project(allTimeSpendMinor: 999_999_999, capMinor: null));
		$this->assertSame([], $this->engine->project(allTimeSpendMinor: 0, capMinor: 0));
	}

	private function monthly(int $largestSpecialMinor, string|int $largestSpecialTitle = '', ?int $threshold = null, int $availableAfterSavingsMinor = 0): array
	{
		return [
			'totalIncomeMinor' => 0,
			'totalExpenseMinor' => 0,
			'savingsTargetMinor' => 0,
			'availableAfterSavingsMinor' => $availableAfterSavingsMinor,
			'uncategorizedExpenseCount' => 0,
			'uncategorizedExpenseMinor' => 0,
			'largestSpecialMinor' => $largestSpecialMinor,
			'largestSpecialTitle' => is_string($largestSpecialTitle) ? $largestSpecialTitle : '',
			'overspendThresholdMinor' => $threshold,
			'yearMonth' => '2026-05',
		];
	}
}
