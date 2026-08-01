<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Exception;

/**
 * Idempotency-Key reused with a different request body (mobile offline queue).
 * Maps to HTTP 409 + {@code IDEMPOTENCY_MISMATCH}.
 */
final class IdempotencyMismatchException extends BudgetCheckException
{
	public function __construct()
	{
		parent::__construct('IDEMPOTENCY_MISMATCH');
	}
}
