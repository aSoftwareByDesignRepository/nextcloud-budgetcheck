<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Marks expense categories whose transactions are savings transfers (money moved
 * aside) rather than everyday spending for budget saldo calculations.
 */
class Version1012Date20260618140000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('bc_categories')) {
			return $schema;
		}

		$cat = $schema->getTable('bc_categories');
		if (!$cat->hasColumn('is_savings_transfer')) {
			$cat->addColumn('is_savings_transfer', 'boolean', ['notnull' => false, 'default' => false]);
		}

		return $schema;
	}
}
