<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Migration;

use Closure;
use OCA\BudgetCheck\Service\CategoryService;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Repair duplicate system uncategorized categories created by concurrent ensure calls.
 */
class Version1017Date20260715130000 extends SimpleMigrationStep
{
	public function __construct(private IDBConnection $db)
	{
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		return null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		if (!$this->db->tableExists('bc_categories') || !$this->db->tableExists('bc_workspaces')) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('bc_workspaces');
		$result = $qb->executeQuery();
		$repaired = 0;
		while ($row = $result->fetch()) {
			$wid = (int)$row['id'];
			if ($this->dedupeWorkspace($wid)) {
				$repaired++;
			}
		}
		$result->closeCursor();

		if ($repaired > 0) {
			$output->info(sprintf('BudgetCheck: deduplicated system uncategorized categories in %d workspace(s).', $repaired));
		}
	}

	private function dedupeWorkspace(int $workspaceId): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('bc_categories')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('group_key', $qb->createNamedParameter(CategoryService::GROUP_INTERNAL_UNCATEGORIZED)))
			->orderBy('id', 'ASC');
		$result = $qb->executeQuery();
		$ids = [];
		while ($row = $result->fetch()) {
			$ids[] = (int)$row['id'];
		}
		$result->closeCursor();

		if (count($ids) <= 1) {
			return false;
		}

		$keeperId = $ids[0];
		foreach (array_slice($ids, 1) as $dupId) {
			foreach (['bc_transactions', 'bc_recurring_rules', 'bc_budgets', 'bc_budget_defaults'] as $table) {
				if (!$this->db->tableExists($table)) {
					continue;
				}
				$upd = $this->db->getQueryBuilder();
				$upd->update($table)
					->set('category_id', $upd->createNamedParameter($keeperId, \PDO::PARAM_INT))
					->where($upd->expr()->eq('category_id', $upd->createNamedParameter($dupId, \PDO::PARAM_INT)));
				$upd->executeStatement();
			}
			$del = $this->db->getQueryBuilder();
			$del->delete('bc_categories')
				->where($del->expr()->eq('id', $del->createNamedParameter($dupId, \PDO::PARAM_INT)));
			$del->executeStatement();
		}

		return true;
	}
}
