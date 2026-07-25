<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Public;

/**
 * Result of a trusted-app billing write (InvoiceCheck handshake).
 *
 * @psalm-immutable
 */
final class BillingResult
{
	/**
	 * @param list<array{id: int, reason: string}> $failed
	 */
	public function __construct(
		public readonly int $applied,
		public readonly array $failed,
	) {
	}

	public function isFullSuccess(): bool
	{
		return $this->failed === [] && $this->applied > 0;
	}

	public function isEmptySuccess(): bool
	{
		return $this->failed === [] && $this->applied === 0;
	}

	/**
	 * @return list<int>
	 */
	public function failedIds(): array
	{
		$ids = [];
		foreach ($this->failed as $row) {
			$ids[] = (int) $row['id'];
		}
		return $ids;
	}
}
