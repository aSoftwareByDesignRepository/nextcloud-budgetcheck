<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

use OCA\BudgetCheck\AppInfo\Application;
use OCA\BudgetCheck\Exception\AccessDeniedException;
use OCA\BudgetCheck\Exception\InternalErrorException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * Workspace lifecycle: list/create/update plus member CRUD.
 *
 * Critical invariants enforced here:
 *  - Workspace `type` is required at creation and immutable after.
 *  - Household workspaces force `fiscal_year_start_month = 1` and reject any
 *    project-only payload field. Project workspaces require both project dates,
 *    end >= start, and reject the household-only `auto_copy_budgets_from_previous_month`
 *    knob (which only makes sense in a calendar-month rhythm).
 *  - The creator becomes a workspace manager in the same DB transaction so we
 *    cannot end up with an orphaned workspace nobody can edit.
 *  - Project date changes are rejected when existing transactions would fall
 *    outside the new window (orphan-prevention).
 */
class WorkspaceService
{
	public const TYPE_HOUSEHOLD = 'household';
	public const TYPE_PROJECT = 'project';

	private const TYPES = [self::TYPE_HOUSEHOLD, self::TYPE_PROJECT];
	private const TAX_BUDGET_BASES = ['net', 'gross'];

	public function __construct(
		private IDBConnection $db,
		private AccessControlService $access,
		private ITimeFactory $timeFactory,
		private AuditLogService $audit,
		private TimezoneCatalog $timezones,
		private IUserManager $userManager,
		private CategoryService $categories,
		private MoneyService $money,
	) {
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function listForUser(string $userId): array
	{
		$ids = $this->access->workspacesForUser($userId);
		if ($ids === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('bc_workspaces')
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
			->orderBy('type', 'ASC')
			->addOrderBy('name', 'ASC');
		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$workspace = $this->hydrateRow($row);
			$workspace['role'] = $this->access->role((int)$workspace['id'], $userId);
			$out[] = $workspace;
		}
		$result->closeCursor();
		return $out;
	}

	/**
	 * Get a single workspace and verify the caller is a member. Throws
	 * `access_denied` for non-members so non-existent and non-permitted IDs
	 * look identical to the client.
	 */
	public function getForUser(int $workspaceId, string $userId): array
	{
		if ($workspaceId < 1) {
			throw new \InvalidArgumentException('workspaceId is required.');
		}
		$workspace = $this->loadById($workspaceId);
		if ($workspace === null) {
			throw new AccessDeniedException();
		}
		$role = $this->access->role($workspaceId, $userId);
		if ($role === null) {
			throw new AccessDeniedException();
		}
		$workspace['role'] = $role;
		return $workspace;
	}

	public function createWorkspace(string $userId, array $payload): array
	{
		$type = $this->normaliseType((string)($payload['type'] ?? ''));
		if ($type === self::TYPE_PROJECT && array_key_exists('primaryPlanningYear', $payload) && $payload['primaryPlanningYear'] !== null && $payload['primaryPlanningYear'] !== '') {
			throw new \InvalidArgumentException('Project workspaces do not use primaryPlanningYear.');
		}
		$name = $this->normaliseName((string)($payload['name'] ?? ''));
		$currency = $this->normaliseCurrency((string)($payload['currencyCode'] ?? $this->access->getDefaultCurrency()));
		$timezone = $this->normaliseTimezone((string)($payload['timezone'] ?? $this->access->getDefaultTimezone()));
		$fiscalStart = $this->normaliseFiscalStartMonth((int)($payload['fiscalYearStartMonth'] ?? 1), $type);
		$autoCopy = !empty($payload['autoCopyBudgetsFromPreviousMonth']);
		$overspendThreshold = $this->normaliseNullableMinor($payload['overspendThresholdMinor'] ?? null);

		[$projectStart, $projectEnd, $projectCap, $defaultVatRate] = $this->extractProjectFields($payload, $type);
		$primaryPlanningYear = null;
		if ($type === self::TYPE_HOUSEHOLD) {
			$primaryPlanningYear = $this->normalisePrimaryPlanningYear($payload['primaryPlanningYear'] ?? null);
		}

		// Household workspaces must not carry project payload fields.
		if ($type === self::TYPE_HOUSEHOLD) {
			foreach (['projectStartDate', 'projectEndDate', 'projectTotalCapMinor'] as $forbidden) {
				if (array_key_exists($forbidden, $payload) && $payload[$forbidden] !== null && $payload[$forbidden] !== '') {
					throw new \InvalidArgumentException('Household workspaces do not accept project fields.');
				}
			}
		}

		$now = $this->utcNow();
		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('bc_workspaces')
				->values([
					'name' => $qb->createNamedParameter($name),
					'type' => $qb->createNamedParameter($type),
					'currency_code' => $qb->createNamedParameter($currency),
					'timezone' => $qb->createNamedParameter($timezone),
					'fiscal_year_start_month' => $qb->createNamedParameter($fiscalStart, \PDO::PARAM_INT),
					'tax_mode_enabled' => $qb->createNamedParameter(false, \PDO::PARAM_BOOL),
					'tax_budget_basis' => $qb->createNamedParameter('gross'),
					'overspend_threshold_minor' => $qb->createNamedParameter($overspendThreshold, $overspendThreshold === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT),
					'auto_copy_budgets_from_previous_month' => $qb->createNamedParameter($autoCopy, \PDO::PARAM_BOOL),
					'is_closed' => $qb->createNamedParameter(false, \PDO::PARAM_BOOL),
					'is_active' => $qb->createNamedParameter(true, \PDO::PARAM_BOOL),
					'project_total_cap_minor' => $qb->createNamedParameter($projectCap, $projectCap === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT),
					'project_start_date' => $qb->createNamedParameter($projectStart),
					'project_end_date' => $qb->createNamedParameter($projectEnd),
					'default_vat_rate_bp' => $qb->createNamedParameter($defaultVatRate, $defaultVatRate === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT),
					'primary_planning_year' => $qb->createNamedParameter(
						$primaryPlanningYear,
						$primaryPlanningYear === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT
					),
					'created_by' => $qb->createNamedParameter($userId),
					'created_at' => $qb->createNamedParameter($now),
					'updated_at' => $qb->createNamedParameter($now),
				]);
			$qb->executeStatement();
			$id = (int)$this->db->lastInsertId('bc_workspaces');

			$mqb = $this->db->getQueryBuilder();
			$mqb->insert('bc_workspace_members')
				->values([
					'workspace_id' => $mqb->createNamedParameter($id, \PDO::PARAM_INT),
					'user_id' => $mqb->createNamedParameter($userId),
					'role' => $mqb->createNamedParameter(AccessControlService::ROLE_MANAGER),
					'created_at' => $mqb->createNamedParameter($now),
				]);
			$mqb->executeStatement();

			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}

		$workspace = $this->loadById($id);
		if ($workspace === null) {
			// Should never happen; commit succeeded above. Defensive only.
			throw new InternalErrorException();
		}
		$workspace['role'] = AccessControlService::ROLE_MANAGER;
		$this->access->rememberLastUsedWorkspace($userId, $id);
		$this->audit->record($userId, 'workspace_created', 'workspace', (string)$id, ['type' => $type, 'name' => $name], $id);
		$this->categories->ensureSystemCategoriesForWorkspace($id, $userId);
		return $workspace;
	}

	public function updateWorkspace(int $workspaceId, string $userId, array $payload): array
	{
		$workspace = $this->getForUser($workspaceId, $userId);
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_MANAGER);
		if ($workspace['type'] === self::TYPE_PROJECT && array_key_exists('primaryPlanningYear', $payload)) {
			throw new \InvalidArgumentException('Project workspaces do not use primaryPlanningYear.');
		}

		$updates = [];
		$logChanges = [];

		if (array_key_exists('name', $payload)) {
			$name = $this->normaliseName((string)$payload['name']);
			if ($name !== $workspace['name']) {
				$updates['name'] = $name;
				$logChanges['name'] = $name;
			}
		}
		if (array_key_exists('currencyCode', $payload)) {
			$currency = $this->normaliseCurrency((string)$payload['currencyCode']);
			if ($currency !== $workspace['currencyCode']) {
				// Currency changes can change rounding semantics. We allow it
				// only if there are no transactions yet — anything else would
				// silently revalue the historical ledger.
				if ($this->countTransactions($workspaceId) > 0) {
					throw new \InvalidArgumentException('Currency cannot be changed once transactions exist.');
				}
				$updates['currency_code'] = $currency;
				$logChanges['currencyCode'] = $currency;
			}
		}
		if (array_key_exists('timezone', $payload)) {
			$timezone = $this->normaliseTimezone((string)$payload['timezone']);
			if ($timezone !== $workspace['timezone']) {
				$updates['timezone'] = $timezone;
				$logChanges['timezone'] = $timezone;
			}
		}
		if (array_key_exists('overspendThresholdMinor', $payload)) {
			$threshold = $this->normaliseNullableMinor($payload['overspendThresholdMinor']);
			if ($threshold !== $workspace['overspendThresholdMinor']) {
				$updates['overspend_threshold_minor'] = $threshold;
				$logChanges['overspendThresholdMinor'] = $threshold;
			}
		}
		if ($workspace['type'] === self::TYPE_HOUSEHOLD && array_key_exists('autoCopyBudgetsFromPreviousMonth', $payload)) {
			$autoCopy = (bool)$payload['autoCopyBudgetsFromPreviousMonth'];
			if ($autoCopy !== $workspace['autoCopyBudgetsFromPreviousMonth']) {
				$updates['auto_copy_budgets_from_previous_month'] = $autoCopy;
				$logChanges['autoCopyBudgetsFromPreviousMonth'] = $autoCopy;
			}
		}
		if ($workspace['type'] === self::TYPE_HOUSEHOLD && array_key_exists('primaryPlanningYear', $payload)) {
			$py = $this->normalisePrimaryPlanningYear($payload['primaryPlanningYear']);
			$cur = array_key_exists('primaryPlanningYear', $workspace) && $workspace['primaryPlanningYear'] !== null
				? (int)$workspace['primaryPlanningYear']
				: null;
			if ($py !== $cur) {
				$updates['primary_planning_year'] = $py;
				$logChanges['primaryPlanningYear'] = $py;
			}
		}
		if ($workspace['type'] === self::TYPE_PROJECT) {
			$startProvided = array_key_exists('projectStartDate', $payload);
			$endProvided = array_key_exists('projectEndDate', $payload);
			if ($startProvided || $endProvided) {
				$rawStart = $startProvided ? $payload['projectStartDate'] : $workspace['projectStartDate'];
				$rawEnd = $endProvided ? $payload['projectEndDate'] : $workspace['projectEndDate'];
				$startDate = $this->parseDate((string)$rawStart, 'projectStartDate');
				$endDate = $this->parseDate((string)$rawEnd, 'projectEndDate');
				if ($endDate < $startDate) {
					throw new \InvalidArgumentException('projectEndDate must not be before projectStartDate.');
				}
				$this->ensureNoOrphans($workspaceId, $startDate, $endDate);
				$updates['project_start_date'] = $startDate->format('Y-m-d');
				$updates['project_end_date'] = $endDate->format('Y-m-d');
				$logChanges['projectStartDate'] = $updates['project_start_date'];
				$logChanges['projectEndDate'] = $updates['project_end_date'];
			}
			if (array_key_exists('projectTotalCapMinor', $payload)) {
				$cap = $this->normaliseNullableMinor($payload['projectTotalCapMinor']);
				if ($cap !== $workspace['projectTotalCapMinor']) {
					$updates['project_total_cap_minor'] = $cap;
					$logChanges['projectTotalCapMinor'] = $cap;
				}
			}
		} else {
			// Reject project payload fields silently arriving on a household workspace.
			foreach (['projectStartDate', 'projectEndDate', 'projectTotalCapMinor'] as $forbidden) {
				if (array_key_exists($forbidden, $payload) && $payload[$forbidden] !== null && $payload[$forbidden] !== '') {
					throw new \InvalidArgumentException('Household workspaces do not accept project fields.');
				}
			}
		}

		if ($updates === []) {
			return $workspace;
		}
		$updates['updated_at'] = $this->utcNow();

		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_workspaces');
		foreach ($updates as $col => $value) {
			$type = match (true) {
				is_bool($value) => \PDO::PARAM_BOOL,
				is_int($value) => \PDO::PARAM_INT,
				$value === null => \PDO::PARAM_NULL,
				default => \PDO::PARAM_STR,
			};
			$qb->set($col, $qb->createNamedParameter($value, $type));
		}
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)));
		$qb->executeStatement();

		$this->audit->record($userId, 'workspace_updated', 'workspace', (string)$workspaceId, $logChanges, $workspaceId);
		return $this->getForUser($workspaceId, $userId);
	}

	public function updateTaxMode(int $workspaceId, string $userId, array $payload): array
	{
		$workspace = $this->getForUser($workspaceId, $userId);
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_MANAGER);

		$enabled = !empty($payload['taxModeEnabled']);
		$basis = strtolower((string)($payload['taxBudgetBasis'] ?? $workspace['taxBudgetBasis']));
		if (!in_array($basis, self::TAX_BUDGET_BASES, true)) {
			throw new \InvalidArgumentException('taxBudgetBasis must be one of: ' . implode(', ', self::TAX_BUDGET_BASES) . '.');
		}
		$rate = $payload['defaultVatRateBp'] ?? null;
		if ($rate !== null && $rate !== '') {
			if (!is_int($rate) && !ctype_digit((string)$rate)) {
				throw new \InvalidArgumentException('defaultVatRateBp must be an integer (basis points).');
			}
			$rate = (int)$rate;
			if ($rate < MoneyService::MIN_VAT_RATE_BP || $rate > MoneyService::MAX_VAT_RATE_BP) {
				throw new \InvalidArgumentException('defaultVatRateBp out of range.');
			}
		} else {
			$rate = null;
		}

		// When disabling tax mode, we must scrub stored tax fields from existing
		// transactions so future calculations cannot accidentally pick them up.
		if (!$enabled && $workspace['taxModeEnabled']) {
			$tx = $this->db->getQueryBuilder();
			$tx->update('bc_transactions')
				->set('entry_amount_basis', $tx->createNamedParameter('simple'))
				->set('net_amount_minor', $tx->createNamedParameter(null, \PDO::PARAM_NULL))
				->set('vat_rate_bp', $tx->createNamedParameter(null, \PDO::PARAM_NULL))
				->set('vat_amount_minor', $tx->createNamedParameter(null, \PDO::PARAM_NULL))
				->set('gross_amount_minor', $tx->createNamedParameter(null, \PDO::PARAM_NULL))
				->set('tax_calculation_locked', $tx->createNamedParameter(false, \PDO::PARAM_BOOL))
				->set('updated_by', $tx->createNamedParameter($userId))
				->set('updated_at', $tx->createNamedParameter($this->utcNow()))
				->where($tx->expr()->eq('workspace_id', $tx->createNamedParameter($workspaceId, \PDO::PARAM_INT)));
			$tx->executeStatement();
		}

		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_workspaces')
			->set('tax_mode_enabled', $qb->createNamedParameter($enabled, \PDO::PARAM_BOOL))
			->set('tax_budget_basis', $qb->createNamedParameter($basis))
			->set('default_vat_rate_bp', $qb->createNamedParameter($rate, $rate === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($this->utcNow()))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)));
		$qb->executeStatement();

		$this->audit->record($userId, 'workspace_tax_mode_updated', 'workspace', (string)$workspaceId, [
			'enabled' => $enabled,
			'basis' => $basis,
			'defaultRateBp' => $rate,
		], $workspaceId);
		return $this->getForUser($workspaceId, $userId);
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function listMembers(int $workspaceId, string $userId): array
	{
		$this->getForUser($workspaceId, $userId);
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('bc_workspace_members')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->orderBy('role', 'ASC');
		$result = $qb->executeQuery();
		$rows = [];
		while ($row = $result->fetch()) {
			$user = $this->userManager->get((string)$row['user_id']);
			$rows[] = [
				'id' => (int)$row['id'],
				'workspaceId' => (int)$row['workspace_id'],
				'userId' => (string)$row['user_id'],
				'displayName' => $user !== null ? $user->getDisplayName() : (string)$row['user_id'],
				'enabled' => $user !== null ? $user->isEnabled() : false,
				'role' => (string)$row['role'],
				'createdAt' => (string)$row['created_at'],
			];
		}
		$result->closeCursor();
		return $rows;
	}

	public function addMember(int $workspaceId, string $userId, array $payload): array
	{
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_MANAGER);
		$candidate = trim((string)($payload['userId'] ?? ''));
		if ($candidate === '') {
			throw new \InvalidArgumentException('userId is required.');
		}
		if ($this->userManager->get($candidate) === null) {
			throw new \InvalidArgumentException('Unknown user.');
		}
		$role = $this->normaliseRole((string)($payload['role'] ?? AccessControlService::ROLE_VIEWER));
		// Conflict: trying to re-add an existing member.
		if ($this->access->role($workspaceId, $candidate) !== null) {
			throw new \InvalidArgumentException('User is already a member of this workspace.');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->insert('bc_workspace_members')
			->values([
				'workspace_id' => $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT),
				'user_id' => $qb->createNamedParameter($candidate),
				'role' => $qb->createNamedParameter($role),
				'created_at' => $qb->createNamedParameter($this->utcNow()),
			]);
		$qb->executeStatement();
		$this->audit->record($userId, 'workspace_member_added', 'workspace_member', $candidate, ['role' => $role], $workspaceId);
		return $this->listMembers($workspaceId, $userId);
	}

	public function updateMember(int $memberId, string $userId, array $payload): array
	{
		$row = $this->loadMember($memberId);
		if ($row === null) {
			throw new AccessDeniedException();
		}
		$workspaceId = (int)$row['workspace_id'];
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_MANAGER);
		$role = $this->normaliseRole((string)($payload['role'] ?? $row['role']));
		// Last-manager protection: do not let the only manager of a workspace
		// downgrade themselves and orphan the workspace.
		if ($role !== AccessControlService::ROLE_MANAGER && (string)$row['role'] === AccessControlService::ROLE_MANAGER) {
			$this->ensureNotLastManager($workspaceId, (int)$row['id']);
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_workspace_members')
			->set('role', $qb->createNamedParameter($role))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($memberId, \PDO::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->record($userId, 'workspace_member_updated', 'workspace_member', (string)$row['user_id'], ['role' => $role], $workspaceId);
		return $this->listMembers($workspaceId, $userId);
	}

	public function removeMember(int $memberId, string $userId): array
	{
		$row = $this->loadMember($memberId);
		if ($row === null) {
			throw new AccessDeniedException();
		}
		$workspaceId = (int)$row['workspace_id'];
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_MANAGER);
		if ((string)$row['role'] === AccessControlService::ROLE_MANAGER) {
			$this->ensureNotLastManager($workspaceId, (int)$row['id']);
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete('bc_workspace_members')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($memberId, \PDO::PARAM_INT)));
		$qb->executeStatement();
		$this->access->forgetLastUsedWorkspace((string)$row['user_id'], $workspaceId);
		$this->audit->record($userId, 'workspace_member_removed', 'workspace_member', (string)$row['user_id'], [], $workspaceId);
		return $this->listMembers($workspaceId, $userId);
	}

	private function loadMember(int $memberId): ?array
	{
		if ($memberId < 1) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('bc_workspace_members')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($memberId, \PDO::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? null : $row;
	}

	private function ensureNotLastManager(int $workspaceId, int $memberIdBeingChanged): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'count'))
			->from('bc_workspace_members')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('role', $qb->createNamedParameter(AccessControlService::ROLE_MANAGER)))
			->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($memberIdBeingChanged, \PDO::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if ((int)($row['count'] ?? 0) === 0) {
			throw new \InvalidArgumentException('Cannot remove or downgrade the last workspace manager. Promote another member first.');
		}
	}

	public function loadById(int $workspaceId): ?array
	{
		if ($workspaceId < 1) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('bc_workspaces')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? null : $this->hydrateRow($row);
	}

	private function hydrateRow(array $row): array
	{
		$currencyCode = (string)$row['currency_code'];
		return [
			'id' => (int)$row['id'],
			'name' => (string)$row['name'],
			'type' => (string)$row['type'],
			'currencyCode' => $currencyCode,
			'currencyDecimals' => $this->money->decimalsFor($currencyCode),
			'timezone' => (string)$row['timezone'],
			'fiscalYearStartMonth' => (int)$row['fiscal_year_start_month'],
			'taxModeEnabled' => (bool)$row['tax_mode_enabled'],
			'taxBudgetBasis' => (string)$row['tax_budget_basis'],
			'overspendThresholdMinor' => $row['overspend_threshold_minor'] === null ? null : (int)$row['overspend_threshold_minor'],
			'autoCopyBudgetsFromPreviousMonth' => (bool)$row['auto_copy_budgets_from_previous_month'],
			'isClosed' => (bool)$row['is_closed'],
			'isActive' => (bool)$row['is_active'],
			'projectTotalCapMinor' => $row['project_total_cap_minor'] === null ? null : (int)$row['project_total_cap_minor'],
			'projectStartDate' => $row['project_start_date'] !== null ? (string)$row['project_start_date'] : null,
			'projectEndDate' => $row['project_end_date'] !== null ? (string)$row['project_end_date'] : null,
			'defaultVatRateBp' => $row['default_vat_rate_bp'] === null ? null : (int)$row['default_vat_rate_bp'],
			'createdBy' => (string)$row['created_by'],
			'createdAt' => (string)$row['created_at'],
			'updatedAt' => (string)$row['updated_at'],
			'activeCalendarYearMonth' => $this->activeHouseholdYearMonth((string)$row['type'], (string)$row['timezone']),
			'primaryPlanningYear' => isset($row['primary_planning_year']) && $row['primary_planning_year'] !== null
				? (int)$row['primary_planning_year']
				: null,
		];
	}

	private function activeHouseholdYearMonth(string $type, string $timezone): ?string
	{
		if ($type !== self::TYPE_HOUSEHOLD) {
			return null;
		}
		try {
			$tz = new \DateTimeZone($timezone);
		} catch (\Throwable) {
			$tz = new \DateTimeZone('UTC');
		}
		$now = $this->timeFactory->getDateTime('now', $tz);
		return $now->format('Y-m');
	}

	public function bookingDateInsideProjectWindow(array $workspace, \DateTimeImmutable $date): bool
	{
		if (($workspace['type'] ?? null) !== self::TYPE_PROJECT) {
			return true;
		}
		$start = $workspace['projectStartDate'] !== null ? new \DateTimeImmutable($workspace['projectStartDate']) : null;
		$end = $workspace['projectEndDate'] !== null ? new \DateTimeImmutable($workspace['projectEndDate']) : null;
		if ($start !== null && $date < $start) {
			return false;
		}
		if ($end !== null && $date > $end) {
			return false;
		}
		return true;
	}

	private function ensureNoOrphans(int $workspaceId, \DateTimeImmutable $start, \DateTimeImmutable $end): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'count'))
			->from('bc_transactions')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->andWhere($qb->expr()->orX(
				$qb->expr()->lt('booking_date', $qb->createNamedParameter($start->format('Y-m-d'))),
				$qb->expr()->gt('booking_date', $qb->createNamedParameter($end->format('Y-m-d')))
			));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if ((int)($row['count'] ?? 0) > 0) {
			throw new \InvalidArgumentException('The new project date window would orphan existing transactions. Move or delete them first.');
		}
	}

	private function countTransactions(int $workspaceId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'count'))
			->from('bc_transactions')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['count'] ?? 0);
	}

	// ------------------------------------------------------------------
	//  Validation helpers
	// ------------------------------------------------------------------

	private function normaliseType(string $type): string
	{
		$type = strtolower(trim($type));
		if (!in_array($type, self::TYPES, true)) {
			throw new \InvalidArgumentException('Workspace type must be one of: ' . implode(', ', self::TYPES) . '.');
		}
		return $type;
	}

	private function normaliseName(string $name): string
	{
		$name = trim($name);
		if ($name === '') {
			throw new \InvalidArgumentException('Workspace name is required.');
		}
		if (mb_strlen($name) > 120) {
			throw new \InvalidArgumentException('Workspace name must be 120 characters or fewer.');
		}
		return $name;
	}

	private function normaliseCurrency(string $currency): string
	{
		$currency = strtoupper(trim($currency));
		if (!preg_match('/^[A-Z]{3}$/', $currency)) {
			throw new \InvalidArgumentException('Currency must be a 3-letter ISO 4217 code.');
		}
		if (!$this->money->isSupportedCurrency($currency)) {
			throw new \InvalidArgumentException('Currency is not supported. Pick a value from the workspace currency list.');
		}
		return $currency;
	}

	private function normaliseTimezone(string $timezone): string
	{
		$timezone = trim($timezone);
		if (!$this->timezones->isValid($timezone)) {
			throw new \InvalidArgumentException('Invalid timezone. Use an IANA identifier.');
		}
		return $timezone;
	}

	/**
	 * Calendar year anchor for household planning (budgets, monthly close, yearly overview).
	 * Defaults to the current UTC year when omitted.
	 */
	private function normalisePrimaryPlanningYear(mixed $value): int
	{
		if ($value === null || $value === '') {
			return (int)$this->timeFactory->getDateTime('now', new \DateTimeZone('UTC'))->format('Y');
		}
		if (is_string($value) && !ctype_digit(trim($value))) {
			throw new \InvalidArgumentException('primaryPlanningYear must be a whole number between 1900 and 9999.');
		}
		$y = is_int($value) ? $value : (int)(is_string($value) ? trim($value) : (string)$value);
		if ($y < 1900 || $y > 9999) {
			throw new \InvalidArgumentException('primaryPlanningYear must be between 1900 and 9999.');
		}

		return $y;
	}

	private function normaliseFiscalStartMonth(int $month, string $type): int
	{
		if ($type === self::TYPE_HOUSEHOLD) {
			return 1;
		}
		if ($month < 1 || $month > 12) {
			throw new \InvalidArgumentException('fiscalYearStartMonth must be between 1 and 12.');
		}
		return $month;
	}

	private function normaliseRole(string $role): string
	{
		$role = strtolower(trim($role));
		if (!in_array($role, [AccessControlService::ROLE_MANAGER, AccessControlService::ROLE_CONTRIBUTOR, AccessControlService::ROLE_VIEWER], true)) {
			throw new \InvalidArgumentException('Invalid role.');
		}
		return $role;
	}

	private function normaliseNullableMinor(mixed $value): ?int
	{
		if ($value === null || $value === '' || $value === false) {
			return null;
		}
		if (!is_int($value) && !ctype_digit((string)$value)) {
			throw new \InvalidArgumentException('Threshold must be a non-negative integer (minor units).');
		}
		$int = (int)$value;
		if ($int < 0) {
			throw new \InvalidArgumentException('Threshold must be a non-negative integer (minor units).');
		}
		return $int;
	}

	/**
	 * @return array{0: ?string, 1: ?string, 2: ?int, 3: ?int}
	 */
	private function extractProjectFields(array $payload, string $type): array
	{
		if ($type !== self::TYPE_PROJECT) {
			return [null, null, null, null];
		}
		$startRaw = trim((string)($payload['projectStartDate'] ?? ''));
		$endRaw = trim((string)($payload['projectEndDate'] ?? ''));
		if ($startRaw === '' || $endRaw === '') {
			throw new \InvalidArgumentException('Project workspaces require projectStartDate and projectEndDate.');
		}
		$start = $this->parseDate($startRaw, 'projectStartDate');
		$end = $this->parseDate($endRaw, 'projectEndDate');
		if ($end < $start) {
			throw new \InvalidArgumentException('projectEndDate must not be before projectStartDate.');
		}
		$cap = $this->normaliseNullableMinor($payload['projectTotalCapMinor'] ?? null);
		$rate = null;
		if (isset($payload['defaultVatRateBp']) && $payload['defaultVatRateBp'] !== '' && $payload['defaultVatRateBp'] !== null) {
			$rate = (int)$payload['defaultVatRateBp'];
			if ($rate < MoneyService::MIN_VAT_RATE_BP || $rate > MoneyService::MAX_VAT_RATE_BP) {
				throw new \InvalidArgumentException('defaultVatRateBp out of range.');
			}
		}
		return [$start->format('Y-m-d'), $end->format('Y-m-d'), $cap, $rate];
	}

	private function parseDate(string $value, string $field): \DateTimeImmutable
	{
		$value = trim($value);
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
			throw new \InvalidArgumentException($field . ' must be in YYYY-MM-DD format.');
		}
		try {
			return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->setTime(0, 0);
		} catch (\Throwable) {
			throw new \InvalidArgumentException($field . ' is not a valid date.');
		}
	}

	private function utcNow(): string
	{
		return $this->timeFactory->getDateTime('now', new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
	}
}
