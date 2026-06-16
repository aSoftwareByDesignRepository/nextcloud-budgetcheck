<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Exception;

/**
 * Thrown when a logged-in user may not enter the BudgetCheck app shell.
 * {@see self::getDenialReason()} is set when directory restriction blocks the user.
 */
class AppAccessDeniedException extends \RuntimeException
{
	public function __construct(
		string $message = 'app_access_denied',
		int $code = 0,
		?\Throwable $previous = null,
		private readonly ?string $denialReason = null,
	) {
		parent::__construct($message, $code, $previous);
	}

	public function getDenialReason(): ?string
	{
		return $this->denialReason;
	}
}
