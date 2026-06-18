<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Household workspace default for whether planning totals include special transactions.
 */
class Version1013Date20260618160000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('bc_workspaces')) {
			return $schema;
		}

		$table = $schema->getTable('bc_workspaces');
		if (!$table->hasColumn('include_specials_default')) {
			$table->addColumn('include_specials_default', 'boolean', [
				'notnull' => false,
				'default' => false,
			]);
		}

		return $schema;
	}
}
