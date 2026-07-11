<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Speed up idempotency checks for recurring planned rows.
 */
class Version1015Date20260711120000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('bc_transactions')) {
			$table = $schema->getTable('bc_transactions');
			if (!$table->hasIndex('bc_tx_recurring_date_idx')) {
				$table->addIndex(['recurring_rule_id', 'booking_date'], 'bc_tx_recurring_date_idx');
			}
		}

		return $schema;
	}
}
