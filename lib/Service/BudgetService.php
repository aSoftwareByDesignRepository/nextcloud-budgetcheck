<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

use OCA\BudgetCheck\Exception\AccessDeniedException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Per-month, per-category budget plan rows.
 *
 * - Each row is `(workspace, year_month, category_id?)` — `category_id` is null
 *   for the optional workspace-wide budget envelope.
 * - Bulk upsert is the primary write path: managers tend to update many
 *   categories in one save (planning UI), so we wrap the whole batch in a
 *   single DB transaction and emit one audit row.
 */
class BudgetService
{
	private ?bool $budgetDefaultsTableExists = null;

	public function __construct(
		private IDBConnection $db,
		private AccessControlService $access,
		private MoneyService $money,
		private ITimeFactory $timeFactory,
		private AuditLogService $audit,
		private CategoryService $categories,
		private IL10N $l10n,
	) {
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function listForMonth(int $workspaceId, string $userId, string $yearMonth, string $currencyCode): array
	{
		$this->access->ensureMembership($workspaceId, $userId);
		$ym = $this->validateYearMonth($yearMonth);
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('bc_budgets')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('year_month', $qb->createNamedParameter($ym)))
			->orderBy('category_id', 'ASC');
		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$out[] = $this->hydrate($row, $currencyCode);
		}
		$result->closeCursor();
		return $out;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function listDefaults(int $workspaceId, string $userId, string $currencyCode): array
	{
		$this->access->ensureMembership($workspaceId, $userId);
		if (!$this->budgetDefaultsTableAvailable()) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('bc_budget_defaults')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->orderBy('category_id', 'ASC');
		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$out[] = $this->hydrateDefault($row, $currencyCode);
		}
		$result->closeCursor();
		return $out;
	}

	/**
	 * @param list<array{categoryId:?int, plannedMinor:int}> $rows
	 */
	public function bulkUpsert(int $workspaceId, string $userId, string $yearMonth, array $rows, array $workspace): array
	{
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_MANAGER);
		$ym = $this->validateYearMonth($yearMonth);
		$decimals = $this->money->decimalsFor($workspace['currencyCode']);
		$now = $this->utcNow();
		$uncatId = $this->categories->internalUncategorizedCategoryId($workspaceId);

		$normalised = [];
		foreach ($rows as $row) {
			$categoryId = $row['categoryId'] ?? null;
			if ($categoryId !== null) {
				$categoryId = (int)$categoryId;
				if ($categoryId < 1) {
					throw new \InvalidArgumentException('categoryId must be a positive integer or null.');
				}
				if ($uncatId !== null && $categoryId === $uncatId) {
					throw new \InvalidArgumentException($this->l10n->t('Budgets cannot be assigned to the system Uncategorized category.'));
				}
				if (!$this->categoryBelongsToWorkspace($categoryId, $workspaceId)) {
					throw new AccessDeniedException();
				}
			}
			$plannedRaw = $row['plannedMinor'] ?? ($row['planned'] ?? 0);
			$plannedMinor = is_int($plannedRaw) ? $plannedRaw : $this->money->parseHumanAmount($plannedRaw, $decimals);
			if ($plannedMinor < 0) {
				throw new \InvalidArgumentException('plannedMinor must be zero or positive.');
			}
			$normalised[] = ['categoryId' => $categoryId, 'plannedMinor' => $plannedMinor];
		}

		// De-dupe: a single (workspace, ym, categoryId) pair must only have one row.
		$seen = [];
		foreach ($normalised as $row) {
			$key = $row['categoryId'] === null ? 'global' : (string)$row['categoryId'];
			if (isset($seen[$key])) {
				throw new \InvalidArgumentException('Duplicate budget row for category ' . $key . '.');
			}
			$seen[$key] = true;
		}

		$this->db->beginTransaction();
		try {
			foreach ($normalised as $row) {
				$existing = $this->loadExisting($workspaceId, $ym, $row['categoryId']);
				if ($existing === null) {
					if ($row['plannedMinor'] === 0) {
						continue;
					}
					$qb = $this->db->getQueryBuilder();
					$qb->insert('bc_budgets')
						->values([
							'workspace_id' => $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT),
							'year_month' => $qb->createNamedParameter($ym),
							'category_id' => $qb->createNamedParameter($row['categoryId'], $row['categoryId'] === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT),
							'planned_minor' => $qb->createNamedParameter($row['plannedMinor'], \PDO::PARAM_INT),
							'updated_by' => $qb->createNamedParameter($userId),
							'updated_at' => $qb->createNamedParameter($now),
						]);
					$qb->executeStatement();
				} elseif ($row['plannedMinor'] === 0) {
					$qb = $this->db->getQueryBuilder();
					$qb->delete('bc_budgets')
						->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$existing['id'], \PDO::PARAM_INT)));
					$qb->executeStatement();
				} elseif ((int)$existing['planned_minor'] !== $row['plannedMinor']) {
					$qb = $this->db->getQueryBuilder();
					$qb->update('bc_budgets')
						->set('planned_minor', $qb->createNamedParameter($row['plannedMinor'], \PDO::PARAM_INT))
						->set('updated_by', $qb->createNamedParameter($userId))
						->set('updated_at', $qb->createNamedParameter($now))
						->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$existing['id'], \PDO::PARAM_INT)));
					$qb->executeStatement();
				}
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}

		$this->audit->record($userId, 'budgets_upserted', 'budget', $ym, ['count' => count($normalised)], $workspaceId);
		return $this->listForMonth($workspaceId, $userId, $ym, $workspace['currencyCode']);
	}

	/**
	 * Map of categoryId -> planned_minor for a given month, including the
	 * workspace-wide row keyed as `0` when present.
	 *
	 * @return array<int,int>
	 */
	public function plannedMapForMonth(int $workspaceId, string $yearMonth): array
	{
		$ym = $this->validateYearMonth($yearMonth);
		$defaults = $this->plannedDefaultsMap($workspaceId);
		$qb = $this->db->getQueryBuilder();
		$qb->select('category_id', 'planned_minor')
			->from('bc_budgets')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('year_month', $qb->createNamedParameter($ym)));
		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$key = $row['category_id'] === null ? 0 : (int)$row['category_id'];
			$out[$key] = (int)$row['planned_minor'];
		}
		$result->closeCursor();
		return $out + $defaults;
	}

	/**
	 * @param list<array{categoryId:int, plannedMinor:int|string}> $rows
	 * @return list<array<string,mixed>>
	 */
	public function bulkUpsertDefaults(int $workspaceId, string $userId, array $rows, array $workspace): array
	{
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_MANAGER);
		if (!$this->budgetDefaultsTableAvailable()) {
			// Older installs can miss this table until migrations run; keep API stable.
			return [];
		}
		$decimals = $this->money->decimalsFor($workspace['currencyCode']);
		$now = $this->utcNow();
		$uncatId = $this->categories->internalUncategorizedCategoryId($workspaceId);
		$normalised = [];
		foreach ($rows as $row) {
			$categoryId = (int)($row['categoryId'] ?? 0);
			if ($categoryId < 1) {
				throw new \InvalidArgumentException('categoryId must be a positive integer.');
			}
			if ($uncatId !== null && $categoryId === $uncatId) {
				throw new \InvalidArgumentException($this->l10n->t('Budgets cannot be assigned to the system Uncategorized category.'));
			}
			if (!$this->categoryBelongsToWorkspace($categoryId, $workspaceId)) {
				throw new AccessDeniedException();
			}
			$plannedRaw = $row['plannedMinor'] ?? ($row['planned'] ?? 0);
			$plannedMinor = is_int($plannedRaw) ? $plannedRaw : $this->money->parseHumanAmount($plannedRaw, $decimals);
			if ($plannedMinor < 0) {
				throw new \InvalidArgumentException('plannedMinor must be zero or positive.');
			}
			$normalised[$categoryId] = $plannedMinor;
		}
		$this->db->beginTransaction();
		try {
			foreach ($normalised as $categoryId => $plannedMinor) {
				$existing = $this->loadExistingDefault($workspaceId, $categoryId);
				if ($existing === null) {
					if ($plannedMinor === 0) {
						continue;
					}
					$qb = $this->db->getQueryBuilder();
					$qb->insert('bc_budget_defaults')
						->values([
							'workspace_id' => $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT),
							'category_id' => $qb->createNamedParameter($categoryId, \PDO::PARAM_INT),
							'planned_minor' => $qb->createNamedParameter($plannedMinor, \PDO::PARAM_INT),
							'updated_by' => $qb->createNamedParameter($userId),
							'updated_at' => $qb->createNamedParameter($now),
						]);
					$qb->executeStatement();
				} elseif ($plannedMinor === 0) {
					$qb = $this->db->getQueryBuilder();
					$qb->delete('bc_budget_defaults')
						->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$existing['id'], \PDO::PARAM_INT)));
					$qb->executeStatement();
				} elseif ((int)$existing['planned_minor'] !== $plannedMinor) {
					$qb = $this->db->getQueryBuilder();
					$qb->update('bc_budget_defaults')
						->set('planned_minor', $qb->createNamedParameter($plannedMinor, \PDO::PARAM_INT))
						->set('updated_by', $qb->createNamedParameter($userId))
						->set('updated_at', $qb->createNamedParameter($now))
						->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$existing['id'], \PDO::PARAM_INT)));
					$qb->executeStatement();
				}
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
		$this->audit->record($userId, 'budget_defaults_upserted', 'budget_default', (string)$workspaceId, ['count' => count($normalised)], $workspaceId);
		return $this->listDefaults($workspaceId, $userId, $workspace['currencyCode']);
	}

	private function loadExisting(int $workspaceId, string $yearMonth, ?int $categoryId): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('bc_budgets')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('year_month', $qb->createNamedParameter($yearMonth)));
		if ($categoryId === null) {
			$qb->andWhere($qb->expr()->isNull('category_id'));
		} else {
			$qb->andWhere($qb->expr()->eq('category_id', $qb->createNamedParameter($categoryId, \PDO::PARAM_INT)));
		}
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? null : $row;
	}

	private function loadExistingDefault(int $workspaceId, int $categoryId): ?array
	{
		if (!$this->budgetDefaultsTableAvailable()) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('bc_budget_defaults')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('category_id', $qb->createNamedParameter($categoryId, \PDO::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? null : $row;
	}

	private function categoryBelongsToWorkspace(int $categoryId, int $workspaceId): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('bc_categories')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($categoryId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row !== false;
	}

	private function hydrate(array $row, string $currencyCode): array
	{
		return [
			'id' => (int)$row['id'],
			'workspaceId' => (int)$row['workspace_id'],
			'yearMonth' => (string)$row['year_month'],
			'categoryId' => $row['category_id'] === null ? null : (int)$row['category_id'],
			'planned' => $this->money->envelope((int)$row['planned_minor'], $currencyCode),
			'updatedBy' => (string)$row['updated_by'],
			'updatedAt' => (string)$row['updated_at'],
		];
	}

	private function hydrateDefault(array $row, string $currencyCode): array
	{
		return [
			'id' => (int)$row['id'],
			'workspaceId' => (int)$row['workspace_id'],
			'categoryId' => (int)$row['category_id'],
			'planned' => $this->money->envelope((int)$row['planned_minor'], $currencyCode),
			'updatedBy' => (string)$row['updated_by'],
			'updatedAt' => (string)$row['updated_at'],
		];
	}

	/**
	 * @return array<int,int>
	 */
	private function plannedDefaultsMap(int $workspaceId): array
	{
		if (!$this->budgetDefaultsTableAvailable()) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('category_id', 'planned_minor')
			->from('bc_budget_defaults')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)));
		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$out[(int)$row['category_id']] = (int)$row['planned_minor'];
		}
		$result->closeCursor();
		return $out;
	}

	private function budgetDefaultsTableAvailable(): bool
	{
		if ($this->budgetDefaultsTableExists !== null) {
			return $this->budgetDefaultsTableExists;
		}

		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')
				->from('bc_budget_defaults')
				->setMaxResults(1);
			$result = $qb->executeQuery();
			$result->closeCursor();
			$this->budgetDefaultsTableExists = true;
			return true;
		} catch (\Throwable $e) {
			if ($this->isMissingBudgetDefaultsTableError($e)) {
				$this->budgetDefaultsTableExists = false;
				return false;
			}
			throw $e;
		}
	}

	private function isMissingBudgetDefaultsTableError(\Throwable $e): bool
	{
		$message = strtolower($e->getMessage());
		if (!str_contains($message, 'bc_budget_defaults')) {
			return false;
		}
		return str_contains($message, "doesn't exist")
			|| str_contains($message, 'base table or view not found')
			|| str_contains($message, 'sqlstate[42s02]')
			|| str_contains($message, '1146');
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
