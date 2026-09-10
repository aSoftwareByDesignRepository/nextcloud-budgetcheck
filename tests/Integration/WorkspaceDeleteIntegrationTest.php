<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Integration;

use OCA\BudgetCheck\AppInfo\Application;
use OCA\BudgetCheck\Exception\AccessDeniedException;
use OCA\BudgetCheck\Exception\ValidationException;
use OCA\BudgetCheck\Service\AccessControlService;
use OCA\BudgetCheck\Service\WorkspaceDeletionService;
use OCA\BudgetCheck\Service\WorkspaceService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use Test\TestCase;

/**
 * Live-container proof: manager hard-delete cascades; wrong confirm / ACL deny.
 */
final class WorkspaceDeleteIntegrationTest extends TestCase
{
	private const OWNER = 'bc_del_owner';
	private const VIEWER = 'bc_del_viewer';
	private const PASSWORD = 'bc-del-pass-9xK!';

	/** @var list<int> */
	private array $workspaceIds = [];

	/** @var list<string> */
	private array $createdUsers = [];

	/** @var array{admins:?string, restriction:?string} */
	private array $prev = ['admins' => null, 'restriction' => null];

	protected function setUp(): void
	{
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud is not bootstrapped (run inside Docker with NEXTCLOUD_ROOT).');
		}
		/** @var IConfig $config */
		$config = \OC::$server->get(IConfig::class);
		$this->prev['admins'] = $config->getAppValue(Application::APP_ID, AccessControlService::KEY_APP_ADMINS, '[]');
		$this->prev['restriction'] = $config->getAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, '0');
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, '0');
		$config->setAppValue(
			Application::APP_ID,
			AccessControlService::KEY_APP_ADMINS,
			json_encode([self::OWNER], JSON_THROW_ON_ERROR),
		);

		/** @var IUserManager $users */
		$users = \OC::$server->get(IUserManager::class);
		foreach ([self::OWNER, self::VIEWER] as $uid) {
			if ($users->userExists($uid)) {
				$users->get($uid)?->delete();
			}
			$users->createUser($uid, self::PASSWORD);
			$this->createdUsers[] = $uid;
		}
	}

	protected function tearDown(): void
	{
		if (!isset(\OC::$server)) {
			return;
		}
		/** @var IDBConnection $db */
		$db = \OC::$server->get(IDBConnection::class);
		foreach ($this->workspaceIds as $id) {
			try {
				foreach ([
					'bc_tx_attachments',
					'bc_transactions',
					'bc_recurring_rules',
					'bc_budgets',
					'bc_budget_defaults',
					'bc_savings_targets',
					'bc_monthly_snapshots',
					'bc_categories',
					'bc_booking_statuses',
					'bc_idempotency',
					'bc_workspace_members',
					'bc_workspace_groups',
					'bc_workspaces',
				] as $table) {
					if (!$db->tableExists($table)) {
						continue;
					}
					$qb = $db->getQueryBuilder();
					if ($table === 'bc_tx_attachments') {
						continue;
					}
					if ($table === 'bc_workspaces') {
						$qb->delete($table)->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)));
					} else {
						$qb->delete($table)->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($id, \PDO::PARAM_INT)));
					}
					$qb->executeStatement();
				}
			} catch (\Throwable) {
			}
		}
		/** @var IConfig $config */
		$config = \OC::$server->get(IConfig::class);
		if ($this->prev['admins'] !== null) {
			$config->setAppValue(Application::APP_ID, AccessControlService::KEY_APP_ADMINS, $this->prev['admins']);
		}
		if ($this->prev['restriction'] !== null) {
			$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, $this->prev['restriction']);
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

	public function testManagerDeleteRemovesWorkspaceAndChildren(): void
	{
		/** @var WorkspaceService $workspaces */
		$workspaces = \OC::$server->get(WorkspaceService::class);
		/** @var WorkspaceDeletionService $deletion */
		$deletion = \OC::$server->get(WorkspaceDeletionService::class);
		/** @var IDBConnection $db */
		$db = \OC::$server->get(IDBConnection::class);

		$created = $workspaces->createWorkspace(self::OWNER, [
			'name' => 'Delete Me Household',
			'type' => 'household',
			'privacyMode' => 'standard',
			'primaryPlanningYear' => (int)date('Y'),
		]);
		$id = (int)$created['id'];
		$this->workspaceIds[] = $id;

		$beforeQb = $db->getQueryBuilder();
		$beforeQb->select($beforeQb->func()->count('*', 'c'))
			->from('bc_categories')
			->where($beforeQb->expr()->eq('workspace_id', $beforeQb->createNamedParameter($id, \PDO::PARAM_INT)));
		$beforeRes = $beforeQb->executeQuery();
		$beforeRow = $beforeRes->fetch();
		$beforeRes->closeCursor();
		self::assertGreaterThan(0, (int)($beforeRow['c'] ?? 0), 'createWorkspace should seed categories');

		$impact = $deletion->previewImpact($id, self::OWNER);
		self::assertSame('Delete Me Household', $impact['name']);
		self::assertGreaterThanOrEqual(1, $impact['memberCount']);

		$result = $deletion->deleteWorkspace($id, self::OWNER, 'Delete Me Household');
		self::assertTrue($result['deleted']);
		self::assertSame($id, $result['id']);

		self::assertNull($workspaces->loadById($id));
		$cqb = $db->getQueryBuilder();
		$cqb->select($cqb->func()->count('*', 'c'))
			->from('bc_categories')
			->where($cqb->expr()->eq('workspace_id', $cqb->createNamedParameter($id, \PDO::PARAM_INT)));
		$cres = $cqb->executeQuery();
		$row = $cres->fetch();
		$cres->closeCursor();
		self::assertSame(0, (int)($row['c'] ?? -1));
	}

	public function testWrongConfirmNameLeavesWorkspaceIntact(): void
	{
		/** @var WorkspaceService $workspaces */
		$workspaces = \OC::$server->get(WorkspaceService::class);
		/** @var WorkspaceDeletionService $deletion */
		$deletion = \OC::$server->get(WorkspaceDeletionService::class);

		$created = $workspaces->createWorkspace(self::OWNER, [
			'name' => 'Keep Me',
			'type' => 'household',
			'privacyMode' => 'private',
			'primaryPlanningYear' => (int)date('Y'),
		]);
		$id = (int)$created['id'];
		$this->workspaceIds[] = $id;

		try {
			$deletion->deleteWorkspace($id, self::OWNER, 'keep me');
			self::fail('Expected ValidationException');
		} catch (ValidationException) {
			$this->addToAssertionCount(1);
		}

		self::assertNotNull($workspaces->loadById($id));
	}

	public function testViewerCannotDelete(): void
	{
		/** @var WorkspaceService $workspaces */
		$workspaces = \OC::$server->get(WorkspaceService::class);
		/** @var WorkspaceDeletionService $deletion */
		$deletion = \OC::$server->get(WorkspaceDeletionService::class);

		$created = $workspaces->createWorkspace(self::OWNER, [
			'name' => 'Viewer Blocked',
			'type' => 'household',
			'privacyMode' => 'standard',
			'primaryPlanningYear' => (int)date('Y'),
		]);
		$id = (int)$created['id'];
		$this->workspaceIds[] = $id;
		$workspaces->addMember($id, self::OWNER, [
			'userId' => self::VIEWER,
			'role' => AccessControlService::ROLE_VIEWER,
		]);

		try {
			$deletion->deleteWorkspace($id, self::VIEWER, 'Viewer Blocked');
			self::fail('Expected AccessDeniedException');
		} catch (AccessDeniedException) {
			$this->addToAssertionCount(1);
		}
		self::assertNotNull($workspaces->loadById($id));
	}
}
