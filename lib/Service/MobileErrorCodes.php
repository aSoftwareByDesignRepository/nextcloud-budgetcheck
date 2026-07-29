<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

/**
 * Maps domain InvalidArgumentException messages to stable mobile API error codes.
 */
final class MobileErrorCodes
{
	public const MONTH_CLOSED = 'MONTH_CLOSED';
	public const TAX_DISABLED = 'TAX_DISABLED';
	public const VALIDATION = 'VALIDATION';

	public static function fromInvalidArgument(string $message): string
	{
		$lower = strtolower($message);
		if (str_contains($lower, 'closed month') || str_contains($lower, 'month is closed')) {
			return self::MONTH_CLOSED;
		}
		if (str_contains($lower, 'tax') && (str_contains($lower, 'disabled') || str_contains($lower, 'simple'))) {
			return self::TAX_DISABLED;
		}
		return self::VALIDATION;
	}

	public static function httpStatusFor(string $code): int
	{
		return $code === self::MONTH_CLOSED ? 422 : 400;
	}
}
