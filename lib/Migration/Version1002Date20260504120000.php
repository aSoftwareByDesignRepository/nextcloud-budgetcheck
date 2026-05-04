<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Migration;

use Closure;
use OCA\BudgetCheck\Service\WorkspaceService;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Household workspaces store the calendar year the owner chose as the primary
 * planning year (annual plan anchor). Project workspaces keep NULL.
 */
class Version1002Date20260504120000 extends SimpleMigrationStep
{
	public function __construct(private IDBConnection $db)
	{
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('bc_workspaces')) {
			$table = $schema->getTable('bc_workspaces');
			if (!$table->hasColumn('primary_planning_year')) {
				$table->addColumn('primary_planning_year', 'smallint', ['notnull' => false]);
			}
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'created_at')
			->from('bc_workspaces')
			->where($qb->expr()->eq('type', $qb->createNamedParameter(WorkspaceService::TYPE_HOUSEHOLD)))
			->andWhere($qb->expr()->isNull('primary_planning_year'));
		$res = $qb->executeQuery();
		while ($row = $res->fetch()) {
			$id = (int)$row['id'];
			$created = (string)($row['created_at'] ?? '');
			$y = (int)substr($created, 0, 4);
			if ($y < 1900 || $y > 9999) {
				$y = (int)gmdate('Y');
			}
			$up = $this->db->getQueryBuilder();
			$up->update('bc_workspaces')
				->set('primary_planning_year', $up->createNamedParameter($y, \PDO::PARAM_INT))
				->where($up->expr()->eq('id', $up->createNamedParameter($id, \PDO::PARAM_INT)));
			$up->executeStatement();
		}
		$res->closeCursor();
	}
}
