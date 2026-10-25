<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Alert dedupe + audit log (spec: alert-notifications).
 *
 * One row per fired alert: (user_id, workspace_id, alert_type, dedupe_key) is
 * unique, so "insert-then-send" is the atomic dedupe primitive — a duplicate
 * insert means another evaluation already claimed this alert and the send is
 * skipped (NN-02 of the spec).
 */
class Version1026Date20261026100000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('bc_alert_log')) {
			$t = $schema->createTable('bc_alert_log');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('workspace_id', 'bigint', ['notnull' => true]);
			$t->addColumn('user_id', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('alert_type', 'string', ['length' => 24, 'notnull' => true]);
			$t->addColumn('dedupe_key', 'string', ['length' => 80, 'notnull' => true]);
			$t->addColumn('subject', 'string', ['length' => 255, 'notnull' => true, 'default' => '']);
			$t->addColumn('link_route', 'string', ['length' => 255, 'notnull' => false]);
			$t->addColumn('fired_at', 'datetime', ['notnull' => true]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'bc_alertlog_pk');
			$t->addUniqueIndex(
				['user_id', 'workspace_id', 'alert_type', 'dedupe_key'],
				'bc_alertlog_dedupe_uq',
			);
			$t->addIndex(['workspace_id', 'fired_at'], 'bc_alertlog_ws_idx');
		}

		return $schema;
	}
}
