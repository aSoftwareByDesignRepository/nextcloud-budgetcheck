<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

use OCA\BudgetCheck\Exception\ValidationException;
use OCA\BudgetCheck\Exception\WorkspaceTypeMismatchException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Deterministic financial calculations consumed by the dashboard, monthly
 * planner, yearly overview, and project-period overview.
 *
 * Hard rules (§9 of the spec):
 *  - All sums use minor units; nothing here ever sees a float.
 *  - `total_income`, `total_expense`, `net_result`, `available_after_savings`
 *    are computed from live ledger rows, then optionally compared with the
 *    immutable monthly snapshot for evidence.
 *  - Tax-aware workspaces switch budget consumption from gross amounts to net
 *    amounts based on `tax_budget_basis`.
 *
 * The service is the **only** place where these numbers are produced. The
 * SnapshotService consumes this same method to ensure the close evidence
 * matches what the dashboard reports.
 */
class SummaryService
{
	public function __construct(
		private IDBConnection $db,
		private AccessControlService $access,
		private MoneyService $money,
		private BudgetService $budgets,
		private SavingsTargetService $savings,
		private WorkspaceService $workspaces,
		private CategoryService $categories,
		private WarningEngine $warnings,
		private ITimeFactory $timeFactory,
		private TransactionService $transactions,
	) {
	}

	/**
	 * Household monthly summary. Rejects project workspaces with a marker
	 * exception code that the controller maps to HTTP 422 + `NOT_APPLICABLE_FOR_WORKSPACE_TYPE`.
	 */
	public function household(int $workspaceId, string $userId, string $yearMonth): array
	{
		$workspace = $this->workspaces->getForUser($workspaceId, $userId);
		if ($workspace['type'] !== WorkspaceService::TYPE_HOUSEHOLD) {
			throw new WorkspaceTypeMismatchException('household', $workspace['type'], 'monthly_summary');
		}
		$ym = $this->validateYearMonth($yearMonth);
		[$start, $end] = $this->monthBounds($ym);

		$rows = $this->loadTransactionsForRange($workspaceId, $start, $end);
		$uncatIds = $this->categories->internalUncategorizedCategoryIds($workspaceId);
		$figures = $this->aggregateMonth($workspace, $rows, $uncatIds);
		$savingsRow = $this->savings->load($workspaceId, $userId, $ym, $workspace['currencyCode']);
		$plannedMap = $this->budgets->plannedMapForMonth($workspaceId, $ym);
		$consumption = $this->categoryConsumption($workspace, $rows, $plannedMap, $uncatIds);
		$plannedTotalMinor = 0;
		foreach ($plannedMap as $cid => $plannedAmt) {
			$cid = (int)$cid;
			if ($cid > 0 && in_array($cid, $uncatIds, true)) {
				continue;
			}
			$plannedTotalMinor += (int)$plannedAmt;
		}
		$figures['plannedTotalMinor'] = $plannedTotalMinor;
		$savingsTargetMinor = $this->savings->computeTargetValue($savingsRow, $figures['totalIncomeMinor']);
		$availableAfterSavingsMinor = $figures['totalIncomeMinor'] - $figures['totalExpenseMinor'] - $savingsTargetMinor;
		$snapshot = $this->loadSnapshot($workspaceId, $ym);

		$uncategorizedExpenseCount = 0;
		$uncategorizedExpenseMinor = 0;
		foreach ($rows as $row) {
			if ((string)$row['direction'] !== TransactionService::DIRECTION_EXPENSE) {
				continue;
			}
			if (!in_array((int)$row['category_id'], $uncatIds, true)) {
				continue;
			}
			$uncategorizedExpenseCount++;
			$uncategorizedExpenseMinor += (int)$row['amount_minor'];
		}

		$monthlyForWarnings = [
			'totalIncomeMinor' => $figures['totalIncomeMinor'],
			'totalExpenseMinor' => $figures['totalExpenseMinor'],
			'savingsTargetMinor' => $savingsTargetMinor,
			'availableAfterSavingsMinor' => $availableAfterSavingsMinor,
			'uncategorizedExpenseCount' => $uncategorizedExpenseCount,
			'uncategorizedExpenseMinor' => $uncategorizedExpenseMinor,
			'largestSpecialMinor' => $figures['largestSpecialMinor'],
			'largestSpecialTitle' => $figures['largestSpecialTitle'],
			'overspendThresholdMinor' => $workspace['overspendThresholdMinor'],
			'yearMonth' => $ym,
		];
		// Categories never come back null in our model — every row has a
		// category id — but we keep the warning hook in the engine for future
		// soft-deleted-category states.
		$warnings = $this->warnings->household($monthlyForWarnings, $consumption);

		return [
			'workspace' => $workspace,
			'yearMonth' => $ym,
			'ledgerYearMonthSpan' => $this->transactions->ledgerYearMonthBounds($workspaceId),
			'monthLedger' => [
				'transactionCount' => count($rows),
				'hasIncomeOrExpense' => $figures['totalIncomeMinor'] > 0 || $figures['totalExpenseMinor'] > 0,
			],
			'isClosed' => $snapshot !== null,
			'snapshot' => $snapshot,
			'totals' => [
				'income' => $this->money->envelope($figures['totalIncomeMinor'], $workspace['currencyCode']),
				'expense' => $this->money->envelope($figures['totalExpenseMinor'], $workspace['currencyCode']),
				'netResult' => $this->money->envelope($figures['netResultMinor'], $workspace['currencyCode']),
				'savingsTarget' => $this->money->envelope($savingsTargetMinor, $workspace['currencyCode']),
				'availableAfterSavings' => $this->money->envelope($availableAfterSavingsMinor, $workspace['currencyCode']),
				'specialIncome' => $this->money->envelope($figures['specialIncomeMinor'], $workspace['currencyCode']),
				'specialExpense' => $this->money->envelope($figures['specialExpenseMinor'], $workspace['currencyCode']),
				'taxBasis' => $workspace['taxModeEnabled'] ? $workspace['taxBudgetBasis'] : null,
				'tax' => $workspace['taxModeEnabled'] ? [
					'net' => $this->money->envelope($figures['totalNetMinor'], $workspace['currencyCode']),
					'vat' => $this->money->envelope($figures['totalVatMinor'], $workspace['currencyCode']),
					'gross' => $this->money->envelope($figures['totalGrossMinor'], $workspace['currencyCode']),
				] : null,
			],
			'savings' => $savingsRow,
			'budget' => [
				'plannedTotal' => $this->money->envelope($figures['plannedTotalMinor'], $workspace['currencyCode']),
				'actualTotal' => $this->money->envelope($figures['budgetedActualMinor'], $workspace['currencyCode']),
				'remaining' => $this->money->envelope($figures['plannedTotalMinor'] - $figures['budgetedActualMinor'], $workspace['currencyCode']),
				'byCategory' => array_values(array_map(fn ($row) => [
					'categoryId' => $row['categoryId'],
					'name' => $row['name'],
					'planned' => $this->money->envelope($row['plannedMinor'], $workspace['currencyCode']),
					'actual' => $this->money->envelope($row['actualMinor'], $workspace['currencyCode']),
					'remaining' => $this->money->envelope($row['plannedMinor'] - $row['actualMinor'], $workspace['currencyCode']),
				], $consumption)),
			],
			'specials' => array_map(fn ($row) => [
				'id' => $row['id'],
				'title' => $row['title'],
				'date' => $row['date'],
				'amount' => $this->money->envelope($row['amountMinor'], $workspace['currencyCode']),
				'direction' => $row['direction'],
			], $figures['specials']),
			'warnings' => $warnings,
		];
	}

	/**
	 * Project workspace period overview. When `yearMonth` is supplied, returns
	 * the slice for that calendar month clipped to the project window; without
	 * it, returns the full project totals.
	 */
	public function projectPeriod(int $workspaceId, string $userId, ?string $yearMonth = null): array
	{
		$workspace = $this->workspaces->getForUser($workspaceId, $userId);
		if ($workspace['type'] !== WorkspaceService::TYPE_PROJECT) {
			throw new WorkspaceTypeMismatchException('project', $workspace['type'], 'project_period_summary');
		}
			if ($yearMonth !== null && $yearMonth !== '') {
			$ym = $this->validateYearMonth($yearMonth);
			[$monthStart, $monthEnd] = $this->monthBounds($ym);
			$projectStart = $workspace['projectStartDate'] !== null ? new \DateTimeImmutable($workspace['projectStartDate']) : $monthStart;
			$projectEnd = $workspace['projectEndDate'] !== null ? new \DateTimeImmutable($workspace['projectEndDate']) : $monthEnd;
			$start = $monthStart > $projectStart ? $monthStart : $projectStart;
			$end = $monthEnd < $projectEnd ? $monthEnd : $projectEnd;
			if ($end < $start) {
				throw new ValidationException(
					'This calendar month does not overlap the project date window.',
					['yearMonth' => 'Pick a month that intersects the project start and end dates.'],
				);
			}
		} else {
			$ym = null;
			$start = $workspace['projectStartDate'] !== null ? new \DateTimeImmutable($workspace['projectStartDate']) : new \DateTimeImmutable('1970-01-01');
			$end = $workspace['projectEndDate'] !== null ? new \DateTimeImmutable($workspace['projectEndDate']) : $this->timeFactory->getDateTime('now')->setTime(0, 0)->modify('+10 years');
		}
		$uncatIds = $this->categories->internalUncategorizedCategoryIds($workspaceId);
		$rows = $this->loadTransactionsForRange($workspaceId, $start, $end);
		$figures = $this->aggregateMonth($workspace, $rows, $uncatIds);

		$allTimeRows = $this->loadTransactionsForRange(
			$workspaceId,
			$workspace['projectStartDate'] !== null ? new \DateTimeImmutable($workspace['projectStartDate']) : new \DateTimeImmutable('1970-01-01'),
			$workspace['projectEndDate'] !== null ? new \DateTimeImmutable($workspace['projectEndDate']) : $this->timeFactory->getDateTime('now')->setTime(23, 59, 59)
		);
		$allTimeFigures = $this->aggregateMonth($workspace, $allTimeRows, $uncatIds);

		$warnings = $this->warnings->project(
			$allTimeFigures['totalExpenseMinor'],
			$workspace['projectTotalCapMinor']
		);

		return [
			'workspace' => $workspace,
			'yearMonth' => $ym,
			'ledgerYearMonthSpan' => $this->transactions->ledgerYearMonthBounds($workspaceId),
			'monthLedger' => [
				'transactionCount' => count($rows),
				'hasIncomeOrExpense' => $figures['totalIncomeMinor'] > 0 || $figures['totalExpenseMinor'] > 0,
			],
			'window' => [
				'from' => $start->format('Y-m-d'),
				'to' => $end->format('Y-m-d'),
			],
			'totals' => [
				'income' => $this->money->envelope($figures['totalIncomeMinor'], $workspace['currencyCode']),
				'expense' => $this->money->envelope($figures['totalExpenseMinor'], $workspace['currencyCode']),
				'netResult' => $this->money->envelope($figures['netResultMinor'], $workspace['currencyCode']),
				'specialIncome' => $this->money->envelope($figures['specialIncomeMinor'], $workspace['currencyCode']),
				'specialExpense' => $this->money->envelope($figures['specialExpenseMinor'], $workspace['currencyCode']),
				'taxBasis' => $workspace['taxModeEnabled'] ? $workspace['taxBudgetBasis'] : null,
				'tax' => $workspace['taxModeEnabled'] ? [
					'net' => $this->money->envelope($figures['totalNetMinor'], $workspace['currencyCode']),
					'vat' => $this->money->envelope($figures['totalVatMinor'], $workspace['currencyCode']),
					'gross' => $this->money->envelope($figures['totalGrossMinor'], $workspace['currencyCode']),
				] : null,
			],
			'allTime' => [
				'expense' => $this->money->envelope($allTimeFigures['totalExpenseMinor'], $workspace['currencyCode']),
				'income' => $this->money->envelope($allTimeFigures['totalIncomeMinor'], $workspace['currencyCode']),
				'cap' => $workspace['projectTotalCapMinor'] !== null
					? $this->money->envelope($workspace['projectTotalCapMinor'], $workspace['currencyCode'])
					: null,
				'remainingHeadroom' => $workspace['projectTotalCapMinor'] !== null
					? $this->money->envelope($workspace['projectTotalCapMinor'] - $allTimeFigures['totalExpenseMinor'], $workspace['currencyCode'])
					: null,
			],
			'specials' => array_map(fn ($row) => [
				'id' => $row['id'],
				'title' => $row['title'],
				'date' => $row['date'],
				'amount' => $this->money->envelope($row['amountMinor'], $workspace['currencyCode']),
				'direction' => $row['direction'],
			], $figures['specials']),
			'warnings' => $warnings,
		];
	}

	public function yearly(int $workspaceId, string $userId, int $year): array
	{
		$workspace = $this->workspaces->getForUser($workspaceId, $userId);
		if ($workspace['type'] !== WorkspaceService::TYPE_HOUSEHOLD) {
			throw new WorkspaceTypeMismatchException('household', $workspace['type'], 'yearly_summary');
		}
		if ($year < 1900 || $year > 9999) {
			throw new \InvalidArgumentException('year is out of range.');
		}
		$months = [];
		$totalIncome = 0;
		$totalExpense = 0;
		$savingsAchievedTotal = 0;
		$savingsTargetTotal = 0;
		$overBudgetMonths = 0;
		$uncatIds = $this->categories->internalUncategorizedCategoryIds($workspaceId);
		for ($month = 1; $month <= 12; $month++) {
			$ym = sprintf('%04d-%02d', $year, $month);
			[$start, $end] = $this->monthBounds($ym);
			$rows = $this->loadTransactionsForRange($workspaceId, $start, $end);
			$figures = $this->aggregateMonth($workspace, $rows, $uncatIds);
			$savingsRow = $this->savings->load($workspaceId, $userId, $ym, $workspace['currencyCode']);
			$savingsTargetMinor = $this->savings->computeTargetValue($savingsRow, $figures['totalIncomeMinor']);
			$availableAfterSavingsMinor = $figures['totalIncomeMinor'] - $figures['totalExpenseMinor'] - $savingsTargetMinor;
			$plannedMap = $this->budgets->plannedMapForMonth($workspaceId, $ym);
			$consumption = $this->categoryConsumption($workspace, $rows, $plannedMap, $uncatIds);
			$overBudget = false;
			foreach ($consumption as $row) {
				if ($row['plannedMinor'] > 0 && $row['actualMinor'] > $row['plannedMinor']) {
					$overBudget = true;
					break;
				}
			}
			if ($overBudget) {
				$overBudgetMonths++;
			}
			$totalIncome += $figures['totalIncomeMinor'];
			$totalExpense += $figures['totalExpenseMinor'];
			$savingsTargetTotal += $savingsTargetMinor;
			$cashAfterExpenses = $figures['totalIncomeMinor'] - $figures['totalExpenseMinor'];
			$savingsAchievedTotal += min($savingsTargetMinor, max(0, $cashAfterExpenses));
			$months[] = [
				'yearMonth' => $ym,
				'income' => $this->money->envelope($figures['totalIncomeMinor'], $workspace['currencyCode']),
				'expense' => $this->money->envelope($figures['totalExpenseMinor'], $workspace['currencyCode']),
				'netResult' => $this->money->envelope($figures['netResultMinor'], $workspace['currencyCode']),
				'savingsTarget' => $this->money->envelope($savingsTargetMinor, $workspace['currencyCode']),
				'availableAfterSavings' => $this->money->envelope($availableAfterSavingsMinor, $workspace['currencyCode']),
				'overBudget' => $overBudget,
				'isClosed' => $this->loadSnapshot($workspaceId, $ym) !== null,
			];
		}
		$achievementRatio = $savingsTargetTotal > 0 ? min(1.0, $savingsAchievedTotal / $savingsTargetTotal) : null;
		return [
			'workspace' => $workspace,
			'year' => $year,
			'totals' => [
				'income' => $this->money->envelope($totalIncome, $workspace['currencyCode']),
				'expense' => $this->money->envelope($totalExpense, $workspace['currencyCode']),
				'netResult' => $this->money->envelope($totalIncome - $totalExpense, $workspace['currencyCode']),
				'savingsTarget' => $this->money->envelope($savingsTargetTotal, $workspace['currencyCode']),
				'savingsAchieved' => $this->money->envelope($savingsAchievedTotal, $workspace['currencyCode']),
				'savingsAchievementRatio' => $achievementRatio,
				'overBudgetMonths' => $overBudgetMonths,
			],
			'months' => $months,
		];
	}

	/**
	 * Aggregate a list of transaction rows into the canonical figures bag used
	 * by every monthly/period summary.
	 *
	 * @param list<int> $uncategorizedCategoryIds expense categories excluded from budget consumption (§9)
	 * @return array{
	 *     totalIncomeMinor:int,
	 *     totalExpenseMinor:int,
	 *     netResultMinor:int,
	 *     specialIncomeMinor:int,
	 *     specialExpenseMinor:int,
	 *     largestSpecialMinor:int,
	 *     largestSpecialTitle:string,
	 *     budgetedActualMinor:int,
	 *     plannedTotalMinor:int,
	 *     totalNetMinor:int,
	 *     totalVatMinor:int,
	 *     totalGrossMinor:int,
	 *     specials:list<array<string,mixed>>
	 * }
	 */
	private function aggregateMonth(array $workspace, array $rows, array $uncategorizedCategoryIds = []): array
	{
		$uncategorizedCategoryIds = array_values(array_unique(array_map(static fn (int $id): int => $id, $uncategorizedCategoryIds)));
		$totalIncome = 0;
		$totalExpense = 0;
		$specialIncome = 0;
		$specialExpense = 0;
		$largestSpecialMinor = 0;
		$largestSpecialTitle = '';
		$budgetedActual = 0;
		$totalNet = 0;
		$totalVat = 0;
		$totalGross = 0;
		$specials = [];
		$basis = $workspace['taxBudgetBasis'] ?? 'gross';
		$taxModeEnabled = (bool)($workspace['taxModeEnabled'] ?? false);

		foreach ($rows as $row) {
			$amountForBudget = (int)$row['amount_minor'];
			if ($taxModeEnabled && (string)$row['entry_amount_basis'] !== TransactionService::BASIS_SIMPLE) {
				if ($basis === 'net' && $row['net_amount_minor'] !== null) {
					$amountForBudget = (int)$row['net_amount_minor'];
				} elseif ($basis === 'gross' && $row['gross_amount_minor'] !== null) {
					$amountForBudget = (int)$row['gross_amount_minor'];
				}
				$totalNet += (int)($row['net_amount_minor'] ?? $row['amount_minor']);
				$totalVat += (int)($row['vat_amount_minor'] ?? 0);
				$totalGross += (int)($row['gross_amount_minor'] ?? $row['amount_minor']);
			} else {
				$totalNet += (int)$row['amount_minor'];
				$totalGross += (int)$row['amount_minor'];
			}
			if ((string)$row['direction'] === TransactionService::DIRECTION_INCOME) {
				$totalIncome += (int)$row['amount_minor'];
				if ((bool)$row['is_special']) {
					$specialIncome += (int)$row['amount_minor'];
				}
			} else {
				$totalExpense += (int)$row['amount_minor'];
				if ((bool)$row['is_special']) {
					$specialExpense += (int)$row['amount_minor'];
				}
				if (!in_array((int)$row['category_id'], $uncategorizedCategoryIds, true)) {
					$budgetedActual += $amountForBudget;
				}
			}
			if ((bool)$row['is_special']) {
				if (
					(string)$row['direction'] === TransactionService::DIRECTION_EXPENSE
					&& (int)$row['amount_minor'] > $largestSpecialMinor
				) {
					$largestSpecialMinor = (int)$row['amount_minor'];
					$largestSpecialTitle = (string)$row['title'];
				}
				$specials[] = [
					'id' => (int)$row['id'],
					'title' => (string)$row['title'],
					'date' => (string)$row['booking_date'],
					'amountMinor' => (int)$row['amount_minor'],
					'direction' => (string)$row['direction'],
				];
			}
		}
		return [
			'totalIncomeMinor' => $totalIncome,
			'totalExpenseMinor' => $totalExpense,
			'netResultMinor' => $totalIncome - $totalExpense,
			'specialIncomeMinor' => $specialIncome,
			'specialExpenseMinor' => $specialExpense,
			'largestSpecialMinor' => $largestSpecialMinor,
			'largestSpecialTitle' => $largestSpecialTitle,
			'budgetedActualMinor' => $budgetedActual,
			'plannedTotalMinor' => 0, // filled by category consumption helper
			'totalNetMinor' => $taxModeEnabled ? $totalNet : 0,
			'totalVatMinor' => $taxModeEnabled ? $totalVat : 0,
			'totalGrossMinor' => $taxModeEnabled ? $totalGross : 0,
			'specials' => $specials,
		];
	}

	/**
	 * @param list<int> $uncategorizedCategoryIds excluded from planned-vs-actual rows (§9)
	 * @return list<array{categoryId:int, name:string, plannedMinor:int, actualMinor:int}>
	 */
	private function categoryConsumption(array $workspace, array $rows, array $plannedMap, array $uncategorizedCategoryIds = []): array
	{
		// Build a categoryId -> name map for any row that references one.
		$categoryIds = [];
		foreach ($rows as $row) {
			$cid = (int)$row['category_id'];
			if (in_array($cid, $uncategorizedCategoryIds, true)) {
				continue;
			}
			$categoryIds[$cid] = true;
		}
		foreach ($plannedMap as $cid => $_) {
			$cid = (int)$cid;
			if ($cid > 0 && !in_array($cid, $uncategorizedCategoryIds, true)) {
				$categoryIds[$cid] = true;
			}
		}
		$names = $this->categoryNamesById(array_keys($categoryIds));

		// Compute actual per category. Only expense rows count toward category
		// budget consumption — the reference workbook plans expenses, not income.
		$actuals = [];
		$basis = $workspace['taxBudgetBasis'] ?? 'gross';
		$taxModeEnabled = (bool)($workspace['taxModeEnabled'] ?? false);
		foreach ($rows as $row) {
			if ((string)$row['direction'] !== TransactionService::DIRECTION_EXPENSE) {
				continue;
			}
			$cid = (int)$row['category_id'];
			if (in_array($cid, $uncategorizedCategoryIds, true)) {
				continue;
			}
			$amount = (int)$row['amount_minor'];
			if ($taxModeEnabled && (string)$row['entry_amount_basis'] !== TransactionService::BASIS_SIMPLE) {
				if ($basis === 'net' && $row['net_amount_minor'] !== null) {
					$amount = (int)$row['net_amount_minor'];
				} elseif ($basis === 'gross' && $row['gross_amount_minor'] !== null) {
					$amount = (int)$row['gross_amount_minor'];
				}
			}
			$actuals[$cid] = ($actuals[$cid] ?? 0) + $amount;
		}

		$out = [];
		foreach ($categoryIds as $cid => $_) {
			if ($cid <= 0) {
				continue;
			}
			$out[] = [
				'categoryId' => $cid,
				'name' => $names[$cid] ?? '#' . $cid,
				'plannedMinor' => (int)($plannedMap[$cid] ?? 0),
				'actualMinor' => (int)($actuals[$cid] ?? 0),
			];
		}
		usort($out, static function (array $a, array $b): int {
			return strcmp($a['name'], $b['name']);
		});
		return $out;
	}

	/**
	 * @param list<int> $ids
	 * @return array<int,string>
	 */
	private function categoryNamesById(array $ids): array
	{
		if ($ids === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'name')
			->from('bc_categories')
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$out[(int)$row['id']] = (string)$row['name'];
		}
		$result->closeCursor();
		return $out;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function loadTransactionsForRange(int $workspaceId, \DateTimeImmutable $start, \DateTimeImmutable $end): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('bc_transactions')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->andWhere($qb->expr()->gte('booking_date', $qb->createNamedParameter($start->format('Y-m-d'))))
			->andWhere($qb->expr()->lte('booking_date', $qb->createNamedParameter($end->format('Y-m-d'))));
		$result = $qb->executeQuery();
		$rows = [];
		while ($row = $result->fetch()) {
			$rows[] = $row;
		}
		$result->closeCursor();
		return $rows;
	}

	private function loadSnapshot(int $workspaceId, string $yearMonth): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('bc_monthly_snapshots')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('year_month', $qb->createNamedParameter($yearMonth)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if ($row === false) {
			return null;
		}
		return [
			'id' => (int)$row['id'],
			'workspaceId' => (int)$row['workspace_id'],
			'yearMonth' => (string)$row['year_month'],
			'calcHash' => (string)$row['calc_hash'],
			'generatedBy' => (string)$row['generated_by'],
			'generatedAt' => (string)$row['generated_at'],
		];
	}

	/**
	 * @return array{0:\DateTimeImmutable, 1:\DateTimeImmutable}
	 */
	private function monthBounds(string $yearMonth): array
	{
		$start = new \DateTimeImmutable($yearMonth . '-01');
		$end = $start->modify('last day of this month');
		return [$start, $end];
	}

	private function validateYearMonth(string $value): string
	{
		$value = trim($value);
		if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
			throw new \InvalidArgumentException('yearMonth must be in YYYY-MM format with a valid month.');
		}
		return $value;
	}

}
