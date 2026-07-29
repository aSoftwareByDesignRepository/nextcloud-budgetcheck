<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\MobileMutationChannel;
use PHPUnit\Framework\TestCase;

final class MobileMutationChannelTest extends TestCase
{
	public function testAllowsBasicAuth(): void
	{
		self::assertTrue(MobileMutationChannel::isSafe('Basic dXNlcjpwYXNz', null, null));
	}

	public function testAllowsBearerAuth(): void
	{
		self::assertTrue(MobileMutationChannel::isSafe('Bearer tok-123', null, null));
	}

	public function testRejectsEmptyAuthScheme(): void
	{
		self::assertFalse(MobileMutationChannel::isSafe('Basic ', null, null));
		self::assertFalse(MobileMutationChannel::isSafe('Token abc', null, null));
		self::assertFalse(MobileMutationChannel::isSafe('', null, null));
		self::assertFalse(MobileMutationChannel::isSafe(null, null, null));
	}

	public function testAllowsRequesttokenHeader(): void
	{
		self::assertTrue(MobileMutationChannel::isSafe(null, 'csrf-token', null));
	}

	public function testAllowsRequesttokenParam(): void
	{
		self::assertTrue(MobileMutationChannel::isSafe(null, null, 'csrf-token'));
	}

	public function testRejectsWhitespaceOnlyToken(): void
	{
		self::assertFalse(MobileMutationChannel::isSafe(null, '   ', '  '));
	}

	public function testPrefersAuthOverMissingToken(): void
	{
		self::assertTrue(MobileMutationChannel::isSafe('Basic abc', '', ''));
	}
}
