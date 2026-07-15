<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Irregular schedules for recurring rules (issue #11).
 *
 * Rules with `frequency = 'schedule'` store their explicit occurrence list in
 * `schedule_json`: a JSON array of `{date: "YYYY-MM-DD", amountMinor: int|null}`
 * entries, sorted ascending, unique dates, at most 60 entries. `start_date`,
 * `end_date` and `next_due_date` remain derived from the list so all existing
 * generate / match / close machinery keeps working unchanged. Interval-based
 * rules keep the column NULL.
 */
class Version1018Date20260715150000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('bc_recurring_rules')) {
			$table = $schema->getTable('bc_recurring_rules');
			if (!$table->hasColumn('schedule_json')) {
				$table->addColumn('schedule_json', 'text', ['notnull' => false]);
			}
		}

		return $schema;
	}
}
