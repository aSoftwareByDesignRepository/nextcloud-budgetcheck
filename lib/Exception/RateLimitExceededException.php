<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Exception;

/**
 * Sliding-window rate limit exceeded. Maps to HTTP 429.
 */
final class RateLimitExceededException extends BudgetCheckException
{
	public function __construct()
	{
		parent::__construct('rate_limit_exceeded');
	}
}
