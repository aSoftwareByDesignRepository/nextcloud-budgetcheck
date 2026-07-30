<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service\ReceiptSuggest;

interface ReceiptSuggestMetricsInterface
{
	public function increment(string $key): void;

	/**
	 * @return array<string, int>
	 */
	public function snapshot(): array;
}
