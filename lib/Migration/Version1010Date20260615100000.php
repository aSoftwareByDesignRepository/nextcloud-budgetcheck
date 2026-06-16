<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Group-based workspace membership.
 *
 * Adds `bc_workspace_groups`, the parallel of `bc_workspace_members` for
 * Nextcloud groups. A group can be granted the `viewer` or `contributor`
 * role in a workspace (never `manager`: managing a workspace stays tied to
 * accountable individual accounts, which also keeps the last-manager
 * invariant in {@see \OCA\BudgetCheck\Service\WorkspaceService} simple).
 *
 * Every user that belongs to such a group inherits the group's role, taking
 * the strongest of their individual role and any group role.
 *
 * Portable to MariaDB and PostgreSQL (only the OCP schema wrapper, no
 * DB-specific types or literals). Idempotent: guarded by hasTable.
 */
class Version1010Date20260615100000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('bc_workspace_groups')) {
			$t = $schema->createTable('bc_workspace_groups');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('workspace_id', 'bigint', ['notnull' => true]);
			$t->addColumn('gid', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('role', 'string', ['length' => 16, 'notnull' => true, 'default' => 'viewer']);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'bc_wsg_pk');
			$t->addUniqueIndex(['workspace_id', 'gid'], 'bc_ws_grp_uidx');
			$t->addIndex(['gid'], 'bc_ws_grp_gid_idx');
		}

		return $schema;
	}
}
