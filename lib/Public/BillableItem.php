<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Public;

/**
 * Immutable DTO for a billable BudgetCheck transaction exposed to InvoiceCheck.
 *
 * @psalm-immutable
 */
final class BillableItem
{
	public function __construct(
		public readonly int $id,
		public readonly int $workspaceId,
		public readonly string $bookingDate,
		public readonly string $direction,
		public readonly int $amountMinor,
		public readonly string $currency,
		public readonly string $title,
		public readonly ?string $notes,
		public readonly ?int $categoryId,
		public readonly ?int $vatRateBp,
		public readonly ?int $netAmountMinor,
		public readonly ?int $grossAmountMinor,
		public readonly string $billingStatus,
		public readonly string $updatedAt,
		public readonly int $version,
	) {
	}

	/**
	 * @return array{
	 *   id: int,
	 *   workspaceId: int,
	 *   bookingDate: string,
	 *   direction: string,
	 *   amountMinor: int,
	 *   currency: string,
	 *   title: string,
	 *   notes: string|null,
	 *   categoryId: int|null,
	 *   vatRateBp: int|null,
	 *   netAmountMinor: int|null,
	 *   grossAmountMinor: int|null,
	 *   billingStatus: string,
	 *   updatedAt: string,
	 *   version: int
	 * }
	 */
	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'workspaceId' => $this->workspaceId,
			'bookingDate' => $this->bookingDate,
			'direction' => $this->direction,
			'amountMinor' => $this->amountMinor,
			'currency' => $this->currency,
			'title' => $this->title,
			'notes' => $this->notes,
			'categoryId' => $this->categoryId,
			'vatRateBp' => $this->vatRateBp,
			'netAmountMinor' => $this->netAmountMinor,
			'grossAmountMinor' => $this->grossAmountMinor,
			'billingStatus' => $this->billingStatus,
			'updatedAt' => $this->updatedAt,
			'version' => $this->version,
		];
	}
}
