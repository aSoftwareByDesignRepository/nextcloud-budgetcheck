<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

use OCA\BudgetCheck\AppInfo\Application;
use OCA\BudgetCheck\Exception\AccessDeniedException;
use OCA\BudgetCheck\Exception\NotAuthenticatedException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Centralised authorisation for BudgetCheck.
 *
 * Roles, top-down:
 *  - System admin: any Nextcloud admin. Always allowed; can do everything an
 *    app admin can do plus reset stuck app config from the OCC.
 *  - App admin: configured via app config (`app_admin_user_ids`). Can manage
 *    every workspace and the global app settings.
 *  - Workspace manager: persisted in `bc_workspace_members.role = manager`.
 *    Owns workspace settings, members, categories, recurring rules, budgets,
 *    savings target, tax settings, monthly close and reopen.
 *  - Workspace contributor: ledger CRUD only.
 *  - Workspace viewer: read-only.
 *
 * Every endpoint must call one of:
 *  - {@see currentUserId()}   — derive UID from the session, throw if anonymous
 *  - {@see requireAppAdmin()} — gate global config changes
 *  - {@see ensureMembership()} — gate per-workspace reads
 *  - {@see ensureMinimumRole()} — gate per-workspace mutations
 *
 * Hidden UI is convenience. Server enforcement is mandatory.
 */
class AccessControlService
{
	public const KEY_APP_ADMINS = 'app_admin_user_ids';
	public const KEY_DEFAULT_TIMEZONE = 'default_timezone';
	public const KEY_DEFAULT_CURRENCY = 'default_currency';
	public const KEY_LAST_USED_WORKSPACE = 'budgetcheck_last_workspace';

	public const ROLE_MANAGER = 'manager';
	public const ROLE_CONTRIBUTOR = 'contributor';
	public const ROLE_VIEWER = 'viewer';

	private const ROLE_RANK = [
		self::ROLE_VIEWER => 1,
		self::ROLE_CONTRIBUTOR => 2,
		self::ROLE_MANAGER => 3,
	];

	public function __construct(
		private IDBConnection $db,
		private IConfig $config,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
		private ITimeFactory $timeFactory,
		private IUserManager $userManager,
		private MoneyService $money,
	) {
	}

	public function currentUserId(): string
	{
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new NotAuthenticatedException();
		}
		return $user->getUID();
	}

	public function isSystemAdmin(string $userId): bool
	{
		return $userId !== '' && $this->groupManager->isAdmin($userId);
	}

	public function isAppAdmin(string $userId): bool
	{
		if ($userId === '') {
			return false;
		}
		return $this->isSystemAdmin($userId) || in_array($userId, $this->getAppAdminIds(), true);
	}

	/**
	 * App-level access: a user may enter the app shell when they are an app
	 * admin OR a member of at least one workspace. Anything finer is enforced
	 * by per-workspace membership checks below.
	 */
	public function canUseApp(string $userId): bool
	{
		if ($userId === '') {
			return false;
		}
		if ($this->isAppAdmin($userId)) {
			return true;
		}
		return $this->countWorkspaceMemberships($userId) > 0;
	}

	public function requireAppAdmin(): string
	{
		$userId = $this->currentUserId();
		if (!$this->isAppAdmin($userId)) {
			throw new AccessDeniedException();
		}
		return $userId;
	}

	/**
	 * Resolve the role of a user inside a workspace. App admins are treated as
	 * managers everywhere so they can recover from a misconfigured workspace
	 * without first having to add themselves as a member.
	 *
	 * Returns null when the user has no role at all.
	 */
	public function role(int $workspaceId, string $userId): ?string
	{
		if ($workspaceId < 1 || $userId === '') {
			return null;
		}
		if ($this->isAppAdmin($userId)) {
			return self::ROLE_MANAGER;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('role')
			->from('bc_workspace_members')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if ($row === false) {
			return null;
		}
		$role = (string)$row['role'];
		return in_array($role, [self::ROLE_MANAGER, self::ROLE_CONTRIBUTOR, self::ROLE_VIEWER], true)
			? $role
			: null;
	}

	/**
	 * Throw `access_denied` if the user has no role inside the workspace.
	 * Returns the resolved role on success.
	 */
	public function ensureMembership(int $workspaceId, string $userId): string
	{
		$role = $this->role($workspaceId, $userId);
		if ($role === null) {
			throw new AccessDeniedException();
		}
		return $role;
	}

	/**
	 * Require at least the given role rank to mutate the workspace.
	 *
	 * @param string $minimum one of ROLE_VIEWER, ROLE_CONTRIBUTOR, ROLE_MANAGER
	 */
	public function ensureMinimumRole(int $workspaceId, string $userId, string $minimum): string
	{
		$role = $this->ensureMembership($workspaceId, $userId);
		if (self::ROLE_RANK[$role] < (self::ROLE_RANK[$minimum] ?? PHP_INT_MAX)) {
			throw new AccessDeniedException();
		}
		return $role;
	}

	/**
	 * @return list<int>
	 */
	public function workspacesForUser(string $userId): array
	{
		if ($userId === '') {
			return [];
		}
		if ($this->isAppAdmin($userId)) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')->from('bc_workspaces')->orderBy('name', 'ASC');
			$result = $qb->executeQuery();
			$ids = [];
			while ($row = $result->fetch()) {
				$ids[] = (int)$row['id'];
			}
			$result->closeCursor();
			return $ids;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('workspace_id')
			->from('bc_workspace_members')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$ids = [];
		while ($row = $result->fetch()) {
			$ids[] = (int)$row['workspace_id'];
		}
		$result->closeCursor();
		return $ids;
	}

	/**
	 * Persist the workspace the user most recently opened. Used by the page
	 * controller to land the user on the right scope after navigation actions
	 * that did not include a workspaceId in the URL.
	 */
	public function rememberLastUsedWorkspace(string $userId, int $workspaceId): void
	{
		if ($userId === '' || $workspaceId < 1) {
			return;
		}
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_LAST_USED_WORKSPACE, (string)$workspaceId);
	}

	public function lastUsedWorkspace(string $userId): ?int
	{
		if ($userId === '') {
			return null;
		}
		$value = (int)$this->config->getUserValue($userId, Application::APP_ID, self::KEY_LAST_USED_WORKSPACE, '0');
		return $value > 0 ? $value : null;
	}

	public function forgetLastUsedWorkspace(string $userId, int $workspaceId): void
	{
		if ($this->lastUsedWorkspace($userId) === $workspaceId) {
			$this->config->deleteUserValue($userId, Application::APP_ID, self::KEY_LAST_USED_WORKSPACE);
		}
	}

	/**
	 * App-wide settings (currently: app admins, default timezone/currency for
	 * the workspace creation form).
	 *
	 * @return array{appAdminUserIds:list<string>, defaultTimezone:string, defaultCurrency:string}
	 */
	public function getAppPolicy(): array
	{
		return [
			'appAdminUserIds' => $this->getAppAdminIds(),
			'defaultTimezone' => $this->getDefaultTimezone(),
			'defaultCurrency' => $this->getDefaultCurrency(),
		];
	}

	public function saveAppPolicy(array $payload): array
	{
		$adminCandidates = $payload['appAdminUserIds'] ?? [];
		if (!is_array($adminCandidates)) {
			throw new \InvalidArgumentException('appAdminUserIds must be an array.');
		}
		$normalised = [];
		foreach ($adminCandidates as $candidate) {
			if (!is_string($candidate)) {
				continue;
			}
			$candidate = trim($candidate);
			if ($candidate === '' || strlen($candidate) > 64) {
				continue;
			}
			$normalised[$candidate] = true;
		}
		$adminIds = array_keys($normalised);
		foreach ($adminIds as $adminId) {
			$user = $this->userManager->get($adminId);
			if ($user === null) {
				throw new \InvalidArgumentException('One or more app administrator entries refer to users that do not exist.');
			}
			if (!$user->isEnabled()) {
				throw new \InvalidArgumentException('One or more app administrator entries refer to disabled users.');
			}
		}

		$currentUser = $this->userSession->getUser();
		$currentUserId = $currentUser?->getUID() ?? '';
		if ($currentUserId !== '' && !$this->isSystemAdmin($currentUserId) && $this->isAppAdmin($currentUserId)) {
			$removingSelf = !in_array($currentUserId, $adminIds, true);
			if ($removingSelf && $adminIds === []) {
				throw new \InvalidArgumentException('You cannot remove your own app administrator access without assigning another administrator first.');
			}
		}

		$timezone = trim((string)($payload['defaultTimezone'] ?? $this->getDefaultTimezone()));
		if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
			throw new \InvalidArgumentException('Invalid default timezone.');
		}
		$currency = strtoupper(trim((string)($payload['defaultCurrency'] ?? $this->getDefaultCurrency())));
		if (!preg_match('/^[A-Z]{3}$/', $currency)) {
			throw new \InvalidArgumentException('Invalid default currency code.');
		}
		if (!$this->money->isSupportedCurrency($currency)) {
			throw new \InvalidArgumentException('Unsupported default currency for new workspaces.');
		}

		$this->config->setAppValue(Application::APP_ID, self::KEY_APP_ADMINS, json_encode($adminIds, JSON_THROW_ON_ERROR));
		$this->config->setAppValue(Application::APP_ID, self::KEY_DEFAULT_TIMEZONE, $timezone);
		$this->config->setAppValue(Application::APP_ID, self::KEY_DEFAULT_CURRENCY, $currency);

		return [
			'appAdminUserIds' => $adminIds,
			'defaultTimezone' => $timezone,
			'defaultCurrency' => $currency,
		];
	}

	public function getDefaultTimezone(): string
	{
		$value = (string)$this->config->getAppValue(Application::APP_ID, self::KEY_DEFAULT_TIMEZONE, '');
		if ($value !== '' && in_array($value, \DateTimeZone::listIdentifiers(), true)) {
			return $value;
		}
		return 'Europe/Berlin';
	}

	public function getDefaultCurrency(): string
	{
		$value = strtoupper((string)$this->config->getAppValue(Application::APP_ID, self::KEY_DEFAULT_CURRENCY, ''));
		if (preg_match('/^[A-Z]{3}$/', $value) !== 1 || !$this->money->isSupportedCurrency($value)) {
			return 'EUR';
		}
		return $value;
	}

	/**
	 * @return list<string>
	 */
	private function getAppAdminIds(): array
	{
		$raw = (string)$this->config->getAppValue(Application::APP_ID, self::KEY_APP_ADMINS, '[]');
		try {
			$value = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return [];
		}
		if (!is_array($value)) {
			return [];
		}
		return array_values(array_unique(array_filter($value, static fn ($v): bool => is_string($v) && $v !== '')));
	}

	private function countWorkspaceMemberships(string $userId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'count'))
			->from('bc_workspace_members')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['count'] ?? 0);
	}

	/**
	 * Remove every workspace membership and the last-used pointer for a deleted
	 * user. Snapshot rows and audit history keep referencing the UID by design
	 * because they are evidence; this method only purges live authorization
	 * state.
	 */
	public function purgeUser(string $userId): void
	{
		if ($userId === '') {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete('bc_workspace_members')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
		$adminIds = $this->getAppAdminIds();
		if (in_array($userId, $adminIds, true)) {
			$adminIds = array_values(array_diff($adminIds, [$userId]));
			$this->config->setAppValue(Application::APP_ID, self::KEY_APP_ADMINS, json_encode($adminIds, JSON_THROW_ON_ERROR));
		}
		$this->config->deleteUserValue($userId, Application::APP_ID, self::KEY_LAST_USED_WORKSPACE);
	}
}
