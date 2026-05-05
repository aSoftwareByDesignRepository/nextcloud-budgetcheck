<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\SummaryService;
use PHPUnit\Framework\TestCase;

final class SummaryTaxBasisAggregationTest extends TestCase
{
	public function testAggregateMonthUsesNetForBudgetConsumptionWhenWorkspaceBasisIsNet(): void
	{
		$svc = self::newService();
		$workspace = ['taxModeEnabled' => true, 'taxBudgetBasis' => 'net'];
		$rows = [[
			'amount_minor' => 11_900,
			'entry_amount_basis' => 'gross',
			'net_amount_minor' => 10_000,
			'vat_amount_minor' => 1_900,
			'gross_amount_minor' => 11_900,
			'direction' => 'expense',
			'category_id' => 5,
			'is_special' => false,
			'id' => 1,
			'title' => 'receipt',
			'booking_date' => '2026-05-10',
		]];

		$out = self::invokeAggregateMonth($svc, $workspace, $rows, []);
		$this->assertSame(10_000, $out['budgetedActualMinor']);
		$this->assertSame(10_000, $out['totalNetMinor']);
		$this->assertSame(1_900, $out['totalVatMinor']);
		$this->assertSame(11_900, $out['totalGrossMinor']);
	}

	public function testAggregateMonthUsesGrossForBudgetConsumptionWhenWorkspaceBasisIsGross(): void
	{
		$svc = self::newService();
		$workspace = ['taxModeEnabled' => true, 'taxBudgetBasis' => 'gross'];
		$rows = [[
			'amount_minor' => 11_900,
			'entry_amount_basis' => 'net',
			'net_amount_minor' => 10_000,
			'vat_amount_minor' => 1_900,
			'gross_amount_minor' => 11_900,
			'direction' => 'expense',
			'category_id' => 5,
			'is_special' => false,
			'id' => 1,
			'title' => 'receipt',
			'booking_date' => '2026-05-10',
		]];

		$out = self::invokeAggregateMonth($svc, $workspace, $rows, []);
		$this->assertSame(11_900, $out['budgetedActualMinor']);
	}

	private static function invokeAggregateMonth(object $instance, array $workspace, array $rows, array $uncatIds): array
	{
		$ref = new \ReflectionMethod(SummaryService::class, 'aggregateMonth');
		$ref->setAccessible(true);
		/** @var array<string,mixed> $out */
		$out = $ref->invoke($instance, $workspace, $rows, $uncatIds);
		return $out;
	}

	private static function newService(): SummaryService
	{
		$ref = new \ReflectionClass(SummaryService::class);
		/** @var SummaryService $svc */
		$svc = $ref->newInstanceWithoutConstructor();
		return $svc;
	}
}

