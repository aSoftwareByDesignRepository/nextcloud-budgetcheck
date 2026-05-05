<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Rename workspace column exceeding Nextcloud portability limits (Oracle / schema checks).
 *
 * @see MigrationService::ensureOracleConstraints maximum identifier length comments
 */
class Version1005Date20260506210000 extends SimpleMigrationStep
{
	private const LOGICAL_TABLE_WSP = 'bc_workspaces';

	private const OLD_COLUMN = 'auto_copy_budgets_from_previous_month';

	private const NEW_COLUMN = 'auto_copy_prev_month';

	public function __construct(
		private IDBConnection $db,
		private IConfig $config,
	) {
	}

	/**
	 * Reads the configured table prefix from system config. The public
	 * `IDBConnection` interface does not expose `getPrefix()`, so calling
	 * it on the injected `ConnectionAdapter` would fail at runtime.
	 */
	private function tablePrefix(): string
	{
		return (string)$this->config->getSystemValue('dbtableprefix', 'oc_');
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		return null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		if (!$this->db->tableExists(self::LOGICAL_TABLE_WSP)) {
			return;
		}
		if ($this->selectColumnFails(self::LOGICAL_TABLE_WSP, self::OLD_COLUMN)) {
			return;
		}
		if (!$this->selectColumnFails(self::LOGICAL_TABLE_WSP, self::NEW_COLUMN)) {
			$output->info('BudgetCheck: column ' . self::NEW_COLUMN . ' already exists; skipping legacy rename.');
			return;
		}

		$prefix = $this->tablePrefix();
		$table = $prefix . self::LOGICAL_TABLE_WSP;
		$provider = $this->db->getDatabaseProvider();

		if ($provider === IDBConnection::PLATFORM_MYSQL) {
			$this->db->executeStatement(
				sprintf(
					'ALTER TABLE `%s` RENAME COLUMN `%s` TO `%s`',
					$table,
					self::OLD_COLUMN,
					self::NEW_COLUMN
				)
			);
		} elseif ($provider === IDBConnection::PLATFORM_ORACLE) {
			$this->db->executeStatement(
				sprintf(
					'ALTER TABLE %s RENAME COLUMN %s TO %s',
					$table,
					self::OLD_COLUMN,
					self::NEW_COLUMN
				)
			);
		} elseif ($provider === IDBConnection::PLATFORM_POSTGRES) {
			$this->db->executeStatement(
				sprintf(
					'ALTER TABLE "%s" RENAME COLUMN "%s" TO "%s"',
					$table,
					self::OLD_COLUMN,
					self::NEW_COLUMN
				)
			);
		} elseif ($provider === IDBConnection::PLATFORM_SQLITE) {
			$this->db->executeStatement(
				sprintf(
					'ALTER TABLE "%s" RENAME COLUMN "%s" TO "%s"',
					$table,
					self::OLD_COLUMN,
					self::NEW_COLUMN
				)
			);
		}

		$output->info('BudgetCheck: renamed ' . self::OLD_COLUMN . ' to ' . self::NEW_COLUMN . ' on bc_workspaces.');
	}

	private function selectColumnFails(string $logicalTable, string $column): bool
	{
		if (!preg_match('/^[a-z0-9_]+$/', $column)) {
			return true;
		}

		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select($column)->from($logicalTable)->setMaxResults(1);
			$rs = $qb->executeQuery();
			$rs->fetch();
			$rs->closeCursor();
			return false;
		} catch (\Throwable $e) {
			return true;
		}
	}
}
