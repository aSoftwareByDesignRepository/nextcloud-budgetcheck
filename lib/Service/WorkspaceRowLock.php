<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

use OCP\IDBConnection;

/**
 * Exclusive workspace row lock for close/write serialization.
 *
 * Callers MUST already be inside an open DB transaction. Holding this lock
 * while re-checking {@see bc_monthly_snapshots} closes the TOCTOU window
 * between "month is open" and a concurrent monthly close.
 */
final class WorkspaceRowLock
{
	public static function acquire(IDBConnection $db, int $workspaceId): void
	{
		if ($workspaceId < 1) {
			throw new \InvalidArgumentException('Invalid workspace.');
		}
		$qb = $db->getQueryBuilder();
		$qb->select('id')
			->from('bc_workspaces')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->forUpdate();
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if ($row === false) {
			throw new \InvalidArgumentException('Workspace not found.');
		}
	}
}
