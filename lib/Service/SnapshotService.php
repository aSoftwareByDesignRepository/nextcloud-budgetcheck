<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

use OCA\BudgetCheck\Exception\WorkspaceTypeMismatchException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Monthly close + reopen evidence.
 *
 * - `close` produces an immutable JSON snapshot containing the full monthly
 *   summary for that month plus a SHA-256 `calc_hash` over a deterministic
 *   serialisation of the same numbers. Two close runs against the same data
 *   produce the same hash, which is what auditors need.
 * - `reopen` requires the manager role, removes the snapshot row and emits an
 *   audit entry. The ledger row remains intact (no destructive op runs on
 *   reopen).
 *
 * Project workspaces have no monthly close concept (§12.3); the controller
 * returns 422 for project IDs.
 */
class SnapshotService
{
	public function __construct(
		private IDBConnection $db,
		private AccessControlService $access,
		private WorkspaceService $workspaces,
		private SummaryService $summary,
		private ITimeFactory $timeFactory,
		private AuditLogService $audit,
	) {
	}

	public function close(int $workspaceId, string $userId, string $yearMonth): array
	{
		$workspace = $this->workspaces->getForUser($workspaceId, $userId);
		if ($workspace['type'] !== WorkspaceService::TYPE_HOUSEHOLD) {
			throw new WorkspaceTypeMismatchException('household', $workspace['type'], 'monthly_close');
		}
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_MANAGER);
		$ym = $this->validateYearMonth($yearMonth);

		// Block double-close racing two managers: rely on the unique index plus
		// a pre-flight check inside a transaction. We compute the summary
		// before opening the transaction because it issues read queries that
		// don't need to participate in the snapshot insert and would otherwise
		// hold locks longer than necessary.
		// Snapshots must stay canonical and user-independent, so monthly close
		// always uses the baseline planning view with specials excluded.
		$summary = $this->summary->household($workspaceId, $userId, $ym, false);
		$canonical = $this->canonicaliseForHash($summary);
		$hash = hash('sha256', $canonical);
		$now = $this->utcNow();
		$this->db->beginTransaction();
		try {
			if ($this->loadSnapshotRow($workspaceId, $ym) !== null) {
				$this->db->rollBack();
				throw new \InvalidArgumentException('Month is already closed.');
			}
			$qb = $this->db->getQueryBuilder();
			$qb->insert('bc_monthly_snapshots')
				->values([
					'workspace_id' => $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT),
					'year_month' => $qb->createNamedParameter($ym),
					'snapshot_json' => $qb->createNamedParameter(json_encode($summary, JSON_THROW_ON_ERROR)),
					'calc_hash' => $qb->createNamedParameter($hash),
					'generated_by' => $qb->createNamedParameter($userId),
					'generated_at' => $qb->createNamedParameter($now),
				]);
			$qb->executeStatement();
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
		$this->audit->record($userId, 'monthly_close', 'monthly_snapshot', $ym, ['hash' => $hash], $workspaceId);
		return [
			'workspaceId' => $workspaceId,
			'yearMonth' => $ym,
			'calcHash' => $hash,
			'generatedBy' => $userId,
		];
	}

	public function reopen(int $workspaceId, string $userId, string $yearMonth): array
	{
		$workspace = $this->workspaces->getForUser($workspaceId, $userId);
		if ($workspace['type'] !== WorkspaceService::TYPE_HOUSEHOLD) {
			throw new WorkspaceTypeMismatchException('household', $workspace['type'], 'monthly_reopen');
		}
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_MANAGER);
		$ym = $this->validateYearMonth($yearMonth);
		$existing = $this->loadSnapshotRow($workspaceId, $ym);
		if ($existing === null) {
			throw new \InvalidArgumentException('Month is not closed.');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete('bc_monthly_snapshots')
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$existing['id'], \PDO::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->record($userId, 'monthly_reopen', 'monthly_snapshot', $ym, ['hash' => (string)$existing['calc_hash']], $workspaceId);
		return ['workspaceId' => $workspaceId, 'yearMonth' => $ym, 'reopened' => true];
	}

	/**
	 * Build a stable, sorted representation of just the **financial fields** of
	 * the monthly summary so the SHA-256 hash is reproducible across PHP/JSON
	 * key ordering and unaffected by display strings (currency formatting,
	 * locale-dependent dates).
	 */
	private function canonicaliseForHash(array $summary): string
	{
		$totals = $summary['totals'] ?? [];
		$picked = [
			'workspaceId' => (int)($summary['workspace']['id'] ?? 0),
			'yearMonth' => (string)($summary['yearMonth'] ?? ''),
			'incomeMinor' => (int)($totals['income']['minor'] ?? 0),
			'expenseMinor' => (int)($totals['expense']['minor'] ?? 0),
			'netResultMinor' => (int)($totals['netResult']['minor'] ?? 0),
			'savingsTargetMinor' => (int)($totals['savingsTarget']['minor'] ?? 0),
			'savingsTransferredMinor' => (int)($totals['savingsTransferred']['minor'] ?? 0),
			'availableAfterSavingsMinor' => (int)($totals['availableAfterSavings']['minor'] ?? 0),
			'specialIncomeMinor' => (int)($totals['specialIncome']['minor'] ?? 0),
			'specialExpenseMinor' => (int)($totals['specialExpense']['minor'] ?? 0),
			'taxBasis' => $totals['taxBasis'] ?? null,
			'taxNetMinor' => (int)($totals['tax']['net']['minor'] ?? 0),
			'taxVatMinor' => (int)($totals['tax']['vat']['minor'] ?? 0),
			'taxGrossMinor' => (int)($totals['tax']['gross']['minor'] ?? 0),
			'budgetPlannedMinor' => (int)($summary['budget']['plannedTotal']['minor'] ?? 0),
			'budgetActualMinor' => (int)($summary['budget']['actualTotal']['minor'] ?? 0),
			'byCategory' => array_map(static fn ($row) => [
				'categoryId' => $row['categoryId'],
				'plannedMinor' => $row['planned']['minor'] ?? 0,
				'actualMinor' => $row['actual']['minor'] ?? 0,
			], $summary['budget']['byCategory'] ?? []),
		];
		// Stable sort the per-category rows by id so insertion order doesn't matter.
		usort($picked['byCategory'], static fn ($a, $b) => $a['categoryId'] <=> $b['categoryId']);
		return json_encode($picked, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
	}

	public function isMonthClosed(int $workspaceId, string $yearMonth): bool
	{
		$ym = trim($yearMonth);
		if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) {
			return false;
		}
		return $this->loadSnapshotRow($workspaceId, $ym) !== null;
	}

	private function loadSnapshotRow(int $workspaceId, string $yearMonth): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('bc_monthly_snapshots')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('year_month', $qb->createNamedParameter($yearMonth)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? null : $row;
	}

	private function validateYearMonth(string $value): string
	{
		$value = trim($value);
		if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
			throw new \InvalidArgumentException('yearMonth must be in YYYY-MM format with a valid month.');
		}
		return $value;
	}

	private function utcNow(): string
	{
		return $this->timeFactory->getDateTime('now', new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
	}
}
