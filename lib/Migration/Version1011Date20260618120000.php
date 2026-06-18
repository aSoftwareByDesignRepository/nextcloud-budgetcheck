<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Links generated recurring entries to their rule and marks them as planned
 * placeholders until a matching real transaction replaces them.
 */
class Version1011Date20260618120000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('bc_transactions')) {
			return $schema;
		}

		$tx = $schema->getTable('bc_transactions');
		if (!$tx->hasColumn('recurring_rule_id')) {
			$tx->addColumn('recurring_rule_id', 'bigint', ['notnull' => false]);
		}
		if (!$tx->hasColumn('is_planned')) {
			$tx->addColumn('is_planned', 'boolean', ['notnull' => false, 'default' => false]);
		}
		if (!$tx->hasIndex('bc_tx_ws_planned_idx')) {
			$tx->addIndex(['workspace_id', 'is_planned', 'category_id'], 'bc_tx_ws_planned_idx');
		}

		return $schema;
	}
}
