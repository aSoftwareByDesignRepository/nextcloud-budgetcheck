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
use Psr\Log\LoggerInterface;

/**
 * Centralised authorisation for BudgetCheck.
 *
 * Roles, top-down:
 *  - System admin: any Nextcloud admin. Always allowed; can do everything an
 *    app admin can do plus reset stuck app config from the OCC.
 *  - App admin: configured via app config (`app_admin_user_ids`). Can manage
 *    every *standard* workspace and the global app settings. Private workspaces
 *    require an individual membership row — app/system admin bypass does not apply.
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
 *
 * App-level directory gate (aligned with the ProjectCheck access policy pattern):
 * when {@see self::KEY_ACCESS_RESTRICTION} is enabled, only Nextcloud system administrators,
 * configured app administrators, users explicitly listed, or members of listed groups may pass
 * the gate. Workspace membership is enforced separately on workspace-scoped resources, so users
 * can open the app shell after being allowed and then be onboarded into workspaces.
 */
class AccessControlService
{
	public const KEY_APP_ADMINS = 'app_admin_user_ids';
	public const KEY_DEFAULT_TIMEZONE = 'default_timezone';
	public const KEY_DEFAULT_CURRENCY = 'default_currency';
	public const KEY_LAST_USED_WORKSPACE = 'budgetcheck_last_workspace';
	public const KEY_FAVORITE_WORKSPACES = 'budgetcheck_favorite_workspaces';

	public const KEY_ACCESS_RESTRICTION = 'access_restriction_enabled';
	public const KEY_ACCESS_ALLOWED_USER_IDS = 'access_allowed_user_ids';
	public const KEY_ACCESS_ALLOWED_GROUP_IDS = 'access_allowed_group_ids';

	/** @see AppAccessMiddleware audit log when {@see canUseApp} is false */
	public const DENIAL_RESTRICTION = 'restriction';

	public const ROLE_MANAGER = 'manager';
	public const ROLE_CONTRIBUTOR = 'contributor';
	public const ROLE_VIEWER = 'viewer';

	/** App-admin break-glass applies; default for existing workspaces. */
	public const PRIVACY_STANDARD = 'standard';
	/** Membership-only; admins without an individual row cannot see or manage. */
	public const PRIVACY_PRIVATE = 'private';

	public const PRIVACY_MODES = [self::PRIVACY_STANDARD, self::PRIVACY_PRIVATE];

	private const ROLE_RANK = [
		self::ROLE_VIEWER => 1,
		self::ROLE_CONTRIBUTOR => 2,
		self::ROLE_MANAGER => 3,
	];

	/**
	 * Roles a Nextcloud group may hold in a workspace. Managers stay individual,
	 * accountable accounts so the last-manager invariant cannot be satisfied by
	 * a group whose membership is edited outside BudgetCheck.
	 */
	public const GROUP_ASSIGNABLE_ROLES = [self::ROLE_VIEWER, self::ROLE_CONTRIBUTOR];

	/** @var array<string, bool> */
	private array $groupMembershipCache = [];

	/** @var array<string, list<string>> */
	private array $userGroupIdsCache = [];

	/** @var array<int, string> */
	private array $privacyModeCache = [];

	public function __construct(
		private IDBConnection $db,
		private IConfig $config,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
		private ITimeFactory $timeFactory,
		private IUserManager $userManager,
		private MoneyService $money,
		private LoggerInterface $logger,
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
	 * App-level access: Nextcloud or delegated app administrators always pass.
	 * Otherwise, when directory restriction is enabled, the user must match the
	 * configured allow lists. Workspace membership is checked by workspace-scoped
	 * service methods such as {@see ensureMembership()}.
	 */
	public function canUseApp(string $userId): bool
	{
		if ($userId === '') {
			return false;
		}
		if ($this->isAppAdmin($userId)) {
			return true;
		}
		if ($this->isAccessRestrictionEnabled() && !$this->userMatchesAccessAllowList($userId)) {
			return false;
		}
		return true;
	}

	/**
	 * Human-facing reason when {@see canUseApp} is false (never call when true).
	 */
	public function denialReasonWhenCannotUseApp(string $userId): string
	{
		if ($this->isAppAdmin($userId)) {
			return self::DENIAL_RESTRICTION;
		}
		if ($this->isAccessRestrictionEnabled() && !$this->userMatchesAccessAllowList($userId)) {
			return self::DENIAL_RESTRICTION;
		}
		return self::DENIAL_RESTRICTION;
	}

	public function isAccessRestrictionEnabled(): bool
	{
		return $this->config->getAppValue(Application::APP_ID, self::KEY_ACCESS_RESTRICTION, '0') === '1';
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
	 * Resolve the effective role of a user inside a workspace.
	 *
	 * Standard workspaces: app admins are treated as managers everywhere so they
	 * can recover from a misconfigured workspace without first having to add
	 * themselves as a member. Otherwise the effective role is the strongest of
	 * individual membership and group assignments (groups capped at contributor).
	 *
	 * Private workspaces: individual membership only — no app-admin bypass and
	 * no group inheritance (groups are forbidden on private; defence in depth).
	 * Returns null when the user has no role from an allowed source.
	 */
	public function role(int $workspaceId, string $userId): ?string
	{
		if ($workspaceId < 1 || $userId === '') {
			return null;
		}
		$privacy = $this->privacyMode($workspaceId);
		if ($privacy === self::PRIVACY_PRIVATE) {
			return $this->individualRole($workspaceId, $userId);
		}
		if ($this->isAppAdmin($userId)) {
			return self::ROLE_MANAGER;
		}
		$candidates = [];
		$individual = $this->individualRole($workspaceId, $userId);
		if ($individual !== null) {
			$candidates[] = $individual;
		}
		foreach ($this->groupRolesForUser($workspaceId, $userId) as $groupRole) {
			$candidates[] = $groupRole;
		}
		return $this->strongestRole($candidates);
	}

	/**
	 * Persisted privacy mode for a workspace. Missing / unknown rows treat as
	 * standard so a partial migration never opens a silent private hole.
	 */
	public function privacyMode(int $workspaceId): string
	{
		if ($workspaceId < 1) {
			return self::PRIVACY_STANDARD;
		}
		if (array_key_exists($workspaceId, $this->privacyModeCache)) {
			return $this->privacyModeCache[$workspaceId];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('privacy_mode')
			->from('bc_workspaces')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if ($row === false) {
			$this->privacyModeCache[$workspaceId] = self::PRIVACY_STANDARD;
			return self::PRIVACY_STANDARD;
		}
		$mode = strtolower(trim((string)($row['privacy_mode'] ?? self::PRIVACY_STANDARD)));
		if (!in_array($mode, self::PRIVACY_MODES, true)) {
			$mode = self::PRIVACY_STANDARD;
		}
		$this->privacyModeCache[$workspaceId] = $mode;
		return $mode;
	}

	/**
	 * Drop a cached privacy mode after a successful toggle so the same request
	 * sees the new value (role / list helpers share this service instance).
	 */
	public function forgetPrivacyModeCache(int $workspaceId): void
	{
		unset($this->privacyModeCache[$workspaceId]);
	}

	/**
	 * Whether the user may create a workspace with the given privacy mode.
	 * Standard create stays app-admin only; private create is open to anyone
	 * who already passes the app door.
	 */
	public function canCreateWorkspace(string $userId, string $privacyMode): bool
	{
		if ($userId === '') {
			return false;
		}
		$mode = strtolower(trim($privacyMode));
		if ($mode === self::PRIVACY_PRIVATE) {
			return $this->canUseApp($userId);
		}
		if ($mode === self::PRIVACY_STANDARD) {
			return $this->isAppAdmin($userId);
		}
		return false;
	}

	/**
	 * UI capability: may open the create-workspace flow for at least one mode.
	 */
	public function canCreateAnyWorkspace(string $userId): bool
	{
		return $this->canCreateWorkspace($userId, self::PRIVACY_STANDARD)
			|| $this->canCreateWorkspace($userId, self::PRIVACY_PRIVATE);
	}

	/**
	 * Opaque count of private workspaces for admin digests (names never exposed).
	 */
	public function countPrivateWorkspaces(): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'count'))
			->from('bc_workspaces')
			->where($qb->expr()->eq('privacy_mode', $qb->createNamedParameter(self::PRIVACY_PRIVATE)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['count'] ?? 0);
	}

	/**
	 * Normalise a privacy_mode payload value. Invalid input throws.
	 */
	public function normalisePrivacyMode(mixed $raw, string $default = self::PRIVACY_STANDARD): string
	{
		if ($raw === null || $raw === '') {
			return $default;
		}
		if (!is_string($raw) && !is_int($raw)) {
			throw new \InvalidArgumentException('privacy_mode must be standard or private.');
		}
		$mode = strtolower(trim((string)$raw));
		if (!in_array($mode, self::PRIVACY_MODES, true)) {
			throw new \InvalidArgumentException('privacy_mode must be standard or private.');
		}
		return $mode;
	}

	/**
	 * The user's own membership role from `bc_workspace_members`, or null.
	 * Public for privacy toggles that must ignore app-admin bypass.
	 */
	public function individualMemberRole(int $workspaceId, string $userId): ?string
	{
		return $this->individualRole($workspaceId, $userId);
	}

	/**
	 * The user's own membership role from `bc_workspace_members`, or null.
	 */
	protected function individualRole(int $workspaceId, string $userId): ?string
	{
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
	 * Roles the user inherits from groups assigned to the workspace. Only
	 * group-assignable roles are honoured even if the row somehow stored a
	 * higher role, so a corrupted/edited row can never elevate to manager.
	 *
	 * @return list<string>
	 */
	protected function groupRolesForUser(int $workspaceId, string $userId): array
	{
		$gids = $this->userGroupIds($userId);
		if ($gids === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('role')
			->from('bc_workspace_groups')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->in('gid', $qb->createNamedParameter($gids, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
		$result = $qb->executeQuery();
		$roles = [];
		while ($row = $result->fetch()) {
			$role = (string)$row['role'];
			if (in_array($role, self::GROUP_ASSIGNABLE_ROLES, true)) {
				$roles[] = $role;
			}
		}
		$result->closeCursor();
		return $roles;
	}

	/**
	 * Pick the strongest role from a list, or null when the list is empty.
	 *
	 * Pure helper (no DB/session) so the precedence rule is unit-testable.
	 *
	 * @param list<string> $roles
	 */
	public function strongestRole(array $roles): ?string
	{
		$best = null;
		$bestRank = 0;
		foreach ($roles as $role) {
			$rank = self::ROLE_RANK[$role] ?? 0;
			if ($rank > $bestRank) {
				$bestRank = $rank;
				$best = $role;
			}
		}
		return $best;
	}

	/**
	 * Group IDs the user belongs to, cached for the request. Returns an empty
	 * list for unknown/anonymous users.
	 *
	 * @return list<string>
	 */
	private function userGroupIds(string $userId): array
	{
		if (!array_key_exists($userId, $this->userGroupIdsCache)) {
			$user = $userId !== '' ? $this->userManager->get($userId) : null;
			$this->userGroupIdsCache[$userId] = $user === null
				? []
				: array_values($this->groupManager->getUserGroupIds($user));
		}
		return $this->userGroupIdsCache[$userId];
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
			return $this->workspaceIdsVisibleToAppAdmin($userId);
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

		$gids = $this->userGroupIds($userId);
		if ($gids !== []) {
			$gq = $this->db->getQueryBuilder();
			$gq->selectDistinct('g.workspace_id')
				->from('bc_workspace_groups', 'g')
				->innerJoin('g', 'bc_workspaces', 'w', $gq->expr()->eq('g.workspace_id', 'w.id'))
				->where($gq->expr()->in('g.gid', $gq->createNamedParameter($gids, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
				->andWhere($gq->expr()->neq('w.privacy_mode', $gq->createNamedParameter(self::PRIVACY_PRIVATE)));
			$gResult = $gq->executeQuery();
			while ($row = $gResult->fetch()) {
				$ids[] = (int)$row['workspace_id'];
			}
			$gResult->closeCursor();
		}

		return array_values(array_unique($ids));
	}

	/**
	 * App admins see every standard workspace plus private workspaces where they
	 * hold an individual membership row.
	 *
	 * @return list<int>
	 */
	private function workspaceIdsVisibleToAppAdmin(string $userId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('bc_workspaces')
			->where($qb->expr()->neq('privacy_mode', $qb->createNamedParameter(self::PRIVACY_PRIVATE)))
			->orderBy('name', 'ASC');
		$result = $qb->executeQuery();
		$ids = [];
		while ($row = $result->fetch()) {
			$ids[] = (int)$row['id'];
		}
		$result->closeCursor();

		$mq = $this->db->getQueryBuilder();
		$mq->select('m.workspace_id')
			->from('bc_workspace_members', 'm')
			->innerJoin('m', 'bc_workspaces', 'w', $mq->expr()->eq('m.workspace_id', 'w.id'))
			->where($mq->expr()->eq('m.user_id', $mq->createNamedParameter($userId)))
			->andWhere($mq->expr()->eq('w.privacy_mode', $mq->createNamedParameter(self::PRIVACY_PRIVATE)));
		$mResult = $mq->executeQuery();
		while ($row = $mResult->fetch()) {
			$ids[] = (int)$row['workspace_id'];
		}
		$mResult->closeCursor();

		return array_values(array_unique($ids));
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
	 * @return list<int>
	 */
	public function favoriteWorkspaceIds(string $userId): array
	{
		if ($userId === '') {
			return [];
		}
		$raw = (string)$this->config->getUserValue($userId, Application::APP_ID, self::KEY_FAVORITE_WORKSPACES, '[]');
		try {
			$decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return [];
		}
		if (!is_array($decoded)) {
			return [];
		}
		$out = [];
		foreach ($decoded as $id) {
			if (is_int($id) && $id > 0) {
				$out[] = $id;
				continue;
			}
			if (is_string($id) && ctype_digit($id)) {
				$out[] = (int)$id;
			}
		}
		return array_values(array_unique($out));
	}

	/**
	 * @param list<int> $workspaceIds
	 */
	public function saveFavoriteWorkspaceIds(string $userId, array $workspaceIds): void
	{
		if ($userId === '') {
			return;
		}
		$clean = [];
		foreach ($workspaceIds as $id) {
			if ($id > 0) {
				$clean[] = $id;
			}
		}
		$clean = array_values(array_unique($clean));
		$this->config->setUserValue(
			$userId,
			Application::APP_ID,
			self::KEY_FAVORITE_WORKSPACES,
			json_encode($clean, JSON_THROW_ON_ERROR)
		);
	}

	/**
	 * App-wide settings: app admins, optional directory restriction, defaults
	 * for the workspace creation form. Preview rows help the settings UI render
	 * chips without extra round-trips (same data app admins already see in members).
	 *
	 * @return array{
	 *   appAdminUserIds:list<string>,
	 *   appAdminsPreview:list<array{id:string,displayName:string}>,
	 *   accessRestrictionEnabled:bool,
	 *   allowedUserIds:list<string>,
	 *   allowedGroupIds:list<string>,
	 *   allowedUsersPreview:list<array{id:string,displayName:string}>,
	 *   allowedGroupsPreview:list<array{id:string,displayName:string}>,
	 *   defaultTimezone:string,
	 *   defaultCurrency:string,
	 *   privateWorkspaceCount:int
	 * }
	 */
	public function getAppPolicy(): array
	{
		$allowedUserIds = $this->readJsonIdListConfig(self::KEY_ACCESS_ALLOWED_USER_IDS);
		$allowedGroupIds = $this->readJsonIdListConfig(self::KEY_ACCESS_ALLOWED_GROUP_IDS);
		$appAdminUserIds = $this->getAppAdminIds();
		return [
			'appAdminUserIds' => $appAdminUserIds,
			'appAdminsPreview' => $this->previewUsers($appAdminUserIds),
			'accessRestrictionEnabled' => $this->isAccessRestrictionEnabled(),
			'allowedUserIds' => $allowedUserIds,
			'allowedGroupIds' => $allowedGroupIds,
			'allowedUsersPreview' => $this->previewUsers($allowedUserIds),
			'allowedGroupsPreview' => $this->previewGroups($allowedGroupIds),
			'defaultTimezone' => $this->getDefaultTimezone(),
			'defaultCurrency' => $this->getDefaultCurrency(),
			'privateWorkspaceCount' => $this->countPrivateWorkspaces(),
		];
	}

	/**
	 * Persist app policy.
	 *
	 * Optional `settings_section` scopes the write (ProjectCheck parity):
	 * - missing key → full replace ("all") for legacy/API clients
	 * - access|admins|defaults → merge that scope onto the current policy
	 * - unknown / empty → reject (never coerce to full wipe)
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public function saveAppPolicy(array $payload): array
	{
		$allowedSections = ['access', 'admins', 'defaults', 'all'];
		if (!array_key_exists('settings_section', $payload)) {
			$settingsSection = 'all';
		} else {
			$rawSection = strtolower(trim((string) $payload['settings_section']));
			if (!in_array($rawSection, $allowedSections, true)) {
				throw new \InvalidArgumentException('Invalid settings_section. Reload the page and try again.');
			}
			$settingsSection = $rawSection;
		}

		$current = $this->getAppPolicy();
		$merged = $current;

		if ($settingsSection === 'all' || $settingsSection === 'admins') {
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
			$merged['appAdminUserIds'] = $adminIds;
		}

		if ($settingsSection === 'all' || $settingsSection === 'defaults') {
			$timezone = trim((string)($payload['defaultTimezone'] ?? $merged['defaultTimezone'] ?? $this->getDefaultTimezone()));
			if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
				throw new \InvalidArgumentException('Invalid default timezone.');
			}
			$currency = strtoupper(trim((string)($payload['defaultCurrency'] ?? $merged['defaultCurrency'] ?? $this->getDefaultCurrency())));
			if (!preg_match('/^[A-Z]{3}$/', $currency)) {
				throw new \InvalidArgumentException('Invalid default currency code.');
			}
			if (!$this->money->isSupportedCurrency($currency)) {
				throw new \InvalidArgumentException('Unsupported default currency for new workspaces.');
			}
			$merged['defaultTimezone'] = $timezone;
			$merged['defaultCurrency'] = $currency;
		}

		if ($settingsSection === 'all' || $settingsSection === 'access') {
			$restrictionRaw = $payload['accessRestrictionEnabled'] ?? false;
			$restrictionEnabled = $restrictionRaw === true
				|| $restrictionRaw === 1
				|| $restrictionRaw === '1'
				|| $restrictionRaw === 'true';

			$allowedUserCandidates = $payload['allowedUserIds'] ?? [];
			if (!is_array($allowedUserCandidates)) {
				throw new \InvalidArgumentException('allowedUserIds must be an array.');
			}
			$allowedGroupCandidates = $payload['allowedGroupIds'] ?? [];
			if (!is_array($allowedGroupCandidates)) {
				throw new \InvalidArgumentException('allowedGroupIds must be an array.');
			}
			$allowedUserIds = $this->normalizeUserIds($allowedUserCandidates);
			$allowedGroupIds = $this->normalizeGroupIds($allowedGroupCandidates);
			if ($restrictionEnabled && $allowedUserIds === [] && $allowedGroupIds === []) {
				throw new \InvalidArgumentException('When access restriction is enabled, at least one allowed user or one allowed group is required.');
			}
			$merged['accessRestrictionEnabled'] = $restrictionEnabled;
			$merged['allowedUserIds'] = $allowedUserIds;
			$merged['allowedGroupIds'] = $allowedGroupIds;
		}

		$adminIds = is_array($merged['appAdminUserIds'] ?? null) ? $merged['appAdminUserIds'] : [];
		$timezone = (string)($merged['defaultTimezone'] ?? $this->getDefaultTimezone());
		$currency = (string)($merged['defaultCurrency'] ?? $this->getDefaultCurrency());
		$restrictionEnabled = !empty($merged['accessRestrictionEnabled']);
		$allowedUserIds = is_array($merged['allowedUserIds'] ?? null) ? $merged['allowedUserIds'] : [];
		$allowedGroupIds = is_array($merged['allowedGroupIds'] ?? null) ? $merged['allowedGroupIds'] : [];

		$this->config->setAppValue(Application::APP_ID, self::KEY_APP_ADMINS, json_encode($adminIds, JSON_THROW_ON_ERROR));
		$this->config->setAppValue(Application::APP_ID, self::KEY_DEFAULT_TIMEZONE, $timezone);
		$this->config->setAppValue(Application::APP_ID, self::KEY_DEFAULT_CURRENCY, $currency);
		$this->config->setAppValue(Application::APP_ID, self::KEY_ACCESS_RESTRICTION, $restrictionEnabled ? '1' : '0');
		$this->config->setAppValue(
			Application::APP_ID,
			self::KEY_ACCESS_ALLOWED_USER_IDS,
			json_encode($allowedUserIds, JSON_THROW_ON_ERROR),
		);
		$this->config->setAppValue(
			Application::APP_ID,
			self::KEY_ACCESS_ALLOWED_GROUP_IDS,
			json_encode($allowedGroupIds, JSON_THROW_ON_ERROR),
		);

		return $this->getAppPolicy();
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
		$allowUsers = array_values(array_filter(
			$this->readJsonIdListConfig(self::KEY_ACCESS_ALLOWED_USER_IDS),
			static fn (string $id): bool => $id !== $userId,
		));
		$this->config->setAppValue(
			Application::APP_ID,
			self::KEY_ACCESS_ALLOWED_USER_IDS,
			json_encode($allowUsers, JSON_THROW_ON_ERROR),
		);
		$this->config->deleteUserValue($userId, Application::APP_ID, self::KEY_LAST_USED_WORKSPACE);
	}

	/**
	 * Remove every trace of a deleted Nextcloud group: workspace group
	 * assignments and the directory allow-list entry. Called from the
	 * group-deleted event listener so authorization state never references a
	 * group that no longer exists.
	 */
	public function purgeGroup(string $gid): void
	{
		if ($gid === '') {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete('bc_workspace_groups')
			->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)));
		$qb->executeStatement();

		$allowedGroups = $this->readJsonIdListConfig(self::KEY_ACCESS_ALLOWED_GROUP_IDS);
		$filtered = array_values(array_filter(
			$allowedGroups,
			static fn (string $id): bool => $id !== $gid,
		));
		if ($filtered !== $allowedGroups) {
			$this->config->setAppValue(
				Application::APP_ID,
				self::KEY_ACCESS_ALLOWED_GROUP_IDS,
				json_encode($filtered, JSON_THROW_ON_ERROR),
			);
		}
	}

	private function userMatchesAccessAllowList(string $userId): bool
	{
		foreach ($this->readJsonIdListConfig(self::KEY_ACCESS_ALLOWED_USER_IDS) as $uid) {
			if ($uid === $userId) {
				return true;
			}
		}
		foreach ($this->readJsonIdListConfig(self::KEY_ACCESS_ALLOWED_GROUP_IDS) as $gid) {
			if ($this->isUserInGroupCached($userId, $gid)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return list<string>
	 */
	private function readJsonIdListConfig(string $key): array
	{
		$raw = trim((string)$this->config->getAppValue(Application::APP_ID, $key, '[]'));
		if ($raw === '') {
			return [];
		}
		try {
			$data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			$this->logger->warning('Invalid JSON in BudgetCheck access list; treating as empty', [
				'app' => Application::APP_ID,
				'key' => $key,
			]);
			return [];
		}
		if (!is_array($data)) {
			return [];
		}
		$out = [];
		foreach ($data as $item) {
			if (is_string($item) && $item !== '') {
				$out[] = $item;
			}
		}
		return array_values(array_unique($out));
	}

	/**
	 * @param list<string> $userIds
	 * @return list<array{id:string,displayName:string}>
	 */
	private function previewUsers(array $userIds): array
	{
		$out = [];
		foreach ($userIds as $uid) {
			$user = $this->userManager->get($uid);
			$out[] = [
				'id' => $uid,
				'displayName' => $user !== null ? $user->getDisplayName() : $uid,
			];
		}
		return $out;
	}

	/**
	 * @param list<string> $groupIds
	 * @return list<array{id:string,displayName:string}>
	 */
	private function previewGroups(array $groupIds): array
	{
		$out = [];
		foreach ($groupIds as $gid) {
			$group = $this->groupManager->get($gid);
			$out[] = [
				'id' => $gid,
				'displayName' => $group !== null ? $group->getDisplayName() : $gid,
			];
		}
		return $out;
	}

	/**
	 * @param list<mixed> $raw
	 * @return list<string>
	 */
	private function normalizeUserIds(array $raw): array
	{
		$out = [];
		foreach ($raw as $id) {
			$id = is_string($id) ? trim($id) : '';
			if ($id === '' || strlen($id) > 64) {
				continue;
			}
			$user = $this->userManager->get($id);
			if ($user === null) {
				throw new \InvalidArgumentException('One or more allowed user entries refer to accounts that do not exist.');
			}
			if (!$user->isEnabled()) {
				throw new \InvalidArgumentException('One or more allowed user entries refer to disabled accounts.');
			}
			$out[] = $id;
		}
		return array_values(array_unique($out));
	}

	/**
	 * @param list<mixed> $raw
	 * @return list<string>
	 */
	private function normalizeGroupIds(array $raw): array
	{
		$out = [];
		foreach ($raw as $id) {
			$id = is_string($id) ? trim($id) : '';
			if ($id === '') {
				continue;
			}
			if ($this->groupManager->get($id) === null) {
				throw new \InvalidArgumentException('One or more allowed group entries refer to groups that do not exist.');
			}
			$out[] = $id;
		}
		return array_values(array_unique($out));
	}

	private function isUserInGroupCached(string $userId, string $groupId): bool
	{
		$key = $userId . "\0" . $groupId;
		if (!array_key_exists($key, $this->groupMembershipCache)) {
			$this->groupMembershipCache[$key] = $this->groupManager->isInGroup($userId, $groupId);
		}
		return $this->groupMembershipCache[$key];
	}
}
