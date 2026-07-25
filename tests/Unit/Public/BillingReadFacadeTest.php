<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Public;

use OCA\BudgetCheck\Public\BillableItem;
use OCA\BudgetCheck\Public\BillingReadFacade;
use OCA\BudgetCheck\Public\BillingResult;
use OCA\BudgetCheck\Service\TransactionBillingService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

class BillingReadFacadeTest extends TestCase
{
	public function testIsAvailableUsesAppManager(): void
	{
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->with('budgetcheck')->willReturn(true);
		$facade = new BillingReadFacade($this->createMock(TransactionBillingService::class), $apps);
		$this->assertTrue($facade->isAvailable());
	}

	public function testListBillableItemsDelegates(): void
	{
		$item = new BillableItem(
			1, 2, '2026-07-01', 'expense', 1999, 'EUR', 'Taxi', null, 3,
			null, null, null, 'open', '2026-07-01 10:00:00', 1
		);
		$billing = $this->createMock(TransactionBillingService::class);
		$billing->expects($this->once())
			->method('listBillableOpen')
			->with('alice', ['workspaceId' => 2])
			->willReturn([$item]);
		$apps = $this->createMock(IAppManager::class);

		$out = (new BillingReadFacade($billing, $apps))->listBillableItems('alice', ['workspaceId' => 2]);
		$this->assertCount(1, $out);
		$this->assertSame(1999, $out[0]->amountMinor);
		$this->assertSame(1, $out[0]->toArray()['id']);
	}

	public function testBillingResultHelpers(): void
	{
		$full = new BillingResult(3, []);
		$this->assertTrue($full->isFullSuccess());
		$this->assertFalse($full->isEmptySuccess());

		$empty = new BillingResult(0, []);
		$this->assertTrue($empty->isEmptySuccess());
		$this->assertFalse($empty->isFullSuccess());

		$fail = new BillingResult(1, [['id' => 9, 'reason' => 'forbidden']]);
		$this->assertFalse($fail->isFullSuccess());
		$this->assertSame([9], $fail->failedIds());
	}
}
