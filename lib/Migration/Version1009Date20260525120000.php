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
 * Shorten the household default savings percent column to stay within the 30-char
 * identifier portability ceiling (Oracle / schema tooling).
 */
class Version1009Date20260525120000 extends SimpleMigrationStep
{
	private const LOGICAL_TABLE = 'bc_workspaces';

	public function __construct(
		private IDBConnection $db,
		private IConfig $config,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable(self::LOGICAL_TABLE)) {
			return $schema;
		}

		$table = $schema->getTable(self::LOGICAL_TABLE);
		$legacy = BudgetCheckTableCatalog::COL_DEF_SAV_TGT_PCT_BP_LEGACY;
		$target = BudgetCheckTableCatalog::COL_DEF_SAV_TGT_PCT_BP;

		if (!$table->hasColumn($target) && !$table->hasColumn($legacy)) {
			$table->addColumn($target, 'integer', ['notnull' => false]);
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		if (!$this->db->tableExists(self::LOGICAL_TABLE)) {
			return;
		}
		if ($this->selectColumnFails(self::LOGICAL_TABLE, BudgetCheckTableCatalog::COL_DEF_SAV_TGT_PCT_BP_LEGACY)) {
			return;
		}
		if (!$this->selectColumnFails(self::LOGICAL_TABLE, BudgetCheckTableCatalog::COL_DEF_SAV_TGT_PCT_BP)) {
			$output->info('BudgetCheck: ' . BudgetCheckTableCatalog::COL_DEF_SAV_TGT_PCT_BP . ' already exists; skipping legacy rename.');
			return;
		}

		$prefix = (string)$this->config->getSystemValue('dbtableprefix', 'oc_');
		$table = $prefix . self::LOGICAL_TABLE;
		$legacy = BudgetCheckTableCatalog::COL_DEF_SAV_TGT_PCT_BP_LEGACY;
		$target = BudgetCheckTableCatalog::COL_DEF_SAV_TGT_PCT_BP;
		$provider = $this->db->getDatabaseProvider();

		if ($provider === IDBConnection::PLATFORM_MYSQL) {
			$this->db->executeStatement(sprintf(
				'ALTER TABLE `%s` RENAME COLUMN `%s` TO `%s`',
				$table,
				$legacy,
				$target,
			));
		} elseif ($provider === IDBConnection::PLATFORM_ORACLE) {
			$this->db->executeStatement(sprintf(
				'ALTER TABLE %s RENAME COLUMN %s TO %s',
				$table,
				$legacy,
				$target,
			));
		} elseif ($provider === IDBConnection::PLATFORM_POSTGRES) {
			$this->db->executeStatement(sprintf(
				'ALTER TABLE "%s" RENAME COLUMN "%s" TO "%s"',
				$table,
				$legacy,
				$target,
			));
		} elseif ($provider === IDBConnection::PLATFORM_SQLITE) {
			$this->db->executeStatement(sprintf(
				'ALTER TABLE "%s" RENAME COLUMN "%s" TO "%s"',
				$table,
				$legacy,
				$target,
			));
		}

		$output->info(sprintf('BudgetCheck: renamed %s to %s on bc_workspaces.', $legacy, $target));
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
		} catch (\Throwable) {
			return true;
		}
	}
}
