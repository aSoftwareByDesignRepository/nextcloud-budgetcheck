<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Public;

use OCA\BudgetCheck\Service\TransactionBillingService;
use OCP\App\IAppManager;

/**
 * Read-only billing API for trusted sibling apps (InvoiceCheck).
 *
 * Server-side only. Never query bc_* from InvoiceCheck — use this facade.
 */
class BillingReadFacade
{
	public const MAX_ITEMS = TransactionBillingService::MAX_BULK_ROWS;

	public function __construct(
		private TransactionBillingService $billing,
		private IAppManager $appManager,
	) {
	}

	public function isAvailable(): bool
	{
		return $this->appManager->isEnabledForUser('budgetcheck');
	}

	/**
	 * @param array{
	 *   workspaceId?: int,
	 *   dateFrom?: string,
	 *   dateTo?: string,
	 *   direction?: string,
	 *   limit?: int
	 * } $filters
	 * @return list<BillableItem>
	 */
	public function listBillableItems(string $actorUid, array $filters = []): array
	{
		return $this->billing->listBillableOpen($actorUid, $filters);
	}

	/**
	 * @param list<int> $itemIds
	 * @return list<BillableItem>
	 */
	public function getItemsByIds(string $actorUid, array $itemIds): array
	{
		return $this->billing->getItemsByIds($actorUid, $itemIds, false);
	}

	/**
	 * Trusted sibling path — ACL already enforced by the caller (e.g. InvoiceCheck).
	 *
	 * @param list<int> $itemIds
	 * @return list<BillableItem>
	 */
	public function getItemsByIdsTrusted(array $itemIds): array
	{
		return $this->billing->getItemsByIds('system', $itemIds, true);
	}

	/**
	 * @return list<int>
	 */
	public function listAccessibleWorkspaceIds(string $actorUid): array
	{
		return $this->billing->listAccessibleWorkspaceIds($actorUid);
	}
}
