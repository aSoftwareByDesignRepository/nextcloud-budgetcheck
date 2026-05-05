<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

use OCA\BudgetCheck\Exception\AccessDeniedException;
use OCA\BudgetCheck\Exception\ConflictException;
use OCA\BudgetCheck\Exception\InternalErrorException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Ledger entries (income and expense transactions).
 *
 * Invariants enforced for every write:
 *  - The workspace must exist and the actor must hold at least the contributor
 *    role. (Viewers can only read.)
 *  - The category must belong to the same workspace. Cross-workspace IDs are
 *    rejected with a generic `access_denied` to defeat IDOR probing.
 *  - Category direction must match the transaction direction.
 *  - amount_minor > 0; sign comes from `direction`.
 *  - For project workspaces, the booking date must lie inside the project window.
 *  - Tax fields are only accepted when the workspace has tax_mode enabled.
 *    When disabled, any tax payload is rejected (§7 / §13.7 of the spec).
 *  - Updates require the same `version` the client read; otherwise we throw a
 *    409 to signal optimistic-locking conflict.
 *
 * Soft delete: setting `deleted_at` removes the row from list queries and
 * summaries while keeping it as evidence for auditing/snapshots.
 */
class TransactionService
{
	public const DIRECTION_INCOME = 'income';
	public const DIRECTION_EXPENSE = 'expense';
	private const DIRECTIONS = [self::DIRECTION_INCOME, self::DIRECTION_EXPENSE];

	public const BASIS_SIMPLE = 'simple';
	public const BASIS_NET = 'net';
	public const BASIS_GROSS = 'gross';
	private const BASES = [self::BASIS_SIMPLE, self::BASIS_NET, self::BASIS_GROSS];

	public function __construct(
		private IDBConnection $db,
		private AccessControlService $access,
		private MoneyService $money,
		private ITimeFactory $timeFactory,
		private AuditLogService $audit,
		private CategoryService $categories,
		private BookingStatusService $bookingStatuses,
	) {
	}

	/**
	 * @param array{from?:?string, to?:?string, categoryId?:int|null, statusId?:int|null, q?:?string, isSpecial?:bool|null, uncategorized?:bool, includeDeleted?:bool, limit?:int, offset?:int} $filters
	 */
	public function listForWorkspace(int $workspaceId, string $userId, array $filters, array $workspace): array
	{
		$this->access->ensureMembership($workspaceId, $userId);
		$limit = max(1, min(500, (int)($filters['limit'] ?? 100)));
		$offset = max(0, (int)($filters['offset'] ?? 0));
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('bc_transactions')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)));
		if (empty($filters['includeDeleted'])) {
			$qb->andWhere($qb->expr()->isNull('deleted_at'));
		}
		[$from, $to] = $this->resolveDateWindow($workspace, $filters);
		if ($from !== null) {
			$qb->andWhere($qb->expr()->gte('booking_date', $qb->createNamedParameter($from)));
		}
		if ($to !== null) {
			$qb->andWhere($qb->expr()->lte('booking_date', $qb->createNamedParameter($to)));
		}
		$uncategorizedOnly = !empty($filters['uncategorized']);
		if ($uncategorizedOnly) {
			$uncatId = $this->categories->internalUncategorizedCategoryId($workspaceId);
			if ($uncatId === null) {
				$qb->andWhere($qb->expr()->eq('id', $qb->createNamedParameter(0, \PDO::PARAM_INT)));
			} else {
				$qb->andWhere($qb->expr()->eq('category_id', $qb->createNamedParameter($uncatId, \PDO::PARAM_INT)));
				$qb->andWhere($qb->expr()->eq('direction', $qb->createNamedParameter(self::DIRECTION_EXPENSE)));
			}
		} elseif (!empty($filters['categoryId'])) {
			$qb->andWhere($qb->expr()->eq('category_id', $qb->createNamedParameter((int)$filters['categoryId'], \PDO::PARAM_INT)));
		}
		if (!empty($filters['statusId'])) {
			$qb->andWhere($qb->expr()->eq('booking_status_id', $qb->createNamedParameter((int)$filters['statusId'], \PDO::PARAM_INT)));
		}
		if (isset($filters['isSpecial']) && $filters['isSpecial'] !== null) {
			$qb->andWhere($qb->expr()->eq('is_special', $qb->createNamedParameter((bool)$filters['isSpecial'], \PDO::PARAM_BOOL)));
		}
		if (!empty($filters['q'])) {
			$needle = '%' . $this->db->escapeLikeParameter(trim((string)$filters['q'])) . '%';
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->iLike('title', $qb->createNamedParameter($needle)),
				$qb->expr()->iLike('notes', $qb->createNamedParameter($needle))
			));
		}
		$qb->orderBy('booking_date', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);
		$result = $qb->executeQuery();
		$rows = [];
		while ($row = $result->fetch()) {
			$rows[] = $this->hydrate($row, $workspace['currencyCode']);
		}
		$result->closeCursor();

		$total = $this->countForWorkspace($workspaceId, $filters, $workspace);
		return [
			'items' => $rows,
			'total' => $total,
			'limit' => $limit,
			'offset' => $offset,
			'window' => ['from' => $from, 'to' => $to],
		];
	}

	private function countForWorkspace(int $workspaceId, array $filters, array $workspace): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'count'))
			->from('bc_transactions')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)));
		if (empty($filters['includeDeleted'])) {
			$qb->andWhere($qb->expr()->isNull('deleted_at'));
		}
		[$from, $to] = $this->resolveDateWindow($workspace, $filters);
		if ($from !== null) {
			$qb->andWhere($qb->expr()->gte('booking_date', $qb->createNamedParameter($from)));
		}
		if ($to !== null) {
			$qb->andWhere($qb->expr()->lte('booking_date', $qb->createNamedParameter($to)));
		}
		$uncategorizedOnly = !empty($filters['uncategorized']);
		if ($uncategorizedOnly) {
			$uncatId = $this->categories->internalUncategorizedCategoryId($workspaceId);
			if ($uncatId === null) {
				$qb->andWhere($qb->expr()->eq('id', $qb->createNamedParameter(0, \PDO::PARAM_INT)));
			} else {
				$qb->andWhere($qb->expr()->eq('category_id', $qb->createNamedParameter($uncatId, \PDO::PARAM_INT)));
				$qb->andWhere($qb->expr()->eq('direction', $qb->createNamedParameter(self::DIRECTION_EXPENSE)));
			}
		} elseif (!empty($filters['categoryId'])) {
			$qb->andWhere($qb->expr()->eq('category_id', $qb->createNamedParameter((int)$filters['categoryId'], \PDO::PARAM_INT)));
		}
		if (!empty($filters['statusId'])) {
			$qb->andWhere($qb->expr()->eq('booking_status_id', $qb->createNamedParameter((int)$filters['statusId'], \PDO::PARAM_INT)));
		}
		if (isset($filters['isSpecial']) && $filters['isSpecial'] !== null) {
			$qb->andWhere($qb->expr()->eq('is_special', $qb->createNamedParameter((bool)$filters['isSpecial'], \PDO::PARAM_BOOL)));
		}
		if (!empty($filters['q'])) {
			$needle = '%' . $this->db->escapeLikeParameter(trim((string)$filters['q'])) . '%';
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->iLike('title', $qb->createNamedParameter($needle)),
				$qb->expr()->iLike('notes', $qb->createNamedParameter($needle))
			));
		}
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['count'] ?? 0);
	}

	/**
	 * First and last YYYY-MM that contain any non-deleted booking.
	 * Caller must only use this after the user is already authorized for the workspace.
	 *
	 * @return array{firstYearMonth: string|null, lastYearMonth: string|null}
	 */
	public function ledgerYearMonthBounds(int $workspaceId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->func()->min('booking_date'), 'min_d')
			->selectAlias($qb->func()->max('booking_date'), 'max_d')
			->from('bc_transactions')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'));
		$row = $qb->executeQuery()->fetch();
		if ($row === false || $row['min_d'] === null || $row['min_d'] === '') {
			return ['firstYearMonth' => null, 'lastYearMonth' => null];
		}
		$min = (string)$row['min_d'];
		$max = (string)$row['max_d'];

		return [
			'firstYearMonth' => substr($min, 0, 7),
			'lastYearMonth' => substr($max, 0, 7),
		];
	}

	/**
	 * Number of non-deleted rows whose booking_date falls in the calendar month {@see $yearMonth} (YYYY-MM).
	 */
	public function countBookingsInCalendarMonth(int $workspaceId, string $yearMonth): int
	{
		if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $yearMonth)) {
			return 0;
		}
		$start = $yearMonth . '-01';
		try {
			$end = (new \DateTimeImmutable($yearMonth . '-01', new \DateTimeZone('UTC')))
				->modify('last day of this month')
				->format('Y-m-d');
		} catch (\Throwable) {
			return 0;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'count'))
			->from('bc_transactions')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->andWhere($qb->expr()->gte('booking_date', $qb->createNamedParameter($start)))
			->andWhere($qb->expr()->lte('booking_date', $qb->createNamedParameter($end)));
		$row = $qb->executeQuery()->fetch();

		return (int)($row['count'] ?? 0);
	}

	/**
	 * Resolve the (from,to) bounds we will apply to a transaction list query.
	 * Project workspaces are clamped to their date window even if a wider range
	 * was requested; household workspaces default to current calendar year up to
	 * end of current month when the caller did not supply explicit bounds.
	 *
	 * @return array{0:?string, 1:?string}
	 */
	private function resolveDateWindow(array $workspace, array $filters): array
	{
		$from = isset($filters['from']) && $filters['from'] !== '' ? $this->parseIsoDate((string)$filters['from'], 'from')->format('Y-m-d') : null;
		$to = isset($filters['to']) && $filters['to'] !== '' ? $this->parseIsoDate((string)$filters['to'], 'to')->format('Y-m-d') : null;

		if (($workspace['type'] ?? null) === WorkspaceService::TYPE_PROJECT) {
			$ws_from = $workspace['projectStartDate'];
			$ws_to = $workspace['projectEndDate'];
			if ($from === null || $from < $ws_from) {
				$from = $ws_from;
			}
			if ($to === null || $to > $ws_to) {
				$to = $ws_to;
			}
			return [$from, $to];
		}

		if ($from === null && $to === null) {
			$tz = new \DateTimeZone($workspace['timezone'] ?? 'UTC');
			$now = $this->timeFactory->getDateTime('now', $tz);
			$year = (int)$now->format('Y');
			$month = (int)$now->format('m');
			$from = sprintf('%04d-01-01', $year);
			$to = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), $tz))
				->modify('last day of this month')
				->format('Y-m-d');
		}
		return [$from, $to];
	}

	public function create(int $workspaceId, string $userId, array $payload, array $workspace, array $category, ?array $bookingStatus = null): array
	{
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_CONTRIBUTOR);
		$direction = $this->normaliseDirection((string)($payload['direction'] ?? ''));
		$this->ensureCategoryMatchesDirection($category, $direction);
		$bookingDate = $this->parseIsoDate((string)($payload['bookingDate'] ?? ''), 'bookingDate');
		if (!$this->bookingDateInsideProjectWindow($workspace, $bookingDate)) {
			throw new \InvalidArgumentException('bookingDate must lie inside the project date window.');
		}
		$title = $this->normaliseTitle((string)($payload['title'] ?? ''));
		$notes = $this->normaliseNotes($payload['notes'] ?? null);
		$isSpecial = !empty($payload['isSpecial']) || ($category['isSpecial'] ?? false);
		$externalRef = $this->normaliseExternalRef($payload['externalRef'] ?? null);
		$bookingStatusId = $this->resolveBookingStatusId($workspace, $bookingStatus);

		$decimals = $this->money->decimalsFor($workspace['currencyCode']);
		$amount = $this->money->parseHumanAmount($payload['amount'] ?? ($payload['amountMinor'] ?? null), $decimals);
		$taxFields = $this->resolveTaxFields($payload, $workspace, $amount, $direction);

		$now = $this->utcNow();
		$qb = $this->db->getQueryBuilder();
		$qb->insert('bc_transactions')
			->values([
				'workspace_id' => $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT),
				'category_id' => $qb->createNamedParameter($category['id'], \PDO::PARAM_INT),
				'booking_date' => $qb->createNamedParameter($bookingDate->format('Y-m-d')),
				'amount_minor' => $qb->createNamedParameter($amount, \PDO::PARAM_INT),
				'direction' => $qb->createNamedParameter($direction),
				'entry_amount_basis' => $qb->createNamedParameter($taxFields['basis']),
				'net_amount_minor' => $qb->createNamedParameter($taxFields['net'], $taxFields['net'] === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT),
				'vat_rate_bp' => $qb->createNamedParameter($taxFields['vatRateBp'], $taxFields['vatRateBp'] === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT),
				'vat_amount_minor' => $qb->createNamedParameter($taxFields['vat'], $taxFields['vat'] === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT),
				'gross_amount_minor' => $qb->createNamedParameter($taxFields['gross'], $taxFields['gross'] === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT),
				'tax_calculation_locked' => $qb->createNamedParameter(false, \PDO::PARAM_BOOL),
				'title' => $qb->createNamedParameter($title),
				'notes' => $qb->createNamedParameter($notes),
				'is_special' => $qb->createNamedParameter($isSpecial, \PDO::PARAM_BOOL),
				'external_ref' => $qb->createNamedParameter($externalRef),
				'booking_status_id' => $qb->createNamedParameter($bookingStatusId, $bookingStatusId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT),
				'version' => $qb->createNamedParameter(1, \PDO::PARAM_INT),
				'created_by' => $qb->createNamedParameter($userId),
				'updated_by' => $qb->createNamedParameter($userId),
				'created_at' => $qb->createNamedParameter($now),
				'updated_at' => $qb->createNamedParameter($now),
				'deleted_at' => $qb->createNamedParameter(null, \PDO::PARAM_NULL),
			]);
		$qb->executeStatement();
		$id = (int)$this->db->lastInsertId('bc_transactions');
		$this->audit->record($userId, 'transaction_created', 'transaction', (string)$id, [
			'amountMinor' => $amount,
			'direction' => $direction,
			'date' => $bookingDate->format('Y-m-d'),
		], $workspaceId);
		return $this->loadHydrated($id, $workspace['currencyCode']);
	}

	public function update(int $transactionId, string $userId, array $payload, array $workspace, ?array $category = null, ?array $bookingStatus = null): array
	{
		$existing = $this->loadRow($transactionId);
		if ($existing === null || (int)$existing['workspace_id'] !== $workspace['id']) {
			throw new AccessDeniedException();
		}
		if ($existing['deleted_at'] !== null) {
			throw new \InvalidArgumentException('Transaction has been deleted.');
		}
		$this->access->ensureMinimumRole($workspace['id'], $userId, AccessControlService::ROLE_CONTRIBUTOR);

		// Optimistic locking.
		$expectedVersion = isset($payload['version']) ? (int)$payload['version'] : null;
		if ($expectedVersion !== null && $expectedVersion !== (int)$existing['version']) {
			throw new ConflictException();
		}

		$updates = [];
		$logChanges = [];

		$direction = isset($payload['direction']) ? $this->normaliseDirection((string)$payload['direction']) : (string)$existing['direction'];
		// Resolve category last so we can validate against the new direction.
		$categoryRow = $category;
		if (isset($payload['categoryId'])) {
			$categoryRow = ['id' => (int)$payload['categoryId']];
		}
		if ($categoryRow === null) {
			$categoryRow = ['id' => (int)$existing['category_id']];
		}
		// Real lookups require the caller to pass the resolved category. The
		// controller does this already.
		if (isset($category) && (int)$category['id'] !== (int)$existing['category_id']) {
			$this->ensureCategoryMatchesDirection($category, $direction);
			$updates['category_id'] = (int)$category['id'];
			$logChanges['categoryId'] = (int)$category['id'];
		} elseif (isset($category)) {
			$this->ensureCategoryMatchesDirection($category, $direction);
		}
		if ($direction !== (string)$existing['direction']) {
			$updates['direction'] = $direction;
			$logChanges['direction'] = $direction;
		}
		if (array_key_exists('bookingDate', $payload)) {
			$bookingDate = $this->parseIsoDate((string)$payload['bookingDate'], 'bookingDate');
			if (!$this->bookingDateInsideProjectWindow($workspace, $bookingDate)) {
				throw new \InvalidArgumentException('bookingDate must lie inside the project date window.');
			}
			if ($bookingDate->format('Y-m-d') !== (string)$existing['booking_date']) {
				$updates['booking_date'] = $bookingDate->format('Y-m-d');
				$logChanges['bookingDate'] = $updates['booking_date'];
			}
		}
		if (array_key_exists('title', $payload)) {
			$title = $this->normaliseTitle((string)$payload['title']);
			if ($title !== (string)$existing['title']) {
				$updates['title'] = $title;
				$logChanges['title'] = $title;
			}
		}
		if (array_key_exists('notes', $payload)) {
			$notes = $this->normaliseNotes($payload['notes']);
			if ($notes !== ($existing['notes'] ?? null)) {
				$updates['notes'] = $notes;
				$logChanges['notes'] = $notes !== null;
			}
		}
		if (array_key_exists('isSpecial', $payload)) {
			$isSpecial = (bool)$payload['isSpecial'];
			if ($isSpecial !== (bool)$existing['is_special']) {
				$updates['is_special'] = $isSpecial;
				$logChanges['isSpecial'] = $isSpecial;
			}
		}
		if (array_key_exists('externalRef', $payload)) {
			$externalRef = $this->normaliseExternalRef($payload['externalRef']);
			if ($externalRef !== ($existing['external_ref'] ?? null)) {
				$updates['external_ref'] = $externalRef;
				$logChanges['externalRef'] = $externalRef;
			}
		}
		if (array_key_exists('bookingStatusId', $payload)) {
			$statusId = $this->resolveBookingStatusId($workspace, $bookingStatus);
			$current = $existing['booking_status_id'] === null ? null : (int)$existing['booking_status_id'];
			if ($statusId !== $current) {
				$updates['booking_status_id'] = $statusId;
				$logChanges['bookingStatusId'] = $statusId;
			}
		}

		// Amount and tax: when either is touched, recompute the whole tax row to
		// keep `net + vat = gross` invariant.
		$amountChanged = array_key_exists('amount', $payload) || array_key_exists('amountMinor', $payload);
		$taxChanged = array_key_exists('entryAmountBasis', $payload)
			|| array_key_exists('vatRateBp', $payload);
		if ($amountChanged || $taxChanged) {
			$decimals = $this->money->decimalsFor($workspace['currencyCode']);
			$amount = $amountChanged
				? $this->money->parseHumanAmount($payload['amount'] ?? ($payload['amountMinor'] ?? null), $decimals)
				: (int)$existing['amount_minor'];
			$basisInput = (string)($payload['entryAmountBasis'] ?? $existing['entry_amount_basis']);
			$rateInput = $payload['vatRateBp'] ?? $existing['vat_rate_bp'];
			$tax = $this->resolveTaxFields([
				'amountMinor' => $amount,
				'entryAmountBasis' => $basisInput,
				'vatRateBp' => $rateInput,
			], $workspace, $amount, $direction);
			$updates['amount_minor'] = $amount;
			$updates['entry_amount_basis'] = $tax['basis'];
			$updates['net_amount_minor'] = $tax['net'];
			$updates['vat_rate_bp'] = $tax['vatRateBp'];
			$updates['vat_amount_minor'] = $tax['vat'];
			$updates['gross_amount_minor'] = $tax['gross'];
			$logChanges['amountMinor'] = $amount;
			$logChanges['basis'] = $tax['basis'];
		}

		if ($updates === []) {
			return $this->loadHydrated($transactionId, $workspace['currencyCode']);
		}

		$updates['version'] = (int)$existing['version'] + 1;
		$updates['updated_by'] = $userId;
		$updates['updated_at'] = $this->utcNow();

		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_transactions');
		foreach ($updates as $col => $value) {
			$type = match (true) {
				is_bool($value) => \PDO::PARAM_BOOL,
				is_int($value) => \PDO::PARAM_INT,
				$value === null => \PDO::PARAM_NULL,
				default => \PDO::PARAM_STR,
			};
			$qb->set($col, $qb->createNamedParameter($value, $type));
		}
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($transactionId, \PDO::PARAM_INT)));
		$qb->andWhere($qb->expr()->eq('version', $qb->createNamedParameter((int)$existing['version'], \PDO::PARAM_INT)));
		$affected = $qb->executeStatement();
		if ($affected === 0) {
			throw new ConflictException();
		}
		$this->audit->record($userId, 'transaction_updated', 'transaction', (string)$transactionId, $logChanges, $workspace['id']);
		return $this->loadHydrated($transactionId, $workspace['currencyCode']);
	}

	public function delete(int $transactionId, string $userId, array $workspace): bool
	{
		$existing = $this->loadRow($transactionId);
		if ($existing === null || (int)$existing['workspace_id'] !== $workspace['id']) {
			throw new AccessDeniedException();
		}
		$this->access->ensureMinimumRole($workspace['id'], $userId, AccessControlService::ROLE_CONTRIBUTOR);
		if ($existing['deleted_at'] !== null) {
			return true;
		}
		// Block deletes inside a closed month — the user must reopen first.
		$ym = substr((string)$existing['booking_date'], 0, 7);
		if ($this->monthIsClosed($workspace['id'], $ym)) {
			throw new \InvalidArgumentException('This transaction belongs to a closed month. Reopen the month before editing.');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_transactions')
			->set('deleted_at', $qb->createNamedParameter($this->utcNow()))
			->set('updated_by', $qb->createNamedParameter($userId))
			->set('updated_at', $qb->createNamedParameter($this->utcNow()))
			->set('version', $qb->createNamedParameter((int)$existing['version'] + 1, \PDO::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($transactionId, \PDO::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->record($userId, 'transaction_deleted', 'transaction', (string)$transactionId, [], $workspace['id']);
		return true;
	}

	public function loadForWorkspace(int $transactionId, int $workspaceId): ?array
	{
		$row = $this->loadRow($transactionId);
		if ($row === null || (int)$row['workspace_id'] !== $workspaceId) {
			return null;
		}
		return $row;
	}

	/**
	 * Return the workspace that owns the given transaction, or null when the
	 * row does not exist. Performs no membership check; the caller must do
	 * that against the returned id (typically via {@see WorkspaceService::getForUser()}).
	 */
	public function ownerWorkspaceId(int $transactionId): ?int
	{
		$row = $this->loadRow($transactionId);
		return $row === null ? null : (int)$row['workspace_id'];
	}

	public function loadHydrated(int $transactionId, string $currencyCode): array
	{
		$row = $this->loadRow($transactionId);
		if ($row === null) {
			throw new InternalErrorException();
		}
		return $this->hydrate($row, $currencyCode);
	}

	private function loadRow(int $transactionId): ?array
	{
		if ($transactionId < 1) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('bc_transactions')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($transactionId, \PDO::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? null : $row;
	}

	private function hydrate(array $row, string $currencyCode): array
	{
		return [
			'id' => (int)$row['id'],
			'workspaceId' => (int)$row['workspace_id'],
			'categoryId' => (int)$row['category_id'],
			'bookingDate' => (string)$row['booking_date'],
			'amount' => $this->money->envelope((int)$row['amount_minor'], $currencyCode),
			'direction' => (string)$row['direction'],
			'entryAmountBasis' => (string)$row['entry_amount_basis'],
			'net' => $row['net_amount_minor'] === null ? null : $this->money->envelope((int)$row['net_amount_minor'], $currencyCode),
			'vatRateBp' => $row['vat_rate_bp'] === null ? null : (int)$row['vat_rate_bp'],
			'vat' => $row['vat_amount_minor'] === null ? null : $this->money->envelope((int)$row['vat_amount_minor'], $currencyCode),
			'gross' => $row['gross_amount_minor'] === null ? null : $this->money->envelope((int)$row['gross_amount_minor'], $currencyCode),
			'taxCalculationLocked' => (bool)$row['tax_calculation_locked'],
			'title' => (string)$row['title'],
			'notes' => $row['notes'] !== null ? (string)$row['notes'] : null,
			'isSpecial' => (bool)$row['is_special'],
			'externalRef' => $row['external_ref'] !== null ? (string)$row['external_ref'] : null,
			'bookingStatusId' => $row['booking_status_id'] === null ? null : (int)$row['booking_status_id'],
			'version' => (int)$row['version'],
			'createdBy' => (string)$row['created_by'],
			'updatedBy' => (string)$row['updated_by'],
			'createdAt' => (string)$row['created_at'],
			'updatedAt' => (string)$row['updated_at'],
			'deletedAt' => $row['deleted_at'] !== null ? (string)$row['deleted_at'] : null,
		];
	}

	private function bookingDateInsideProjectWindow(array $workspace, \DateTimeImmutable $date): bool
	{
		if (($workspace['type'] ?? null) !== WorkspaceService::TYPE_PROJECT) {
			return true;
		}
		if ($workspace['projectStartDate'] !== null) {
			$start = new \DateTimeImmutable($workspace['projectStartDate']);
			if ($date < $start) {
				return false;
			}
		}
		if ($workspace['projectEndDate'] !== null) {
			$end = new \DateTimeImmutable($workspace['projectEndDate']);
			if ($date > $end) {
				return false;
			}
		}
		return true;
	}

	private function ensureCategoryMatchesDirection(array $category, string $direction): void
	{
		if (($category['type'] ?? '') !== $direction) {
			throw new \InvalidArgumentException('Category direction does not match transaction direction.');
		}
		if (!($category['isActive'] ?? true)) {
			throw new \InvalidArgumentException('Category is deactivated.');
		}
	}

	private function resolveBookingStatusId(array $workspace, ?array $bookingStatus): ?int
	{
		if ($bookingStatus === null) {
			return null;
		}
		if (($workspace['type'] ?? null) !== WorkspaceService::TYPE_PROJECT) {
			throw new \InvalidArgumentException('Booking statuses are available only in project workspaces.');
		}
		return (int)$bookingStatus['id'];
	}

	/**
	 * @return array{basis:string, net:?int, vat:?int, gross:?int, vatRateBp:?int}
	 */
	private function resolveTaxFields(array $payload, array $workspace, int $amount, string $direction): array
	{
		$taxModeEnabled = (bool)($workspace['taxModeEnabled'] ?? false);
		$basis = strtolower((string)($payload['entryAmountBasis'] ?? self::BASIS_SIMPLE));
		if (!in_array($basis, self::BASES, true)) {
			throw new \InvalidArgumentException('entryAmountBasis must be simple, net, or gross.');
		}

		// Reject tax payload on a non-tax workspace (§13.7).
		if (!$taxModeEnabled) {
			$forbiddenSet = false;
			foreach (['vatRateBp', 'vatAmountMinor', 'netAmountMinor', 'grossAmountMinor'] as $forbidden) {
				if (isset($payload[$forbidden]) && $payload[$forbidden] !== '' && $payload[$forbidden] !== null) {
					$forbiddenSet = true;
				}
			}
			if ($basis !== self::BASIS_SIMPLE) {
				$forbiddenSet = true;
			}
			if ($forbiddenSet) {
				throw new \InvalidArgumentException('Tax fields are not accepted because tax mode is disabled for this workspace.');
			}
			return ['basis' => self::BASIS_SIMPLE, 'net' => null, 'vat' => null, 'gross' => null, 'vatRateBp' => null];
		}

		// Tax mode enabled.
		if ($basis === self::BASIS_SIMPLE) {
			return ['basis' => self::BASIS_SIMPLE, 'net' => null, 'vat' => null, 'gross' => null, 'vatRateBp' => null];
		}

		$rateRaw = $payload['vatRateBp'] ?? ($workspace['defaultVatRateBp'] ?? null);
		if ($rateRaw === '' || $rateRaw === null) {
			throw new \InvalidArgumentException('vatRateBp is required when entryAmountBasis is net or gross.');
		}
		if (!is_int($rateRaw) && !ctype_digit((string)$rateRaw)) {
			throw new \InvalidArgumentException('vatRateBp must be an integer (basis points).');
		}
		$rate = (int)$rateRaw;
		$converted = $this->money->convertTax($amount, $rate, $basis);
		return [
			'basis' => $basis,
			'net' => $converted['net'],
			'vat' => $converted['vat'],
			'gross' => $converted['gross'],
			'vatRateBp' => $rate,
		];
	}

	private function monthIsClosed(int $workspaceId, string $yearMonth): bool
	{
		if (!preg_match('/^\d{4}-\d{2}$/', $yearMonth)) {
			return false;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'count'))
			->from('bc_monthly_snapshots')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('year_month', $qb->createNamedParameter($yearMonth)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['count'] ?? 0) > 0;
	}

	private function normaliseDirection(string $direction): string
	{
		$direction = strtolower(trim($direction));
		if (!in_array($direction, self::DIRECTIONS, true)) {
			throw new \InvalidArgumentException('direction must be income or expense.');
		}
		return $direction;
	}

	private function normaliseTitle(string $title): string
	{
		$title = trim($title);
		if ($title === '') {
			throw new \InvalidArgumentException('title is required.');
		}
		if (mb_strlen($title) > 180) {
			throw new \InvalidArgumentException('title must be 180 characters or fewer.');
		}
		return $title;
	}

	private function normaliseNotes(mixed $notes): ?string
	{
		if ($notes === null) {
			return null;
		}
		$notes = trim((string)$notes);
		if ($notes === '') {
			return null;
		}
		if (mb_strlen($notes) > 4000) {
			throw new \InvalidArgumentException('notes must be 4000 characters or fewer.');
		}
		return $notes;
	}

	private function normaliseExternalRef(mixed $value): ?string
	{
		if ($value === null) {
			return null;
		}
		$value = trim((string)$value);
		if ($value === '') {
			return null;
		}
		if (mb_strlen($value) > 128) {
			throw new \InvalidArgumentException('externalRef must be 128 characters or fewer.');
		}
		return $value;
	}

	private function parseIsoDate(string $value, string $field): \DateTimeImmutable
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
