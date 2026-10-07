<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Optional billing period (Abrechnungszeitraum) for project workspaces.
 *
 * Final invoices routinely arrive after the project end date; the booking-date
 * check previously forced users to widen the customer-visible project period.
 * `billing_start_date` / `billing_end_date` bound booking dates instead when
 * set — each side falls back to the project bound when null, so existing
 * workspaces behave exactly as before.
 */
class Version1023Date20261007210000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('bc_workspaces')) {
			$table = $schema->getTable('bc_workspaces');
			if (!$table->hasColumn('billing_start_date')) {
				$table->addColumn('billing_start_date', Types::DATE, [
					'notnull' => false,
					'default' => null,
				]);
			}
			if (!$table->hasColumn('billing_end_date')) {
				$table->addColumn('billing_end_date', Types::DATE, [
					'notnull' => false,
					'default' => null,
				]);
			}
		}

		return $schema;
	}
}
