<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

use OCA\BudgetCheck\Exception\AccessDeniedException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

class BookingStatusService
{
	public function __construct(
		private IDBConnection $db,
		private AccessControlService $access,
		private WorkspaceService $workspaces,
		private ITimeFactory $timeFactory,
		private AuditLogService $audit,
	) {
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function listForWorkspace(int $workspaceId, string $userId, bool $includeInactive = false): array
	{
		$workspace = $this->workspaces->getForUser($workspaceId, $userId);
		$this->ensureProjectWorkspace($workspace);
		$this->ensureDefaultsExist($workspaceId);
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('bc_booking_statuses')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->orderBy('sort_order', 'ASC')
			->addOrderBy('name', 'ASC');
		if (!$includeInactive) {
			$qb->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)));
		}
		$res = $qb->executeQuery();
		$out = [];
		while ($row = $res->fetch()) {
			$out[] = $this->hydrate($row);
		}
		$res->closeCursor();
		return $out;
	}

	public function create(int $workspaceId, string $userId, array $payload): array
	{
		$workspace = $this->workspaces->getForUser($workspaceId, $userId);
		$this->ensureProjectWorkspace($workspace);
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_MANAGER);

		$name = $this->normaliseName((string)($payload['name'] ?? ''));
		$sort = $this->normaliseSort($payload['sortOrder'] ?? 100);
		$now = $this->utcNow();

		$qb = $this->db->getQueryBuilder();
		$qb->insert('bc_booking_statuses')
			->values([
				'workspace_id' => $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT),
				'name' => $qb->createNamedParameter($name),
				'sort_order' => $qb->createNamedParameter($sort, \PDO::PARAM_INT),
				// `is_done` is reserved for potential future semantics; currently unused.
				'is_done' => $qb->createNamedParameter(false, \PDO::PARAM_BOOL),
				'is_active' => $qb->createNamedParameter(true, \PDO::PARAM_BOOL),
				'created_at' => $qb->createNamedParameter($now),
				'updated_at' => $qb->createNamedParameter($now),
			]);
		$qb->executeStatement();
		$id = (int)$this->db->lastInsertId('bc_booking_statuses');
		$this->audit->record($userId, 'booking_status_created', 'booking_status', (string)$id, ['name' => $name], $workspaceId);
		return $this->loadForWorkspace($id, $workspaceId);
	}

	public function update(int $statusId, string $userId, array $payload): array
	{
		$row = $this->loadRow($statusId);
		if ($row === null) {
			throw new AccessDeniedException();
		}
		$workspaceId = (int)$row['workspace_id'];
		$workspace = $this->workspaces->getForUser($workspaceId, $userId);
		$this->ensureProjectWorkspace($workspace);
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_MANAGER);

		$updates = [];
		$changes = [];
		if (array_key_exists('name', $payload)) {
			$name = $this->normaliseName((string)$payload['name']);
			if ($name !== (string)$row['name']) {
				$updates['name'] = $name;
				$changes['name'] = $name;
			}
		}
		if (array_key_exists('sortOrder', $payload)) {
			$sort = $this->normaliseSort($payload['sortOrder']);
			if ($sort !== (int)$row['sort_order']) {
				$updates['sort_order'] = $sort;
				$changes['sortOrder'] = $sort;
			}
		}
		if ($updates === []) {
			$shouldNormalizeDone = array_key_exists('isDone', $payload);
			$needsNormalizeDone = $shouldNormalizeDone && (bool)($row['is_done'] ?? false) === true;
			if (!$needsNormalizeDone) {
				return $this->hydrate($row);
			}
		}
		// Booking status workflow semantics are intentionally not using `is_done`.
		// Normalize to `false` whenever we touch a status (metadata edit or stale client input).
		$updates['is_done'] = false;
		$updates['updated_at'] = $this->utcNow();
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_booking_statuses');
		foreach ($updates as $col => $value) {
			$type = match (true) {
				is_bool($value) => \PDO::PARAM_BOOL,
				is_int($value) => \PDO::PARAM_INT,
				default => \PDO::PARAM_STR,
			};
			$qb->set($col, $qb->createNamedParameter($value, $type));
		}
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($statusId, \PDO::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->record($userId, 'booking_status_updated', 'booking_status', (string)$statusId, $changes, $workspaceId);
		return $this->loadForWorkspace($statusId, $workspaceId);
	}

	public function deactivate(int $statusId, string $userId): array
	{
		$row = $this->loadRow($statusId);
		if ($row === null) {
			throw new AccessDeniedException();
		}
		$workspaceId = (int)$row['workspace_id'];
		$workspace = $this->workspaces->getForUser($workspaceId, $userId);
		$this->ensureProjectWorkspace($workspace);
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_MANAGER);

		$this->db->beginTransaction();
		try {
			$tx = $this->db->getQueryBuilder();
			$tx->update('bc_transactions')
				->set('booking_status_id', $tx->createNamedParameter(null, \PDO::PARAM_NULL))
				->set('updated_at', $tx->createNamedParameter($this->utcNow()))
				->set('updated_by', $tx->createNamedParameter($userId))
				->where($tx->expr()->eq('workspace_id', $tx->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
				->andWhere($tx->expr()->eq('booking_status_id', $tx->createNamedParameter($statusId, \PDO::PARAM_INT)))
				->andWhere($tx->expr()->isNull('deleted_at'));
			$tx->executeStatement();

			$qb = $this->db->getQueryBuilder();
			$qb->update('bc_booking_statuses')
				->set('is_active', $qb->createNamedParameter(false, \PDO::PARAM_BOOL))
				->set('is_done', $qb->createNamedParameter(false, \PDO::PARAM_BOOL))
				->set('updated_at', $qb->createNamedParameter($this->utcNow()))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($statusId, \PDO::PARAM_INT)));
			$qb->executeStatement();
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}

		$this->audit->record($userId, 'booking_status_deactivated', 'booking_status', (string)$statusId, [], $workspaceId);
		return $this->hydrate(array_merge($row, ['is_active' => false]));
	}

	public function loadActiveForWorkspace(int $statusId, int $workspaceId): ?array
	{
		$row = $this->loadRow($statusId);
		if ($row === null) {
			return null;
		}
		if ((int)$row['workspace_id'] !== $workspaceId || !(bool)$row['is_active']) {
			return null;
		}
		return $this->hydrate($row);
	}

	private function loadForWorkspace(int $statusId, int $workspaceId): array
	{
		$row = $this->loadRow($statusId);
		if ($row === null || (int)$row['workspace_id'] !== $workspaceId) {
			throw new AccessDeniedException();
		}
		return $this->hydrate($row);
	}

	private function loadRow(int $statusId): ?array
	{
		if ($statusId < 1) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('bc_booking_statuses')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($statusId, \PDO::PARAM_INT)));
		$res = $qb->executeQuery();
		$row = $res->fetch();
		$res->closeCursor();
		return $row === false ? null : $row;
	}

	private function ensureProjectWorkspace(array $workspace): void
	{
		if (($workspace['type'] ?? null) !== WorkspaceService::TYPE_PROJECT) {
			throw new \InvalidArgumentException('Booking statuses are available only for project workspaces.');
		}
	}

	private function normaliseName(string $name): string
	{
		$name = trim($name);
		if ($name === '') {
			throw new \InvalidArgumentException('name is required.');
		}
		if (mb_strlen($name) > 80) {
			throw new \InvalidArgumentException('name must be 80 characters or fewer.');
		}
		return $name;
	}

	private function normaliseSort(mixed $sort): int
	{
		$value = is_int($sort) ? $sort : (int)(is_string($sort) && ctype_digit(trim($sort)) ? trim($sort) : 100);
		return max(0, min(10000, $value));
	}

	private function hydrate(array $row): array
	{
		return [
			'id' => (int)$row['id'],
			'workspaceId' => (int)$row['workspace_id'],
			'name' => (string)$row['name'],
			'sortOrder' => (int)$row['sort_order'],
			'isActive' => (bool)$row['is_active'],
			'createdAt' => (string)$row['created_at'],
			'updatedAt' => (string)$row['updated_at'],
		];
	}

	private function utcNow(): string
	{
		return $this->timeFactory->getDateTime('now', new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
	}

	private function ensureDefaultsExist(int $workspaceId): void
	{
		$countQb = $this->db->getQueryBuilder();
		$countQb->select($countQb->func()->count('*', 'count'))
			->from('bc_booking_statuses')
			->where($countQb->expr()->eq('workspace_id', $countQb->createNamedParameter($workspaceId, \PDO::PARAM_INT)));
		$countRow = $countQb->executeQuery()->fetch();
		if ((int)($countRow['count'] ?? 0) > 0) {
			return;
		}

		$now = $this->utcNow();
		foreach ([
			['Open', 10, false],
			['In Progress', 20, false],
			['Blocked', 30, false],
			['Paid', 40, false],
		] as $default) {
			try {
				$ins = $this->db->getQueryBuilder();
				$ins->insert('bc_booking_statuses')
					->values([
						'workspace_id' => $ins->createNamedParameter($workspaceId, \PDO::PARAM_INT),
						'name' => $ins->createNamedParameter($default[0]),
						'sort_order' => $ins->createNamedParameter($default[1], \PDO::PARAM_INT),
						'is_done' => $ins->createNamedParameter($default[2], \PDO::PARAM_BOOL),
						'is_active' => $ins->createNamedParameter(true, \PDO::PARAM_BOOL),
						'created_at' => $ins->createNamedParameter($now),
						'updated_at' => $ins->createNamedParameter($now),
					]);
				$ins->executeStatement();
			} catch (\Throwable) {
				// Another request may have inserted the same defaults concurrently.
			}
		}
	}
}

