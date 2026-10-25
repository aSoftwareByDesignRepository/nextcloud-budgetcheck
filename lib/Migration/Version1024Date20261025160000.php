<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Drop bc_mobile_push: the push-token registry is removed with the push feature
 * (no FCM sender exists or will ever exist). Idempotent — fresh installs never
 * see the table only when running 1020+; either way this leaves no table.
 */
class Version1024Date20261025160000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('bc_mobile_push')) {
			$schema->dropTable('bc_mobile_push');
		}

		return $schema;
	}
}
