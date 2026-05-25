<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Initial schema for BudgetCheck.
 *
 * - All tables use the `bc_` prefix and big-int primary keys.
 * - All money values are stored in minor units as `bigint` (never floats).
 * - Workspace scoping is enforced by composite indexes (workspace_id, …).
 * - Soft-delete on transactions keeps history intact while removing rows from
 *   day-to-day queries; snapshots reference the live ledger as of close time
 *   and additionally keep their own JSON copy of the totals + a calc hash.
 *
 * The migration is portable to MariaDB and PostgreSQL (no DB-specific types,
 * no SQL literals, only the OCP schema wrapper).
 */
class Version1000Date20260503000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('bc_workspaces')) {
			$t = $schema->createTable('bc_workspaces');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('name', 'string', ['length' => 120, 'notnull' => true]);
			$t->addColumn('type', 'string', ['length' => 16, 'notnull' => true]);
			$t->addColumn('currency_code', 'string', ['length' => 3, 'notnull' => true, 'default' => 'EUR']);
			$t->addColumn('timezone', 'string', ['length' => 64, 'notnull' => true, 'default' => 'Europe/Berlin']);
			$t->addColumn('fiscal_year_start_month', 'smallint', ['notnull' => true, 'default' => 1]);
			$t->addColumn('tax_mode_enabled', 'boolean', ['notnull' => false, 'default' => false]);
			$t->addColumn('tax_budget_basis', 'string', ['length' => 8, 'notnull' => true, 'default' => 'gross']);
			$t->addColumn('overspend_threshold_minor', 'bigint', ['notnull' => false]);
			$t->addColumn('auto_copy_prev_month', 'boolean', ['notnull' => false, 'default' => false]);
			$t->addColumn('is_closed', 'boolean', ['notnull' => false, 'default' => false]);
			$t->addColumn('is_active', 'boolean', ['notnull' => false, 'default' => true]);
			$t->addColumn('project_total_cap_minor', 'bigint', ['notnull' => false]);
			$t->addColumn('project_start_date', 'date', ['notnull' => false]);
			$t->addColumn('project_end_date', 'date', ['notnull' => false]);
			$t->addColumn('default_vat_rate_bp', 'integer', ['notnull' => false]);
			$t->addColumn('default_savings_target_mode', 'string', ['length' => 16, 'notnull' => false]);
			$t->addColumn(BudgetCheckTableCatalog::COL_DEF_SAV_TGT_PCT_BP, 'integer', ['notnull' => false]);
			$t->addColumn('default_savings_target_minor', 'bigint', ['notnull' => false]);
			$t->addColumn('created_by', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'bc_ws_pk');
			$t->addIndex(['type', 'is_active'], 'bc_ws_type_active_idx');
			$t->addIndex(['created_by'], 'bc_ws_creator_idx');
		}

		if (!$schema->hasTable('bc_workspace_members')) {
			$t = $schema->createTable('bc_workspace_members');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('workspace_id', 'bigint', ['notnull' => true]);
			$t->addColumn('user_id', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('role', 'string', ['length' => 16, 'notnull' => true, 'default' => 'viewer']);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'bc_wsm_pk');
			$t->addUniqueIndex(['workspace_id', 'user_id'], 'bc_ws_mem_uidx');
			$t->addIndex(['user_id'], 'bc_ws_mem_user_idx');
		}

		if (!$schema->hasTable('bc_categories')) {
			$t = $schema->createTable('bc_categories');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('workspace_id', 'bigint', ['notnull' => true]);
			$t->addColumn('name', 'string', ['length' => 120, 'notnull' => true]);
			$t->addColumn('type', 'string', ['length' => 8, 'notnull' => true]);
			$t->addColumn('group_key', 'string', ['length' => 64, 'notnull' => false]);
			$t->addColumn('is_special', 'boolean', ['notnull' => false, 'default' => false]);
			$t->addColumn('tax_handling_mode', 'string', ['length' => 24, 'notnull' => true, 'default' => 'inherit_workspace']);
			$t->addColumn('is_active', 'boolean', ['notnull' => false, 'default' => true]);
			$t->addColumn('created_by', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'bc_cat_pk');
			// Active categories must be unique on (workspace, name, type). Inactive duplicates
			// are tolerated so renames do not collide with archived rows. Enforced in service.
			$t->addIndex(['workspace_id', 'is_active', 'type'], 'bc_cat_ws_active_idx');
			$t->addIndex(['workspace_id', 'name'], 'bc_cat_ws_name_idx');
		}

		if (!$schema->hasTable('bc_transactions')) {
			$t = $schema->createTable('bc_transactions');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('workspace_id', 'bigint', ['notnull' => true]);
			$t->addColumn('category_id', 'bigint', ['notnull' => true]);
			$t->addColumn('booking_date', 'date', ['notnull' => true]);
			$t->addColumn('amount_minor', 'bigint', ['notnull' => true]);
			$t->addColumn('direction', 'string', ['length' => 8, 'notnull' => true]);
			$t->addColumn('entry_amount_basis', 'string', ['length' => 8, 'notnull' => true, 'default' => 'simple']);
			$t->addColumn('net_amount_minor', 'bigint', ['notnull' => false]);
			$t->addColumn('vat_rate_bp', 'integer', ['notnull' => false]);
			$t->addColumn('vat_amount_minor', 'bigint', ['notnull' => false]);
			$t->addColumn('gross_amount_minor', 'bigint', ['notnull' => false]);
			$t->addColumn('tax_calculation_locked', 'boolean', ['notnull' => false, 'default' => false]);
			$t->addColumn('title', 'string', ['length' => 180, 'notnull' => true]);
			$t->addColumn('notes', 'text', ['notnull' => false]);
			$t->addColumn('is_special', 'boolean', ['notnull' => false, 'default' => false]);
			$t->addColumn('external_ref', 'string', ['length' => 128, 'notnull' => false]);
			$t->addColumn('version', 'integer', ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_by', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('updated_by', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->addColumn('deleted_at', 'datetime', ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'bc_tx_pk');
			$t->addIndex(['workspace_id', 'booking_date'], 'bc_tx_ws_date_idx');
			$t->addIndex(['workspace_id', 'category_id'], 'bc_tx_ws_cat_idx');
			$t->addIndex(['workspace_id', 'is_special'], 'bc_tx_ws_special_idx');
			$t->addIndex(['workspace_id', 'deleted_at'], 'bc_tx_ws_deleted_idx');
		}

		if (!$schema->hasTable('bc_recurring_rules')) {
			$t = $schema->createTable('bc_recurring_rules');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('workspace_id', 'bigint', ['notnull' => true]);
			$t->addColumn('category_id', 'bigint', ['notnull' => true]);
			$t->addColumn('direction', 'string', ['length' => 8, 'notnull' => true]);
			$t->addColumn('title', 'string', ['length' => 180, 'notnull' => true]);
			$t->addColumn('amount_minor', 'bigint', ['notnull' => true]);
			$t->addColumn('frequency', 'string', ['length' => 24, 'notnull' => true]);
			$t->addColumn('interval_count', 'integer', ['notnull' => true, 'default' => 1]);
			$t->addColumn('start_date', 'date', ['notnull' => true]);
			$t->addColumn('end_date', 'date', ['notnull' => false]);
			$t->addColumn('next_due_date', 'date', ['notnull' => true]);
			$t->addColumn('is_active', 'boolean', ['notnull' => false, 'default' => true]);
			$t->addColumn('created_by', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'bc_rr_pk');
			$t->addIndex(['workspace_id', 'is_active'], 'bc_rr_ws_active_idx');
			$t->addIndex(['workspace_id', 'next_due_date'], 'bc_rr_ws_due_idx');
		}

		if (!$schema->hasTable('bc_budgets')) {
			$t = $schema->createTable('bc_budgets');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('workspace_id', 'bigint', ['notnull' => true]);
			$t->addColumn('year_month', 'string', ['length' => 7, 'notnull' => true]);
			// nullable -> workspace-wide budget row
			$t->addColumn('category_id', 'bigint', ['notnull' => false]);
			$t->addColumn('planned_minor', 'bigint', ['notnull' => true]);
			$t->addColumn('updated_by', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'bc_bud_pk');
			// Uniqueness on (workspace, year_month, category_id) is enforced in the service
			// because some DBs treat NULL in unique indexes inconsistently. We provide a
			// covering index for fast lookup either way.
			$t->addIndex(['workspace_id', 'year_month'], 'bc_bud_ws_ym_idx');
			$t->addIndex(['workspace_id', 'category_id'], 'bc_bud_ws_cat_idx');
		}

		if (!$schema->hasTable('bc_budget_defaults')) {
			$t = $schema->createTable('bc_budget_defaults');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('workspace_id', 'bigint', ['notnull' => true]);
			$t->addColumn('category_id', 'bigint', ['notnull' => true]);
			$t->addColumn('planned_minor', 'bigint', ['notnull' => true]);
			$t->addColumn('updated_by', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'bc_buddef_pk');
			$t->addUniqueIndex(['workspace_id', 'category_id'], 'bc_buddef_ws_cat_uidx');
			$t->addIndex(['workspace_id'], 'bc_buddef_ws_idx');
		}

		if (!$schema->hasTable('bc_savings_targets')) {
			$t = $schema->createTable('bc_savings_targets');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('workspace_id', 'bigint', ['notnull' => true]);
			$t->addColumn('year_month', 'string', ['length' => 7, 'notnull' => true]);
			$t->addColumn('target_mode', 'string', ['length' => 16, 'notnull' => true]);
			$t->addColumn('target_percent_bp', 'integer', ['notnull' => false]);
			$t->addColumn('target_minor', 'bigint', ['notnull' => false]);
			$t->addColumn('updated_by', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'bc_sav_pk');
			$t->addUniqueIndex(['workspace_id', 'year_month'], 'bc_sav_ws_ym_uidx');
		}

		if (!$schema->hasTable('bc_monthly_snapshots')) {
			$t = $schema->createTable('bc_monthly_snapshots');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('workspace_id', 'bigint', ['notnull' => true]);
			$t->addColumn('year_month', 'string', ['length' => 7, 'notnull' => true]);
			$t->addColumn('snapshot_json', 'text', ['notnull' => true]);
			$t->addColumn('calc_hash', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('generated_by', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('generated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'bc_msnap_pk');
			$t->addUniqueIndex(['workspace_id', 'year_month'], 'bc_snap_ws_ym_uidx');
		}

		if (!$schema->hasTable('bc_audit_log')) {
			$t = $schema->createTable('bc_audit_log');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('actor_user_id', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('action', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('object_type', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('object_id', 'string', ['length' => 128, 'notnull' => true]);
			$t->addColumn('workspace_id', 'bigint', ['notnull' => false]);
			$t->addColumn('details_json', 'text', ['notnull' => true]);
			$t->addColumn('ip_hash', 'string', ['length' => 64, 'notnull' => false]);
			$t->addColumn('user_agent_hash', 'string', ['length' => 64, 'notnull' => false]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'bc_audit_pk');
			$t->addIndex(['action', 'created_at'], 'bc_audit_action_idx');
			$t->addIndex(['actor_user_id'], 'bc_audit_actor_idx');
			$t->addIndex(['workspace_id', 'created_at'], 'bc_audit_ws_idx');
		}

		return $schema;
	}
}
