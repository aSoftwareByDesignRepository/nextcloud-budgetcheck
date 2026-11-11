<?php

declare(strict_types=1);

/**
 * BudgetCheck transfer manifest — every appconfig key and every
 * bc_* table classified exactly once
 * (gate: scripts/check-config-transfer-manifest.py).
 *
 * The 'transfer' settings section lives in
 * {@see \OCA\BudgetCheck\Service\AppSettingsSectionCatalog} (app settings,
 * admin-only) — BudgetCheck splits workspace settings from app settings,
 * so the section slug is registered there, not in a SettingsSectionCatalog.
 */

namespace OCA\BudgetCheck\ConfigTransfer;

final class TransferManifest extends AbstractTransferManifest
{
	private const SCHEMA_VERSION = 1;

	public function schemaVersion(): int
	{
		return self::SCHEMA_VERSION;
	}

	/** @return array<string,array> */
	public function keys(): array
	{
		return self::normaliseKeys([
			// ── access door / admin policy — written atomically through
			// AccessControlService::saveAppPolicy (never raw setAppValue) ──
			'access_restriction_enabled' => ['class' => 'mirrored', 'security' => true, 'section' => 'access'],
			'access_allowed_user_ids' => ['class' => 'mirrored', 'security' => true, 'refs' => ['kind' => 'uid_list_json'], 'section' => 'access'],
			'access_allowed_group_ids' => ['class' => 'mirrored', 'security' => true, 'refs' => ['kind' => 'gid_list_json'], 'section' => 'access'],
			'app_admin_user_ids' => ['class' => 'mirrored', 'security' => true, 'refs' => ['kind' => 'uid_list_json'], 'section' => 'admins'],
			// ── app defaults (app settings → Defaults) ──
			'default_timezone' => ['class' => 'plain', 'section' => 'defaults'],
			'default_currency' => ['class' => 'plain', 'section' => 'defaults'],
			// occ-managed display default (read-only legacy knob, honored at
			// runtime by LocaleFormatService — carried so a tuned test
			// instance keeps its locale default on prod).
			'default_locale' => ['class' => 'plain', 'section' => 'defaults'],
			// ── per-user app values (oc_preferences — instance-personal,
			// excluded per spec AS-05; declared so the gate sees them) ──
			'budgetcheck_last_workspace' => 'internal',
			'budgetcheck_favorite_workspaces' => 'internal',
			'alerts_enabled' => 'internal',
			'alerts_threshold_pct' => 'internal',
			'alerts_email_enabled' => 'internal',
			'alerts_quiet_start' => 'internal',
			'alerts_quiet_end' => 'internal',
			'alerts_cat_thresholds' => 'internal',
			'dashboard_widget_enabled' => 'internal',
			'dashboard_widget_workspace' => 'internal',
			// MobileHomeKpi response-field names — declared via KEY_* consts
			// but never written to appconfig; internal documents that.
			'available_after_savings' => 'internal',
			'spend_vs_cap' => 'internal',
			'spend_to_date' => 'internal',
			// ── internal bookkeeping — never transferred ──
			'audit_hash_salt' => 'internal',
			'uninstall_repair_pass' => 'internal',
			'upgrade_backup_last_snapshot_id' => 'internal',
			'upgrade_backup_max_snapshots' => 'internal',
			'transfer_receipts' => 'internal',
			'transfer_job_state' => 'internal',
			'transfer_last_import_at' => 'internal',
			// Dynamic/prefix key families — prefixes, not keys; members are
			// dynamic and never transferred by definition:
			//   'rate_limit:'            legacy per-user rate-limit counters
			//   'import_prefs_ws_'       per-user import preferences (user values)
			//   'summary_view_ws_'       per-user summary view prefs (user values)
			//   'alerts_type_'           per-user alert type toggles (user values)
			//   'alerts_cat_thresholds_' per-user category thresholds (user values)
			//   'upgrade_backup_*'       snapshot-restore rewrites restored keys
		]);
	}

	/** @return array<string,array> */
	public function tables(): array
	{
		return self::normaliseTables([
			// ══ workspaces + membership ══
			'bc_workspaces' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['name', 'created_by'],
				'uid_ref' => ['created_by']],
			'bc_workspace_members' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['workspace_id', 'user_id'],
				'fk' => ['workspace_id' => 'bc_workspaces'], 'depends_on' => ['bc_workspaces'],
				'uid_ref' => ['user_id']],
			'bc_workspace_groups' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['workspace_id', 'gid'],
				'fk' => ['workspace_id' => 'bc_workspaces'], 'depends_on' => ['bc_workspaces'],
				'gid_ref' => ['gid']],

			// ══ workspace-scoped reference + planning data ══
			'bc_categories' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['workspace_id', 'name', 'type'],
				'fk' => ['workspace_id' => 'bc_workspaces'], 'depends_on' => ['bc_workspaces'],
				'uid_ref' => ['created_by']],
			'bc_booking_statuses' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['workspace_id', 'name'],
				'fk' => ['workspace_id' => 'bc_workspaces'], 'depends_on' => ['bc_workspaces']],
			'bc_payees' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['workspace_id', 'name_norm'],
				'fk' => ['workspace_id' => 'bc_workspaces'], 'depends_on' => ['bc_workspaces'],
				'uid_ref' => ['created_by', 'updated_by']],
			'bc_category_rules' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['workspace_id', 'category_id', 'match_field', 'match_type', 'pattern', 'direction', 'priority'],
				'fk' => ['workspace_id' => 'bc_workspaces', 'category_id' => 'bc_categories'],
				'depends_on' => ['bc_workspaces', 'bc_categories'],
				'uid_ref' => ['created_by', 'updated_by']],
			'bc_budgets' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['workspace_id', 'year_month', 'category_id'],
				'fk' => ['workspace_id' => 'bc_workspaces', 'category_id' => 'bc_categories'],
				'depends_on' => ['bc_workspaces', 'bc_categories'],
				'uid_ref' => ['updated_by']],
			'bc_budget_defaults' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['workspace_id', 'category_id'],
				'fk' => ['workspace_id' => 'bc_workspaces', 'category_id' => 'bc_categories'],
				'depends_on' => ['bc_workspaces', 'bc_categories'],
				'uid_ref' => ['updated_by']],
			'bc_savings_targets' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['workspace_id', 'year_month'],
				'fk' => ['workspace_id' => 'bc_workspaces'], 'depends_on' => ['bc_workspaces'],
				'uid_ref' => ['updated_by']],
			'bc_recurring_rules' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['workspace_id', 'title', 'frequency', 'start_date'],
				'fk' => ['workspace_id' => 'bc_workspaces', 'category_id' => 'bc_categories'],
				'depends_on' => ['bc_workspaces', 'bc_categories'],
				'uid_ref' => ['created_by']],

			// ══ ledger data ══
			// Transactions have no honest natural key (legitimate duplicate
			// rows exist) — spec default for tables without one:
			// seed-if-empty on merge, full wipe+load on replace.
			'bc_transactions' => ['class' => 'business', 'strategy' => 'seed-if-empty',
				'fk' => [
					'workspace_id' => 'bc_workspaces',
					'category_id' => 'bc_categories',
					'booking_status_id' => 'bc_booking_statuses',
					'recurring_rule_id' => 'bc_recurring_rules',
					'budget_id' => 'bc_budgets',
					'payee_id' => 'bc_payees',
				],
				'depends_on' => [
					'bc_workspaces', 'bc_categories', 'bc_booking_statuses',
					'bc_recurring_rules', 'bc_budgets', 'bc_payees',
				],
				'uid_ref' => ['created_by', 'updated_by']],
			// Closed-month snapshots are the immutable close record — they
			// cannot be recomputed on the target (the close produces the
			// hash), so they are business data, not 'derived'.
			'bc_monthly_snapshots' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['workspace_id', 'year_month'],
				'fk' => ['workspace_id' => 'bc_workspaces'], 'depends_on' => ['bc_workspaces'],
				'uid_ref' => ['generated_by']],

			// ══ internal / log — NEVER transferred ══
			// Attachment rows reference files under
			// appdata_<instance>/budgetcheck/tx-attachments/<transaction_id>/ —
			// the blob path is keyed by the SOURCE transaction id, which the
			// engine remaps on apply; transferred rows would dangle.
			'bc_tx_attachments' => ['class' => 'internal'],
			'bc_idempotency' => ['class' => 'internal'],
			'bc_mobile_push' => ['class' => 'internal'], // dropped legacy table (push feature removed)
			'bc_alert_log' => ['class' => 'log'],
			'bc_audit_log' => ['class' => 'log'],
		]);
	}
}
