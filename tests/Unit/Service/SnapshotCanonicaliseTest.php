<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\SnapshotService;
use PHPUnit\Framework\TestCase;

/**
 * Pin the canonical hash input shape so two close runs against the same
 * underlying numbers produce the same SHA-256 hash regardless of insertion
 * order, key ordering, or absent optional totals. The canonical helper is
 * private; we exercise it through a reflection wrapper that stays inside the
 * test boundary.
 */
final class SnapshotCanonicaliseTest extends TestCase
{
	public function testCanonicalIsStableAcrossOrdering(): void
	{
		$summaryA = [
			'workspace' => ['id' => 17],
			'yearMonth' => '2026-05',
			'totals' => [
				'income' => ['minor' => 250_000],
				'expense' => ['minor' => 175_000],
				'netResult' => ['minor' => 75_000],
				'savingsTarget' => ['minor' => 30_000],
				'availableAfterSavings' => ['minor' => 45_000],
				'specialIncome' => ['minor' => 0],
				'specialExpense' => ['minor' => 12_000],
				'taxBasis' => null,
				'tax' => null,
			],
			'budget' => [
				'plannedTotal' => ['minor' => 200_000],
				'actualTotal' => ['minor' => 175_000],
				'byCategory' => [
					['categoryId' => 5, 'planned' => ['minor' => 80_000], 'actual' => ['minor' => 60_000]],
					['categoryId' => 1, 'planned' => ['minor' => 120_000], 'actual' => ['minor' => 115_000]],
				],
			],
		];

		$summaryB = $summaryA;
		// Swap the order of byCategory to verify the canonicaliser sorts.
		$summaryB['budget']['byCategory'] = array_reverse($summaryA['budget']['byCategory']);

		$canonical = self::invokeCanonicalise(new SnapshotServiceTestable(), $summaryA);
		$canonical2 = self::invokeCanonicalise(new SnapshotServiceTestable(), $summaryB);
		$this->assertSame($canonical, $canonical2);
	}

	public function testCanonicalIncludesEveryAuditField(): void
	{
		$summary = [
			'workspace' => ['id' => 1],
			'yearMonth' => '2026-05',
			'totals' => [
				'income' => ['minor' => 1],
				'expense' => ['minor' => 2],
				'netResult' => ['minor' => -1],
				'savingsTarget' => ['minor' => 3],
				'availableAfterSavings' => ['minor' => -4],
				'specialIncome' => ['minor' => 5],
				'specialExpense' => ['minor' => 6],
				'taxBasis' => 'gross',
				'tax' => [
					'net' => ['minor' => 7],
					'vat' => ['minor' => 8],
					'gross' => ['minor' => 9],
				],
			],
			'budget' => [
				'plannedTotal' => ['minor' => 10],
				'actualTotal' => ['minor' => 11],
				'byCategory' => [],
			],
		];
		$canonical = self::invokeCanonicalise(new SnapshotServiceTestable(), $summary);
		foreach ([1, 2, -1, 3, -4, 5, 6, 7, 8, 9, 10, 11] as $value) {
			$this->assertStringContainsString((string)$value, $canonical);
		}
		$this->assertStringContainsString('"taxBasis":"gross"', $canonical);
	}

	private static function invokeCanonicalise(object $instance, array $summary): string
	{
		$ref = new \ReflectionMethod(SnapshotService::class, 'canonicaliseForHash');
		$ref->setAccessible(true);
		return (string)$ref->invoke($instance, $summary);
	}
}

/**
 * Bypasses SnapshotService's full constructor so we can reach the private
 * canonicaliser without instantiating eight collaborator services.
 */
final class SnapshotServiceTestable extends SnapshotService
{
	public function __construct() // @phpstan-ignore-line — testing helper.
	{
	}
}
