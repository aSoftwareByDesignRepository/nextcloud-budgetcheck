<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud DB-Standards (auto-generated)
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Tables that must exist after budgetcheck's current migrations ran —
 * every table ever created minus those intentionally dropped by later
 * migrations. Schema-repair (Ensure*Schema) and schema-readiness probes
 * use EXPECTED_TABLES; uninstall drop lists use UninstallDropTables::TABLES
 * (which deliberately keeps dropped legacy tables for complete cleanup).
 *
 * Regenerate via:
 *     php scripts/check-nextcloud-db-standards.php sync-uninstall --app=budgetcheck
 */
namespace OCA\BudgetCheck\Repair;

final class SchemaTableCatalog
{
	/** @var list<string> */
	public const EXPECTED_TABLES = [
		'bc_alert_log',
		'bc_audit_log',
		'bc_booking_statuses',
		'bc_budget_defaults',
		'bc_budgets',
		'bc_categories',
		'bc_category_rules',
		'bc_idempotency',
		'bc_monthly_snapshots',
		'bc_payees',
		'bc_recurring_rules',
		'bc_savings_targets',
		'bc_transactions',
		'bc_tx_attachments',
		'bc_workspace_groups',
		'bc_workspace_members',
		'bc_workspaces',
	];

	/** @var list<string> tables dropped intentionally by later migrations */
	public const DROPPED_LEGACY_TABLES = [
		'bc_mobile_push',
	];
}
