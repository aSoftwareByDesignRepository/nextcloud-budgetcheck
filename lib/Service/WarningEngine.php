<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

/**
 * Pure-domain warning generator.
 *
 * Each warning has:
 *  - `code`        machine-readable identifier for tests + tracking
 *  - `severity`    `info` | `warning` | `critical`
 *  - `title`       short user-facing label (translated by the controller)
 *  - `message`     plain-language explanation
 *  - `recovery`    suggested next-screen + filter so the UI can render an
 *                  affordance that actually fixes the problem (§6.4)
 *  - `meta`        extra structured context (e.g. categoryId, ratio)
 *
 * Strings are deliberately English here. The controller wraps the entire
 * payload through `IL10N::t()` before serving it to the page so we keep
 * domain code free of presentation locale concerns.
 */
class WarningEngine
{
	public const SEV_INFO = 'info';
	public const SEV_WARNING = 'warning';
	public const SEV_CRITICAL = 'critical';

	public function __construct(private MoneyService $money)
	{
	}

	/**
	 * @param list<array{categoryId:int, plannedMinor:int, actualMinor:int, name:string}> $byCategory
	 *        Per-category consumption rows. The categoryId lives on the row
	 *        itself so the engine never mistakes a list index for an entity id.
	 * @param array{
	 *     totalIncomeMinor:int,
	 *     totalExpenseMinor:int,
	 *     savingsTargetMinor:int,
	 *     availableAfterSavingsMinor:int,
	 *     uncategorizedExpenseCount:int,
	 *     uncategorizedExpenseMinor:int,
	 *     largestSpecialMinor:int,
	 *     largestSpecialTitle:string,
	 *     overspendThresholdMinor:?int,
	 *     yearMonth:string,
	 * } $monthly
	 *
	 * @return list<array<string,mixed>>
	 */
	public function household(array $monthly, array $byCategory): array
	{
		$out = [];
		foreach ($byCategory as $row) {
			if (($row['plannedMinor'] ?? 0) <= 0) {
				continue;
			}
			$direction = (string)($row['direction'] ?? '');
			if ($direction === CategoryService::TYPE_INCOME) {
				// Income targets use the same planned/actual fields but exceeding the
				// target is desirable — never emit expense-style overspend warnings.
				continue;
			}
			$categoryId = (int)($row['categoryId'] ?? 0);
			$planned = (int)$row['plannedMinor'];
			$actual = (int)($row['actualMinor'] ?? 0);
			$ratio = $actual / $planned;
			if ($actual > $planned) {
				$out[] = [
					'code' => 'budget_overspent',
					'severity' => self::SEV_WARNING,
					'title' => 'Over budget',
					'message' => sprintf(
						'%s spent %.0f%% of its monthly budget.',
						$row['name'] ?? '#' . $categoryId,
						$ratio * 100
					),
					'recovery' => [
						'screen' => 'budgets',
						'params' => ['yearMonth' => $monthly['yearMonth']],
					],
					'meta' => [
						'categoryId' => $categoryId,
						'categoryName' => $row['name'] ?? ('#' . $categoryId),
						'plannedMinor' => $planned,
						'actualMinor' => $actual,
					],
				];
			} elseif ($ratio >= 0.9) {
				$out[] = [
					'code' => 'budget_near_limit',
					'severity' => self::SEV_INFO,
					'title' => 'Near budget limit',
					'message' => sprintf(
						'%s has reached %.0f%% of its monthly budget.',
						$row['name'] ?? '#' . $categoryId,
						$ratio * 100
					),
					'recovery' => [
						'screen' => 'budgets',
						'params' => ['yearMonth' => $monthly['yearMonth']],
					],
					'meta' => [
						'categoryId' => $categoryId,
						'categoryName' => $row['name'] ?? ('#' . $categoryId),
						'plannedMinor' => $planned,
						'actualMinor' => $actual,
					],
				];
			}
		}

		if (($monthly['uncategorizedExpenseCount'] ?? 0) > 0) {
			$out[] = [
				'code' => 'uncategorized_expense',
				'severity' => self::SEV_WARNING,
				'title' => 'Uncategorized expenses',
				'message' => sprintf(
					'%d expense %s without a category. They count toward the total but not toward any budget.',
					(int)$monthly['uncategorizedExpenseCount'],
					$monthly['uncategorizedExpenseCount'] === 1 ? 'remains' : 'remain'
				),
				'recovery' => [
					'screen' => 'transactions',
					'params' => ['filter' => 'uncategorized', 'yearMonth' => $monthly['yearMonth']],
				],
				'meta' => [
					'count' => (int)$monthly['uncategorizedExpenseCount'],
					'amountMinor' => (int)$monthly['uncategorizedExpenseMinor'],
				],
			];
		}

		if (($monthly['availableAfterSavingsMinor'] ?? 0) < 0) {
			$out[] = [
				'code' => 'available_after_savings_negative',
				'severity' => self::SEV_CRITICAL,
				'title' => 'Available after savings is negative',
				'message' => 'Income minus expense minus savings target is below zero this month.',
				'recovery' => [
					'screen' => 'monthly',
					'params' => ['yearMonth' => $monthly['yearMonth']],
				],
				'meta' => [
					'availableAfterSavingsMinor' => (int)$monthly['availableAfterSavingsMinor'],
				],
			];
		}

		$threshold = $monthly['overspendThresholdMinor'] ?? null;
		if ($threshold !== null && ($monthly['largestSpecialMinor'] ?? 0) >= $threshold) {
			$out[] = [
				'code' => 'large_special_expense',
				'severity' => self::SEV_INFO,
				'title' => 'Large special expense',
				'message' => sprintf(
					'%s exceeds the configured large-expense threshold.',
					$monthly['largestSpecialTitle'] ?? 'A special transaction'
				),
				'recovery' => [
					'screen' => 'transactions',
					'params' => ['filter' => 'special', 'yearMonth' => $monthly['yearMonth']],
				],
				'meta' => [
					'amountMinor' => (int)$monthly['largestSpecialMinor'],
					'specialTitle' => (string)($monthly['largestSpecialTitle'] ?? ''),
				],
			];
		}

		return $out;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function project(int $allTimeSpendMinor, ?int $capMinor): array
	{
		$out = [];
		if ($capMinor === null || $capMinor <= 0) {
			return $out;
		}
		$ratio = $allTimeSpendMinor / $capMinor;
		if ($allTimeSpendMinor > $capMinor) {
			$out[] = [
				'code' => 'project_cap_exceeded',
				'severity' => self::SEV_CRITICAL,
				'title' => 'Project cap exceeded',
				'message' => sprintf(
					'All-time project spend is %.0f%% of the cap.',
					$ratio * 100
				),
				'recovery' => [
					'screen' => 'period',
					'params' => [],
				],
				'meta' => [
					'allTimeSpendMinor' => $allTimeSpendMinor,
					'capMinor' => $capMinor,
				],
			];
		} elseif ($ratio >= 0.9) {
			$out[] = [
				'code' => 'project_cap_near',
				'severity' => self::SEV_WARNING,
				'title' => 'Approaching project cap',
				'message' => sprintf(
					'Project spend has reached %.0f%% of the cap.',
					$ratio * 100
				),
				'recovery' => [
					'screen' => 'period',
					'params' => [],
				],
				'meta' => [
					'allTimeSpendMinor' => $allTimeSpendMinor,
					'capMinor' => $capMinor,
					'ratio' => $ratio,
				],
			];
		}
		return $out;
	}
}
