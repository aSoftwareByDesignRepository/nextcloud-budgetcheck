<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service\ReceiptSuggest;

use OCA\BudgetCheck\Service\ReceiptSuggest\ReceiptSuggestAcceptLock;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IMemcache;
use PHPUnit\Framework\TestCase;

final class ReceiptSuggestAcceptLockTest extends TestCase
{
	public function testTryAcquireUsesMemcacheAdd(): void
	{
		$cache = $this->createMock(IMemcache::class);
		$cache->expects($this->once())
			->method('add')
			->with('u:alice:j:42', '1', 180)
			->willReturn(true);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->expects($this->once())
			->method('createDistributed')
			->with('budgetcheck-rs-accept')
			->willReturn($cache);

		$lock = new ReceiptSuggestAcceptLock($factory);
		$this->assertTrue($lock->tryAcquire('alice', 42));
	}

	public function testSecondAcquireFails(): void
	{
		$cache = $this->createMock(IMemcache::class);
		$cache->method('add')->willReturn(false);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		$lock = new ReceiptSuggestAcceptLock($factory);
		$this->assertFalse($lock->tryAcquire('alice', 42));
	}

	public function testFallbackUsesHasKeyWhenNotMemcache(): void
	{
		$cache = $this->createMock(ICache::class);
		$cache->expects($this->once())->method('hasKey')->with('u:alice:j:7')->willReturn(false);
		$cache->expects($this->once())->method('set')->with('u:alice:j:7', '1', 180);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		$lock = new ReceiptSuggestAcceptLock($factory);
		$this->assertTrue($lock->tryAcquire('alice', 7));
	}

	public function testReleaseRemovesKey(): void
	{
		$cache = $this->createMock(ICache::class);
		$cache->expects($this->once())->method('remove')->with('u:alice:j:42');
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		$lock = new ReceiptSuggestAcceptLock($factory);
		$lock->release('alice', 42);
	}
}
