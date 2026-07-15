<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Repair;

use OCA\BudgetCheck\Service\BudgetPlannedService;
use OCA\BudgetCheck\Service\BudgetService;
use OCA\BudgetCheck\Service\CategoryService;
use OCA\BudgetCheck\Service\SummaryService;
use OCA\BudgetCheck\Service\WorkspaceService;
use OCP\IDBConnection;

/**
 * Idempotent demo dataset for planned-forecast verification (issue #14).
 *
 * Creates income/expense categories, budget targets, and planned ledger rows
 * for July–September 2026. Safe to re-run: removes prior PF Demo rows first.
 */
final class SeedPlannedForecastDemo
{
	public const CATEGORY_PREFIX = 'PF Demo: ';

	/** @var list<string> */
	public const MONTHS = ['2026-07', '2026-08', '2026-09'];

	private const TARGETS = [
		'Salary' => ['type' => CategoryService::TYPE_INCOME, 'minor' => 5_000_00],
		'Rent' => ['type' => CategoryService::TYPE_EXPENSE, 'minor' => 1_500_00],
		'Groceries' => ['type' => CategoryService::TYPE_EXPENSE, 'minor' => 400_00],
	];

	public function __construct(
		private IDBConnection $db,
		private WorkspaceService $workspaces,
		private CategoryService $categories,
		private BudgetService $budgets,
		private BudgetPlannedService $budgetPlanned,
		private SummaryService $summary,
	) {
	}

	/**
	 * @return array{
	 *     workspaceId:int,
	 *     userId:string,
	 *     categories:array<string,int>,
	 *     months:list<string>,
	 *     plannedSync:array<string,array<string,int>>,
	 *     julySummary:array<string,mixed>
	 * }
	 */
	public function run(int $workspaceId, string $userId): array
	{
		$workspace = $this->workspaces->getForUser($workspaceId, $userId);
		if ($workspace['type'] !== WorkspaceService::TYPE_HOUSEHOLD) {
			throw new \InvalidArgumentException('Workspace must be a household.');
		}

		$this->cleanup($workspaceId);
		$categoryIds = $this->ensureCategories($workspaceId, $userId);

		$defaultRows = [];
		foreach ($categoryIds as $label => $categoryId) {
			$defaultRows[] = [
				'categoryId' => $categoryId,
				'plannedMinor' => self::TARGETS[$label]['minor'],
			];
		}
		$this->budgets->bulkUpsertDefaults($workspaceId, $userId, $defaultRows, $workspace);

		$plannedSync = [];
		foreach (self::MONTHS as $ym) {
			$monthRows = [];
			foreach ($categoryIds as $label => $categoryId) {
				$monthRows[] = [
					'categoryId' => $categoryId,
					'plannedMinor' => self::TARGETS[$label]['minor'],
				];
			}
			$this->budgets->bulkUpsert($workspaceId, $userId, $ym, $monthRows, $workspace);
			$plannedSync[$ym] = $this->budgetPlanned->syncMonth($workspaceId, $userId, $ym, $workspace);
		}

		$july = $this->summary->household($workspaceId, $userId, '2026-07');

		return [
			'workspaceId' => $workspaceId,
			'userId' => $userId,
			'categories' => $categoryIds,
			'months' => self::MONTHS,
			'plannedSync' => $plannedSync,
			'julySummary' => [
				'yearMonth' => $july['yearMonth'],
				'totals' => [
					'incomeMinor' => (int)($july['totals']['income']['minor'] ?? 0),
					'expenseMinor' => (int)($july['totals']['expense']['minor'] ?? 0),
				],
				'planned' => [
					'incomeTargetMinor' => (int)($july['planned']['incomeTarget']['minor'] ?? 0),
					'ledgerIncomeMinor' => (int)($july['planned']['ledger']['income']['minor'] ?? 0),
					'ledgerExpenseMinor' => (int)($july['planned']['ledger']['expense']['minor'] ?? 0),
					'entryCount' => (int)($july['planned']['ledger']['entryCount'] ?? 0),
				],
				'budgetPlannedMinor' => (int)($july['budget']['plannedTotal']['minor'] ?? 0),
				'activity' => $july['activity'] ?? null,
			],
		];
	}

	private function cleanup(int $workspaceId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('bc_categories')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->like('name', $qb->createNamedParameter(self::CATEGORY_PREFIX . '%')));
		$result = $qb->executeQuery();
		$ids = [];
		while ($row = $result->fetch()) {
			$ids[] = (int)$row['id'];
		}
		$result->closeCursor();
		if ($ids === []) {
			return;
		}

		$now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

		$tx = $this->db->getQueryBuilder();
		$tx->update('bc_transactions')
			->set('deleted_at', $tx->createNamedParameter($now))
			->where($tx->expr()->eq('workspace_id', $tx->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($tx->expr()->in('category_id', $tx->createNamedParameter($ids, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
		$tx->executeStatement();

		$bud = $this->db->getQueryBuilder();
		$bud->delete('bc_budgets')
			->where($bud->expr()->eq('workspace_id', $bud->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($bud->expr()->in('category_id', $bud->createNamedParameter($ids, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
		$bud->executeStatement();

		$def = $this->db->getQueryBuilder();
		$def->delete('bc_budget_defaults')
			->where($def->expr()->eq('workspace_id', $def->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($def->expr()->in('category_id', $def->createNamedParameter($ids, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
		$def->executeStatement();

		$cat = $this->db->getQueryBuilder();
		$cat->delete('bc_categories')
			->where($cat->expr()->eq('workspace_id', $cat->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($cat->expr()->in('id', $cat->createNamedParameter($ids, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
		$cat->executeStatement();
	}

	/**
	 * @return array<string,int> label => category id
	 */
	private function ensureCategories(int $workspaceId, string $userId): array
	{
		$out = [];
		foreach (self::TARGETS as $label => $meta) {
			$name = self::CATEGORY_PREFIX . $label;
			$created = $this->categories->create($workspaceId, $userId, [
				'name' => $name,
				'type' => $meta['type'],
			]);
			$out[$label] = (int)$created['id'];
		}
		return $out;
	}
}
