<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

use OCA\BudgetCheck\Exception\WorkspaceTypeMismatchException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Analytics insights (spec: analytics-insights): category trend, top payees,
 * month-end forecast.
 *
 * Every method is read-only, uses exactly one indexed range/grouped scan on
 * bc_transactions (NN-04), and resolves amounts with the identical
 * tax-basis/specials semantics as SummaryService so surfaces cannot drift.
 */
class AnalyticsService
{
	public const MIN_MONTHS = 6;
	public const MAX_MONTHS = 24;
	public const MAX_PAYEE_LIMIT = 100;
	public const FORECAST_METHOD = 'linear_mtd_plus_scheduled';

	public function __construct(
		private IDBConnection $db,
		private AccessControlService $access,
		private MoneyService $money,
		private SummaryService $summaries,
		private WorkspaceService $workspaces,
		private CategoryService $categories,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * Effective transaction amount under the workspace's budget semantics —
	 * the same resolution {@see SummaryService::categoryConsumption} applies
	 * (tax-basis net/gross when tax mode is on and split fields exist).
	 */
	public static function effectiveAmountMinor(array $workspace, array $row): int
	{
		$amount = (int)$row['amount_minor'];
		if (!empty($workspace['taxModeEnabled'])
			&& (string)($row['entry_amount_basis'] ?? '') !== TransactionService::BASIS_SIMPLE) {
			$basis = (string)($workspace['taxBudgetBasis'] ?? 'gross');
			if ($basis === 'net' && $row['net_amount_minor'] !== null) {
				$amount = (int)$row['net_amount_minor'];
			} elseif ($basis === 'gross' && $row['gross_amount_minor'] !== null) {
				$amount = (int)$row['gross_amount_minor'];
			}
		}
		return $amount;
	}

	/**
	 * Per-month series for one category over the last N months (bounded 6–24).
	 * Future months never appear — series ends at the current month.
	 */
	public function categoryTrend(int $workspaceId, string $userId, int $categoryId, int $months, string $direction = 'expense'): array
	{
		$workspace = $this->workspaces->getForUser($workspaceId, $userId);
		if ($months < self::MIN_MONTHS || $months > self::MAX_MONTHS) {
			throw new \InvalidArgumentException('months must be between 6 and 24.');
		}
		if (!in_array($direction, ['income', 'expense'], true)) {
			throw new \InvalidArgumentException('direction must be income or expense.');
		}
		$category = $this->categories->loadForWorkspace($categoryId, $workspaceId);
		if ($category === null) {
			throw new \InvalidArgumentException('categoryId is required.');
		}
		$now = \DateTimeImmutable::createFromInterface($this->timeFactory->getDateTime());
		$currentYm = $now->format('Y-m');
		$first = (new \DateTimeImmutable($currentYm . '-01'))->modify('-' . ($months - 1) . ' months');
		$rows = $this->summaries->loadTransactionsForRange($workspaceId, $first, $now);
		$uncatIds = $this->categories->internalUncategorizedCategoryIds($workspaceId);
		$currency = (string)$workspace['currencyCode'];

		$perMonth = [];
		foreach ($rows as $row) {
			if (!empty($row['is_planned']) || (int)$row['category_id'] !== $categoryId) {
				continue;
			}
			if ((string)$row['direction'] !== $direction) {
				continue;
			}
			$ym = substr((string)$row['booking_date'], 0, 7);
			if ($ym > $currentYm) {
				continue;
			}
			$amount = self::effectiveAmountMinor($workspace, $row);
			$bucket = &$perMonth[$ym];
			$bucket ??= ['actual' => 0, 'specials' => 0];
			if ((bool)$row['is_special']) {
				$bucket['specials'] += $amount;
			} else {
				$bucket['actual'] += $amount;
			}
		}

		$series = [];
		$cursor = $first;
		while ($cursor->format('Y-m') <= $currentYm) {
			$ym = $cursor->format('Y-m');
			$b = $perMonth[$ym] ?? ['actual' => 0, 'specials' => 0];
			$series[] = [
				'yearMonth' => $ym,
				'actual' => $this->money->envelope($b['actual'], $currency),
				'specials' => $this->money->envelope($b['specials'], $currency),
			];
			$cursor = $cursor->modify('first day of next month');
		}

		return [
			'categoryId' => $categoryId,
			'categoryName' => (string)($category['name'] ?? ''),
			'direction' => $direction,
			'months' => $months,
			'series' => $series,
			'isUncategorized' => in_array($categoryId, $uncatIds, true),
		];
	}

	/**
	 * Top-N payees by spend for a period — one grouped server-side query on
	 * the payee_id index; the NULL bucket is labelled "(No payee)".
	 *
	 * @param string $period month|year|trailing12
	 */
	public function topPayees(int $workspaceId, string $userId, string $period, string $direction, int $limit): array
	{
		$workspace = $this->workspaces->getForUser($workspaceId, $userId);
		if (!in_array($period, ['month', 'year', 'trailing12'], true)) {
			throw new \InvalidArgumentException('period must be one of: month, year, trailing12.');
		}
		if (!in_array($direction, ['income', 'expense'], true)) {
			throw new \InvalidArgumentException('direction must be income or expense.');
		}
		$limit = max(1, min(self::MAX_PAYEE_LIMIT, $limit));
		$now = $this->timeFactory->getDateTime();
		[$from, $to] = match ($period) {
			'month' => [$now->format('Y-m-01'), $now->format('Y-m-t')],
			'year' => [$now->format('Y-01-01'), $now->format('Y-12-31')],
			default => [$now->modify('-11 months')->format('Y-m-01'), $now->format('Y-m-t')],
		};

		$qb = $this->db->getQueryBuilder();
		$qb->select('t.payee_id')
			->selectAlias('p.name', 'payee_name')
			->selectAlias($qb->createFunction('SUM(t.amount_minor)'), 'total_minor')
			->selectAlias($qb->createFunction('COUNT(*)'), 'entry_count')
			->from('bc_transactions', 't')
			->leftJoin('t', 'bc_payees', 'p', $qb->expr()->eq('t.payee_id', 'p.id'))
			->where($qb->expr()->eq('t.workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('t.deleted_at'))
			->andWhere($qb->expr()->eq('t.is_planned', $qb->createNamedParameter(false, \PDO::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('t.direction', $qb->createNamedParameter($direction)))
			->andWhere($qb->expr()->gte('t.booking_date', $qb->createNamedParameter($from)))
			->andWhere($qb->expr()->lte('t.booking_date', $qb->createNamedParameter($to)))
			->groupBy('t.payee_id', 'p.name')
			->orderBy('total_minor', 'DESC')
			->setMaxResults($limit + 1);
		$result = $qb->executeQuery();
		$groups = [];
		$grandTotal = 0;
		while ($row = $result->fetch()) {
			$groups[] = $row;
			$grandTotal += (int)$row['total_minor'];
		}
		$result->closeCursor();

		$currency = (string)$workspace['currencyCode'];
		$payees = [];
		foreach (array_slice($groups, 0, $limit) as $row) {
			$total = (int)$row['total_minor'];
			$payees[] = [
				'payeeId' => $row['payee_id'] !== null ? (int)$row['payee_id'] : null,
				'name' => $row['payee_id'] !== null ? (string)($row['payee_name'] ?? '') : '(No payee)',
				'total' => $this->money->envelope($total, $currency),
				'shareRatio' => $grandTotal > 0 ? round($total / $grandTotal, 4) : 0.0,
				'entryCount' => (int)$row['entry_count'],
			];
		}
		$remainderMinor = 0;
		foreach (array_slice($groups, $limit) as $row) {
			$remainderMinor += (int)$row['total_minor'];
		}
		return [
			'payees' => $payees,
			'period' => $period,
			'direction' => $direction,
			'window' => ['from' => $from, 'to' => $to],
			'remainder' => $this->money->envelope($remainderMinor, $currency),
			'grandTotal' => $this->money->envelope($grandTotal, $currency),
		];
	}

	/**
	 * Honest month-end projection: linear MTD extrapolation plus scheduled
	 * recurring remainder. The method string ships in the payload (NN-01 —
	 * a projection with no provenance is forbidden).
	 */
	public function monthForecast(int $workspaceId, string $userId): array
	{
		$workspace = $this->workspaces->getForUser($workspaceId, $userId);
		if (($workspace['type'] ?? '') !== WorkspaceService::TYPE_HOUSEHOLD) {
			throw new WorkspaceTypeMismatchException('household', (string)$workspace['type'], 'month_forecast');
		}
		$now = $this->timeFactory->getDateTime();
		$ym = $now->format('Y-m');
		[$start, $end] = $this->summaries->monthBounds($ym);
		$elapsedDays = (int)$now->format('j');
		$daysInMonth = (int)$end->format('t');
		$rows = $this->summaries->loadTransactionsForRange($workspaceId, $start, $end);

		$mtdIncome = 0;
		$mtdExpense = 0;
		$specialExpense = 0;
		foreach ($rows as $row) {
			if (!empty($row['is_planned']) || (string)$row['booking_date'] > $now->format('Y-m-d')) {
				continue;
			}
			$amount = self::effectiveAmountMinor($workspace, $row);
			if ((string)$row['direction'] === TransactionService::DIRECTION_INCOME) {
				$mtdIncome += $amount;
			} else {
				if ((bool)$row['is_special']) {
					$specialExpense += $amount;
					continue; // specials never feed the linear projection
				}
				$mtdExpense += $amount;
			}
		}

		// Scheduled recurring expense/income still due in the remaining days.
		[$schedExpense, $schedIncome] = $this->scheduledRemainder($workspaceId, $now, $end);

		$projectedExpense = (int)round($mtdExpense / max(1, $elapsedDays) * $daysInMonth) + $schedExpense;
		$projectedIncome = (int)round($mtdIncome / max(1, $elapsedDays) * $daysInMonth) + $schedIncome;
		$dataSufficient = $elapsedDays >= 3 && ($mtdIncome + $mtdExpense) > 0;

		$plannedMap = $this->plannedTotalForMonth($workspaceId, $ym);
		$projectedConsumption = $plannedMap > 0 ? round($projectedExpense / $plannedMap, 4) : null;

		$currency = (string)$workspace['currencyCode'];
		return [
			'yearMonth' => $ym,
			'elapsedDays' => $elapsedDays,
			'daysInMonth' => $daysInMonth,
			'mtd' => [
				'income' => $this->money->envelope($mtdIncome, $currency),
				'expense' => $this->money->envelope($mtdExpense, $currency),
				'specialExpense' => $this->money->envelope($specialExpense, $currency),
			],
			'scheduledRemainder' => [
				'expense' => $this->money->envelope($schedExpense, $currency),
				'income' => $this->money->envelope($schedIncome, $currency),
			],
			'projected' => [
				'expense' => $this->money->envelope($projectedExpense, $currency),
				'income' => $this->money->envelope($projectedIncome, $currency),
				'netResult' => $this->money->envelope($projectedIncome - $projectedExpense, $currency),
			],
			'budget' => [
				'plannedTotal' => $this->money->envelope($plannedMap, $currency),
				'actualTotal' => $this->money->envelope($mtdExpense, $currency),
				'projectedConsumptionRatio' => $projectedConsumption,
			],
			'method' => self::FORECAST_METHOD,
			'dataSufficient' => $dataSufficient,
		];
	}

	/**
	 * Sum of active book/plan recurring rules due strictly after today inside
	 * the month — the "known remainder" half of the forecast formula.
	 *
	 * @return array{0:int,1:int} [expenseMinor, incomeMinor]
	 */
	private function scheduledRemainder(int $workspaceId, \DateTimeInterface $now, \DateTimeImmutable $monthEnd): array
	{
		$today = $now->format('Y-m-d');
		$end = $monthEnd->format('Y-m-d');
		$qb = $this->db->getQueryBuilder();
		$qb->select('amount_minor', 'direction', 'next_due_date', 'schedule_json', 'frequency', 'interval_count')
			->from('bc_recurring_rules')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
			->andWhere($qb->expr()->gt('next_due_date', $qb->createNamedParameter($today)))
			->andWhere($qb->expr()->lte('next_due_date', $qb->createNamedParameter($end)));
		$result = $qb->executeQuery();
		$expense = 0;
		$income = 0;
		while ($row = $result->fetch()) {
			$schedule = RecurringRuleService::scheduleForRow($row);
			$amount = RecurringRuleService::occurrenceAmountMinor(
				$schedule,
				(string)$row['next_due_date'],
				(int)$row['amount_minor'],
			);
			if ((string)$row['direction'] === TransactionService::DIRECTION_INCOME) {
				$income += $amount;
			} else {
				$expense += $amount;
			}
		}
		$result->closeCursor();
		return [$expense, $income];
	}

	private function plannedTotalForMonth(int $workspaceId, string $ym): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('SUM(planned_minor)'), 's')
			->from('bc_budgets')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('year_month', $qb->createNamedParameter($ym)));
		$row = $qb->executeQuery()->fetch();
		return (int)($row['s'] ?? 0);
	}
}
