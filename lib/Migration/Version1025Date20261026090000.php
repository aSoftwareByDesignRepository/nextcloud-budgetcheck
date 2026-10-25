<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Payee dimension + deterministic categorization rules (spec: payee-and-categorization-rules).
 *
 *  - `bc_payees`: normalized counterparty entity per workspace (`name_norm`
 *    unique per workspace so "ALDI SÜD" and " aldi süd " collapse into one row).
 *  - `bc_transactions.payee_id`: nullable reference — every path that creates
 *    transactions (web, import, mobile, InvoiceCheck facade) keeps working
 *    without it. No FK cascade: payee deletion is soft (`is_active=0`), merge
 *    is the only destructive operation and is explicit.
 *  - `bc_category_rules`: deterministic matching policy (contains / equals /
 *    starts_with on title|payee|notes), evaluated in `priority, id` order.
 */
class Version1025Date20261026090000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('bc_payees')) {
			$t = $schema->createTable('bc_payees');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('workspace_id', 'bigint', ['notnull' => true]);
			$t->addColumn('name', 'string', ['length' => 180, 'notnull' => true]);
			$t->addColumn('name_norm', 'string', ['length' => 180, 'notnull' => true]);
			$t->addColumn('is_active', 'boolean', ['notnull' => false, 'default' => true]);
			$t->addColumn('created_by', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('updated_by', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'bc_payees_pk');
			$t->addUniqueIndex(['workspace_id', 'name_norm'], 'bc_payees_norm_uq');
			$t->addIndex(['workspace_id'], 'bc_payees_ws_idx');
		}

		if ($schema->hasTable('bc_transactions')) {
			$table = $schema->getTable('bc_transactions');
			if (!$table->hasColumn('payee_id')) {
				$table->addColumn('payee_id', 'bigint', ['notnull' => false, 'default' => null]);
			}
			if (!$table->hasIndex('bc_tx_ws_payee_idx')) {
				$table->addIndex(['workspace_id', 'payee_id'], 'bc_tx_ws_payee_idx');
			}
		}

		if (!$schema->hasTable('bc_category_rules')) {
			$t = $schema->createTable('bc_category_rules');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('workspace_id', 'bigint', ['notnull' => true]);
			$t->addColumn('category_id', 'bigint', ['notnull' => true]);
			$t->addColumn('match_field', 'string', ['length' => 8, 'notnull' => true]);
			$t->addColumn('match_type', 'string', ['length' => 12, 'notnull' => true]);
			$t->addColumn('pattern', 'string', ['length' => 255, 'notnull' => true]);
			$t->addColumn('direction', 'string', ['length' => 8, 'notnull' => true, 'default' => 'any']);
			$t->addColumn('priority', 'integer', ['notnull' => true, 'default' => 100]);
			$t->addColumn('use_regex', 'boolean', ['notnull' => false, 'default' => false]);
			$t->addColumn('is_active', 'boolean', ['notnull' => false, 'default' => true]);
			$t->addColumn('hit_count', 'bigint', ['notnull' => true, 'default' => 0]);
			$t->addColumn('version', 'integer', ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_by', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('updated_by', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'bc_catrules_pk');
			$t->addIndex(['workspace_id', 'is_active', 'priority'], 'bc_catrules_scan_idx');
		}

		return $schema;
	}
}
