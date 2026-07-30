<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service\ReceiptSuggest;

/**
 * One suggested booking line (split or single total).
 */
final class ReceiptSuggestionLine
{
	public function __construct(
		public readonly string $label,
		public readonly int $amountMinor,
		public readonly int $categoryId,
		public readonly float $confidence,
	) {
	}

	/** @return array{label:string,amountMinor:int,categoryId:int,confidence:float} */
	public function toArray(): array
	{
		return [
			'label' => $this->label,
			'amountMinor' => $this->amountMinor,
			'categoryId' => $this->categoryId,
			'confidence' => $this->confidence,
		];
	}
}
