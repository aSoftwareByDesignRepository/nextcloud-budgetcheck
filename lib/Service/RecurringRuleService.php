<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

use OCA\BudgetCheck\Exception\AccessDeniedException;
use OCA\BudgetCheck\Exception\InternalErrorException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Recurring transaction templates ("rules"). The product never auto-posts
 * transactions; rules only suggest entries to the planner.
 *
 * The {@see preview()} method projects all dues between the rule's
 * `next_due_date` and the requested upper bound (capped at 36 occurrences to
 * keep responses bounded). Calling {@see generate()} materialises one
 * transaction in the ledger and advances `next_due_date`.
 */
class RecurringRuleService
{
	public const FREQ_MONTHLY = 'monthly';
	public const FREQ_QUARTERLY = 'quarterly';
	public const FREQ_YEARLY = 'yearly';
	public const FREQ_CUSTOM = 'custom_interval';
	private const FREQUENCIES = [self::FREQ_MONTHLY, self::FREQ_QUARTERLY, self::FREQ_YEARLY, self::FREQ_CUSTOM];

	private const MAX_PREVIEW = 36;
	private const MAX_GENERATE_BATCH = 600;

	public function __construct(
		private IDBConnection $db,
		private AccessControlService $access,
		private MoneyService $money,
		private ITimeFactory $timeFactory,
		private AuditLogService $audit,
	) {
	}

	public function listForWorkspace(int $workspaceId, string $userId, string $currencyCode): array
	{
		$this->access->ensureMembership($workspaceId, $userId);
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('bc_recurring_rules')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->orderBy('is_active', 'DESC')
			->addOrderBy('next_due_date', 'ASC')
			->addOrderBy('id', 'DESC');
		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$out[] = $this->hydrate($row, $currencyCode);
		}
		$result->closeCursor();
		return $out;
	}

	public function create(int $workspaceId, string $userId, array $payload, array $workspace, array $category): array
	{
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_MANAGER);
		$direction = strtolower(trim((string)($payload['direction'] ?? '')));
		if (!in_array($direction, [TransactionService::DIRECTION_INCOME, TransactionService::DIRECTION_EXPENSE], true)) {
			throw new \InvalidArgumentException('direction must be income or expense.');
		}
		if ($category['type'] !== $direction) {
			throw new \InvalidArgumentException('Category direction does not match rule direction.');
		}
		$title = trim((string)($payload['title'] ?? ''));
		if ($title === '' || mb_strlen($title) > 180) {
			throw new \InvalidArgumentException('title must be 1-180 characters.');
		}
		$decimals = $this->money->decimalsFor($workspace['currencyCode']);
		$amount = $this->money->parseHumanAmount($payload['amount'] ?? ($payload['amountMinor'] ?? null), $decimals);
		$frequency = strtolower(trim((string)($payload['frequency'] ?? '')));
		if (!in_array($frequency, self::FREQUENCIES, true)) {
			throw new \InvalidArgumentException('frequency must be one of: ' . implode(', ', self::FREQUENCIES) . '.');
		}
		$intervalCount = (int)($payload['intervalCount'] ?? 1);
		if ($intervalCount < 1 || $intervalCount > 36) {
			throw new \InvalidArgumentException('intervalCount must be between 1 and 36.');
		}
		$startDate = $this->parseIsoDate((string)($payload['startDate'] ?? ''), 'startDate');
		$endRaw = (string)($payload['endDate'] ?? '');
		$endDate = $endRaw !== '' ? $this->parseIsoDate($endRaw, 'endDate') : null;
		if ($endDate !== null && $endDate < $startDate) {
			throw new \InvalidArgumentException('endDate must not be before startDate.');
		}
		$nextDue = $startDate;

		$now = $this->utcNow();
		$qb = $this->db->getQueryBuilder();
		$qb->insert('bc_recurring_rules')
			->values([
				'workspace_id' => $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT),
				'category_id' => $qb->createNamedParameter($category['id'], \PDO::PARAM_INT),
				'direction' => $qb->createNamedParameter($direction),
				'title' => $qb->createNamedParameter($title),
				'amount_minor' => $qb->createNamedParameter($amount, \PDO::PARAM_INT),
				'frequency' => $qb->createNamedParameter($frequency),
				'interval_count' => $qb->createNamedParameter($intervalCount, \PDO::PARAM_INT),
				'start_date' => $qb->createNamedParameter($startDate->format('Y-m-d')),
				'end_date' => $qb->createNamedParameter($endDate?->format('Y-m-d')),
				'next_due_date' => $qb->createNamedParameter($nextDue->format('Y-m-d')),
				'is_active' => $qb->createNamedParameter(true, \PDO::PARAM_BOOL),
				'created_by' => $qb->createNamedParameter($userId),
				'created_at' => $qb->createNamedParameter($now),
				'updated_at' => $qb->createNamedParameter($now),
			]);
		$qb->executeStatement();
		$id = (int)$this->db->lastInsertId('bc_recurring_rules');
		$this->audit->record($userId, 'recurring_rule_created', 'recurring_rule', (string)$id, [
			'frequency' => $frequency,
			'amountMinor' => $amount,
		], $workspaceId);
		return $this->loadHydrated($id, $workspace['currencyCode']);
	}

	public function update(int $ruleId, string $userId, array $payload, array $workspace, ?array $category = null): array
	{
		$row = $this->loadRow($ruleId);
		if ($row === null || (int)$row['workspace_id'] !== (int)$workspace['id']) {
			throw new AccessDeniedException();
		}
		$this->access->ensureMinimumRole((int)$workspace['id'], $userId, AccessControlService::ROLE_MANAGER);

		$updates = [];
		if (array_key_exists('title', $payload)) {
			$title = trim((string)$payload['title']);
			if ($title === '' || mb_strlen($title) > 180) {
				throw new \InvalidArgumentException('title must be 1-180 characters.');
			}
			$updates['title'] = $title;
		}
		if (array_key_exists('amount', $payload) || array_key_exists('amountMinor', $payload)) {
			$decimals = $this->money->decimalsFor($workspace['currencyCode']);
			$amount = $this->money->parseHumanAmount($payload['amount'] ?? $payload['amountMinor'], $decimals);
			$updates['amount_minor'] = $amount;
		}
		if (array_key_exists('frequency', $payload)) {
			$frequency = strtolower(trim((string)$payload['frequency']));
			if (!in_array($frequency, self::FREQUENCIES, true)) {
				throw new \InvalidArgumentException('frequency must be one of: ' . implode(', ', self::FREQUENCIES) . '.');
			}
			$updates['frequency'] = $frequency;
		}
		if (array_key_exists('intervalCount', $payload)) {
			$intervalCount = (int)$payload['intervalCount'];
			if ($intervalCount < 1 || $intervalCount > 36) {
				throw new \InvalidArgumentException('intervalCount must be between 1 and 36.');
			}
			$updates['interval_count'] = $intervalCount;
		}
		if (array_key_exists('startDate', $payload)) {
			$updates['start_date'] = $this->parseIsoDate((string)$payload['startDate'], 'startDate')->format('Y-m-d');
		}
		if (array_key_exists('endDate', $payload)) {
			$endRaw = (string)$payload['endDate'];
			$updates['end_date'] = $endRaw === '' ? null : $this->parseIsoDate($endRaw, 'endDate')->format('Y-m-d');
		}
		if (array_key_exists('nextDueDate', $payload)) {
			$updates['next_due_date'] = $this->parseIsoDate((string)$payload['nextDueDate'], 'nextDueDate')->format('Y-m-d');
		}
		if (array_key_exists('isActive', $payload)) {
			$updates['is_active'] = (bool)$payload['isActive'];
		}
		if ($category !== null && (int)$category['id'] !== (int)$row['category_id']) {
			$direction = $updates['direction'] ?? (string)$row['direction'];
			if (($category['type'] ?? '') !== $direction) {
				throw new \InvalidArgumentException('Category direction does not match rule direction.');
			}
			$updates['category_id'] = (int)$category['id'];
		}
		if ($updates === []) {
			return $this->loadHydrated($ruleId, $workspace['currencyCode']);
		}
		$effectiveStart = (string)($updates['start_date'] ?? $row['start_date']);
		$effectiveEnd = array_key_exists('end_date', $updates) ? $updates['end_date'] : $row['end_date'];
		$effectiveNextDue = (string)($updates['next_due_date'] ?? $row['next_due_date']);
		if ($effectiveEnd !== null && $effectiveEnd < $effectiveStart) {
			throw new \InvalidArgumentException('endDate must not be before startDate.');
		}
		if ($effectiveNextDue < $effectiveStart) {
			throw new \InvalidArgumentException('nextDueDate must not be before startDate.');
		}
		if ($effectiveEnd !== null && $effectiveNextDue > $effectiveEnd) {
			throw new \InvalidArgumentException('nextDueDate must not be after endDate.');
		}
		$updates['updated_at'] = $this->utcNow();
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_recurring_rules');
		foreach ($updates as $col => $value) {
			$type = match (true) {
				is_bool($value) => \PDO::PARAM_BOOL,
				is_int($value) => \PDO::PARAM_INT,
				$value === null => \PDO::PARAM_NULL,
				default => \PDO::PARAM_STR,
			};
			$qb->set($col, $qb->createNamedParameter($value, $type));
		}
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($ruleId, \PDO::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->record($userId, 'recurring_rule_updated', 'recurring_rule', (string)$ruleId, $updates, (int)$workspace['id']);
		return $this->loadHydrated($ruleId, $workspace['currencyCode']);
	}

	public function delete(int $ruleId, string $userId, array $workspace): bool
	{
		$row = $this->loadRow($ruleId);
		if ($row === null || (int)$row['workspace_id'] !== (int)$workspace['id']) {
			throw new AccessDeniedException();
		}
		$this->access->ensureMinimumRole((int)$workspace['id'], $userId, AccessControlService::ROLE_MANAGER);
		$qb = $this->db->getQueryBuilder();
		$qb->delete('bc_recurring_rules')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($ruleId, \PDO::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->record($userId, 'recurring_rule_deleted', 'recurring_rule', (string)$ruleId, [], (int)$workspace['id']);
		return true;
	}

	/**
	 * Project upcoming dues for the rule, capped at MAX_PREVIEW occurrences.
	 *
	 * @return array{rule:array<string,mixed>, occurrences: list<array{date:string, amount:array<string,mixed>}>}
	 */
	public function preview(int $ruleId, string $userId, ?string $through, array $workspace): array
	{
		$row = $this->loadRow($ruleId);
		if ($row === null || (int)$row['workspace_id'] !== (int)$workspace['id']) {
			throw new AccessDeniedException();
		}
		$this->access->ensureMembership((int)$workspace['id'], $userId);
		$end = $through !== null && $through !== ''
			? $this->parseIsoDate($through, 'through')
			: (new \DateTimeImmutable($row['next_due_date']))->modify('+12 months');
		$ruleEnd = $row['end_date'] !== null ? new \DateTimeImmutable($row['end_date']) : null;
		if ($ruleEnd !== null && $end > $ruleEnd) {
			$end = $ruleEnd;
		}
		$occurrences = [];
		$next = new \DateTimeImmutable((string)$row['next_due_date']);
		$count = 0;
		while ($next <= $end && $count < self::MAX_PREVIEW) {
			$occurrences[] = [
				'date' => $next->format('Y-m-d'),
				'amount' => $this->money->envelope((int)$row['amount_minor'], $workspace['currencyCode']),
			];
			$next = $this->advance($next, (string)$row['frequency'], (int)$row['interval_count']);
			$count++;
		}
		return [
			'rule' => $this->hydrate($row, $workspace['currencyCode']),
			'occurrences' => $occurrences,
		];
	}

	/**
	 * Materialise the **next due** occurrence as a real transaction and advance
	 * `next_due_date`. Returns the newly created transaction id.
	 */
	public function generate(int $ruleId, string $userId, array $workspace, TransactionService $tx, array $category, array $options = []): array
	{
		$row = $this->loadRow($ruleId);
		if ($row === null || (int)$row['workspace_id'] !== (int)$workspace['id']) {
			throw new AccessDeniedException();
		}
		$this->access->ensureMinimumRole((int)$workspace['id'], $userId, AccessControlService::ROLE_CONTRIBUTOR);
		if (!(bool)$row['is_active']) {
			throw new \InvalidArgumentException('Rule is inactive.');
		}
		$next = new \DateTimeImmutable((string)$row['next_due_date']);
		$ruleEnd = $row['end_date'] !== null ? new \DateTimeImmutable($row['end_date']) : null;
		if ($ruleEnd !== null && $next > $ruleEnd) {
			throw new \InvalidArgumentException('Rule has reached its end date.');
		}

		$throughRaw = trim((string)($options['through'] ?? ''));
		$through = $throughRaw !== '' ? $this->parseIsoDate($throughRaw, 'through') : null;
		if ($through !== null && $through < $next) {
			throw new \InvalidArgumentException('through must be on or after next due date.');
		}
		if ($ruleEnd !== null && $through !== null && $through > $ruleEnd) {
			$through = $ruleEnd;
		}

		$createdIds = [];
		$firstCreated = null;
		$generatedCount = 0;
		$generatedFrom = null;
		$generatedTo = null;
		$finalNext = $next;
		$loopEnd = $through ?? $next;
		while ($finalNext <= $loopEnd) {
			if ($generatedCount >= self::MAX_GENERATE_BATCH) {
				throw new \InvalidArgumentException('Too many occurrences in one generate call. Shorten the period and try again.');
			}
			$created = $tx->create((int)$workspace['id'], $userId, [
				'categoryId' => (int)$row['category_id'],
				'direction' => (string)$row['direction'],
				'bookingDate' => $finalNext->format('Y-m-d'),
				'amountMinor' => (int)$row['amount_minor'],
				'title' => (string)$row['title'],
				'isSpecial' => false,
			], $workspace, $category);
			$createdIds[] = (int)$created['id'];
			$firstCreated ??= $created;
			$generatedCount++;
			$generatedFrom ??= $finalNext;
			$generatedTo = $finalNext;
			$finalNext = $this->advance($finalNext, (string)$row['frequency'], (int)$row['interval_count']);
			if ($ruleEnd !== null && $finalNext > $ruleEnd) {
				break;
			}
		}

		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_recurring_rules')
			->set('next_due_date', $qb->createNamedParameter($finalNext->format('Y-m-d')))
			->set('updated_at', $qb->createNamedParameter($this->utcNow()))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($ruleId, \PDO::PARAM_INT)));
		$qb->executeStatement();

		$details = [
			'count' => $generatedCount,
			'transactionIds' => $createdIds,
			'nextDueDate' => $finalNext->format('Y-m-d'),
		];
		if ($generatedFrom !== null) {
			$details['fromDate'] = $generatedFrom->format('Y-m-d');
		}
		if ($generatedTo !== null) {
			$details['toDate'] = $generatedTo->format('Y-m-d');
		}
		$this->audit->record($userId, 'recurring_rule_generated', 'recurring_rule', (string)$ruleId, $details, (int)$workspace['id']);

		if ($through === null) {
			return $firstCreated ?? ['id' => $createdIds[0]];
		}

		return $details;
	}

	private function advance(\DateTimeImmutable $current, string $frequency, int $interval): \DateTimeImmutable
	{
		// We compute the next date arithmetically and snap it to the same
		// day-of-month when possible to avoid drift across months with fewer days
		// (e.g. Jan 31 -> Feb 28). PHP's "+1 month" already does the right thing
		// for our purposes when start date is <= 28; we clamp manually for >=29.
		$months = match ($frequency) {
			self::FREQ_MONTHLY => $interval,
			self::FREQ_QUARTERLY => $interval * 3,
			self::FREQ_YEARLY => $interval * 12,
			self::FREQ_CUSTOM => $interval,
			default => $interval,
		};
		$year = (int)$current->format('Y');
		$month = (int)$current->format('n');
		$day = (int)$current->format('j');
		$totalMonths = ($year * 12) + ($month - 1) + $months;
		$newYear = intdiv($totalMonths, 12);
		$newMonth = ($totalMonths % 12) + 1;
		$daysInNewMonth = (int)(new \DateTimeImmutable(sprintf('%04d-%02d-01', $newYear, $newMonth)))
			->modify('last day of this month')->format('j');
		$newDay = min($day, $daysInNewMonth);
		return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $newYear, $newMonth, $newDay));
	}

	public function loadHydrated(int $ruleId, string $currencyCode): array
	{
		$row = $this->loadRow($ruleId);
		if ($row === null) {
			throw new InternalErrorException();
		}
		return $this->hydrate($row, $currencyCode);
	}

	/**
	 * Return the workspace that owns the given rule, or null when the row does
	 * not exist. Used by controllers to derive the workspace before role checks.
	 */
	public function ownerWorkspaceId(int $ruleId): ?int
	{
		$row = $this->loadRow($ruleId);
		return $row === null ? null : (int)$row['workspace_id'];
	}

	private function loadRow(int $ruleId): ?array
	{
		if ($ruleId < 1) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('bc_recurring_rules')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($ruleId, \PDO::PARAM_INT)));
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
			'direction' => (string)$row['direction'],
			'title' => (string)$row['title'],
			'amount' => $this->money->envelope((int)$row['amount_minor'], $currencyCode),
			'frequency' => (string)$row['frequency'],
			'intervalCount' => (int)$row['interval_count'],
			'startDate' => (string)$row['start_date'],
			'endDate' => $row['end_date'] !== null ? (string)$row['end_date'] : null,
			'nextDueDate' => (string)$row['next_due_date'],
			'isActive' => (bool)$row['is_active'],
			'createdBy' => (string)$row['created_by'],
			'createdAt' => (string)$row['created_at'],
			'updatedAt' => (string)$row['updated_at'],
		];
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
