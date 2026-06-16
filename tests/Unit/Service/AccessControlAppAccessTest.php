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
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Verifies that app-entry access follows directory policy only.
 * Workspace membership is enforced by workspace-scoped service checks.
 */
final class AccessControlAppAccessTest extends TestCase
{
	public function testRestrictionDisabledAllowsAuthenticatedUser(): void
	{
		$service = $this->newService([
			AccessControlService::KEY_ACCESS_RESTRICTION => '0',
		]);

		$this->assertTrue($service->canUseApp('alice'));
	}

	public function testRestrictionEnabledDeniesUserOutsideAllowLists(): void
	{
		$service = $this->newService([
			AccessControlService::KEY_ACCESS_RESTRICTION => '1',
			AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS => json_encode(['bob'], JSON_THROW_ON_ERROR),
			AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS => json_encode(['finance'], JSON_THROW_ON_ERROR),
		]);

		$this->assertFalse($service->canUseApp('alice'));
		$this->assertSame(AccessControlService::DENIAL_RESTRICTION, $service->denialReasonWhenCannotUseApp('alice'));
	}

	public function testRestrictionEnabledAllowsExplicitUser(): void
	{
		$service = $this->newService([
			AccessControlService::KEY_ACCESS_RESTRICTION => '1',
			AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS => json_encode(['alice'], JSON_THROW_ON_ERROR),
		]);

		$this->assertTrue($service->canUseApp('alice'));
	}

	public function testRestrictionEnabledAllowsUserFromAllowedGroup(): void
	{
		$service = $this->newService([
			AccessControlService::KEY_ACCESS_RESTRICTION => '1',
			AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS => json_encode(['finance'], JSON_THROW_ON_ERROR),
		], static function ($groupManager): void {
			$groupManager->method('isInGroup')
				->willReturnCallback(static fn (string $userId, string $gid): bool => $userId === 'alice' && $gid === 'finance');
		});

		$this->assertTrue($service->canUseApp('alice'));
	}

	public function testAppAdminPassesRestriction(): void
	{
		$service = $this->newService([
			AccessControlService::KEY_ACCESS_RESTRICTION => '1',
			AccessControlService::KEY_APP_ADMINS => json_encode(['alice'], JSON_THROW_ON_ERROR),
			AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS => json_encode([], JSON_THROW_ON_ERROR),
			AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS => json_encode([], JSON_THROW_ON_ERROR),
		]);

		$this->assertTrue($service->canUseApp('alice'));
	}

	/**
	 * @param array<string, string> $appValues
	 * @param null|callable(\PHPUnit\Framework\MockObject\MockObject):void $configureGroupManager
	 */
	private function newService(array $appValues, ?callable $configureGroupManager = null): AccessControlService
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')
			->willReturnCallback(static function (string $app, string $key, string $default = '') use ($appValues): string {
				if ($app !== Application::APP_ID) {
					return $default;
				}
				return $appValues[$key] ?? $default;
			});

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		if ($configureGroupManager === null) {
			$groupManager->method('isInGroup')->willReturn(false);
		} else {
			$configureGroupManager($groupManager);
		}

		return new AccessControlService(
			$this->createMock(IDBConnection::class),
			$config,
			$groupManager,
			$this->createMock(IUserSession::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(IUserManager::class),
			$this->createMock(MoneyService::class),
			$this->createMock(LoggerInterface::class),
		);
	}
}
