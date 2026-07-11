<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Link planned ledger rows to budget targets; workspace default for auto-generate.
 */
class Version1014Date20260619120000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('bc_transactions')) {
			$table = $schema->getTable('bc_transactions');
			if (!$table->hasColumn('budget_id')) {
				$table->addColumn('budget_id', 'bigint', ['notnull' => false]);
				$table->addIndex(['workspace_id', 'budget_id'], 'bc_tx_ws_budget_idx');
			}
		}

		if ($schema->hasTable('bc_workspaces')) {
			$table = $schema->getTable('bc_workspaces');
			if (!$table->hasColumn('gen_planned_budget')) {
				$table->addColumn('gen_planned_budget', 'boolean', [
					'notnull' => false,
					'default' => false,
				]);
			}
		}

		return $schema;
	}
}
