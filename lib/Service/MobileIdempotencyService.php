<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

use OCA\BudgetCheck\Exception\IdempotencyMismatchException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Idempotent POST create for BudgetCheck Mobile offline queue (P2).
 *
 * Scope: (user_id, workspace_id, idem_key). Same key + same body hash → replay.
 * Same key + different body → {@see IdempotencyMismatchException}.
 */
class MobileIdempotencyService
{
	public const RETENTION_DAYS = 14;

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
	 * @param array<string, mixed> $responseBody
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
			// Concurrent insert — re-check as replay.
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
