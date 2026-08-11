<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Workspace confidentiality mode (product ACL).
 *
 * `privacy_mode` values: `standard` (app-admin break-glass applies) or `private`
 * (membership-only; admins without an individual membership row cannot see or manage).
 * Existing rows default to `standard` for backward-compatible ops behaviour.
 */
class Version1021Date20260811120000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('bc_workspaces')) {
			$table = $schema->getTable('bc_workspaces');
			if (!$table->hasColumn('privacy_mode')) {
				$table->addColumn('privacy_mode', Types::STRING, [
					'notnull' => true,
					'length' => 16,
					'default' => 'standard',
				]);
			}
			if (!$table->hasIndex('bc_ws_privacy_idx')) {
				$table->addIndex(['privacy_mode'], 'bc_ws_privacy_idx');
			}
		}

		return $schema;
	}
}
