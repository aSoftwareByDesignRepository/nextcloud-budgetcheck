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
 * Ensures every workspace has the system Uncategorized expense category (§9).
 */
class Version1001Date20260503120000 extends SimpleMigrationStep
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
		$qb = $this->db->getQueryBuilder();
		$qb->select('w.id', 'w.created_by')
			->from('bc_workspaces', 'w');
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$wid = (int)$row['id'];
			$creator = (string)$row['created_by'];
			$check = $this->db->getQueryBuilder();
			$check->select($check->func()->count('*', 'count'))
				->from('bc_categories')
				->where($check->expr()->eq('workspace_id', $check->createNamedParameter($wid, \PDO::PARAM_INT)))
				->andWhere($check->expr()->eq('group_key', $check->createNamedParameter(CategoryService::GROUP_INTERNAL_UNCATEGORIZED)));
			$cRes = $check->executeQuery();
			$c = $cRes->fetch();
			$cRes->closeCursor();
			if ((int)($c['count'] ?? 0) > 0) {
				continue;
			}
			$now = gmdate('Y-m-d H:i:s');
			$ins = $this->db->getQueryBuilder();
			$ins->insert('bc_categories')
				->values([
					'workspace_id' => $ins->createNamedParameter($wid, \PDO::PARAM_INT),
					'name' => $ins->createNamedParameter('Uncategorized'),
					'type' => $ins->createNamedParameter(CategoryService::TYPE_EXPENSE),
					'group_key' => $ins->createNamedParameter(CategoryService::GROUP_INTERNAL_UNCATEGORIZED),
					'is_special' => $ins->createNamedParameter(false, \PDO::PARAM_BOOL),
					'tax_handling_mode' => $ins->createNamedParameter('inherit_workspace'),
					'is_active' => $ins->createNamedParameter(true, \PDO::PARAM_BOOL),
					'created_by' => $ins->createNamedParameter($creator),
					'created_at' => $ins->createNamedParameter($now),
					'updated_at' => $ins->createNamedParameter($now),
				]);
			$ins->executeStatement();
		}
		$result->closeCursor();
	}
}
