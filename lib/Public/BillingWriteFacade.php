<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Public;

use OCA\BudgetCheck\Service\TransactionBillingService;
use OCA\BudgetCheck\Util\BillingStatus;

/**
 * Billing write API for trusted sibling apps (InvoiceCheck).
 *
 * `$trustedSiblingApp` skips BudgetCheck role checks for server-side callers
 * that already authorized the actor. Never expose that flag on HTTP surfaces.
 */
class BillingWriteFacade
{
	public function __construct(
		private TransactionBillingService $billing,
	) {
	}

	/**
	 * @param list<int> $itemIds
	 * @param array<int, string> $expectedUpdatedAt map itemId => 'Y-m-d H:i:s'
	 * @param array{invoiceId?: int, invoiceNumber?: string} $meta
	 */
	public function markItemsInvoiced(
		string $actorUid,
		array $itemIds,
		array $expectedUpdatedAt = [],
		array $meta = [],
		bool $requireFullSuccess = true,
		bool $trustedSiblingApp = false,
	): BillingResult {
		unset($meta);
		return $this->transition(
			$actorUid,
			$itemIds,
			$expectedUpdatedAt,
			BillingStatus::INVOICED,
			BillingStatus::OPEN,
			$requireFullSuccess,
			true,
			$trustedSiblingApp,
		);
	}

	/**
	 * @param list<int> $itemIds
	 * @param array<int, string> $expectedUpdatedAt
	 * @param array{invoiceId?: int} $meta
	 */
	public function reopenItems(
		string $actorUid,
		array $itemIds,
		array $expectedUpdatedAt = [],
		array $meta = [],
		bool $requireFullSuccess = true,
		bool $trustedSiblingApp = false,
	): BillingResult {
		unset($meta);
		return $this->transition(
			$actorUid,
			$itemIds,
			$expectedUpdatedAt,
			BillingStatus::OPEN,
			BillingStatus::INVOICED,
			$requireFullSuccess,
			false,
			$trustedSiblingApp,
		);
	}

	/**
	 * Full invoice payment → mark previously invoiced billable items paid.
	 *
	 * @param list<int> $itemIds
	 * @param array<int, string> $expectedUpdatedAt
	 * @param array{invoiceId?: int, invoiceNumber?: string} $meta
	 */
	public function markItemsPaid(
		string $actorUid,
		array $itemIds,
		array $expectedUpdatedAt = [],
		array $meta = [],
		bool $requireFullSuccess = true,
		bool $trustedSiblingApp = false,
	): BillingResult {
		unset($meta);
		return $this->transition(
			$actorUid,
			$itemIds,
			$expectedUpdatedAt,
			BillingStatus::PAID,
			BillingStatus::INVOICED,
			$requireFullSuccess,
			true,
			$trustedSiblingApp,
		);
	}

	/**
	 * Reverse paid → open (credit note / admin correction). Already-open is OK.
	 *
	 * @param list<int> $itemIds
	 * @param array<int, string> $expectedUpdatedAt
	 * @param array{invoiceId?: int} $meta
	 */
	public function reopenFromPaid(
		string $actorUid,
		array $itemIds,
		array $expectedUpdatedAt = [],
		array $meta = [],
		bool $requireFullSuccess = true,
		bool $trustedSiblingApp = false,
	): BillingResult {
		unset($meta);
		return $this->transition(
			$actorUid,
			$itemIds,
			$expectedUpdatedAt,
			BillingStatus::OPEN,
			BillingStatus::PAID,
			$requireFullSuccess,
			true,
			$trustedSiblingApp,
		);
	}

	/**
	 * Opt-in billable flag for ledger rows (contributor+).
	 */
	public function setItemBillable(string $actorUid, int $itemId, bool $billable): void
	{
		$this->billing->setBillable($actorUid, $itemId, $billable);
	}

	/**
	 * @param list<int> $itemIds
	 * @param array<int, string> $expectedUpdatedAt
	 */
	private function transition(
		string $actorUid,
		array $itemIds,
		array $expectedUpdatedAt,
		string $target,
		string $expectedSource,
		bool $requireFullSuccess,
		bool $alreadyAtTargetIsOk,
		bool $trustedSiblingApp,
	): BillingResult {
		$result = $this->billing->bulkChangeStatusByIds(
			$itemIds,
			$target,
			$actorUid,
			$trustedSiblingApp,
			$expectedUpdatedAt,
			$expectedSource,
			$alreadyAtTargetIsOk,
			$requireFullSuccess,
		);
		return new BillingResult((int) $result['applied'], $result['failed']);
	}
}
