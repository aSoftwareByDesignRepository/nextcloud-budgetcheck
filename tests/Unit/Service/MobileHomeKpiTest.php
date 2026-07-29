<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\MobileHomeKpi;
use OCA\BudgetCheck\Service\WorkspaceService;
use PHPUnit\Framework\TestCase;

final class MobileHomeKpiTest extends TestCase
{
	public function testHouseholdNeverUsesProjectKeys(): void
	{
		self::assertSame(
			MobileHomeKpi::KEY_AVAILABLE_AFTER_SAVINGS,
			MobileHomeKpi::dominantKey(WorkspaceService::TYPE_HOUSEHOLD, false)
		);
		self::assertSame(
			MobileHomeKpi::KEY_AVAILABLE_AFTER_SAVINGS,
			MobileHomeKpi::dominantKey(WorkspaceService::TYPE_HOUSEHOLD, true)
		);
	}

	public function testProjectUsesCapWhenPresent(): void
	{
		self::assertSame(
			MobileHomeKpi::KEY_SPEND_VS_CAP,
			MobileHomeKpi::dominantKey(WorkspaceService::TYPE_PROJECT, true)
		);
	}

	public function testProjectWithoutCapUsesSpendToDate(): void
	{
		self::assertSame(
			MobileHomeKpi::KEY_SPEND_TO_DATE,
			MobileHomeKpi::dominantKey(WorkspaceService::TYPE_PROJECT, false)
		);
	}

	public function testHouseholdAndProjectKeysNeverEqual(): void
	{
		$household = MobileHomeKpi::dominantKey('household', true);
		$project = MobileHomeKpi::dominantKey('project', true);
		self::assertNotSame($household, $project);
	}
}
