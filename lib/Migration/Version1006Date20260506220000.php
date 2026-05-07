<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Drop Doctrine auto-named indexes on BudgetCheck tables.
 *
 * Why
 * ---
 * Some historical iterations of `bc_booking_statuses` left an auto-named index
 * `IDX_<hex>` behind on the `workspace_id` column. That name does not follow
 * the `bc_` namespace convention, would (in theory) collide with another app
 * that produces the same hash on PostgreSQL, and is anyway redundant: the
 * existing composite indexes already cover `workspace_id` queries.
 *
 * Strategy
 * --------
 * Iterate every BudgetCheck table in the live schema and drop any index whose
 * name does NOT start with `bc_` (excluding the primary key, which Doctrine
 * exposes as `primary` on PostgreSQL and `PRIMARY` on MySQL/MariaDB).
 *
 * Idempotent and data-safe: dropping a redundant index never affects rows.
 */
class Version1006Date20260506220000 extends SimpleMigrationStep
{
	private const APP_PREFIX = 'bc_';

	private const APP_TABLES = [
		'bc_workspaces',
		'bc_workspace_members',
		'bc_categories',
		'bc_transactions',
		'bc_recurring_rules',
		'bc_budgets',
		'bc_budget_defaults',
		'bc_savings_targets',
		'bc_monthly_snapshots',
		'bc_audit_log',
		'bc_booking_statuses',
	];

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		foreach (self::APP_TABLES as $tableName) {
			if (!$schema->hasTable($tableName)) {
				continue;
			}

			$table = $schema->getTable($tableName);
			foreach ($table->getIndexes() as $index) {
				$name = $index->getName();
				$lc = strtolower($name);

				// Never drop the primary key. Doctrine surfaces it as
				// `primary` (PostgreSQL convention).
				if ($lc === 'primary' || $index->isPrimary()) {
					continue;
				}

				if (str_starts_with($name, self::APP_PREFIX)) {
					continue;
				}

				$output->info(sprintf(
					'BudgetCheck: dropping non-namespaced index %s on %s.',
					$name,
					$tableName
				));
				$table->dropIndex($name);
			}
		}

		return $schema;
	}
}
