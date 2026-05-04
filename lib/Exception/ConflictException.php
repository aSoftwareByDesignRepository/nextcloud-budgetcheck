<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Exception;

/**
 * Optimistic locking or other recoverable concurrency conflict.
 * Maps to HTTP 409 + {@code version_conflict} (current sole use case).
 */
final class ConflictException extends BudgetCheckException
{
	public function __construct()
	{
		parent::__construct('version_conflict');
	}
}
