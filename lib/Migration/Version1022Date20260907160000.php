<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Recurring rule posting mode (issue #18).
 *
 * `posting_mode` values:
 *  - `plan` — Generate creates planned ledger placeholders (legacy default;
 *    best when bank imports will replace them).
 *  - `book` — Generate / auto-due creates real ledger transactions.
 *
 * Existing rules keep `plan` so import-matching households are unchanged.
 * New rules default to `book` in the service layer (not the column default).
 */
class Version1022Date20260907160000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('bc_recurring_rules')) {
			$table = $schema->getTable('bc_recurring_rules');
			if (!$table->hasColumn('posting_mode')) {
				$table->addColumn('posting_mode', Types::STRING, [
					'notnull' => true,
					'length' => 16,
					'default' => 'plan',
				]);
			}
			if (!$table->hasIndex('bc_rec_posting_due_idx')) {
				$table->addIndex(['is_active', 'posting_mode', 'next_due_date'], 'bc_rec_posting_due_idx');
			}
		}

		return $schema;
	}
}
