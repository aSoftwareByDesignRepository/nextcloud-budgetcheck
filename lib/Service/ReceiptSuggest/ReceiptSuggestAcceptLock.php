<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service\ReceiptSuggest;

use OCP\ICacheFactory;

/**
 * Cross-request exclusive claim for accept — prevents double-book race on concurrent Save.
 * Uses distributed cache set-if-absent (works with APCu/Redis/DB backends).
 */
final class ReceiptSuggestAcceptLock implements ReceiptSuggestAcceptLockInterface
{
	private const TTL_SEC = 180;

	public function __construct(
		private readonly ICacheFactory $cacheFactory,
	) {
	}

	public function tryAcquire(string $userId, int $jobId): bool
	{
		$cache = $this->cacheFactory->createDistributed('budgetcheck-rs-accept');
		$key = $this->key($userId, $jobId);
		if ($cache instanceof \OCP\IMemcache) {
			return $cache->add($key, '1', self::TTL_SEC);
		}
		// Non-memcache backends: best-effort claim (still helps single-node races).
		if ($cache->hasKey($key)) {
			return false;
		}
		$cache->set($key, '1', self::TTL_SEC);
		return true;
	}

	public function release(string $userId, int $jobId): void
	{
		$cache = $this->cacheFactory->createDistributed('budgetcheck-rs-accept');
		$cache->remove($this->key($userId, $jobId));
	}

	private function key(string $userId, int $jobId): string
	{
		return 'u:' . $userId . ':j:' . $jobId;
	}
}
