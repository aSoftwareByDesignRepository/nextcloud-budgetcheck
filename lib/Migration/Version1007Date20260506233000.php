<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add household workspace defaults for monthly savings targets.
 *
 * These columns let household workspaces define a default savings mode/value
 * that is inherited by months without an explicit row in bc_savings_targets.
 */
class Version1007Date20260506233000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('bc_workspaces')) {
			return $schema;
		}

		$table = $schema->getTable('bc_workspaces');
		if (!$table->hasColumn('default_savings_target_mode')) {
			$table->addColumn('default_savings_target_mode', 'string', [
				'length' => 16,
				'notnull' => false,
			]);
		}
		if (!$table->hasColumn(BudgetCheckTableCatalog::COL_DEF_SAV_TGT_PCT_BP)
			&& !$table->hasColumn(BudgetCheckTableCatalog::COL_DEF_SAV_TGT_PCT_BP_LEGACY)) {
			$table->addColumn(BudgetCheckTableCatalog::COL_DEF_SAV_TGT_PCT_BP, 'integer', [
				'notnull' => false,
			]);
		}
		if (!$table->hasColumn('default_savings_target_minor')) {
			$table->addColumn('default_savings_target_minor', 'bigint', [
				'notnull' => false,
			]);
		}

		return $schema;
	}
}
