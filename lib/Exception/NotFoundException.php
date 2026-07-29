<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Exception;

/**
 * Authenticated caller may access the workspace, but the resource id does not exist there.
 * Maps to HTTP 404 + {@code NOT_FOUND} (distinct from {@see AccessDeniedException} / 403).
 */
final class NotFoundException extends BudgetCheckException
{
	public function __construct(string $message = 'Resource not found.')
	{
		parent::__construct($message);
	}
}
