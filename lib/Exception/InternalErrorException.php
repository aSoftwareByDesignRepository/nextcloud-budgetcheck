<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Exception;

/**
 * Invariant violated or post-commit read inconsistency — not the caller’s fault.
 * Maps to HTTP 500; details must only appear in server logs.
 */
final class InternalErrorException extends BudgetCheckException
{
	public function __construct(?\Throwable $previous = null)
	{
		parent::__construct('internal_error', 0, $previous);
	}
}
