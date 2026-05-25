<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Repair;

use OC\DB\MigrationService;
use OCA\BudgetCheck\Migration\BudgetCheckTableCatalog;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Safety net when migrations were marked complete without creating every table
 * (partial install, restored backup, or manual DB edits).
 *
 * Runs on fresh install ({@see info.xml} install repair) and on every upgrade
 * (post-migration repair) so the schema is verified even when post-migration
 * repair does not run on first enable.
 */
final class EnsureBudgetCheckSchema implements IRepairStep
{
	public function __construct(
		private readonly IDBConnection $connection,
	) {
	}

	public function getName(): string
	{
		return 'Ensure BudgetCheck database schema is complete';
	}

	public function run(IOutput $output): void
	{
		$missingBefore = $this->missingTables();
		if ($missingBefore === []) {
			$output->info('BudgetCheck: all ' . count(BudgetCheckTableCatalog::TABLES) . ' tables are present.');
			return;
		}

		$output->info(sprintf(
			'BudgetCheck: %d table(s) missing (%s); running pending migrations.',
			count($missingBefore),
			implode(', ', $missingBefore),
		));

		$migrationService = new MigrationService(
			BudgetCheckTableCatalog::APP_ID,
			$this->connection,
		);
		$migrationService->migrate('latest', false);

		$missingAfter = $this->missingTables();
		if ($missingAfter === []) {
			$output->info('BudgetCheck: schema repair completed; all tables are now present.');
			return;
		}

		throw new \RuntimeException(sprintf(
			'BudgetCheck schema is still incomplete after migrate("latest"). Missing: %s. '
			. 'Run `occ upgrade` or re-enable the app and check the log.',
			implode(', ', $missingAfter),
		));
	}

	/**
	 * @return list<string>
	 */
	private function missingTables(): array
	{
		$missing = [];
		foreach (BudgetCheckTableCatalog::TABLES as $table) {
			if (!$this->connection->tableExists($table)) {
				$missing[] = $table;
			}
		}
		return $missing;
	}
}
