<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\AccessControlService;
use OCA\BudgetCheck\Service\MoneyService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Privacy create gates and normalisation (Q1/Q2) — no DB required.
 */
final class AccessControlPrivacyCreateGateTest extends TestCase
{
	public function testNormalisePrivacyModeDefaultsAndRejectsGarbage(): void
	{
		$service = $this->newService();
		self::assertSame(AccessControlService::PRIVACY_STANDARD, $service->normalisePrivacyMode(null));
		self::assertSame(AccessControlService::PRIVACY_STANDARD, $service->normalisePrivacyMode(''));
		self::assertSame(AccessControlService::PRIVACY_PRIVATE, $service->normalisePrivacyMode('private'));
		self::assertSame(AccessControlService::PRIVACY_STANDARD, $service->normalisePrivacyMode('STANDARD'));
		$this->expectException(\InvalidArgumentException::class);
		$service->normalisePrivacyMode('secret');
	}

	public function testCanCreateStandardRequiresAppAdmin(): void
	{
		$service = $this->newService([
			AccessControlService::KEY_ACCESS_RESTRICTION => '0',
		]);
		self::assertFalse($service->canCreateWorkspace('alice', AccessControlService::PRIVACY_STANDARD));
		self::assertTrue($service->canCreateWorkspace('alice', AccessControlService::PRIVACY_PRIVATE));
		self::assertTrue($service->canCreateAnyWorkspace('alice'));
	}

	public function testCanCreateStandardAsAppAdmin(): void
	{
		$service = $this->newService([
			AccessControlService::KEY_APP_ADMINS => json_encode(['alice'], JSON_THROW_ON_ERROR),
		]);
		self::assertTrue($service->canCreateWorkspace('alice', AccessControlService::PRIVACY_STANDARD));
		self::assertTrue($service->canCreateWorkspace('alice', AccessControlService::PRIVACY_PRIVATE));
	}

	public function testRestrictedDoorBlocksPrivateCreate(): void
	{
		$service = $this->newService([
			AccessControlService::KEY_ACCESS_RESTRICTION => '1',
			AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS => json_encode(['bob'], JSON_THROW_ON_ERROR),
		]);
		self::assertFalse($service->canCreateWorkspace('alice', AccessControlService::PRIVACY_PRIVATE));
		self::assertFalse($service->canCreateAnyWorkspace('alice'));
	}

	public function testUnknownPrivacyModeCannotCreate(): void
	{
		$service = $this->newService();
		self::assertFalse($service->canCreateWorkspace('alice', 'encrypted'));
	}

	public function testEmptyUserCannotCreate(): void
	{
		$service = $this->newService();
		self::assertFalse($service->canCreateWorkspace('', AccessControlService::PRIVACY_PRIVATE));
	}

	/**
	 * @param array<string, string> $config
	 */
	private function newService(array $config = []): AccessControlService
	{
		$configMock = $this->createMock(IConfig::class);
		$configMock->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($config): string {
				return $config[$key] ?? $default;
			}
		);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->method('isInGroup')->willReturn(false);

		return new AccessControlService(
			$this->createMock(IDBConnection::class),
			$configMock,
			$groupManager,
			$this->createMock(IUserSession::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(IUserManager::class),
			$this->createMock(MoneyService::class),
			$this->createMock(LoggerInterface::class),
		);
	}
}
