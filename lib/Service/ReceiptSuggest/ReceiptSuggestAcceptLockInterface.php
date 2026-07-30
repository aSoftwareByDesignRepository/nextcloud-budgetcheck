<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service\ReceiptSuggest;

interface ReceiptSuggestAcceptLockInterface
{
	public function tryAcquire(string $userId, int $jobId): bool;

	public function release(string $userId, int $jobId): void;
}
