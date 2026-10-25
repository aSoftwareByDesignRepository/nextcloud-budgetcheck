<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

use OCA\BudgetCheck\Exception\AccessDeniedException;
use OCA\BudgetCheck\Exception\ConflictException;
use OCA\BudgetCheck\Support\StrictBool;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Normalized counterparty ("payee") entity per workspace.
 *
 * `name_norm` = lower(trim(name)) (NFC-normalized where intl is available) is
 * stored once and unique-indexed per workspace, so case/whitespace variants
 * ("ALDI SÜD" vs " aldi süd ") collapse into one row. Create is idempotent:
 * a norm-equal name returns the existing row — never a duplicate (AC-9 edge).
 *
 * Deletion is soft (`is_active = 0`): historical transactions keep rendering
 * the name through the payee join. Merge is the only destructive operation
 * and repoints transactions in one transaction.
 */
class PayeeService
{
	public function __construct(
		private IDBConnection $db,
		private AccessControlService $access,
		private ITimeFactory $timeFactory,
		private AuditLogService $audit,
	) {
	}

	/** NFC + lowercase + collapsed whitespace — the dedupe key for a workspace. */
	public static function normalizeName(string $name): string
	{
		$name = trim($name);
		if (class_exists(\Normalizer::class)) {
			$normalized = \Normalizer::normalize($name, \Normalizer::FORM_C);
			if (is_string($normalized)) {
				$name = $normalized;
			}
		}
		$name = preg_replace('/\s+/u', ' ', $name) ?? $name;
		return mb_strtolower($name);
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function listForWorkspace(int $workspaceId, string $userId, string $search = '', bool $includeInactive = false): array
	{
		$this->access->ensureMembership($workspaceId, $userId);
		$qb = $this->db->getQueryBuilder();
		$qb->select('p.*')
			->selectAlias($qb->createFunction('(SELECT COUNT(*) FROM `*PREFIX*bc_transactions` t WHERE t.`payee_id` = p.`id` AND t.`deleted_at` IS NULL)'), 'booking_count')
			->from('bc_payees', 'p')
			->where($qb->expr()->eq('p.workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->orderBy('p.name_norm', 'ASC')
			->setMaxResults(500);
		if (!$includeInactive) {
			$qb->andWhere($qb->expr()->eq('p.is_active', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)));
		}
		$search = trim($search);
		if ($search !== '') {
			$needle = '%' . $this->db->escapeLikeParameter(self::normalizeName($search)) . '%';
			$qb->andWhere($qb->expr()->iLike('p.name_norm', $qb->createNamedParameter($needle)));
		}
		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$out[] = $this->hydrate($row);
		}
		$result->closeCursor();
		return $out;
	}

	/**
	 * Active payees whose name contains the needle — the combobox source.
	 * Bounded + index-backed via name_norm.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function searchForWorkspace(int $workspaceId, string $userId, string $search, int $limit = 20): array
	{
		$this->access->ensureMembership($workspaceId, $userId);
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'name')
			->from('bc_payees')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
			->orderBy('name_norm', 'ASC')
			->setMaxResults(max(1, min(50, $limit)));
		$search = trim($search);
		if ($search !== '') {
			$needle = '%' . $this->db->escapeLikeParameter(self::normalizeName($search)) . '%';
			$qb->andWhere($qb->expr()->iLike('name_norm', $qb->createNamedParameter($needle)));
		}
		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$out[] = ['id' => (int)$row['id'], 'name' => (string)$row['name']];
		}
		$result->closeCursor();
		return $out;
	}

	public function loadForWorkspace(int $payeeId, int $workspaceId): ?array
	{
		$row = $this->loadRow($payeeId);
		if ($row === null || (int)$row['workspace_id'] !== $workspaceId) {
			return null;
		}
		return $this->hydrate($row);
	}

	public function ownerWorkspaceId(int $payeeId): ?int
	{
		$row = $this->loadRow($payeeId);
		return $row === null ? null : (int)$row['workspace_id'];
	}

	/**
	 * Idempotent create: norm-equal duplicates return the existing payee.
	 * Contributors may create payees inline from the transaction editor.
	 */
	public function create(int $workspaceId, string $userId, string $name): array
	{
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_CONTRIBUTOR);
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > 180) {
			throw new \InvalidArgumentException('Payee name must be 1–180 characters.');
		}
		$norm = self::normalizeName($name);
		$existing = $this->findByNorm($workspaceId, $norm);
		if ($existing !== null) {
			// Revive a soft-deleted payee rather than minting a duplicate row.
			if (empty($existing['is_active'])) {
				$this->setActiveFlag((int)$existing['id'], $userId, true);
				$existing['is_active'] = true;
			}
			return $this->hydrate($existing);
		}
		$now = $this->now();
		$qb = $this->db->getQueryBuilder();
		$qb->insert('bc_payees')
			->values([
				'workspace_id' => $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
				'name' => $qb->createNamedParameter($name),
				'name_norm' => $qb->createNamedParameter($norm),
				'is_active' => $qb->createNamedParameter(true, \PDO::PARAM_BOOL),
				'created_by' => $qb->createNamedParameter($userId),
				'updated_by' => $qb->createNamedParameter($userId),
				'created_at' => $qb->createNamedParameter($now),
				'updated_at' => $qb->createNamedParameter($now),
			]);
		try {
			$qb->executeStatement();
		} catch (\Throwable $e) {
			if (!$this->isUniqueConstraintViolation($e)) {
				throw $e;
			}
			// Unique-index race: another request created the same norm — return it.
			$existing = $this->findByNorm($workspaceId, $norm);
			if ($existing !== null) {
				if (empty($existing['is_active'])) {
					$this->setActiveFlag((int)$existing['id'], $userId, true);
					$existing['is_active'] = true;
				}
				return $this->hydrate($existing);
			}
			throw $e;
		}
		$row = $this->findByNorm($workspaceId, $norm);
		if ($row === null) {
			throw new AccessDeniedException();
		}
		$this->audit->record($userId, 'payee_created', 'payee', (string)$row['id'], ['name' => $name], $workspaceId);
		return $this->hydrate($row);
	}

	/**
	 * Resolve (or lazily create) a payee for a transaction write path.
	 * Null/empty input returns null — payee stays optional everywhere.
	 */
	public function resolveForWrite(int $workspaceId, string $userId, mixed $payeeId, mixed $payeeName): ?array
	{
		if ($payeeId !== null && $payeeId !== '') {
			// Strict id: '2.9', '2e3', ' 5' must not silently bind another payee.
			if (!is_int($payeeId) && !ctype_digit((string)$payeeId)) {
				throw new \InvalidArgumentException('payeeId must be a positive integer.');
			}
			if ((int)$payeeId > 0) {
				$payee = $this->loadForWorkspace((int)$payeeId, $workspaceId);
				if ($payee === null) {
					throw new AccessDeniedException();
				}
				return $payee;
			}
		}
		$name = trim((string)($payeeName ?? ''));
		if ($name === '') {
			return null;
		}
		return $this->create($workspaceId, $userId, $name);
	}

	/**
	 * Rename / merge / activate-deactivate. Manager-only.
	 */
	public function update(int $payeeId, string $userId, array $payload): array
	{
		$row = $this->loadRow($payeeId);
		if ($row === null) {
			throw new AccessDeniedException();
		}
		$workspaceId = (int)$row['workspace_id'];
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_MANAGER);

		if (isset($payload['action']) && $payload['action'] === 'mergeInto') {
			$targetId = $payload['targetId'] ?? 0;
			if (!is_int($targetId) && !ctype_digit((string)$targetId)) {
				throw new \InvalidArgumentException('targetId must be a positive integer.');
			}
			return $this->merge($row, $userId, (int)$targetId);
		}
		if (array_key_exists('isActive', $payload)) {
			$active = StrictBool::field($payload, 'isActive');
			$this->setActiveFlag($payeeId, $userId, $active);
			$row['is_active'] = $active;
		}
		if (isset($payload['name'])) {
			$name = trim((string)$payload['name']);
			if ($name === '' || mb_strlen($name) > 180) {
				throw new \InvalidArgumentException('Payee name must be 1–180 characters.');
			}
			$norm = self::normalizeName($name);
			$clash = $this->findByNorm($workspaceId, $norm);
			if ($clash !== null && (int)$clash['id'] !== $payeeId) {
				throw new ConflictException('A payee with this name already exists. Merge instead of renaming.', 'payee_name_conflict');
			}
			try {
				$qb = $this->db->getQueryBuilder();
				$qb->update('bc_payees')
					->set('name', $qb->createNamedParameter($name))
					->set('name_norm', $qb->createNamedParameter($norm))
					->set('updated_by', $qb->createNamedParameter($userId))
					->set('updated_at', $qb->createNamedParameter($this->now()))
					->where($qb->expr()->eq('id', $qb->createNamedParameter($payeeId, IQueryBuilder::PARAM_INT)));
				$qb->executeStatement();
			} catch (\Throwable $e) {
				// Concurrent rename won the unique index between the clash check
				// and this write — surface it as a conflict, not a 500.
				if ($this->isUniqueConstraintViolation($e)) {
					throw new ConflictException('A payee with this name already exists. Merge instead of renaming.', 'payee_name_conflict');
				}
				throw $e;
			}
			$row['name'] = $name;
			$row['name_norm'] = $norm;
			$this->audit->record($userId, 'payee_renamed', 'payee', (string)$payeeId, ['name' => $name], $workspaceId);
		}
		return $this->hydrate($row);
	}

	/**
	 * Merge source payee into target: repoint all transactions, then delete the
	 * source row. Transactional — a failure leaves both payees untouched.
	 */
	private function merge(array $sourceRow, string $userId, int $targetId): array
	{
		$sourceId = (int)$sourceRow['id'];
		$workspaceId = (int)$sourceRow['workspace_id'];
		if ($targetId === $sourceId) {
			throw new \InvalidArgumentException('Cannot merge a payee into itself.');
		}
		$target = $this->loadRow($targetId);
		if ($target === null || (int)$target['workspace_id'] !== $workspaceId) {
			throw new AccessDeniedException();
		}
		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->update('bc_transactions')
				->set('payee_id', $qb->createNamedParameter($targetId, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('payee_id', $qb->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();
			$qb = $this->db->getQueryBuilder();
			$qb->delete('bc_payees')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
		$this->audit->record($userId, 'payee_merged', 'payee', (string)$targetId, [
			'sourcePayeeId' => $sourceId,
			'sourceName' => (string)$sourceRow['name'],
		], $workspaceId);
		return $this->hydrate($target);
	}

	/**
	 * Count of non-deleted transactions linked to a payee — shown in the merge
	 * confirmation dialog so the user sees the blast radius before committing.
	 */
	public function bookingCount(int $payeeId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'c'))
			->from('bc_transactions')
			->where($qb->expr()->eq('payee_id', $qb->createNamedParameter($payeeId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'));
		$row = $qb->executeQuery()->fetch();
		return (int)($row['c'] ?? 0);
	}

	/**
	 * NH-04: payees whose normalized names look similar (identical after
	 * stripping punctuation, or containment) — merge suggestions for managers.
	 *
	 * @return list<array{payee:array<string,mixed>,suggestions:list<array<string,mixed>>}>
	 */
	public function mergeSuggestions(int $workspaceId, string $userId): array
	{
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_MANAGER);
		$payees = $this->listForWorkspace($workspaceId, $userId, '', true);
		$out = [];
		foreach ($payees as $a) {
			$na = (string)$a['nameNorm'];
			$sa = preg_replace('/[^a-z0-9äöüß ]/u', '', $na) ?? $na;
			$similar = [];
			foreach ($payees as $b) {
				if ((int)$b['id'] === (int)$a['id']) {
					continue;
				}
				$nb = (string)$b['nameNorm'];
				$sb = preg_replace('/[^a-z0-9äöüß ]/u', '', $nb) ?? $nb;
				if ($sa === $sb || ($sa !== '' && $sb !== '' && (str_contains($sa, $sb) || str_contains($sb, $sa)))) {
					$similar[] = $b;
				}
			}
			if ($similar !== []) {
				$out[] = ['payee' => $a, 'suggestions' => $similar];
			}
		}
		return $out;
	}

	private function setActiveFlag(int $payeeId, string $userId, bool $active): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_payees')
			->set('is_active', $qb->createNamedParameter($active, \PDO::PARAM_BOOL))
			->set('updated_by', $qb->createNamedParameter($userId))
			->set('updated_at', $qb->createNamedParameter($this->now()))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($payeeId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	private function findByNorm(int $workspaceId, string $norm): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('bc_payees')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('name_norm', $qb->createNamedParameter($norm)));
		$row = $qb->executeQuery()->fetch();
		return $row === false ? null : $row;
	}

	private function isUniqueConstraintViolation(\Throwable $e): bool
	{
		if ($e instanceof \OCP\DB\Exception) {
			return $e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION;
		}
		$previous = $e->getPrevious();
		return $e instanceof \Doctrine\DBAL\Exception\UniqueConstraintViolationException
			|| $previous instanceof \Doctrine\DBAL\Exception\UniqueConstraintViolationException;
	}

	private function loadRow(int $payeeId): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('bc_payees')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($payeeId, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		return $row === false ? null : $row;
	}

	private function hydrate(array $row): array
	{
		return [
			'id' => (int)$row['id'],
			'workspaceId' => (int)$row['workspace_id'],
			'name' => (string)$row['name'],
			'nameNorm' => (string)$row['name_norm'],
			'isActive' => (bool)$row['is_active'],
			'bookingCount' => isset($row['booking_count']) ? (int)$row['booking_count'] : null,
			'createdBy' => (string)$row['created_by'],
			'createdAt' => (string)$row['created_at'],
			'updatedAt' => (string)$row['updated_at'],
		];
	}

	private function now(): string
	{
		return $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
	}
}
