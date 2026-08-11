<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Exception\AccessDeniedException;
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
 * Behavioral ACL matrix for private vs standard workspaces (MH-02 / AC-12 / AC-21).
 */
final class AccessControlPrivacyRoleMatrixTest extends TestCase
{
	public function testPrivateIgnoresAppAdminBypassAndGroups(): void
	{
		$svc = $this->matrixService(
			privacy: AccessControlService::PRIVACY_PRIVATE,
			admins: ['admin'],
			individual: ['member' => AccessControlService::ROLE_MANAGER],
			groups: ['admin' => [AccessControlService::ROLE_CONTRIBUTOR]],
		);

		self::assertNull($svc->role(42, 'admin'));
		self::assertSame(AccessControlService::ROLE_MANAGER, $svc->role(42, 'member'));
	}

	public function testStandardAppAdminIsUniversalManager(): void
	{
		$svc = $this->matrixService(
			privacy: AccessControlService::PRIVACY_STANDARD,
			admins: ['admin'],
			individual: [],
			groups: [],
		);

		self::assertSame(AccessControlService::ROLE_MANAGER, $svc->role(7, 'admin'));
	}

	public function testStandardMergesIndividualAndGroupRoles(): void
	{
		$svc = $this->matrixService(
			privacy: AccessControlService::PRIVACY_STANDARD,
			admins: [],
			individual: ['alice' => AccessControlService::ROLE_VIEWER],
			groups: ['alice' => [AccessControlService::ROLE_CONTRIBUTOR]],
		);

		self::assertSame(AccessControlService::ROLE_CONTRIBUTOR, $svc->role(9, 'alice'));
	}

	public function testEnsureMembershipThrowsWhenPrivateNonMember(): void
	{
		$svc = $this->matrixService(
			privacy: AccessControlService::PRIVACY_PRIVATE,
			admins: ['admin'],
			individual: [],
			groups: ['admin' => [AccessControlService::ROLE_MANAGER]],
		);

		$this->expectException(AccessDeniedException::class);
		$svc->ensureMembership(11, 'admin');
	}

	public function testEnsureMinimumRoleHonorsPrivateMembership(): void
	{
		$svc = $this->matrixService(
			privacy: AccessControlService::PRIVACY_PRIVATE,
			admins: ['admin'],
			individual: ['spouse' => AccessControlService::ROLE_CONTRIBUTOR],
			groups: [],
		);

		self::assertSame(
			AccessControlService::ROLE_CONTRIBUTOR,
			$svc->ensureMinimumRole(12, 'spouse', AccessControlService::ROLE_VIEWER)
		);
		$this->expectException(AccessDeniedException::class);
		$svc->ensureMinimumRole(12, 'spouse', AccessControlService::ROLE_MANAGER);
	}

	/**
	 * @param list<string> $admins
	 * @param array<string, string> $individual
	 * @param array<string, list<string>> $groups
	 */
	private function matrixService(
		string $privacy,
		array $admins,
		array $individual,
		array $groups,
	): AccessControlService {
		return new class (
			$this->createMock(IDBConnection::class),
			$this->createMock(IConfig::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserSession::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(IUserManager::class),
			$this->createMock(MoneyService::class),
			$this->createMock(LoggerInterface::class),
			$privacy,
			$admins,
			$individual,
			$groups,
		) extends AccessControlService {
			/**
			 * @param list<string> $admins
			 * @param array<string, string> $individual
			 * @param array<string, list<string>> $groups
			 */
			public function __construct(
				IDBConnection $db,
				IConfig $config,
				IGroupManager $groupManager,
				IUserSession $userSession,
				ITimeFactory $timeFactory,
				IUserManager $userManager,
				MoneyService $money,
				LoggerInterface $logger,
				private string $fixedPrivacy,
				private array $admins,
				private array $individual,
				private array $groups,
			) {
				parent::__construct($db, $config, $groupManager, $userSession, $timeFactory, $userManager, $money, $logger);
			}

			public function privacyMode(int $workspaceId): string
			{
				return $this->fixedPrivacy;
			}

			public function isAppAdmin(string $userId): bool
			{
				return in_array($userId, $this->admins, true);
			}

			protected function individualRole(int $workspaceId, string $userId): ?string
			{
				return $this->individual[$userId] ?? null;
			}

			protected function groupRolesForUser(int $workspaceId, string $userId): array
			{
				return $this->groups[$userId] ?? [];
			}
		};
	}
}
