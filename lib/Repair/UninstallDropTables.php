<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud DB-Standards (auto-generated)
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Drops every table the budgetcheck app has ever created, migration rows, and app config.
 *
 * Nextcloud runs this step on disable ({@see \OC\App\AppManager::disableApp}) and again on
 * remove ({@see \OC\Installer::removeApp}, {@see \OCA\Settings\Controller\AppSettingsController::uninstallApp}).
 * Disable (including auto-disable during a server upgrade) always preserves data; only an
 * explicit app removal drops tables.
 *
 * Regenerate table list via:
 *     php scripts/check-nextcloud-db-standards.php sync-uninstall --app=budgetcheck
 *
 * Uses `DROP TABLE IF EXISTS` (not SchemaWrapper) so IDBConnection injection works on
 * all Nextcloud versions. MySQL temporarily disables FK checks so legacy FK chains
 * (e.g. project_files → projects) cannot block uninstall.
 */
namespace OCA\BudgetCheck\Repair;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

final class UninstallDropTables implements IRepairStep
{
	public const APP_ID = 'budgetcheck';

	/**
	 * Legacy counter from the two-pass implementation. Cleared on disable and by schema
	 * repair steps so upgrades never inherit a stale value.
	 */
	public const REPAIR_PASS_KEY = 'uninstall_repair_pass';

	/**
	 * Sorted list of every table this app has ever created across all migrations.
	 * Kept in sync by the DB-standards linter.
	 */
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

	public function __construct(
		private readonly IDBConnection $connection,
		private readonly IConfig $config,
		private readonly IRootFolder $rootFolder,
	) {
	}

	public function getName(): string
	{
		return 'Drop budgetcheck tables and install metadata on uninstall';
	}

	public function run(IOutput $output): void
	{
		if (UninstallRepairFlow::isRemovalContext()) {
			$this->dropAllTablesAndMetadata($output);
			return;
		}

		// Disable path (manual or auto during server upgrade): idempotent — never drop.
		$this->config->deleteAppValue(self::APP_ID, self::REPAIR_PASS_KEY);
		$output->info(
			'budgetcheck: preserving data on disable. '
			. 'Tables, migration history, and settings are kept until the app is fully removed.'
		);
	}

	private function dropAllTablesAndMetadata(IOutput $output): void
	{
		$provider = $this->connection->getDatabaseProvider();
		$fkChecksDisabled = false;
		if ($provider === IDBConnection::PLATFORM_MYSQL) {
			$this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
			$fkChecksDisabled = true;
		}

		$dropped = 0;
		foreach (self::TABLES as $table) {
			if ($this->dropLogicalTableIfExists($table)) {
				$dropped++;
			}
		}

		if ($fkChecksDisabled) {
			$this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
		}

		$qb = $this->connection->getQueryBuilder();
		$qb->delete('migrations')
			->where($qb->expr()->eq('app', $qb->createNamedParameter(self::APP_ID)));
		$migrationsRemoved = $qb->executeStatement();

		$this->config->deleteAppValues(self::APP_ID);

		$this->purgeUpgradeBackupSnapshots($output);
		$this->purgeTransactionAttachmentFiles($output);

		$output->info(sprintf(
			'budgetcheck: dropped %d of %d table(s); removed %d migration row(s), app config, upgrade-backup snapshots, and receipt attachment files.',
			$dropped,
			count(self::TABLES),
			$migrationsRemoved,
		));
	}

	private function dropLogicalTableIfExists(string $logicalTable): bool
	{
		if (!$this->connection->tableExists($logicalTable)) {
			return false;
		}

		$prefix = (string)$this->config->getSystemValue('dbtableprefix', 'oc_');
		$physical = $prefix . $logicalTable;
		$provider = $this->connection->getDatabaseProvider();

		if ($provider === IDBConnection::PLATFORM_MYSQL) {
			$this->connection->executeStatement(sprintf('DROP TABLE IF EXISTS `%s`', $physical));
		} elseif ($provider === IDBConnection::PLATFORM_POSTGRES) {
			$this->connection->executeStatement(sprintf('DROP TABLE IF EXISTS "%s" CASCADE', $physical));
		} elseif ($provider === IDBConnection::PLATFORM_ORACLE) {
			$this->connection->executeStatement(sprintf('DROP TABLE %s CASCADE CONSTRAINTS', $physical));
		} elseif ($provider === IDBConnection::PLATFORM_SQLITE) {
			$this->connection->executeStatement(sprintf('DROP TABLE IF EXISTS "%s"', $physical));
		}

		return true;
	}

	/**
	 * Pre-update JSON snapshots contain full table exports — remove on explicit app removal.
	 */
	private function purgeUpgradeBackupSnapshots(IOutput $output): void
	{
		$instanceId = (string)$this->config->getSystemValue('instanceid', '');
		if ($instanceId === '') {
			return;
		}

		$path = 'appdata_' . $instanceId . '/' . self::APP_ID . '/upgrade-backups';
		try {
			$node = $this->rootFolder->get($path);
		} catch (NotFoundException) {
			return;
		}

		if (!$node instanceof Folder) {
			return;
		}

		$node->delete();
		$output->info('budgetcheck: removed upgrade-backup snapshots from app data.');
	}

	/**
	 * Receipt binaries live under appdata — drop them on explicit app removal so
	 * uninstall does not leave orphaned personal documents on disk.
	 */
	private function purgeTransactionAttachmentFiles(IOutput $output): void
	{
		$instanceId = (string)$this->config->getSystemValue('instanceid', '');
		if ($instanceId === '') {
			return;
		}

		$path = 'appdata_' . $instanceId . '/' . self::APP_ID . '/tx-attachments';
		try {
			$node = $this->rootFolder->get($path);
		} catch (NotFoundException) {
			return;
		}

		if (!$node instanceof Folder) {
			return;
		}

		$node->delete();
		$output->info('budgetcheck: removed transaction attachment files from app data.');
	}
}
