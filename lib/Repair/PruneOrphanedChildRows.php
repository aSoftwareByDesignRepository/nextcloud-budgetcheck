<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Repair;

use OCA\BudgetCheck\Service\WorkspaceDeletionService;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Removes workspace-scoped rows whose parent workspace no longer exists.
 *
 * The schema intentionally has no foreign keys; workspace deletion cascades
 * {@see WorkspaceDeletionService::CHILD_TABLES} under the workspace row lock.
 * Mutators that ran without that lock (fixed in the write-lock hardening
 * round) could leave orphan rows behind; this step prunes them idempotently
 * on every upgrade.
 */
final class PruneOrphanedChildRows implements IRepairStep
{
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	public function getName(): string
	{
		return 'Remove BudgetCheck rows whose workspace no longer exists';
	}

	public function run(IOutput $output): void
	{
		$total = 0;
		foreach (WorkspaceDeletionService::CHILD_TABLES as $table) {
			$sub = $this->db->getQueryBuilder();
			$sub->select('id')->from('bc_workspaces');
			$qb = $this->db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->notIn('workspace_id', $qb->createFunction($sub->getSQL())));
			$total += $qb->executeStatement();
		}
		if ($total === 0) {
			$output->info('BudgetCheck: no orphaned rows found.');
		} else {
			$output->info(sprintf('BudgetCheck: removed %d orphaned row(s).', $total));
		}
	}
}
