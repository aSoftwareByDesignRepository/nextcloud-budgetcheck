<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\PlannedTransactionMatchService;
use PHPUnit\Framework\TestCase;

final class PlannedTransactionMatchTest extends TestCase
{
	public function testSameMonthIsAdjacent(): void
	{
		$this->assertTrue(PlannedTransactionMatchService::calendarMonthsAreAdjacent('2026-01-01', '2026-01-31'));
	}

	public function testAdjacentMonthsAcrossYearBoundary(): void
	{
		$this->assertTrue(PlannedTransactionMatchService::calendarMonthsAreAdjacent('2025-12-31', '2026-01-01'));
		$this->assertTrue(PlannedTransactionMatchService::calendarMonthsAreAdjacent('2026-01-01', '2025-12-28'));
	}

	public function testSalaryTimingPattern(): void
	{
		$this->assertTrue(PlannedTransactionMatchService::calendarMonthsAreAdjacent('2026-02-01', '2026-01-30'));
	}

	public function testTwoMonthsApartIsNotAdjacent(): void
	{
		$this->assertFalse(PlannedTransactionMatchService::calendarMonthsAreAdjacent('2026-01-15', '2026-03-01'));
	}

	public function testInvalidDatesAreNotAdjacent(): void
	{
		$this->assertFalse(PlannedTransactionMatchService::calendarMonthsAreAdjacent('not-a-date', '2026-01-01'));
	}

	public function testPickClosestCandidatePrefersNearestDate(): void
	{
		$pick = PlannedTransactionMatchService::pickClosestCandidate([
			['id' => 2, 'version' => 1, 'distance' => 500],
			['id' => 1, 'version' => 1, 'distance' => 100],
		]);
		$this->assertSame(1, $pick['id']);
	}

	public function testPickClosestCandidateTieBreaksById(): void
	{
		$pick = PlannedTransactionMatchService::pickClosestCandidate([
			['id' => 5, 'version' => 1, 'distance' => 0],
			['id' => 3, 'version' => 1, 'distance' => 0],
		]);
		$this->assertSame(3, $pick['id']);
	}

	public function testPickClosestCandidateEmptyReturnsNull(): void
	{
		$this->assertNull(PlannedTransactionMatchService::pickClosestCandidate([]));
	}
}
