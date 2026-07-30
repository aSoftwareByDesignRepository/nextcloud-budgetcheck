<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service\ReceiptSuggest;

/**
 * Port for receipt AI suggest orchestration (controllers depend on this, not the final impl).
 * Keeps suggest-never-commit boundaries mockable under PHPUnit without unsealing the service.
 */
interface ReceiptSuggestServiceInterface
{
	/**
	 * @return list<string>
	 */
	public function modesForUser(?string $userId): array;

	public function isAvailable(?string $userId): bool;

	/**
	 * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $upload
	 * @return array<string, mixed>
	 */
	public function startFromUpload(int $workspaceId, string $userId, array $upload): array;

	/**
	 * @return array<string, mixed>
	 */
	public function poll(int $workspaceId, string $userId, int $jobId): array;

	/**
	 * @param array<string, mixed> $accepted
	 * @return array<string, mixed>
	 */
	public function accept(int $workspaceId, string $userId, int $jobId, array $accepted): array;

	public function cancelJob(int $workspaceId, string $userId, int $jobId): void;
}
