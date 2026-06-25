<?php

declare(strict_types=1);

/**
 * Tables and app-data paths included in pre-update upgrade backups.
 *
 * SPDX-FileCopyrightText: 2026 Nextcloud DB-Standards (auto-generated)
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Regenerate via:
 *     php scripts/sync-upgrade-backup.php --app=budgetcheck
 */
namespace OCA\BudgetCheck\Service;

final class UpgradeBackupCatalog
{
	public const APP_ID = 'budgetcheck';

	public const FORMAT_VERSION = 1;

	public const APPDATA_ROOT = 'upgrade-backups';

	/** @var list<string> App-data folder names (under appdata_<instance>/budgetcheck/) to include in snapshots. */
	public const APPDATA_FOLDERS = [

	];

	public const CONFIG_MAX_SNAPSHOTS = 'upgrade_backup_max_snapshots';

	public const CONFIG_LAST_SNAPSHOT_ID = 'upgrade_backup_last_snapshot_id';

	public const DEFAULT_MAX_SNAPSHOTS = 5;

	public const MAX_SNAPSHOTS_LIMIT = 20;

	/** @var list<string> */
	public const BACKUP_TABLES = [
		'bc_audit_log',
		'bc_booking_statuses',
		'bc_budget_defaults',
		'bc_budgets',
		'bc_categories',
		'bc_monthly_snapshots',
		'bc_recurring_rules',
		'bc_savings_targets',
		'bc_transactions',
		'bc_workspace_groups',
		'bc_workspace_members',
		'bc_workspaces',
	];

	/** @var list<string> */
	public const RESTORE_TABLE_ORDER = [
		'bc_audit_log',
		'bc_booking_statuses',
		'bc_budget_defaults',
		'bc_budgets',
		'bc_categories',
		'bc_monthly_snapshots',
		'bc_recurring_rules',
		'bc_savings_targets',
		'bc_transactions',
		'bc_workspace_groups',
		'bc_workspace_members',
		'bc_workspaces',
	];

	public static function isBackupTable(string $table): bool
	{
		return in_array($table, self::BACKUP_TABLES, true);
	}

	public static function clampMaxSnapshots(int $requested): int
	{
		return max(1, min(self::MAX_SNAPSHOTS_LIMIT, $requested));
	}

	/**
	 * @return list<string>
	 */
	public static function existingBackupTables(callable $tableExists): array
	{
		$existing = [];
		foreach (self::BACKUP_TABLES as $table) {
			if ($tableExists($table)) {
				$existing[] = $table;
			}
		}

		return $existing;
	}

	/**
	 * @return list<string>
	 */
	public static function sortedRestoreTables(array $presentTables): array
	{
		$present = array_fill_keys($presentTables, true);
		$ordered = [];
		foreach (self::RESTORE_TABLE_ORDER as $table) {
			if (isset($present[$table])) {
				$ordered[] = $table;
			}
		}

		foreach ($presentTables as $table) {
			if (!in_array($table, $ordered, true)) {
				$ordered[] = $table;
			}
		}

		return $ordered;
	}
}
