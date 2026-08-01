<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

/**
 * Pure Home KPI key selection for the free mobile companion.
 * Never blends household + project totals (web §6.0 / COMPANION-APP D4).
 */
final class MobileHomeKpi
{
	public const KEY_AVAILABLE_AFTER_SAVINGS = 'available_after_savings';
	public const KEY_SPEND_VS_CAP = 'spend_vs_cap';
	public const KEY_SPEND_TO_DATE = 'spend_to_date';

	/**
	 * @param 'household'|'project'|string $workspaceType
	 */
	public static function dominantKey(string $workspaceType, bool $hasProjectCap): string
	{
		if ($workspaceType === WorkspaceService::TYPE_HOUSEHOLD || $workspaceType === 'household') {
			return self::KEY_AVAILABLE_AFTER_SAVINGS;
		}
		return $hasProjectCap ? self::KEY_SPEND_VS_CAP : self::KEY_SPEND_TO_DATE;
	}
}
