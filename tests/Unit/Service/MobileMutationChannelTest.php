<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\MobileMutationChannel;
use PHPUnit\Framework\TestCase;

final class MobileMutationChannelTest extends TestCase
{
	public function testAllowsValidatedBasicWithoutCsrf(): void
	{
		self::assertTrue(MobileMutationChannel::isSafe(false, true));
	}

	public function testAllowsCsrfWithoutBasic(): void
	{
		self::assertTrue(MobileMutationChannel::isSafe(true, false));
		self::assertTrue(MobileMutationChannel::isSafe(true, true));
	}

	public function testRejectsWhenNeitherCsrfNorValidatedBasic(): void
	{
		self::assertFalse(MobileMutationChannel::isSafe(false, false));
	}

	public function testPresenceOnlyAuthIsNotAChannelArgument(): void
	{
		// Historical bug: Authorization header presence alone bypassed CSRF.
		// The helper now only accepts cryptographic CSRF or validated Basic.
		self::assertFalse(MobileMutationChannel::isSafe(false, false));
	}
}
