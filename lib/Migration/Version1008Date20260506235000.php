<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1008Date20260506235000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('bc_budget_defaults')) {
			$t = $schema->createTable('bc_budget_defaults');
		$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
		$t->addColumn('workspace_id', 'bigint', ['notnull' => true]);
		$t->addColumn('category_id', 'bigint', ['notnull' => true]);
		$t->addColumn('planned_minor', 'bigint', ['notnull' => true]);
		$t->addColumn('updated_by', 'string', ['length' => 64, 'notnull' => true]);
		$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
		$t->setPrimaryKey(['id'], 'bc_buddef_pk');
		$t->addUniqueIndex(['workspace_id', 'category_id'], 'bc_buddef_ws_cat_uidx');
		$t->addIndex(['workspace_id'], 'bc_buddef_ws_idx');
		}

		return $schema;
	}
}
