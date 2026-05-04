<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Exception;

/**
 * Caller is authenticated but not permitted (membership, role, or resource scope).
 * Maps to HTTP 403 + {@code access_denied}.
 */
final class AccessDeniedException extends BudgetCheckException
{
	public function __construct()
	{
		parent::__construct('access_denied');
	}
}
