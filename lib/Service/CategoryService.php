<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

use OCA\BudgetCheck\Exception\AccessDeniedException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Workspace-scoped category catalogue.
 *
 * - Categories are typed (`income` or `expense`); the type pins which
 *   transactions can use them, so business rules like "category direction must
 *   equal transaction direction" can be enforced statically.
 * - Active uniqueness is `(workspace, type, name)`. Once deactivated, the same
 *   name can be re-used by a fresh active category — historical transactions
 *   keep referring to the archived row by id, so renames never lose history.
 * - The optional `group_key` lets users tag related categories (e.g. "boat",
 *   "car") without inventing a new entity.
 * - `is_savings_transfer` marks expense categories used for money moved to
 *   savings. Those bookings stay in total expenses (bank mirror) but are
 *   excluded from everyday budget saldo and count toward savings progress.
 */
class CategoryService
{
	public const TYPE_INCOME = 'income';
	public const TYPE_EXPENSE = 'expense';
	private const TYPES = [self::TYPE_INCOME, self::TYPE_EXPENSE];

	public const TAX_HANDLING_MODES = ['inherit_workspace', 'taxable', 'tax_exempt'];

	/** Reserved group_key for the system “uncategorized expense” bucket (§9). */
	public const GROUP_INTERNAL_UNCATEGORIZED = '_bc_internal_uncategorized';

	public function __construct(
		private IDBConnection $db,
		private AccessControlService $access,
		private ITimeFactory $timeFactory,
		private AuditLogService $audit,
	) {
	}

	public function listForWorkspace(int $workspaceId, string $userId, bool $includeInactive = false): array
	{
		$this->access->ensureMembership($workspaceId, $userId);
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('bc_categories')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)));
		if (!$includeInactive) {
			$qb->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)));
		}
		$qb->orderBy('type', 'ASC')
			->addOrderBy('name', 'ASC');
		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$out[] = $this->hydrate($row);
		}
		$result->closeCursor();
		return $out;
	}

	/**
	 * Distinct non-empty group keys for the workspace (for category form selects).
	 * Excludes the internal uncategorized bucket id marker.
	 *
	 * @return list<string>
	 */
	public function distinctGroupKeys(int $workspaceId, string $userId): array
	{
		$this->access->ensureMembership($workspaceId, $userId);
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('group_key')
			->from('bc_categories')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('group_key'))
			->andWhere($qb->expr()->neq('group_key', $qb->createNamedParameter('')))
			->andWhere($qb->expr()->neq('group_key', $qb->createNamedParameter(self::GROUP_INTERNAL_UNCATEGORIZED)))
			->orderBy('group_key', 'ASC');
		$result = $qb->executeQuery();
		$keys = [];
		while ($row = $result->fetch()) {
			$k = (string)($row['group_key'] ?? '');
			if ($k !== '') {
				$keys[] = $k;
			}
		}
		$result->closeCursor();
		return $keys;
	}

	public function create(int $workspaceId, string $userId, array $payload): array
	{
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_MANAGER);
		$name = $this->normaliseName((string)($payload['name'] ?? ''));
		$type = $this->normaliseType((string)($payload['type'] ?? ''));
		$groupKey = $this->normaliseGroupKey($payload['groupKey'] ?? null);
		$isSpecial = !empty($payload['isSpecial']);
		$isSavingsTransfer = $this->normaliseIsSavingsTransfer($payload['isSavingsTransfer'] ?? false, $type);
		$taxMode = $this->normaliseTaxMode((string)($payload['taxHandlingMode'] ?? 'inherit_workspace'));

		$this->ensureUniqueActive($workspaceId, $name, $type);

		$now = $this->utcNow();
		$qb = $this->db->getQueryBuilder();
		$qb->insert('bc_categories')
			->values([
				'workspace_id' => $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT),
				'name' => $qb->createNamedParameter($name),
				'type' => $qb->createNamedParameter($type),
				'group_key' => $qb->createNamedParameter($groupKey),
				'is_special' => $qb->createNamedParameter($isSpecial, \PDO::PARAM_BOOL),
				'is_savings_transfer' => $qb->createNamedParameter($isSavingsTransfer, \PDO::PARAM_BOOL),
				'tax_handling_mode' => $qb->createNamedParameter($taxMode),
				'is_active' => $qb->createNamedParameter(true, \PDO::PARAM_BOOL),
				'created_by' => $qb->createNamedParameter($userId),
				'created_at' => $qb->createNamedParameter($now),
				'updated_at' => $qb->createNamedParameter($now),
			]);
		$qb->executeStatement();
		$id = (int)$this->db->lastInsertId('bc_categories');
		$this->audit->record($userId, 'category_created', 'category', (string)$id, ['type' => $type, 'name' => $name], $workspaceId);
		return $this->loadById($id);
	}

	public function update(int $categoryId, string $userId, array $payload): array
	{
		$category = $this->loadById($categoryId);
		if ($category === null) {
			throw new AccessDeniedException();
		}
		$this->access->ensureMinimumRole($category['workspaceId'], $userId, AccessControlService::ROLE_MANAGER);

		$updates = [];
		$logChanges = [];

		if (array_key_exists('name', $payload)) {
			$name = $this->normaliseName((string)$payload['name']);
			if ($name !== $category['name']) {
				$this->ensureUniqueActive($category['workspaceId'], $name, $category['type'], $categoryId);
				$updates['name'] = $name;
				$logChanges['name'] = $name;
			}
		}
		if (array_key_exists('groupKey', $payload)) {
			$groupKey = $this->normaliseGroupKey($payload['groupKey']);
			if ($groupKey !== $category['groupKey']) {
				$updates['group_key'] = $groupKey;
				$logChanges['groupKey'] = $groupKey;
			}
		}
		if (array_key_exists('isSpecial', $payload)) {
			$isSpecial = (bool)$payload['isSpecial'];
			if ($isSpecial !== $category['isSpecial']) {
				$updates['is_special'] = $isSpecial;
				$logChanges['isSpecial'] = $isSpecial;
			}
		}
		if (array_key_exists('isSavingsTransfer', $payload)) {
			$isSavingsTransfer = $this->normaliseIsSavingsTransfer($payload['isSavingsTransfer'], $category['type']);
			if ($isSavingsTransfer !== $category['isSavingsTransfer']) {
				$updates['is_savings_transfer'] = $isSavingsTransfer;
				$logChanges['isSavingsTransfer'] = $isSavingsTransfer;
			}
		}
		if (array_key_exists('taxHandlingMode', $payload)) {
			$mode = $this->normaliseTaxMode((string)$payload['taxHandlingMode']);
			if ($mode !== $category['taxHandlingMode']) {
				$updates['tax_handling_mode'] = $mode;
				$logChanges['taxHandlingMode'] = $mode;
			}
		}
		// We intentionally do NOT allow changing `type` after creation. Direction
		// is structural: existing transactions reference a typed category, and
		// flipping the type would silently change every historical row's sign.

		if ($updates === []) {
			return $category;
		}
		$updates['updated_at'] = $this->utcNow();
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_categories');
		foreach ($updates as $col => $value) {
			$type = match (true) {
				is_bool($value) => \PDO::PARAM_BOOL,
				$value === null => \PDO::PARAM_NULL,
				default => \PDO::PARAM_STR,
			};
			$qb->set($col, $qb->createNamedParameter($value, $type));
		}
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($categoryId, \PDO::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->record($userId, 'category_updated', 'category', (string)$categoryId, $logChanges, $category['workspaceId']);
		return $this->loadById($categoryId);
	}

	public function deactivate(int $categoryId, string $userId): array
	{
		$category = $this->loadById($categoryId);
		if ($category === null) {
			throw new AccessDeniedException();
		}
		$this->access->ensureMinimumRole($category['workspaceId'], $userId, AccessControlService::ROLE_MANAGER);
		if ($category['groupKey'] === self::GROUP_INTERNAL_UNCATEGORIZED) {
			throw new \InvalidArgumentException('The system Uncategorized category cannot be deactivated.');
		}
		if (!$category['isActive']) {
			return $category;
		}
		// Recurring rules pointing at this category are deactivated in the same
		// breath so background suggestions stop using a vanished bucket.
		$rqb = $this->db->getQueryBuilder();
		$rqb->update('bc_recurring_rules')
			->set('is_active', $rqb->createNamedParameter(false, \PDO::PARAM_BOOL))
			->set('updated_at', $rqb->createNamedParameter($this->utcNow()))
			->where($rqb->expr()->eq('category_id', $rqb->createNamedParameter($categoryId, \PDO::PARAM_INT)));
		$rqb->executeStatement();

		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_categories')
			->set('is_active', $qb->createNamedParameter(false, \PDO::PARAM_BOOL))
			->set('updated_at', $qb->createNamedParameter($this->utcNow()))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($categoryId, \PDO::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->record($userId, 'category_deactivated', 'category', (string)$categoryId, [], $category['workspaceId']);
		return $this->loadById($categoryId);
	}

	public function loadById(int $categoryId): ?array
	{
		if ($categoryId < 1) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('bc_categories')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($categoryId, \PDO::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? null : $this->hydrate($row);
	}

	/**
	 * Idempotent: ensures the internal uncategorized expense category exists.
	 * Called after workspace creation and by migrations for older installs.
	 */
	public function ensureSystemCategoriesForWorkspace(int $workspaceId, string $creatorUserId): void
	{
		if ($this->internalUncategorizedCategoryId($workspaceId) !== null) {
			return;
		}
		$now = $this->utcNow();
		$qb = $this->db->getQueryBuilder();
		$qb->insert('bc_categories')
			->values([
				'workspace_id' => $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT),
				'name' => $qb->createNamedParameter('Uncategorized'),
				'type' => $qb->createNamedParameter(self::TYPE_EXPENSE),
				'group_key' => $qb->createNamedParameter(self::GROUP_INTERNAL_UNCATEGORIZED),
				'is_special' => $qb->createNamedParameter(false, \PDO::PARAM_BOOL),
				'tax_handling_mode' => $qb->createNamedParameter('inherit_workspace'),
				'is_active' => $qb->createNamedParameter(true, \PDO::PARAM_BOOL),
				'created_by' => $qb->createNamedParameter($creatorUserId),
				'created_at' => $qb->createNamedParameter($now),
				'updated_at' => $qb->createNamedParameter($now),
			]);
		$qb->executeStatement();
		$id = (int)$this->db->lastInsertId('bc_categories');
		$this->audit->record($creatorUserId, 'category_created', 'category', (string)$id, ['type' => self::TYPE_EXPENSE, 'name' => 'Uncategorized', 'system' => true], $workspaceId);
	}

	/**
	 * @return list<int>
	 */
	public function internalUncategorizedCategoryIds(int $workspaceId): array
	{
		$id = $this->internalUncategorizedCategoryId($workspaceId);
		return $id === null ? [] : [$id];
	}

	public function internalUncategorizedCategoryId(int $workspaceId): ?int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('bc_categories')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('group_key', $qb->createNamedParameter(self::GROUP_INTERNAL_UNCATEGORIZED)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if ($row === false) {
			return null;
		}
		return (int)$row['id'];
	}

	public function loadForWorkspace(int $categoryId, int $workspaceId): ?array
	{
		$category = $this->loadById($categoryId);
		if ($category === null || $category['workspaceId'] !== $workspaceId) {
			return null;
		}
		return $category;
	}

	private function hydrate(array $row): array
	{
		return [
			'id' => (int)$row['id'],
			'workspaceId' => (int)$row['workspace_id'],
			'name' => (string)$row['name'],
			'type' => (string)$row['type'],
			'groupKey' => $row['group_key'] !== null ? (string)$row['group_key'] : null,
			'isSpecial' => (bool)$row['is_special'],
			'isSavingsTransfer' => (bool)($row['is_savings_transfer'] ?? false),
			'taxHandlingMode' => (string)$row['tax_handling_mode'],
			'isActive' => (bool)$row['is_active'],
			'createdBy' => (string)$row['created_by'],
			'createdAt' => (string)$row['created_at'],
			'updatedAt' => (string)$row['updated_at'],
		];
	}

	private function ensureUniqueActive(int $workspaceId, string $name, string $type, ?int $excludeId = null): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'count'))
			->from('bc_categories')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter($type)))
			->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('name', $qb->createNamedParameter($name)));
		if ($excludeId !== null) {
			$qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($excludeId, \PDO::PARAM_INT)));
		}
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if ((int)($row['count'] ?? 0) > 0) {
			throw new \InvalidArgumentException('A category with this name and direction already exists.');
		}
	}

	private function normaliseName(string $name): string
	{
		$name = trim($name);
		if ($name === '') {
			throw new \InvalidArgumentException('Category name is required.');
		}
		if (mb_strlen($name) > 120) {
			throw new \InvalidArgumentException('Category name must be 120 characters or fewer.');
		}
		return $name;
	}

	private function normaliseType(string $type): string
	{
		$type = strtolower(trim($type));
		if (!in_array($type, self::TYPES, true)) {
			throw new \InvalidArgumentException('Category type must be income or expense.');
		}
		return $type;
	}

	private function normaliseGroupKey(mixed $value): ?string
	{
		if ($value === null) {
			return null;
		}
		$value = trim((string)$value);
		if ($value === '') {
			return null;
		}
		if ($value === self::GROUP_INTERNAL_UNCATEGORIZED) {
			throw new \InvalidArgumentException('This group key is reserved for the system Uncategorized category.');
		}
		if (mb_strlen($value) > 64) {
			throw new \InvalidArgumentException('groupKey must be 64 characters or fewer.');
		}
		if (!preg_match('/^[a-z0-9_\- ]+$/i', $value)) {
			throw new \InvalidArgumentException('groupKey may only contain letters, digits, spaces, underscore and hyphen.');
		}
		return $value;
	}

	private function normaliseIsSavingsTransfer(mixed $value, string $categoryType): bool
	{
		$flag = !empty($value);
		if ($flag && $categoryType !== self::TYPE_EXPENSE) {
			throw new \InvalidArgumentException('isSavingsTransfer applies to expense categories only.');
		}

		return $flag;
	}

	private function normaliseTaxMode(string $mode): string
	{
		$mode = strtolower(trim($mode));
		if (!in_array($mode, self::TAX_HANDLING_MODES, true)) {
			throw new \InvalidArgumentException('taxHandlingMode must be one of: ' . implode(', ', self::TAX_HANDLING_MODES) . '.');
		}
		return $mode;
	}

	private function utcNow(): string
	{
		return $this->timeFactory->getDateTime('now', new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
	}
}
