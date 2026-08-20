<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Integration;

use OCA\BudgetCheck\AppInfo\Application;
use OCA\BudgetCheck\Service\RateLimitService;
use Test\TestCase;

/**
 * Live Nextcloud config: UUID-style LDAP userIds must not trip the 64-char
 * preferences/appconfig key limit when favoriting (GitHub #17).
 */
class RateLimitUuidUserIdIntegrationTest extends TestCase
{
	/** 36-char UUID-style UID (LDAP/AD objectGUID shape). */
	private const UUID_USER = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';

	protected function setUp(): void
	{
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud is not bootstrapped (set NEXTCLOUD_ROOT or run inside Docker).');
		}
		// Synthetic UID — 36 chars like AD objectGUID style identifiers.
		self::assertSame(36, strlen(self::UUID_USER));
		$this->purgeTestPreferences();
	}

	protected function tearDown(): void
	{
		$this->purgeTestPreferences();
	}

	private function purgeTestPreferences(): void
	{
		if (!isset(\OC::$server)) {
			return;
		}
		/** @var \OCP\IConfig $config */
		$config = \OC::$server->get(\OCP\IConfig::class);
		/** @var RateLimitService $svc */
		$svc = \OC::$server->get(RateLimitService::class);
		$config->deleteUserValue(self::UUID_USER, Application::APP_ID, $svc->preferenceKey('workspace_favorites_write'));
		$config->deleteUserValue(self::UUID_USER, Application::APP_ID, $svc->preferenceKey('category_write'));
	}

	public function testServiceResolvesWithLockingAndTime(): void
	{
		$svc = \OC::$server->get(RateLimitService::class);
		$this->assertInstanceOf(RateLimitService::class, $svc);
	}

	public function testUuidUserCanConsumeFavoritesWriteBucket(): void
	{
		/** @var RateLimitService $svc */
		$svc = \OC::$server->get(RateLimitService::class);
		$key = $svc->preferenceKey('workspace_favorites_write');
		$this->assertLessThanOrEqual(64, strlen($key));
		$this->assertSame('rate_limit:workspace_favorites_write', $key);

		// Must not throw "Value ... for key is too long (64)".
		$svc->assertAllowed(self::UUID_USER, 'workspace_favorites_write', 90, 300);

		/** @var \OCP\IConfig $config */
		$config = \OC::$server->get(\OCP\IConfig::class);
		$raw = $config->getUserValue(self::UUID_USER, Application::APP_ID, $key, '[]');
		$entries = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		$this->assertIsArray($entries);
		$this->assertCount(1, $entries);
		$this->assertIsInt($entries[0]);
	}

	public function testUuidUserHitsCeilingAndIsRateLimited(): void
	{
		/** @var RateLimitService $svc */
		$svc = \OC::$server->get(RateLimitService::class);

		$svc->assertAllowed(self::UUID_USER, 'category_write', 2, 300);
		$svc->assertAllowed(self::UUID_USER, 'category_write', 2, 300);

		$this->expectException(\OCA\BudgetCheck\Exception\RateLimitExceededException::class);
		$svc->assertAllowed(self::UUID_USER, 'category_write', 2, 300);
	}
}
