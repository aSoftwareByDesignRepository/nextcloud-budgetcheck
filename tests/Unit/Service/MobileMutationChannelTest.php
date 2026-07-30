<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\MobileMutationChannel;
use PHPUnit\Framework\TestCase;

final class MobileMutationChannelTest extends TestCase
{
	public function testAllowsBasicAuth(): void
	{
		self::assertTrue(MobileMutationChannel::isSafe('Basic dXNlcjpwYXNz', false));
	}

	public function testAllowsBearerAuth(): void
	{
		self::assertTrue(MobileMutationChannel::isSafe('Bearer tok-123', false));
	}

	public function testRejectsEmptyAuthScheme(): void
	{
		self::assertFalse(MobileMutationChannel::isSafe('Basic ', false));
		self::assertFalse(MobileMutationChannel::isSafe('Token abc', false));
		self::assertFalse(MobileMutationChannel::isSafe('', false));
		self::assertFalse(MobileMutationChannel::isSafe(null, false));
	}

	public function testAllowsWhenCsrfCheckPasses(): void
	{
		self::assertTrue(MobileMutationChannel::isSafe(null, true));
		self::assertTrue(MobileMutationChannel::isSafe('', true));
	}

	public function testRejectsWhenCsrfCheckFails(): void
	{
		self::assertFalse(MobileMutationChannel::isSafe(null, false));
		self::assertFalse(MobileMutationChannel::isSafe('', false));
	}

	public function testForgedNonEmptyTokenAloneIsInsufficient(): void
	{
		// Historical bug: any non-empty requesttoken string bypassed the gate.
		// Cryptographic validity is expressed only via $csrfPassed.
		self::assertFalse(MobileMutationChannel::isSafe(null, false));
	}

	public function testPrefersAuthOverFailedCsrf(): void
	{
		self::assertTrue(MobileMutationChannel::isSafe('Basic abc', false));
	}
}
