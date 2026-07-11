<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

use OCA\BudgetCheck\Exception\AccessDeniedException;
use OCA\BudgetCheck\Exception\WorkspaceTypeMismatchException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Materialise category budget targets as planned ledger rows for a month.
 *
 * Each synced row links to a {@see bc_budgets} record via `budget_id`. A real
 * booking in the same category removes the placeholder (see
 * {@see PlannedTransactionMatchService}).
 */
final class BudgetPlannedService
{
	public function __construct(
		private IDBConnection $db,
		private AccessControlService $access,
		private BudgetService $budgets,
		private CategoryService $categories,
		private TransactionService $transactions,
		private SnapshotService $snapshots,
		private AuditLogService $audit,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * @return array{created:int, updated:int, removed:int, skipped:int}
	 */
	public function syncMonth(int $workspaceId, string $userId, string $yearMonth, array $workspace): array
	{
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_MANAGER);
		if (($workspace['type'] ?? null) !== WorkspaceService::TYPE_HOUSEHOLD) {
			throw new WorkspaceTypeMismatchException('household', (string)($workspace['type'] ?? ''), 'budget_planned_sync');
		}
		$ym = $this->validateYearMonth($yearMonth);
		if ($this->snapshots->isMonthClosed($workspaceId, $ym)) {
			throw new \InvalidArgumentException('Month is closed. Reopen it before generating planned entries.');
		}

		$plannedMap = $this->budgets->plannedMapForMonth($workspaceId, $ym);
		$categoryList = $this->categories->listForWorkspace($workspaceId, $userId, false);
		$categoriesById = [];
		foreach ($categoryList as $cat) {
			$categoriesById[(int)$cat['id']] = $cat;
		}
		$uncatId = $this->categories->internalUncategorizedCategoryId($workspaceId);

		$stats = ['created' => 0, 'updated' => 0, 'removed' => 0, 'skipped' => 0];

		foreach ($plannedMap as $categoryKey => $plannedMinor) {
			$categoryId = (int)$categoryKey;
			if ($categoryId < 1) {
				continue;
			}
			if ($uncatId !== null && $categoryId === $uncatId) {
				continue;
			}
			$cat = $categoriesById[$categoryId] ?? null;
			if ($cat === null || empty($cat['isActive'])) {
				continue;
			}
			if (!empty($cat['isSavingsTransfer'])) {
				continue;
			}
			$direction = (string)($cat['type'] ?? '');
			if ($direction !== CategoryService::TYPE_INCOME && $direction !== CategoryService::TYPE_EXPENSE) {
				continue;
			}

			$plannedMinor = max(0, (int)$plannedMinor);
			$budgetRow = $this->ensureBudgetRow($workspaceId, $userId, $ym, $categoryId, $plannedMinor);
			$budgetId = (int)$budgetRow['id'];

			$existing = $this->loadPlannedForBudget($workspaceId, $budgetId);

			if ($plannedMinor === 0) {
				if ($existing !== null) {
					$this->softDeletePlanned($existing, $userId, $workspaceId);
					$stats['removed']++;
				}
				continue;
			}

			if ($this->hasRealBookingInMonth($workspaceId, $categoryId, $direction, $ym)) {
				if ($existing !== null) {
					$this->softDeletePlanned($existing, $userId, $workspaceId);
					$stats['removed']++;
				} else {
					$stats['skipped']++;
				}
				continue;
			}

			if ($this->hasRecurringPlannedInMonth($workspaceId, $categoryId, $direction, $ym)) {
				$stats['skipped']++;
				continue;
			}

			$bookingDate = $ym . '-01';
			$title = (string)$cat['name'];

			if ($existing === null) {
				$this->transactions->create($workspaceId, $userId, [
					'categoryId' => $categoryId,
					'direction' => $direction,
					'bookingDate' => $bookingDate,
					'amountMinor' => $plannedMinor,
					'title' => $title,
					'isSpecial' => false,
					'isPlanned' => true,
					'budgetId' => $budgetId,
				], $workspace, $cat);
				$stats['created']++;
				continue;
			}

			$needsUpdate = (int)$existing['amount_minor'] !== $plannedMinor
				|| (string)$existing['booking_date'] !== $bookingDate
				|| (int)$existing['category_id'] !== $categoryId
				|| (string)$existing['direction'] !== $direction;
			if ($needsUpdate) {
				$this->updatePlannedRow($existing, $userId, $workspace, [
					'amount_minor' => $plannedMinor,
					'booking_date' => $bookingDate,
					'category_id' => $categoryId,
					'direction' => $direction,
					'title' => $title,
				]);
				$stats['updated']++;
			} else {
				$stats['skipped']++;
			}
		}

		$this->cleanupStaleBudgetPlanned($workspaceId, $userId, $ym, $plannedMap, $uncatId, $categoriesById, $stats);

		$this->audit->record($userId, 'budget_planned_synced', 'budget', $ym, $stats, $workspaceId);

		return $stats;
	}

	/**
	 * Ensure a bc_budgets row exists so planned transactions can reference it.
	 *
	 * @return array<string,mixed>
	 */
	private function ensureBudgetRow(int $workspaceId, string $userId, string $yearMonth, int $categoryId, int $plannedMinor): array
	{
		$existing = $this->loadBudgetRow($workspaceId, $yearMonth, $categoryId);
		if ($existing !== null) {
			if ((int)$existing['planned_minor'] !== $plannedMinor && $plannedMinor > 0) {
				$now = $this->utcNow();
				$qb = $this->db->getQueryBuilder();
				$qb->update('bc_budgets')
					->set('planned_minor', $qb->createNamedParameter($plannedMinor, \PDO::PARAM_INT))
					->set('updated_by', $qb->createNamedParameter($userId))
					->set('updated_at', $qb->createNamedParameter($now))
					->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$existing['id'], \PDO::PARAM_INT)));
				$qb->executeStatement();
				$existing['planned_minor'] = $plannedMinor;
			}
			return $existing;
		}
		if ($plannedMinor === 0) {
			throw new AccessDeniedException();
		}
		$now = $this->utcNow();
		$qb = $this->db->getQueryBuilder();
		$qb->insert('bc_budgets')
			->values([
				'workspace_id' => $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT),
				'year_month' => $qb->createNamedParameter($yearMonth),
				'category_id' => $qb->createNamedParameter($categoryId, \PDO::PARAM_INT),
				'planned_minor' => $qb->createNamedParameter($plannedMinor, \PDO::PARAM_INT),
				'updated_by' => $qb->createNamedParameter($userId),
				'updated_at' => $qb->createNamedParameter($now),
			]);
		$qb->executeStatement();
		$id = (int)$this->db->lastInsertId('bc_budgets');
		$row = $this->loadBudgetRowById($id);
		if ($row === null) {
			throw new \RuntimeException('Failed to load budget row after insert.');
		}
		return $row;
	}

	private function loadBudgetRow(int $workspaceId, string $yearMonth, int $categoryId): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('bc_budgets')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('year_month', $qb->createNamedParameter($yearMonth)))
			->andWhere($qb->expr()->eq('category_id', $qb->createNamedParameter($categoryId, \PDO::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? null : $row;
	}

	private function loadBudgetRowById(int $id): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('bc_budgets')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? null : $row;
	}

	private function loadPlannedForBudget(int $workspaceId, int $budgetId): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('bc_transactions')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('budget_id', $qb->createNamedParameter($budgetId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('is_planned', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
			->andWhere($qb->expr()->isNull('deleted_at'));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? null : $row;
	}

	private function hasRealBookingInMonth(int $workspaceId, int $categoryId, string $direction, string $yearMonth): bool
	{
		$start = $yearMonth . '-01';
		try {
			$end = (new \DateTimeImmutable($start, new \DateTimeZone('UTC')))
				->modify('last day of this month')
				->format('Y-m-d');
		} catch (\Throwable) {
			return false;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'count'))
			->from('bc_transactions')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('category_id', $qb->createNamedParameter($categoryId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('direction', $qb->createNamedParameter($direction)))
			->andWhere($qb->expr()->eq('is_planned', $qb->createNamedParameter(false, \PDO::PARAM_BOOL)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->andWhere($qb->expr()->gte('booking_date', $qb->createNamedParameter($start)))
			->andWhere($qb->expr()->lte('booking_date', $qb->createNamedParameter($end)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['count'] ?? 0) > 0;
	}

	private function hasRecurringPlannedInMonth(int $workspaceId, int $categoryId, string $direction, string $yearMonth): bool
	{
		$start = $yearMonth . '-01';
		try {
			$end = (new \DateTimeImmutable($start, new \DateTimeZone('UTC')))
				->modify('last day of this month')
				->format('Y-m-d');
		} catch (\Throwable) {
			return false;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'count'))
			->from('bc_transactions')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('category_id', $qb->createNamedParameter($categoryId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('direction', $qb->createNamedParameter($direction)))
			->andWhere($qb->expr()->eq('is_planned', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
			->andWhere($qb->expr()->isNotNull('recurring_rule_id'))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->andWhere($qb->expr()->gte('booking_date', $qb->createNamedParameter($start)))
			->andWhere($qb->expr()->lte('booking_date', $qb->createNamedParameter($end)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['count'] ?? 0) > 0;
	}

	/**
	 * @param array<string,mixed> $existing
	 * @param array<string,mixed> $updates
	 */
	private function updatePlannedRow(array $existing, string $userId, array $workspace, array $updates): void
	{
		$ym = substr((string)$existing['booking_date'], 0, 7);
		if ($this->snapshots->isMonthClosed((int)$workspace['id'], $ym)) {
			throw new \InvalidArgumentException('Month is closed. Reopen it before generating planned entries.');
		}
		$now = $this->utcNow();
		$version = (int)$existing['version'];
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_transactions');
		foreach ($updates as $col => $value) {
			$type = is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR;
			$qb->set($col, $qb->createNamedParameter($value, $type));
		}
		$qb->set('updated_by', $qb->createNamedParameter($userId))
			->set('updated_at', $qb->createNamedParameter($now))
			->set('version', $qb->createNamedParameter($version + 1, \PDO::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$existing['id'], \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('version', $qb->createNamedParameter($version, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('is_planned', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
			->andWhere($qb->expr()->isNull('deleted_at'));
		if ($qb->executeStatement() === 0) {
			throw new \RuntimeException('Failed to update planned transaction.');
		}
		$this->audit->record($userId, 'planned_transaction_updated', 'transaction', (string)$existing['id'], [
			'source' => 'budget',
		], (int)$workspace['id']);
	}

	/**
	 * @param array<string,mixed> $existing
	 */
	private function softDeletePlanned(array $existing, string $userId, int $workspaceId): void
	{
		$now = $this->utcNow();
		$version = (int)$existing['version'];
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_transactions')
			->set('deleted_at', $qb->createNamedParameter($now))
			->set('updated_by', $qb->createNamedParameter($userId))
			->set('updated_at', $qb->createNamedParameter($now))
			->set('version', $qb->createNamedParameter($version + 1, \PDO::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$existing['id'], \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('version', $qb->createNamedParameter($version, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('is_planned', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
			->andWhere($qb->expr()->isNull('deleted_at'));
		$qb->executeStatement();
	}

	private function cleanupStaleBudgetPlanned(
		int $workspaceId,
		string $userId,
		string $yearMonth,
		array $plannedMap,
		?int $uncatId,
		array $categoriesById,
		array &$stats,
	): void {
		$start = $yearMonth . '-01';
		try {
			$end = (new \DateTimeImmutable($start, new \DateTimeZone('UTC')))
				->modify('last day of this month')
				->format('Y-m-d');
		} catch (\Throwable) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('bc_transactions')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('is_planned', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
			->andWhere($qb->expr()->isNotNull('budget_id'))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->andWhere($qb->expr()->gte('booking_date', $qb->createNamedParameter($start)))
			->andWhere($qb->expr()->lte('booking_date', $qb->createNamedParameter($end)));
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$budgetId = (int)($row['budget_id'] ?? 0);
			$budgetRow = $budgetId > 0 ? $this->loadBudgetRowById($budgetId) : null;
			$categoryId = $budgetRow !== null ? (int)($budgetRow['category_id'] ?? 0) : (int)($row['category_id'] ?? 0);
			$plannedMinor = $categoryId > 0 ? (int)($plannedMap[$categoryId] ?? 0) : 0;
			$cat = $categoriesById[$categoryId] ?? null;
			$shouldKeep = $plannedMinor > 0
				&& $cat !== null
				&& !empty($cat['isActive'])
				&& empty($cat['isSavingsTransfer'])
				&& ($uncatId === null || $categoryId !== $uncatId)
				&& $budgetRow !== null
				&& (string)($budgetRow['year_month'] ?? '') === $yearMonth;
			if (!$shouldKeep) {
				$this->softDeletePlanned($row, $userId, $workspaceId);
				$stats['removed']++;
			}
		}
		$result->closeCursor();
	}

	private function validateYearMonth(string $value): string
	{
		$value = trim($value);
		if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
			throw new \InvalidArgumentException('yearMonth must be in YYYY-MM format with a valid month.');
		}
		return $value;
	}

	private function utcNow(): string
	{
		return $this->timeFactory->getDateTime('now', new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
	}
}
