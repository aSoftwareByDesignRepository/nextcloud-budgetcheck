<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Public;

use OCA\BudgetCheck\Public\BillingResult;
use OCA\BudgetCheck\Public\BillingWriteFacade;
use OCA\BudgetCheck\Service\TransactionBillingService;
use OCA\BudgetCheck\Util\BillingStatus;
use PHPUnit\Framework\TestCase;

class BillingWriteFacadeTest extends TestCase
{
	public function testMarkItemsInvoicedDelegatesAndMapsResult(): void
	{
		$billing = $this->createMock(TransactionBillingService::class);
		$billing->expects($this->once())
			->method('bulkChangeStatusByIds')
			->with(
				[10, 11],
				BillingStatus::INVOICED,
				'alice',
				true,
				[10 => '2026-07-01 10:00:00'],
				BillingStatus::OPEN,
				true,
				true,
			)
			->willReturn([
				'applied' => 2,
				'failed' => [],
			]);

		$facade = new BillingWriteFacade($billing);
		$result = $facade->markItemsInvoiced(
			'alice',
			[10, 11],
			[10 => '2026-07-01 10:00:00'],
			['invoiceId' => 1],
			true,
			true,
		);

		$this->assertSame(2, $result->applied);
		$this->assertTrue($result->isFullSuccess());
	}

	public function testReopenRequiresInvoicedSource(): void
	{
		$billing = $this->createMock(TransactionBillingService::class);
		$billing->expects($this->once())
			->method('bulkChangeStatusByIds')
			->with(
				[5],
				BillingStatus::OPEN,
				'bob',
				false,
				[],
				BillingStatus::INVOICED,
				false,
				true,
			)
			->willReturn([
				'applied' => 0,
				'failed' => [['id' => 5, 'reason' => 'invalid_transition']],
			]);

		$facade = new BillingWriteFacade($billing);
		$result = $facade->reopenItems('bob', [5]);
		$this->assertFalse($result->isFullSuccess());
		$this->assertSame([5], $result->failedIds());
	}

	public function testSetItemBillableDelegates(): void
	{
		$billing = $this->createMock(TransactionBillingService::class);
		$billing->expects($this->once())->method('setBillable')->with('carol', 9, true);
		(new BillingWriteFacade($billing))->setItemBillable('carol', 9, true);
	}

	public function testMarkItemsPaidRequiresInvoicedSource(): void
	{
		$billing = $this->createMock(TransactionBillingService::class);
		$billing->expects($this->once())
			->method('bulkChangeStatusByIds')
			->with(
				[8, 9],
				BillingStatus::PAID,
				'alice',
				true,
				[],
				BillingStatus::INVOICED,
				true,
				true,
			)
			->willReturn(['applied' => 2, 'failed' => []]);

		$facade = new BillingWriteFacade($billing);
		$result = $facade->markItemsPaid('alice', [8, 9], [], [], true, true);
		$this->assertSame(2, $result->applied);
		$this->assertTrue($result->isFullSuccess());
	}

	public function testReopenFromPaidDelegates(): void
	{
		$billing = $this->createMock(TransactionBillingService::class);
		$billing->expects($this->once())
			->method('bulkChangeStatusByIds')
			->with(
				[3],
				BillingStatus::OPEN,
				'bob',
				true,
				[],
				BillingStatus::PAID,
				true,
				true,
			)
			->willReturn(['applied' => 1, 'failed' => []]);

		$result = (new BillingWriteFacade($billing))->reopenFromPaid('bob', [3], [], [], true, true);
		$this->assertSame(1, $result->applied);
	}
}
