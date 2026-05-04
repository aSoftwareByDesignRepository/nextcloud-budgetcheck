<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Exception;

/**
 * No logged-in Nextcloud user. Maps to HTTP 401 + {@code not_authenticated}.
 */
final class NotAuthenticatedException extends BudgetCheckException
{
	public function __construct()
	{
		parent::__construct('not_authenticated');
	}
}
