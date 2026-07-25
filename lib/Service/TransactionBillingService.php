<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

use OCA\BudgetCheck\Exception\AccessDeniedException;
use OCA\BudgetCheck\Public\BillableItem;
use OCA\BudgetCheck\Util\BillingStatus;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Billable settlement transitions for InvoiceCheck — never writes via HTTP.
 *
 * Only rows with is_billable=true participate. Planned / soft-deleted rows are excluded.
 */
class TransactionBillingService
{
	public const MAX_BULK_ROWS = 500;

	public function __construct(
		private IDBConnection $db,
		private AccessControlService $access,
		private ITimeFactory $timeFactory,
		private AuditLogService $audit,
	) {
	}

	/**
	 * @return list<int>
	 */
	public function listAccessibleWorkspaceIds(string $actorUid): array
	{
		return array_values(array_map('intval', $this->access->workspacesForUser($actorUid)));
	}

	/**
	 * @param array{
	 *   workspaceId?: int,
	 *   dateFrom?: string,
	 *   dateTo?: string,
	 *   direction?: string,
	 *   limit?: int
	 * } $filters
	 * @return list<BillableItem>
	 */
	public function listBillableOpen(string $actorUid, array $filters = []): array
	{
		$allowed = $this->access->workspacesForUser($actorUid);
		if ($allowed === []) {
			return [];
		}

		$workspaceFilter = isset($filters['workspaceId']) ? (int) $filters['workspaceId'] : 0;
		if ($workspaceFilter > 0) {
			if (!in_array($workspaceFilter, $allowed, true)) {
				throw new AccessDeniedException();
			}
			$allowed = [$workspaceFilter];
		}

		$limit = min(self::MAX_BULK_ROWS, max(1, (int) ($filters['limit'] ?? self::MAX_BULK_ROWS)));
		$direction = (string) ($filters['direction'] ?? TransactionService::DIRECTION_EXPENSE);
		if (!in_array($direction, [TransactionService::DIRECTION_EXPENSE, TransactionService::DIRECTION_INCOME, 'any'], true)) {
			$direction = TransactionService::DIRECTION_EXPENSE;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('t.*', 'w.currency_code')
			->from('bc_transactions', 't')
			->innerJoin('t', 'bc_workspaces', 'w', $qb->expr()->eq('t.workspace_id', 'w.id'))
			->where($qb->expr()->in('t.workspace_id', $qb->createNamedParameter($allowed, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('t.is_billable', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('t.billing_status', $qb->createNamedParameter(BillingStatus::OPEN)))
			->andWhere($qb->expr()->isNull('t.deleted_at'))
			->andWhere($qb->expr()->eq('t.is_planned', $qb->createNamedParameter(false, \PDO::PARAM_BOOL)));

		if ($direction !== 'any') {
			$qb->andWhere($qb->expr()->eq('t.direction', $qb->createNamedParameter($direction)));
		}
		if (!empty($filters['dateFrom'])) {
			$qb->andWhere($qb->expr()->gte('t.booking_date', $qb->createNamedParameter((string) $filters['dateFrom'])));
		}
		if (!empty($filters['dateTo'])) {
			$qb->andWhere($qb->expr()->lte('t.booking_date', $qb->createNamedParameter((string) $filters['dateTo'])));
		}

		$qb->orderBy('t.booking_date', 'ASC')
			->addOrderBy('t.id', 'ASC')
			->setMaxResults($limit);

		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$out[] = $this->mapRow($row);
		}
		$result->closeCursor();
		return $out;
	}

	/**
	 * @param list<int> $itemIds
	 * @return list<BillableItem>
	 */
	public function getItemsByIds(string $actorUid, array $itemIds, bool $trustedSiblingApp = false): array
	{
		$rows = $this->loadRowsByIds($itemIds);
		$out = [];
		foreach ($rows as $row) {
			$workspaceId = (int) $row['workspace_id'];
			if (!$trustedSiblingApp) {
				try {
					$this->access->ensureMembership($workspaceId, $actorUid);
				} catch (AccessDeniedException) {
					continue;
				}
			}
			$out[] = $this->mapRow($row);
		}
		return $out;
	}

	/**
	 * Mark transactions billable (contributor+). Opt-in only — default false.
	 */
	public function setBillable(string $actorUid, int $transactionId, bool $billable): void
	{
		$row = $this->loadRow($transactionId);
		if ($row === null) {
			throw new AccessDeniedException();
		}
		$workspaceId = (int) $row['workspace_id'];
		$this->access->ensureMinimumRole($workspaceId, $actorUid, AccessControlService::ROLE_CONTRIBUTOR);

		if (($row['billing_status'] ?? BillingStatus::OPEN) === BillingStatus::INVOICED && !$billable) {
			throw new AccessDeniedException();
		}

		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_transactions')
			->set('is_billable', $qb->createNamedParameter($billable, \PDO::PARAM_BOOL))
			->set('updated_at', $qb->createNamedParameter($now))
			->set('updated_by', $qb->createNamedParameter($actorUid))
			->set('version', $qb->createNamedParameter(((int) $row['version']) + 1, \PDO::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($transactionId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('version', $qb->createNamedParameter((int) $row['version'], \PDO::PARAM_INT)))
			->executeStatement();

		$this->audit->record($actorUid, 'transaction.billable', 'transaction', (string) $transactionId, [
			'isBillable' => $billable,
		], $workspaceId);
	}

	/**
	 * @param list<int> $itemIds
	 * @param array<int, string> $expectedUpdatedAt
	 * @return array{applied: int, failed: list<array{id: int, reason: string}>}
	 */
	public function bulkChangeStatusByIds(
		array $itemIds,
		string $target,
		string $actorUid,
		bool $trustedSiblingApp,
		array $expectedUpdatedAt = [],
		string $expectedSource = '',
		bool $alreadyAtTargetIsOk = true,
		bool $requireFullSuccess = true,
	): array {
		if (!BillingStatus::isValid($target)) {
			throw new \InvalidArgumentException('Invalid billing status');
		}

		$itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0)));
		sort($itemIds);
		if ($itemIds === []) {
			return ['applied' => 0, 'failed' => []];
		}
		if (count($itemIds) > self::MAX_BULK_ROWS) {
			return ['applied' => 0, 'failed' => [['id' => 0, 'reason' => 'too_many']]];
		}

		$rows = $this->loadRowsByIds($itemIds);
		$byId = [];
		foreach ($rows as $row) {
			$byId[(int) $row['id']] = $row;
		}

		$failed = [];
		$toApply = [];
		foreach ($itemIds as $id) {
			$row = $byId[$id] ?? null;
			if ($row === null) {
				$failed[] = ['id' => $id, 'reason' => 'not_found'];
				continue;
			}
			if (!empty($row['deleted_at'])) {
				$failed[] = ['id' => $id, 'reason' => 'deleted'];
				continue;
			}
			if (!(bool) $row['is_billable']) {
				$failed[] = ['id' => $id, 'reason' => 'not_billable'];
				continue;
			}
			if (!empty($row['is_planned'])) {
				$failed[] = ['id' => $id, 'reason' => 'planned'];
				continue;
			}

			$workspaceId = (int) $row['workspace_id'];
			if (!$trustedSiblingApp) {
				try {
					$this->access->ensureMinimumRole($workspaceId, $actorUid, AccessControlService::ROLE_CONTRIBUTOR);
				} catch (AccessDeniedException) {
					$failed[] = ['id' => $id, 'reason' => 'forbidden'];
					continue;
				}
			}

			$status = (string) ($row['billing_status'] ?? BillingStatus::OPEN);
			if ($status === $target && $alreadyAtTargetIsOk) {
				continue;
			}
			if ($expectedSource !== '' && $status !== $expectedSource) {
				$failed[] = ['id' => $id, 'reason' => 'invalid_transition'];
				continue;
			}
			if (isset($expectedUpdatedAt[$id])) {
				$expected = (string) $expectedUpdatedAt[$id];
				$actual = (string) $row['updated_at'];
				if ($expected !== '' && $actual !== $expected) {
					$failed[] = ['id' => $id, 'reason' => 'conflict_updated_at'];
					continue;
				}
			}
			$toApply[] = $id;
		}

		if ($requireFullSuccess && $failed !== []) {
			return ['applied' => 0, 'failed' => $failed];
		}

		$applied = 0;
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		foreach ($toApply as $id) {
			$row = $byId[$id];
			$qb = $this->db->getQueryBuilder();
			$affected = $qb->update('bc_transactions')
				->set('billing_status', $qb->createNamedParameter($target))
				->set('updated_at', $qb->createNamedParameter($now))
				->set('updated_by', $qb->createNamedParameter($actorUid))
				->set('version', $qb->createNamedParameter(((int) $row['version']) + 1, \PDO::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)))
				->andWhere($qb->expr()->eq('version', $qb->createNamedParameter((int) $row['version'], \PDO::PARAM_INT)))
				->andWhere($qb->expr()->eq('billing_status', $qb->createNamedParameter((string) $row['billing_status'])))
				->executeStatement();
			if ($affected === 1) {
				$applied++;
				$this->audit->record($actorUid, 'transaction.billing_status', 'transaction', (string) $id, [
					'from' => $row['billing_status'],
					'to' => $target,
				], (int) $row['workspace_id']);
			} else {
				$failed[] = ['id' => $id, 'reason' => 'conflict_version'];
			}
		}

		if ($requireFullSuccess && $failed !== []) {
			return ['applied' => $applied, 'failed' => $failed];
		}

		return ['applied' => $applied, 'failed' => $failed];
	}

	/**
	 * @param list<int> $itemIds
	 * @return list<array<string, mixed>>
	 */
	private function loadRowsByIds(array $itemIds): array
	{
		$itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0)));
		if ($itemIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('t.*', 'w.currency_code')
			->from('bc_transactions', 't')
			->innerJoin('t', 'bc_workspaces', 'w', $qb->expr()->eq('t.workspace_id', 'w.id'))
			->where($qb->expr()->in('t.id', $qb->createNamedParameter($itemIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
		$result = $qb->executeQuery();
		$rows = [];
		while ($row = $result->fetch()) {
			$rows[] = $row;
		}
		$result->closeCursor();
		return $rows;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function loadRow(int $id): ?array
	{
		$rows = $this->loadRowsByIds([$id]);
		return $rows[0] ?? null;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function mapRow(array $row): BillableItem
	{
		return new BillableItem(
			(int) $row['id'],
			(int) $row['workspace_id'],
			(string) $row['booking_date'],
			(string) $row['direction'],
			(int) $row['amount_minor'],
			(string) ($row['currency_code'] ?? 'EUR'),
			(string) ($row['title'] ?? ''),
			isset($row['notes']) && $row['notes'] !== null && $row['notes'] !== '' ? (string) $row['notes'] : null,
			isset($row['category_id']) && $row['category_id'] !== null ? (int) $row['category_id'] : null,
			isset($row['vat_rate_bp']) && $row['vat_rate_bp'] !== null ? (int) $row['vat_rate_bp'] : null,
			isset($row['net_amount_minor']) && $row['net_amount_minor'] !== null ? (int) $row['net_amount_minor'] : null,
			isset($row['gross_amount_minor']) && $row['gross_amount_minor'] !== null ? (int) $row['gross_amount_minor'] : null,
			(string) ($row['billing_status'] ?? BillingStatus::OPEN),
			(string) $row['updated_at'],
			(int) $row['version'],
		);
	}
}
