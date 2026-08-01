<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Opt-in FCM/APNs token registry for BudgetCheck Mobile (P3).
 */
class MobilePushService
{
	private const PLATFORMS = ['android', 'ios'];

	public function __construct(
		private IDBConnection $db,
		private ITimeFactory $timeFactory,
	) {
	}

	public function register(string $userId, string $token, string $platform = 'android'): void
	{
		$token = trim($token);
		if ($token === '' || strlen($token) > 512) {
			throw new \InvalidArgumentException('pushToken is required (max 512 characters).');
		}
		$platform = strtolower(trim($platform));
		if (!in_array($platform, self::PLATFORMS, true)) {
			throw new \InvalidArgumentException('platform must be android or ios.');
		}
		$now = $this->utcNow();
		$existing = $this->find($userId, $token);
		if ($existing !== null) {
			$qb = $this->db->getQueryBuilder();
			$qb->update('bc_mobile_push')
				->set('platform', $qb->createNamedParameter($platform))
				->set('updated_at', $qb->createNamedParameter($now))
				->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$existing['id'], \PDO::PARAM_INT)));
			$qb->executeStatement();
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->insert('bc_mobile_push')
			->values([
				'user_id' => $qb->createNamedParameter($userId),
				'push_token' => $qb->createNamedParameter($token),
				'platform' => $qb->createNamedParameter($platform),
				'created_at' => $qb->createNamedParameter($now),
				'updated_at' => $qb->createNamedParameter($now),
			]);
		$qb->executeStatement();
	}

	public function unregister(string $userId, string $token): void
	{
		$token = trim($token);
		if ($token === '') {
			throw new \InvalidArgumentException('pushToken is required.');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete('bc_mobile_push')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('push_token', $qb->createNamedParameter($token)));
		$qb->executeStatement();
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function find(string $userId, string $token): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('bc_mobile_push')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('push_token', $qb->createNamedParameter($token)))
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
