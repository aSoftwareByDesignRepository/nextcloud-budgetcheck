<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

use OCA\BudgetCheck\Exception\ConflictException;
use OCA\BudgetCheck\Exception\IdempotencyMismatchException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Idempotent POST create for BudgetCheck Mobile offline queue (P2).
 *
 * Scope: (user_id, workspace_id, idem_key). Same key + same body hash → replay.
 * Same key + different body → {@see IdempotencyMismatchException}.
 *
 * Claim-before-create: insert a pending row (http_status=0) under the unique
 * index BEFORE the ledger write so concurrent retries cannot double-insert.
 */
class MobileIdempotencyService
{
	public const RETENTION_DAYS = 14;

	/**
	 * Pending claims older than this are treated as abandoned (process crash /
	 * killed request) so a retry can reclaim instead of stalling forever.
	 */
	public const STALE_PENDING_SECONDS = 120;

	/** Pending claim — create in flight; not a completed replay. */
	public const STATUS_PENDING = 0;

	public function __construct(
		private IDBConnection $db,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * @return array{httpStatus:int, body:array}|null
	 */
	public function findReplay(string $userId, int $workspaceId, string $key, string $requestHash): ?array
	{
		$key = $this->normaliseKey($key);
		$row = $this->loadRow($userId, $workspaceId, $key);
		if ($row === null) {
			return null;
		}
		if (!hash_equals((string)$row['request_hash'], $requestHash)) {
			throw new IdempotencyMismatchException();
		}
		if ((int)$row['http_status'] === self::STATUS_PENDING) {
			return null;
		}
		$body = json_decode((string)$row['response_json'], true);
		if (!is_array($body)) {
			return null;
		}
		return [
			'httpStatus' => (int)$row['http_status'],
			'body' => $body,
		];
	}

	/**
	 * Claim the idempotency key before creating a transaction, or return a
	 * completed replay. Concurrent claimers with the same hash get
	 * {@see ConflictException} while the first request is still pending.
	 *
	 * Stale pending rows (older than {@see STALE_PENDING_SECONDS}) are released
	 * so a retry can reclaim after a crashed request.
	 *
	 * @return array{httpStatus:int, body:array}|null null = freshly claimed
	 */
	public function claimOrReplay(string $userId, int $workspaceId, string $key, string $requestHash): ?array
	{
		$this->purgeExpired();

		$key = $this->normaliseKey($key);
		$existing = $this->loadRow($userId, $workspaceId, $key);
		if ($existing !== null) {
			if ($this->isStalePending($existing)) {
				$this->releaseClaim($userId, $workspaceId, $key);
			} else {
				return $this->replayOrConflict($existing, $requestHash);
			}
		}

		$now = $this->utcNow();
		$qb = $this->db->getQueryBuilder();
		$qb->insert('bc_idempotency')
			->values([
				'user_id' => $qb->createNamedParameter($userId),
				'workspace_id' => $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT),
				'idem_key' => $qb->createNamedParameter($key),
				'request_hash' => $qb->createNamedParameter($requestHash),
				'response_json' => $qb->createNamedParameter('{"ok":false,"pending":true}'),
				'http_status' => $qb->createNamedParameter(self::STATUS_PENDING, \PDO::PARAM_INT),
				'created_at' => $qb->createNamedParameter($now),
			]);
		try {
			$qb->executeStatement();
			return null;
		} catch (\Throwable) {
			$row = $this->loadRow($userId, $workspaceId, $key);
			if ($row === null) {
				throw new IdempotencyMismatchException();
			}
			if ($this->isStalePending($row)) {
				$this->releaseClaim($userId, $workspaceId, $key);
				throw new ConflictException(
					ConflictException::CODE_IDEMPOTENCY_IN_PROGRESS,
					'Idempotent create is already in progress for this key. Retry shortly.',
				);
			}
			return $this->replayOrConflict($row, $requestHash);
		}
	}

	/**
	 * Drop completed idempotency rows past retention, and abandoned pending
	 * claims older than a day (safety net if reclaim never runs).
	 */
	public function purgeExpired(): void
	{
		$now = $this->timeFactory->getTime();
		$completedCutoff = (new \DateTimeImmutable('@' . $now))
			->setTimezone(new \DateTimeZone('UTC'))
			->modify('-' . self::RETENTION_DAYS . ' days')
			->format('Y-m-d H:i:s');
		$stalePendingCutoff = (new \DateTimeImmutable('@' . $now))
			->setTimezone(new \DateTimeZone('UTC'))
			->modify('-1 day')
			->format('Y-m-d H:i:s');

		$qb = $this->db->getQueryBuilder();
		$qb->delete('bc_idempotency')
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($completedCutoff)))
			->andWhere($qb->expr()->neq('http_status', $qb->createNamedParameter(self::STATUS_PENDING, \PDO::PARAM_INT)));
		$qb->executeStatement();

		$qb2 = $this->db->getQueryBuilder();
		$qb2->delete('bc_idempotency')
			->where($qb2->expr()->lt('created_at', $qb2->createNamedParameter($stalePendingCutoff)))
			->andWhere($qb2->expr()->eq('http_status', $qb2->createNamedParameter(self::STATUS_PENDING, \PDO::PARAM_INT)));
		$qb2->executeStatement();
	}

	/**
	 * @param array<string, mixed> $responseBody
	 */
	public function completeClaim(
		string $userId,
		int $workspaceId,
		string $key,
		string $requestHash,
		array $responseBody,
		int $httpStatus,
	): void {
		$key = $this->normaliseKey($key);
		$json = json_encode($responseBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_idempotency')
			->set('response_json', $qb->createNamedParameter(is_string($json) ? $json : '{}'))
			->set('http_status', $qb->createNamedParameter($httpStatus, \PDO::PARAM_INT))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('idem_key', $qb->createNamedParameter($key)))
			->andWhere($qb->expr()->eq('request_hash', $qb->createNamedParameter($requestHash)))
			->andWhere($qb->expr()->eq('http_status', $qb->createNamedParameter(self::STATUS_PENDING, \PDO::PARAM_INT)));
		$qb->executeStatement();
	}

	public function releaseClaim(string $userId, int $workspaceId, string $key): void
	{
		$key = $this->normaliseKey($key);
		$qb = $this->db->getQueryBuilder();
		$qb->delete('bc_idempotency')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('idem_key', $qb->createNamedParameter($key)))
			->andWhere($qb->expr()->eq('http_status', $qb->createNamedParameter(self::STATUS_PENDING, \PDO::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * @param array<string, mixed> $responseBody
	 * @deprecated Prefer claimOrReplay + completeClaim (claim-before-create)
	 */
	public function store(
		string $userId,
		int $workspaceId,
		string $key,
		string $requestHash,
		array $responseBody,
		int $httpStatus,
	): void {
		$key = $this->normaliseKey($key);
		$existing = $this->loadRow($userId, $workspaceId, $key);
		if ($existing !== null) {
			if (!hash_equals((string)$existing['request_hash'], $requestHash)) {
				throw new IdempotencyMismatchException();
			}
			if ((int)$existing['http_status'] === self::STATUS_PENDING) {
				$this->completeClaim($userId, $workspaceId, $key, $requestHash, $responseBody, $httpStatus);
			}
			return;
		}
		$now = $this->utcNow();
		$qb = $this->db->getQueryBuilder();
		$qb->insert('bc_idempotency')
			->values([
				'user_id' => $qb->createNamedParameter($userId),
				'workspace_id' => $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT),
				'idem_key' => $qb->createNamedParameter($key),
				'request_hash' => $qb->createNamedParameter($requestHash),
				'response_json' => $qb->createNamedParameter(json_encode($responseBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
				'http_status' => $qb->createNamedParameter($httpStatus, \PDO::PARAM_INT),
				'created_at' => $qb->createNamedParameter($now),
			]);
		try {
			$qb->executeStatement();
		} catch (\Throwable) {
			$replay = $this->findReplay($userId, $workspaceId, $key, $requestHash);
			if ($replay === null) {
				throw new IdempotencyMismatchException();
			}
		}
	}

	public static function hashPayload(array $payload): string
	{
		ksort($payload);
		$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		return hash('sha256', is_string($json) ? $json : '');
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{httpStatus:int, body:array}
	 */
	private function replayOrConflict(array $row, string $requestHash): array
	{
		if (!hash_equals((string)$row['request_hash'], $requestHash)) {
			throw new IdempotencyMismatchException();
		}
		if ((int)$row['http_status'] === self::STATUS_PENDING) {
			throw new ConflictException(
				ConflictException::CODE_IDEMPOTENCY_IN_PROGRESS,
				'Idempotent create is already in progress for this key. Retry shortly.',
			);
		}
		$body = json_decode((string)$row['response_json'], true);
		if (!is_array($body)) {
			throw new IdempotencyMismatchException();
		}
		return [
			'httpStatus' => (int)$row['http_status'],
			'body' => $body,
		];
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function isStalePending(array $row): bool
	{
		if ((int)$row['http_status'] !== self::STATUS_PENDING) {
			return false;
		}
		$createdRaw = (string)($row['created_at'] ?? '');
		if ($createdRaw === '') {
			return true;
		}
		try {
			$created = new \DateTimeImmutable($createdRaw, new \DateTimeZone('UTC'));
		} catch (\Exception) {
			return true;
		}
		$age = $this->timeFactory->getTime() - $created->getTimestamp();
		return $age >= self::STALE_PENDING_SECONDS;
	}

	private function normaliseKey(string $key): string
	{
		$key = trim($key);
		if ($key === '' || strlen($key) > 64) {
			throw new \InvalidArgumentException('Idempotency-Key must be 1–64 characters.');
		}
		if (!preg_match('/^[A-Za-z0-9._:-]+$/', $key)) {
			throw new \InvalidArgumentException('Idempotency-Key has invalid characters.');
		}
		return $key;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function loadRow(string $userId, int $workspaceId, string $key): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('bc_idempotency')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('idem_key', $qb->createNamedParameter($key)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return is_array($row) ? $row : null;
	}

	private function utcNow(): string
	{
		return (new \DateTimeImmutable('@' . $this->timeFactory->getTime()))
			->setTimezone(new \DateTimeZone('UTC'))
			->format('Y-m-d H:i:s');
	}
}
