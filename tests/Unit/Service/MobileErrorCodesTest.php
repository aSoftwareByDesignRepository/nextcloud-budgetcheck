<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\MobileErrorCodes;
use PHPUnit\Framework\TestCase;

final class MobileErrorCodesTest extends TestCase
{
	public function testMapsClosedMonth(): void
	{
		self::assertSame(
			MobileErrorCodes::MONTH_CLOSED,
			MobileErrorCodes::fromInvalidArgument('Cannot write: month is closed')
		);
		self::assertSame(
			MobileErrorCodes::MONTH_CLOSED,
			MobileErrorCodes::fromInvalidArgument('Closed month booking denied')
		);
		self::assertSame(422, MobileErrorCodes::httpStatusFor(MobileErrorCodes::MONTH_CLOSED));
	}

	public function testMapsTaxDisabled(): void
	{
		self::assertSame(
			MobileErrorCodes::TAX_DISABLED,
			MobileErrorCodes::fromInvalidArgument('Tax fields are disabled in simple mode')
		);
	}

	public function testDefaultsToValidation(): void
	{
		self::assertSame(
			MobileErrorCodes::VALIDATION,
			MobileErrorCodes::fromInvalidArgument('version is required for deletes')
		);
		self::assertSame(400, MobileErrorCodes::httpStatusFor(MobileErrorCodes::VALIDATION));
	}

	public function testTaxWithoutDisabledKeywordIsValidation(): void
	{
		self::assertSame(
			MobileErrorCodes::VALIDATION,
			MobileErrorCodes::fromInvalidArgument('Invalid tax rate')
		);
	}
}
