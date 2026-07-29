<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Migration;

/**
 * Canonical list of BudgetCheck database tables (logical names, without `oc_` prefix).
 *
 * Used by install/uninstall repair steps and DB-standards tooling. Keep in sync with
 * migrations and `php scripts/check-nextcloud-db-standards.php sync-uninstall --app=budgetcheck`.
 */
final class BudgetCheckTableCatalog
{
	public const APP_ID = 'budgetcheck';

	/** @var list<string> */
	public const TABLES = [
		'bc_audit_log',
		'bc_booking_statuses',
		'bc_budget_defaults',
		'bc_budgets',
		'bc_categories',
		'bc_idempotency',
		'bc_mobile_push',
		'bc_monthly_snapshots',
		'bc_recurring_rules',
		'bc_savings_targets',
		'bc_transactions',
		'bc_tx_attachments',
		'bc_workspace_groups',
		'bc_workspace_members',
		'bc_workspaces',
	];

	/** Short column name (≤30 chars) for household default savings percent basis points. */
	public const COL_DEF_SAV_TGT_PCT_BP = 'def_sav_tgt_pct_bp';

	/** Legacy column name replaced by {@see COL_DEF_SAV_TGT_PCT_BP}. */
	public const COL_DEF_SAV_TGT_PCT_BP_LEGACY = 'default_savings_target_percent_bp';
}
