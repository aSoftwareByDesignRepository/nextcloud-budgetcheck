<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Integration;

use OCA\BudgetCheck\AppInfo\Application;
use OCA\BudgetCheck\Service\AccessControlService;
use OCA\BudgetCheck\Service\BookingStatusService;
use OCA\BudgetCheck\Service\CategoryService;
use OCA\BudgetCheck\Service\WorkspaceService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use Test\TestCase;

/**
 * Live-container proof: app defaults + workspace tax/categories/booking/members
 * persist and reload without mocked QueryBuilders (settings-matrix persist_reload).
 */
final class SettingsPersistReloadIntegrationTest extends TestCase
{
	private const OWNER = 'bc_set_owner';
	private const MEMBER = 'bc_set_member';
	private const PASSWORD = 'bc-set-pass-9xK!';

	/** @var list<int> */
	private array $workspaceIds = [];

	/** @var list<string> */
	private array $createdUsers = [];

	/** @var array{admins:?string, restriction:?string, tz:?string, currency:?string} */
	private array $prev = [
		'admins' => null,
		'restriction' => null,
		'tz' => null,
		'currency' => null,
	];

	protected function setUp(): void
	{
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud is not bootstrapped (run inside Docker with NEXTCLOUD_ROOT).');
		}
		/** @var IConfig $config */
		$config = \OC::$server->get(IConfig::class);
		$this->prev['admins'] = $config->getAppValue(Application::APP_ID, AccessControlService::KEY_APP_ADMINS, '[]');
		$this->prev['restriction'] = $config->getAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, '0');
		$this->prev['tz'] = $config->getAppValue(Application::APP_ID, AccessControlService::KEY_DEFAULT_TIMEZONE, '');
		$this->prev['currency'] = $config->getAppValue(Application::APP_ID, AccessControlService::KEY_DEFAULT_CURRENCY, '');
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, '0');
		$config->setAppValue(
			Application::APP_ID,
			AccessControlService::KEY_APP_ADMINS,
			json_encode([self::OWNER], JSON_THROW_ON_ERROR),
		);

		/** @var IUserManager $users */
		$users = \OC::$server->get(IUserManager::class);
		foreach ([self::OWNER, self::MEMBER] as $uid) {
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
		if ($this->prev['tz'] !== null) {
			$config->setAppValue(Application::APP_ID, AccessControlService::KEY_DEFAULT_TIMEZONE, $this->prev['tz']);
		}
		if ($this->prev['currency'] !== null) {
			$config->setAppValue(Application::APP_ID, AccessControlService::KEY_DEFAULT_CURRENCY, $this->prev['currency']);
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

	public function testEmptyAppDefaultsFallBackOnWorkspaceCreate(): void
	{
		/** @var IConfig $config */
		$config = \OC::$server->get(IConfig::class);
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_DEFAULT_TIMEZONE, '');
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_DEFAULT_CURRENCY, '');

		/** @var AccessControlService $access */
		$access = \OC::$server->get(AccessControlService::class);
		self::assertSame('Europe/Berlin', $access->getDefaultTimezone());
		self::assertSame('EUR', $access->getDefaultCurrency());

		/** @var WorkspaceService $workspaces */
		$workspaces = \OC::$server->get(WorkspaceService::class);
		$created = $workspaces->createWorkspace(self::OWNER, [
			'name' => 'Defaults Empty Household',
			'type' => WorkspaceService::TYPE_HOUSEHOLD,
			'privacyMode' => 'standard',
			'primaryPlanningYear' => (int)date('Y'),
		]);
		$this->workspaceIds[] = (int)$created['id'];

		$reloaded = $workspaces->getForUser((int)$created['id'], self::OWNER);
		self::assertSame('Europe/Berlin', $reloaded['timezone']);
		self::assertSame('EUR', $reloaded['currencyCode']);
	}

	public function testConfiguredAppDefaultsAppliedOnWorkspaceCreate(): void
	{
		/** @var IConfig $config */
		$config = \OC::$server->get(IConfig::class);
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_DEFAULT_TIMEZONE, 'America/New_York');
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_DEFAULT_CURRENCY, 'USD');

		/** @var WorkspaceService $workspaces */
		$workspaces = \OC::$server->get(WorkspaceService::class);
		$created = $workspaces->createWorkspace(self::OWNER, [
			'name' => 'Defaults Set Household',
			'type' => WorkspaceService::TYPE_HOUSEHOLD,
			'privacyMode' => 'standard',
			'primaryPlanningYear' => (int)date('Y'),
		]);
		$this->workspaceIds[] = (int)$created['id'];

		$reloaded = $workspaces->getForUser((int)$created['id'], self::OWNER);
		self::assertSame('America/New_York', $reloaded['timezone']);
		self::assertSame('USD', $reloaded['currencyCode']);
	}

	public function testTaxModePersistReloadAndDisableScrubPath(): void
	{
		/** @var WorkspaceService $workspaces */
		$workspaces = \OC::$server->get(WorkspaceService::class);
		$created = $workspaces->createWorkspace(self::OWNER, [
			'name' => 'Tax Persist Household',
			'type' => WorkspaceService::TYPE_HOUSEHOLD,
			'privacyMode' => 'standard',
			'primaryPlanningYear' => (int)date('Y'),
		]);
		$id = (int)$created['id'];
		$this->workspaceIds[] = $id;

		$enabled = $workspaces->updateTaxMode($id, self::OWNER, [
			'taxModeEnabled' => true,
			'taxBudgetBasis' => 'net',
			'defaultVatRateBp' => 1900,
		]);
		self::assertTrue($enabled['taxModeEnabled']);
		self::assertSame('net', $enabled['taxBudgetBasis']);
		self::assertSame(1900, $enabled['defaultVatRateBp']);

		$reloaded = $workspaces->getForUser($id, self::OWNER);
		self::assertTrue($reloaded['taxModeEnabled']);
		self::assertSame('net', $reloaded['taxBudgetBasis']);
		self::assertSame(1900, $reloaded['defaultVatRateBp']);

		$disabled = $workspaces->updateTaxMode($id, self::OWNER, [
			'taxModeEnabled' => false,
			'taxBudgetBasis' => 'gross',
			'defaultVatRateBp' => null,
		]);
		self::assertFalse($disabled['taxModeEnabled']);
		$again = $workspaces->getForUser($id, self::OWNER);
		self::assertFalse($again['taxModeEnabled']);
	}

	public function testCategoryCreateUpdateDeactivatePersistReload(): void
	{
		/** @var WorkspaceService $workspaces */
		$workspaces = \OC::$server->get(WorkspaceService::class);
		/** @var CategoryService $categories */
		$categories = \OC::$server->get(CategoryService::class);

		$created = $workspaces->createWorkspace(self::OWNER, [
			'name' => 'Cat Persist Household',
			'type' => WorkspaceService::TYPE_HOUSEHOLD,
			'privacyMode' => 'standard',
			'primaryPlanningYear' => (int)date('Y'),
		]);
		$id = (int)$created['id'];
		$this->workspaceIds[] = $id;

		$before = $categories->listForWorkspace($id, self::OWNER, false);
		self::assertNotEmpty($before, 'seeded categories expected');

		$row = $categories->create($id, self::OWNER, [
			'name' => 'Atlas Coffee',
			'type' => CategoryService::TYPE_EXPENSE,
			'groupKey' => 'atlas',
		]);
		self::assertSame('Atlas Coffee', $row['name']);
		self::assertTrue($row['isActive']);

		$listed = $categories->listForWorkspace($id, self::OWNER, false);
		$found = array_values(array_filter($listed, static fn (array $c): bool => (int)$c['id'] === (int)$row['id']));
		self::assertCount(1, $found);
		self::assertSame('atlas', $found[0]['groupKey']);

		$updated = $categories->update((int)$row['id'], self::OWNER, [
			'name' => 'Atlas Coffee Renamed',
			'groupKey' => 'atlas-renamed',
		]);
		self::assertSame('Atlas Coffee Renamed', $updated['name']);
		self::assertSame('atlas-renamed', $updated['groupKey']);

		$deactivated = $categories->deactivate((int)$row['id'], self::OWNER);
		self::assertFalse($deactivated['isActive']);
		$activeOnly = $categories->listForWorkspace($id, self::OWNER, false);
		foreach ($activeOnly as $c) {
			self::assertNotSame((int)$row['id'], (int)$c['id']);
		}
		$withInactive = $categories->listForWorkspace($id, self::OWNER, true);
		$archived = array_values(array_filter($withInactive, static fn (array $c): bool => (int)$c['id'] === (int)$row['id']));
		self::assertCount(1, $archived);
		self::assertFalse($archived[0]['isActive']);
	}

	public function testMembersAddListPersistReload(): void
	{
		/** @var WorkspaceService $workspaces */
		$workspaces = \OC::$server->get(WorkspaceService::class);
		$created = $workspaces->createWorkspace(self::OWNER, [
			'name' => 'Members Persist Household',
			'type' => WorkspaceService::TYPE_HOUSEHOLD,
			'privacyMode' => 'standard',
			'primaryPlanningYear' => (int)date('Y'),
		]);
		$id = (int)$created['id'];
		$this->workspaceIds[] = $id;

		$afterAdd = $workspaces->addMember($id, self::OWNER, [
			'userId' => self::MEMBER,
			'role' => AccessControlService::ROLE_VIEWER,
		]);
		$userRows = array_values(array_filter($afterAdd, static fn (array $m): bool => ($m['type'] ?? '') === 'user'));
		$userIds = array_map(static fn (array $m): string => (string)$m['userId'], $userRows);
		self::assertContains(self::OWNER, $userIds);
		self::assertContains(self::MEMBER, $userIds);
		$memberRow = array_values(array_filter($userRows, static fn (array $m): bool => ($m['userId'] ?? '') === self::MEMBER));
		self::assertCount(1, $memberRow);
		self::assertSame(AccessControlService::ROLE_VIEWER, $memberRow[0]['role']);

		$members = $workspaces->listMembers($id, self::OWNER);
		$relistedIds = array_map(
			static fn (array $m): string => (string)$m['userId'],
			array_values(array_filter($members, static fn (array $m): bool => ($m['type'] ?? '') === 'user')),
		);
		self::assertContains(self::MEMBER, $relistedIds);
	}

	public function testBookingStatusCreateUpdatePersistReloadOnProject(): void
	{
		/** @var WorkspaceService $workspaces */
		$workspaces = \OC::$server->get(WorkspaceService::class);
		/** @var BookingStatusService $statuses */
		$statuses = \OC::$server->get(BookingStatusService::class);

		$created = $workspaces->createWorkspace(self::OWNER, [
			'name' => 'Booking Persist Project',
			'type' => WorkspaceService::TYPE_PROJECT,
			'privacyMode' => 'standard',
			'projectStartDate' => date('Y-m-d'),
			'projectEndDate' => date('Y-m-d', strtotime('+30 days')),
		]);
		$id = (int)$created['id'];
		$this->workspaceIds[] = $id;

		$list = $statuses->listForWorkspace($id, self::OWNER, false);
		self::assertNotEmpty($list, 'project booking status defaults expected');

		$row = $statuses->create($id, self::OWNER, [
			'name' => 'Atlas Review',
			'sortOrder' => 42,
		]);
		self::assertSame('Atlas Review', $row['name']);
		self::assertSame(42, $row['sortOrder']);

		$updated = $statuses->update((int)$row['id'], self::OWNER, [
			'name' => 'Atlas Done',
			'sortOrder' => 7,
		]);
		self::assertSame('Atlas Done', $updated['name']);
		self::assertSame(7, $updated['sortOrder']);

		$relisted = $statuses->listForWorkspace($id, self::OWNER, false);
		$found = array_values(array_filter($relisted, static fn (array $s): bool => (int)$s['id'] === (int)$row['id']));
		self::assertCount(1, $found);
		self::assertSame('Atlas Done', $found[0]['name']);
		self::assertSame(7, $found[0]['sortOrder']);

		$deactivated = $statuses->deactivate((int)$row['id'], self::OWNER);
		self::assertFalse($deactivated['isActive']);
	}
}
