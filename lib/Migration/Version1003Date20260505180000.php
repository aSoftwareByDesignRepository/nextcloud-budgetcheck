<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Migration;

use Closure;
use OCA\BudgetCheck\Service\WorkspaceService;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1003Date20260505180000 extends SimpleMigrationStep
{
	public function __construct(private IDBConnection $db)
	{
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('bc_booking_statuses')) {
			$table = $schema->createTable('bc_booking_statuses');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('workspace_id', 'bigint', ['notnull' => true]);
			$table->addColumn('name', 'string', ['length' => 80, 'notnull' => true]);
			$table->addColumn('sort_order', 'integer', ['notnull' => true, 'default' => 100]);
			$table->addColumn('is_done', 'boolean', ['notnull' => true, 'default' => false]);
			$table->addColumn('is_active', 'boolean', ['notnull' => true, 'default' => true]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'bc_bs_pk');
			$table->addIndex(['workspace_id', 'is_active', 'sort_order'], 'bc_bs_ws_active_sort');
			$table->addUniqueIndex(['workspace_id', 'name'], 'bc_bs_ws_name_unique');
		}

		if ($schema->hasTable('bc_transactions')) {
			$tx = $schema->getTable('bc_transactions');
			if (!$tx->hasColumn('booking_status_id')) {
				$tx->addColumn('booking_status_id', 'bigint', ['notnull' => false]);
			}
			if (!$tx->hasIndex('bc_tx_status_idx')) {
				$tx->addIndex(['workspace_id', 'booking_status_id'], 'bc_tx_status_idx');
			}
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		$now = gmdate('Y-m-d H:i:s');
		$wsQb = $this->db->getQueryBuilder();
		$wsQb->select('id')
			->from('bc_workspaces')
			->where($wsQb->expr()->eq('type', $wsQb->createNamedParameter(WorkspaceService::TYPE_PROJECT)));
		$res = $wsQb->executeQuery();
		while ($row = $res->fetch()) {
			$workspaceId = (int)$row['id'];
			$countQb = $this->db->getQueryBuilder();
			$countQb->select($countQb->func()->count('*', 'count'))
				->from('bc_booking_statuses')
				->where($countQb->expr()->eq('workspace_id', $countQb->createNamedParameter($workspaceId, \PDO::PARAM_INT)));
			$countRow = $countQb->executeQuery()->fetch();
			if ((int)($countRow['count'] ?? 0) > 0) {
				continue;
			}
			foreach ([
				['Open', 10, false],
				['In Progress', 20, false],
				['Blocked', 30, false],
				['Paid', 40, false],
			] as $default) {
				$ins = $this->db->getQueryBuilder();
				$ins->insert('bc_booking_statuses')
					->values([
						'workspace_id' => $ins->createNamedParameter($workspaceId, \PDO::PARAM_INT),
						'name' => $ins->createNamedParameter($default[0]),
						'sort_order' => $ins->createNamedParameter($default[1], \PDO::PARAM_INT),
						'is_done' => $ins->createNamedParameter($default[2], \PDO::PARAM_BOOL),
						'is_active' => $ins->createNamedParameter(true, \PDO::PARAM_BOOL),
						'created_at' => $ins->createNamedParameter($now),
						'updated_at' => $ins->createNamedParameter($now),
					]);
				$ins->executeStatement();
			}
		}
		$res->closeCursor();
	}
}

