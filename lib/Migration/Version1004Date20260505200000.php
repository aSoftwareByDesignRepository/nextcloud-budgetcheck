<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1004Date20260505200000 extends SimpleMigrationStep
{
	public function __construct(private IDBConnection $db)
	{
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		return $schemaClosure();
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		$now = gmdate('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_booking_statuses')
			->set('is_done', $qb->createNamedParameter(false, \PDO::PARAM_BOOL))
			->set('updated_at', $qb->createNamedParameter($now))
			->executeStatement();
	}
}

