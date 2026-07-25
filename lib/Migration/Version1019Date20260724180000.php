<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * BC-F0: billable flag + invoice settlement status for trusted sibling apps (InvoiceCheck).
 *
 * Orthogonal to booking_status_id (project UX workflow) and monthly close snapshots.
 */
class Version1019Date20260724180000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('bc_transactions')) {
			return $schema;
		}

		$tx = $schema->getTable('bc_transactions');
		if (!$tx->hasColumn('is_billable')) {
			// Oracle-safe: boolean notnull false
			$tx->addColumn('is_billable', Types::BOOLEAN, [
				'notnull' => false,
				'default' => false,
			]);
		}
		if (!$tx->hasColumn('billing_status')) {
			$tx->addColumn('billing_status', Types::STRING, [
				'notnull' => true,
				'length' => 16,
				'default' => 'open',
			]);
		}
		if (!$tx->hasIndex('bc_tx_bill_idx')) {
			$tx->addIndex(['workspace_id', 'is_billable', 'billing_status'], 'bc_tx_bill_idx');
		}

		return $schema;
	}
}
