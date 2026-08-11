<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Integration;

use OCA\BudgetCheck\AppInfo\Application;
use OCA\BudgetCheck\Exception\AccessDeniedException;
use OCA\BudgetCheck\Exception\ConflictException;
use OCA\BudgetCheck\Service\AccessControlService;
use OCA\BudgetCheck\Service\WorkspaceService;
use OCP\IConfig;
use OCP\IUserManager;
use Test\TestCase;

/**
 * Live-container ACL proof for private workspaces (MH-08 / AC-1,2,3,5–12,16).
 */
final class PrivateWorkspacesAclIntegrationTest extends TestCase
{
	private const OWNER = 'bc_priv_owner';
	private const SPOUSE = 'bc_priv_spouse';
	private const ADMIN = 'bc_priv_admin';
	private const PASSWORD = 'bc-priv-pass-9xK!';

	/** @var list<int> */
	private array $workspaceIds = [];

	/** @var list<string> */
	private array $createdUsers = [];

	/** @var array{admins:?string, restriction:?string, allowedUsers:?string} */
	private array $prev = ['admins' => null, 'restriction' => null, 'allowedUsers' => null];

	protected function setUp(): void
	{
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud is not bootstrapped (run inside Docker with NEXTCLOUD_ROOT).');
		}
		/** @var IConfig $config */
		$config = \OC::$server->get(IConfig::class);
		$this->prev['admins'] = $config->getAppValue(Application::APP_ID, AccessControlService::KEY_APP_ADMINS, '[]');
		$this->prev['restriction'] = $config->getAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, '0');
		$this->prev['allowedUsers'] = $config->getAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS, '[]');

		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, '0');
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS, '[]');

		/** @var IUserManager $users */
		$users = \OC::$server->get(IUserManager::class);
		foreach ([self::OWNER, self::SPOUSE, self::ADMIN] as $uid) {
			if ($users->userExists($uid)) {
				$users->get($uid)?->delete();
			}
			$users->createUser($uid, self::PASSWORD);
			$this->createdUsers[] = $uid;
		}
		$config->setAppValue(
			Application::APP_ID,
			AccessControlService::KEY_APP_ADMINS,
			json_encode([self::ADMIN], JSON_THROW_ON_ERROR),
		);
	}

	protected function tearDown(): void
	{
		if (!isset(\OC::$server)) {
			return;
		}
		/** @var AccessControlService $access */
		$access = \OC::$server->get(AccessControlService::class);
		/** @var WorkspaceService $workspaces */
		$workspaces = \OC::$server->get(WorkspaceService::class);
		foreach ($this->workspaceIds as $id) {
			try {
				// Soft-delete via SQL if needed — remove memberships first.
				$db = \OC::$server->get(\OCP\IDBConnection::class);
				$qb = $db->getQueryBuilder();
				$qb->delete('bc_workspace_members')
					->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($id, \PDO::PARAM_INT)));
				$qb->executeStatement();
				$qb = $db->getQueryBuilder();
				$qb->delete('bc_workspace_groups')
					->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($id, \PDO::PARAM_INT)));
				$qb->executeStatement();
				$qb = $db->getQueryBuilder();
				$qb->delete('bc_workspaces')
					->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)));
				$qb->executeStatement();
				$access->forgetPrivacyModeCache($id);
			} catch (\Throwable) {
				// best-effort cleanup
			}
			unset($workspaces);
		}
		/** @var IConfig $config */
		$config = \OC::$server->get(IConfig::class);
		if ($this->prev['admins'] !== null) {
			$config->setAppValue(Application::APP_ID, AccessControlService::KEY_APP_ADMINS, $this->prev['admins']);
		}
		if ($this->prev['restriction'] !== null) {
			$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, $this->prev['restriction']);
		}
		if ($this->prev['allowedUsers'] !== null) {
			$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS, $this->prev['allowedUsers']);
		}
		/** @var IUserManager $users */
		$users = \OC::$server->get(IUserManager::class);
		foreach ($this->createdUsers as $uid) {
			try {
				if ($users->userExists($uid)) {
					$users->get($uid)?->delete();
				}
			} catch (\Throwable) {
			}
		}
	}

	public function testAdminNonMemberBlindToPrivateWorkspace(): void
	{
		/** @var WorkspaceService $workspaces */
		$workspaces = \OC::$server->get(WorkspaceService::class);
		/** @var AccessControlService $access */
		$access = \OC::$server->get(AccessControlService::class);

		$created = $workspaces->createWorkspace(self::OWNER, [
			'name' => 'Private Household',
			'type' => 'household',
			'privacyMode' => 'private',
			'primaryPlanningYear' => (int)date('Y'),
		]);
		$id = (int)$created['id'];
		$this->workspaceIds[] = $id;

		self::assertSame('private', $created['privacyMode']);
		self::assertSame(AccessControlService::ROLE_MANAGER, $access->role($id, self::OWNER));
		self::assertNull($access->role($id, self::ADMIN), 'App admin must not inherit private manager role');

		$ownerIds = $access->workspacesForUser(self::OWNER);
		$adminIds = $access->workspacesForUser(self::ADMIN);
		self::assertContains($id, $ownerIds);
		self::assertNotContains($id, $adminIds, 'Admin list must exclude private non-membered workspace');

		try {
			$workspaces->getForUser($id, self::ADMIN);
			$this->fail('Non-member admin must not getForUser private workspace');
		} catch (AccessDeniedException) {
			$this->addToAssertionCount(1);
		}

		// Unknown id and inaccessible private must be the same exception type (opacity).
		try {
			$workspaces->getForUser(999999991, self::ADMIN);
			$this->fail('Missing workspace must deny');
		} catch (AccessDeniedException) {
			$this->addToAssertionCount(1);
		}
	}

	public function testMemberSeesPrivateAndAdminBreakGlassReturnsAfterStandard(): void
	{
		/** @var WorkspaceService $workspaces */
		$workspaces = \OC::$server->get(WorkspaceService::class);
		/** @var AccessControlService $access */
		$access = \OC::$server->get(AccessControlService::class);

		$standard = $workspaces->createWorkspace(self::ADMIN, [
			'name' => 'Admin Standard',
			'type' => 'household',
			'privacyMode' => 'standard',
			'primaryPlanningYear' => (int)date('Y'),
		]);
		$id = (int)$standard['id'];
		$this->workspaceIds[] = $id;

		// Two individual managers (owner + spouse). Then remove the creating admin
		// from membership so private mode can prove admin-bypass blindness.
		$workspaces->addMember($id, self::ADMIN, [
			'userId' => self::OWNER,
			'role' => AccessControlService::ROLE_MANAGER,
		]);
		$workspaces->addMember($id, self::ADMIN, [
			'userId' => self::SPOUSE,
			'role' => AccessControlService::ROLE_MANAGER,
		]);
		$members = $workspaces->listMembers($id, self::ADMIN);
		$adminMemberId = null;
		foreach ($members as $row) {
			if (($row['type'] ?? 'user') === 'user' && ($row['userId'] ?? '') === self::ADMIN) {
				$adminMemberId = (int)$row['id'];
				break;
			}
		}
		self::assertNotNull($adminMemberId);
		$workspaces->removeMember($adminMemberId, self::OWNER);

		$updated = $workspaces->updateWorkspace($id, self::OWNER, ['privacyMode' => 'private']);
		self::assertSame('private', $updated['privacyMode']);
		$access->forgetPrivacyModeCache($id);
		self::assertNull($access->role($id, self::ADMIN));
		self::assertSame(AccessControlService::ROLE_MANAGER, $access->role($id, self::OWNER));
		self::assertNotContains($id, $access->workspacesForUser(self::ADMIN));

		$reverted = $workspaces->updateWorkspace($id, self::OWNER, ['privacyMode' => 'standard']);
		self::assertSame('standard', $reverted['privacyMode']);
		$access->forgetPrivacyModeCache($id);
		self::assertSame(AccessControlService::ROLE_MANAGER, $access->role($id, self::ADMIN));
		self::assertContains($id, $access->workspacesForUser(self::ADMIN));
	}

	public function testGroupAssignForbiddenOnPrivateAndDualManagerRequired(): void
	{
		/** @var WorkspaceService $workspaces */
		$workspaces = \OC::$server->get(WorkspaceService::class);

		$created = $workspaces->createWorkspace(self::OWNER, [
			'name' => 'Solo Private',
			'type' => 'household',
			'privacyMode' => 'private',
			'primaryPlanningYear' => (int)date('Y'),
		]);
		$id = (int)$created['id'];
		$this->workspaceIds[] = $id;

		try {
			$workspaces->addGroupMember($id, self::OWNER, [
				'groupId' => 'admin',
				'role' => AccessControlService::ROLE_VIEWER,
			]);
			$this->fail('Groups must be forbidden on private workspaces');
		} catch (ConflictException $e) {
			self::assertSame(ConflictException::CODE_PRIVATE_WORKSPACE_GROUPS_FORBIDDEN, $e->getErrorCode());
		}

		// Convert path: create standard with one manager only → private must fail dual-manager.
		$std = $workspaces->createWorkspace(self::ADMIN, [
			'name' => 'Needs Two Managers',
			'type' => 'household',
			'privacyMode' => 'standard',
			'primaryPlanningYear' => (int)date('Y'),
		]);
		$stdId = (int)$std['id'];
		$this->workspaceIds[] = $stdId;
		// Admin is individual manager via create; still only one manager.
		try {
			$workspaces->updateWorkspace($stdId, self::ADMIN, ['privacyMode' => 'private']);
			$this->fail('→ private with one manager must conflict');
		} catch (ConflictException $e) {
			self::assertSame(ConflictException::CODE_PRIVATE_WORKSPACE_DUAL_MANAGER, $e->getErrorCode());
		}
	}

	public function testDoorPasserCreatesPrivateButNotStandard(): void
	{
		/** @var WorkspaceService $workspaces */
		$workspaces = \OC::$server->get(WorkspaceService::class);
		/** @var AccessControlService $access */
		$access = \OC::$server->get(AccessControlService::class);

		self::assertTrue($access->canCreateWorkspace(self::OWNER, AccessControlService::PRIVACY_PRIVATE));
		self::assertFalse($access->canCreateWorkspace(self::OWNER, AccessControlService::PRIVACY_STANDARD));
		self::assertTrue($access->canCreateWorkspace(self::ADMIN, AccessControlService::PRIVACY_STANDARD));

		try {
			$workspaces->createWorkspace(self::OWNER, [
				'name' => 'Should Fail',
				'type' => 'household',
				'privacyMode' => 'standard',
				'primaryPlanningYear' => (int)date('Y'),
			]);
			$this->fail('Non-admin must not create standard workspace via service');
		} catch (AccessDeniedException) {
			$this->addToAssertionCount(1);
		}

		$private = $workspaces->createWorkspace(self::OWNER, [
			'name' => 'Door Private',
			'type' => 'household',
			'privacyMode' => 'private',
			'primaryPlanningYear' => (int)date('Y'),
		]);
		$this->workspaceIds[] = (int)$private['id'];
		self::assertSame('private', $private['privacyMode']);
	}

	public function testOpaquePrivateWorkspaceCount(): void
	{
		/** @var WorkspaceService $workspaces */
		$workspaces = \OC::$server->get(WorkspaceService::class);
		/** @var AccessControlService $access */
		$access = \OC::$server->get(AccessControlService::class);

		$before = $access->countPrivateWorkspaces();
		$private = $workspaces->createWorkspace(self::OWNER, [
			'name' => 'Counted Private',
			'type' => 'household',
			'privacyMode' => 'private',
			'primaryPlanningYear' => (int)date('Y'),
		]);
		$this->workspaceIds[] = (int)$private['id'];
		self::assertSame($before + 1, $access->countPrivateWorkspaces());
		$policy = $access->getAppPolicy();
		self::assertArrayHasKey('privateWorkspaceCount', $policy);
		self::assertIsInt($policy['privateWorkspaceCount']);
		self::assertStringNotContainsStringIgnoringCase('Counted Private', json_encode($policy, JSON_THROW_ON_ERROR));
	}

	public function testDemoteSecondPrivateManagerIsBlocked(): void
	{
		/** @var WorkspaceService $workspaces */
		$workspaces = \OC::$server->get(WorkspaceService::class);

		$created = $workspaces->createWorkspace(self::OWNER, [
			'name' => 'Dual Managers Private',
			'type' => 'household',
			'privacyMode' => 'private',
			'primaryPlanningYear' => (int)date('Y'),
		]);
		$id = (int)$created['id'];
		$this->workspaceIds[] = $id;
		$workspaces->addMember($id, self::OWNER, [
			'userId' => self::SPOUSE,
			'role' => AccessControlService::ROLE_MANAGER,
		]);

		$spouseMemberId = null;
		foreach ($workspaces->listMembers($id, self::OWNER) as $row) {
			if (($row['type'] ?? '') === 'user' && ($row['userId'] ?? '') === self::SPOUSE) {
				$spouseMemberId = (int)$row['id'];
				break;
			}
		}
		self::assertNotNull($spouseMemberId);

		try {
			$workspaces->updateMember($spouseMemberId, self::OWNER, [
				'role' => AccessControlService::ROLE_CONTRIBUTOR,
			]);
			$this->fail('Demoting one of two private managers must conflict');
		} catch (ConflictException $e) {
			self::assertSame(ConflictException::CODE_PRIVATE_WORKSPACE_DUAL_MANAGER, $e->getErrorCode());
		}

		try {
			$workspaces->removeMember($spouseMemberId, self::OWNER);
			$this->fail('Removing one of two private managers must conflict');
		} catch (ConflictException $e) {
			self::assertSame(ConflictException::CODE_PRIVATE_WORKSPACE_DUAL_MANAGER, $e->getErrorCode());
		}
	}

	public function testPurgeUserLeavesRemainingPrivateManager(): void
	{
		/** @var WorkspaceService $workspaces */
		$workspaces = \OC::$server->get(WorkspaceService::class);
		/** @var AccessControlService $access */
		$access = \OC::$server->get(AccessControlService::class);

		$created = $workspaces->createWorkspace(self::OWNER, [
			'name' => 'Purge Survivor',
			'type' => 'household',
			'privacyMode' => 'private',
			'primaryPlanningYear' => (int)date('Y'),
		]);
		$id = (int)$created['id'];
		$this->workspaceIds[] = $id;
		$workspaces->addMember($id, self::OWNER, [
			'userId' => self::SPOUSE,
			'role' => AccessControlService::ROLE_MANAGER,
		]);

		$access->purgeUser(self::OWNER);
		$access->forgetPrivacyModeCache($id);

		self::assertNull($access->role($id, self::OWNER));
		self::assertSame(AccessControlService::ROLE_MANAGER, $access->role($id, self::SPOUSE));
		self::assertSame(AccessControlService::PRIVACY_PRIVATE, $access->privacyMode($id));
		self::assertContains($id, $access->workspacesForUser(self::SPOUSE));
		self::assertNotContains($id, $access->workspacesForUser(self::ADMIN));
	}

	public function testFavoritesClipInvisiblePrivateWorkspace(): void
	{
		/** @var WorkspaceService $workspaces */
		$workspaces = \OC::$server->get(WorkspaceService::class);
		/** @var AccessControlService $access */
		$access = \OC::$server->get(AccessControlService::class);

		$created = $workspaces->createWorkspace(self::OWNER, [
			'name' => 'Fav Clip Private',
			'type' => 'household',
			'privacyMode' => 'private',
			'primaryPlanningYear' => (int)date('Y'),
		]);
		$id = (int)$created['id'];
		$this->workspaceIds[] = $id;

		// Stale favorite pointing at a private workspace the admin cannot see.
		$access->saveFavoriteWorkspaceIds(self::ADMIN, [$id, 999999]);
		$stored = $access->favoriteWorkspaceIds(self::ADMIN);
		self::assertContains($id, $stored);

		$visible = $access->workspacesForUser(self::ADMIN);
		$clipped = array_values(array_map('intval', array_intersect($stored, $visible)));
		self::assertNotContains($id, $clipped, 'Private non-membered workspace must clip from favorites response');
		self::assertNotContains(999999, $clipped);
	}
}
