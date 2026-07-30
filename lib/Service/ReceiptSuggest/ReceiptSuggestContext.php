<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service\ReceiptSuggest;

/**
 * Workspace context passed into validation / quality gates.
 * Pure value object — no I/O.
 */
final class ReceiptSuggestContext
{
	/**
	 * @param list<int> $allowedCategoryIds Active expense (or both) category IDs for the workspace.
	 * @param string $workspaceCurrencyCode ISO 4217 uppercase (e.g. EUR).
	 * @param \DateTimeImmutable $today Anchor for date windows (injectable for tests).
	 */
	public function __construct(
		public readonly array $allowedCategoryIds,
		public readonly string $workspaceCurrencyCode,
		public readonly \DateTimeImmutable $today,
		public readonly string $source = ReceiptSuggestConstants::SOURCE_ANALYZE_IMAGES,
	) {
	}

	/** @return array<int, true> */
	public function allowedCategorySet(): array
	{
		$set = [];
		foreach ($this->allowedCategoryIds as $id) {
			if (is_int($id) && $id > 0) {
				$set[$id] = true;
			}
		}
		return $set;
	}
}
