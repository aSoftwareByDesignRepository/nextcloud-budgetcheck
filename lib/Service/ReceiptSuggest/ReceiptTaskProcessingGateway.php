<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service\ReceiptSuggest;

/**
 * Narrow port over OCP Task Processing so unit tests can fake providers without a live NC.
 */
interface ReceiptTaskProcessingGateway
{
	/**
	 * @return list<string>
	 */
	public function availableTaskTypeIds(?string $userId = null): array;

	/**
	 * @param array<string, mixed> $input
	 * @return int Task id
	 */
	public function schedule(string $taskTypeId, array $input, string $userId, string $customId): int;

	/**
	 * @return array{id:int,status:int,output:?array,error:?string}
	 */
	public function getUserTask(int $taskId, string $userId): array;

	public function cancel(int $taskId): void;
}
