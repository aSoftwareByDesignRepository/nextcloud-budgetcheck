<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\AppInfo\Application;
use OCA\BudgetCheck\Service\AccessControlService;
use OCA\BudgetCheck\Service\MoneyService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Multipage app-settings must never wipe sibling policy scopes when a section
 * posts a partial body. Invalid settings_section must fail closed (never "all").
 */
final class AccessControlSaveAppPolicyScopeTest extends TestCase
{
	/** @var array<string, string> */
	private array $appValues = [];

	public function testMissingSettingsSectionStillDoesFullReplace(): void
	{
		$service = $this->serviceWithSeed([
			AccessControlService::KEY_APP_ADMINS => json_encode(['admin1'], JSON_THROW_ON_ERROR),
			AccessControlService::KEY_ACCESS_RESTRICTION => '1',
			AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS => json_encode(['u1'], JSON_THROW_ON_ERROR),
			AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS => json_encode(['g1'], JSON_THROW_ON_ERROR),
			AccessControlService::KEY_DEFAULT_TIMEZONE => 'Europe/Berlin',
			AccessControlService::KEY_DEFAULT_CURRENCY => 'EUR',
		]);

		$admin = $this->createMock(IUser::class);
		$admin->method('isEnabled')->willReturn(true);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(static fn (string $uid): ?IUser => $uid === 'admin9' ? $admin : null);

		$service = $this->serviceWithSeed($this->appValues, $userManager);
		$policy = $service->saveAppPolicy([
			'appAdminUserIds' => ['admin9'],
			'accessRestrictionEnabled' => false,
			'allowedUserIds' => [],
			'allowedGroupIds' => [],
			'defaultTimezone' => 'America/New_York',
			'defaultCurrency' => 'USD',
		]);

		self::assertSame(['admin9'], $policy['appAdminUserIds']);
		self::assertFalse($policy['accessRestrictionEnabled']);
		self::assertSame([], $policy['allowedUserIds']);
		self::assertSame('America/New_York', $policy['defaultTimezone']);
		self::assertSame('USD', $policy['defaultCurrency']);
	}

	public function testAdminsScopeDoesNotWipeAccessAllowlists(): void
	{
		$admin = $this->createMock(IUser::class);
		$admin->method('isEnabled')->willReturn(true);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(static fn (string $uid): ?IUser => $uid === 'admin9' ? $admin : null);

		$service = $this->serviceWithSeed([
			AccessControlService::KEY_APP_ADMINS => json_encode(['admin1'], JSON_THROW_ON_ERROR),
			AccessControlService::KEY_ACCESS_RESTRICTION => '1',
			AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS => json_encode(['u1'], JSON_THROW_ON_ERROR),
			AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS => json_encode(['g1'], JSON_THROW_ON_ERROR),
			AccessControlService::KEY_DEFAULT_TIMEZONE => 'Europe/Berlin',
			AccessControlService::KEY_DEFAULT_CURRENCY => 'EUR',
		], $userManager);

		$policy = $service->saveAppPolicy([
			'settings_section' => 'admins',
			'appAdminUserIds' => ['admin9'],
			// Malicious / buggy partial would wipe these if the server replaced all:
			'accessRestrictionEnabled' => false,
			'allowedUserIds' => [],
			'allowedGroupIds' => [],
			'defaultTimezone' => 'UTC',
			'defaultCurrency' => 'CHF',
		]);

		self::assertSame(['admin9'], $policy['appAdminUserIds']);
		self::assertTrue($policy['accessRestrictionEnabled']);
		self::assertSame(['u1'], $policy['allowedUserIds']);
		self::assertSame(['g1'], $policy['allowedGroupIds']);
		self::assertSame('Europe/Berlin', $policy['defaultTimezone']);
		self::assertSame('EUR', $policy['defaultCurrency']);
	}

	public function testAccessScopeDoesNotWipeAppAdmins(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('isEnabled')->willReturn(true);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(static fn (string $uid): ?IUser => $uid === 'u2' ? $user : null);

		$service = $this->serviceWithSeed([
			AccessControlService::KEY_APP_ADMINS => json_encode(['admin1', 'admin2'], JSON_THROW_ON_ERROR),
			AccessControlService::KEY_ACCESS_RESTRICTION => '0',
			AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS => json_encode([], JSON_THROW_ON_ERROR),
			AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS => json_encode([], JSON_THROW_ON_ERROR),
			AccessControlService::KEY_DEFAULT_TIMEZONE => 'Europe/Berlin',
			AccessControlService::KEY_DEFAULT_CURRENCY => 'EUR',
		], $userManager);

		$policy = $service->saveAppPolicy([
			'settings_section' => 'access',
			'appAdminUserIds' => [],
			'accessRestrictionEnabled' => true,
			'allowedUserIds' => ['u2'],
			'allowedGroupIds' => [],
			'defaultTimezone' => 'UTC',
			'defaultCurrency' => 'CHF',
		]);

		self::assertSame(['admin1', 'admin2'], $policy['appAdminUserIds']);
		self::assertTrue($policy['accessRestrictionEnabled']);
		self::assertSame(['u2'], $policy['allowedUserIds']);
		self::assertSame('Europe/Berlin', $policy['defaultTimezone']);
	}

	public function testDefaultsScopeDoesNotWipeAccessOrAdmins(): void
	{
		$service = $this->serviceWithSeed([
			AccessControlService::KEY_APP_ADMINS => json_encode(['admin1'], JSON_THROW_ON_ERROR),
			AccessControlService::KEY_ACCESS_RESTRICTION => '1',
			AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS => json_encode(['u1'], JSON_THROW_ON_ERROR),
			AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS => json_encode([], JSON_THROW_ON_ERROR),
			AccessControlService::KEY_DEFAULT_TIMEZONE => 'Europe/Berlin',
			AccessControlService::KEY_DEFAULT_CURRENCY => 'EUR',
		]);

		$policy = $service->saveAppPolicy([
			'settings_section' => 'defaults',
			'appAdminUserIds' => [],
			'accessRestrictionEnabled' => false,
			'allowedUserIds' => [],
			'allowedGroupIds' => [],
			'defaultTimezone' => 'America/New_York',
			'defaultCurrency' => 'usd',
		]);

		self::assertSame(['admin1'], $policy['appAdminUserIds']);
		self::assertTrue($policy['accessRestrictionEnabled']);
		self::assertSame(['u1'], $policy['allowedUserIds']);
		self::assertSame('America/New_York', $policy['defaultTimezone']);
		self::assertSame('USD', $policy['defaultCurrency']);
	}

	public function testInvalidSettingsSectionIsRejected(): void
	{
		$service = $this->serviceWithSeed([
			AccessControlService::KEY_APP_ADMINS => json_encode(['admin1'], JSON_THROW_ON_ERROR),
			AccessControlService::KEY_ACCESS_RESTRICTION => '0',
			AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS => json_encode([], JSON_THROW_ON_ERROR),
			AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS => json_encode([], JSON_THROW_ON_ERROR),
			AccessControlService::KEY_DEFAULT_TIMEZONE => 'Europe/Berlin',
			AccessControlService::KEY_DEFAULT_CURRENCY => 'EUR',
		]);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid settings_section');
		$service->saveAppPolicy([
			'settings_section' => 'not-a-real-section',
			'appAdminUserIds' => [],
			'accessRestrictionEnabled' => false,
			'allowedUserIds' => [],
			'allowedGroupIds' => [],
			'defaultTimezone' => 'Europe/Berlin',
			'defaultCurrency' => 'EUR',
		]);
	}

	/**
	 * @param array<string, string> $seed
	 */
	private function serviceWithSeed(array $seed, ?IUserManager $userManager = null): AccessControlService
	{
		$this->appValues = $seed;
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')
			->willReturnCallback(function (string $app, string $key, string $default = ''): string {
				if ($app !== Application::APP_ID) {
					return $default;
				}
				return $this->appValues[$key] ?? $default;
			});
		$config->method('setAppValue')
			->willReturnCallback(function (string $app, string $key, string $value): void {
				if ($app === Application::APP_ID) {
					$this->appValues[$key] = $value;
				}
			});

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->method('isInGroup')->willReturn(false);

		$money = $this->createMock(MoneyService::class);
		$money->method('isSupportedCurrency')->willReturn(true);

		return new AccessControlService(
			$this->createMock(IDBConnection::class),
			$config,
			$groupManager,
			$this->createMock(IUserSession::class),
			$this->createMock(ITimeFactory::class),
			$userManager ?? $this->createMock(IUserManager::class),
			$money,
			$this->createMock(LoggerInterface::class),
		);
	}
}
