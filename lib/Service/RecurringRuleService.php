<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

use OCA\BudgetCheck\Exception\AccessDeniedException;
use OCA\BudgetCheck\Exception\InternalErrorException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Recurring transaction templates ("rules").
 *
 * Each rule has a **posting mode** (issue #18):
 *  - {@see POSTING_BOOK} — Generate / the due job create **real** ledger rows
 *    (subscriptions, salary, rent). Cursor advances automatically when due.
 *  - {@see POSTING_PLAN} — Generate creates **planned** placeholders; a matching
 *    bank import or manual entry removes the plan (legacy import workflow).
 *
 * Two schedule models exist (issue #11):
 *  - interval frequencies (monthly, quarterly, yearly, custom months) advance
 *    `next_due_date` arithmetically with day-of-month clamping;
 *  - `schedule` rules carry an explicit, sorted list of occurrence dates in
 *    `schedule_json`, each optionally with its own amount (falling back to the
 *    rule's default `amount_minor`). For these rules `start_date`, `end_date`
 *    and `next_due_date` are always derived from the list, so every downstream
 *    consumer (generate, full-period, planned matching, month close) behaves
 *    exactly as it does for interval rules.
 *
 * The {@see preview()} method projects all dues between the rule's
 * `next_due_date` and the requested upper bound (capped at 36 occurrences to
 * keep responses bounded). Calling {@see generate()} materialises occurrence(s)
 * in the ledger and advances `next_due_date`.
 */
class RecurringRuleService
{
	public const FREQ_MONTHLY = 'monthly';
	public const FREQ_QUARTERLY = 'quarterly';
	public const FREQ_YEARLY = 'yearly';
	public const FREQ_CUSTOM = 'custom_interval';
	public const FREQ_SCHEDULE = 'schedule';
	private const FREQUENCIES = [self::FREQ_MONTHLY, self::FREQ_QUARTERLY, self::FREQ_YEARLY, self::FREQ_CUSTOM, self::FREQ_SCHEDULE];

	/** Create real ledger transactions (default for new rules). */
	public const POSTING_BOOK = 'book';
	/** Create planned placeholders for bank-import matching (legacy). */
	public const POSTING_PLAN = 'plan';
	private const POSTING_MODES = [self::POSTING_BOOK, self::POSTING_PLAN];

	public const MAX_SCHEDULE_ENTRIES = 60;

	private const MAX_PREVIEW = 36;
	private const MAX_GENERATE_BATCH = 600;
	/** Safety cap for one background-job sweep across all workspaces. */
	private const MAX_DUE_RULES_PER_SWEEP = 200;

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
		$schedule = null;
		if ($frequency === self::FREQ_SCHEDULE) {
			$schedule = $this->normaliseSchedulePayload($payload['schedule'] ?? null, $decimals);
			$intervalCount = 1;
			$startDate = new \DateTimeImmutable($schedule[0]['date'], new \DateTimeZone('UTC'));
			$endDate = new \DateTimeImmutable($schedule[count($schedule) - 1]['date'], new \DateTimeZone('UTC'));
		} else {
			if (isset($payload['schedule']) && $payload['schedule'] !== [] && $payload['schedule'] !== null) {
				throw new \InvalidArgumentException('schedule is only allowed when frequency is schedule.');
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
		}
		$nextDue = $startDate;
		$postingMode = $this->normalisePostingMode($payload['postingMode'] ?? $payload['posting_mode'] ?? self::POSTING_BOOK);

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
				'schedule_json' => $qb->createNamedParameter($schedule !== null ? self::encodeSchedule($schedule) : null),
				'posting_mode' => $qb->createNamedParameter($postingMode),
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
			'postingMode' => $postingMode,
			'scheduleCount' => $schedule !== null ? count($schedule) : null,
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
		$decimals = $this->money->decimalsFor($workspace['currencyCode']);
		if (array_key_exists('amount', $payload) || array_key_exists('amountMinor', $payload)) {
			$amount = $this->money->parseHumanAmount($payload['amount'] ?? $payload['amountMinor'], $decimals);
			$updates['amount_minor'] = $amount;
		}
		$currentFrequency = (string)$row['frequency'];
		$newFrequency = $currentFrequency;
		if (array_key_exists('frequency', $payload)) {
			$newFrequency = strtolower(trim((string)$payload['frequency']));
			if (!in_array($newFrequency, self::FREQUENCIES, true)) {
				throw new \InvalidArgumentException('frequency must be one of: ' . implode(', ', self::FREQUENCIES) . '.');
			}
			if ($newFrequency !== $currentFrequency) {
				$updates['frequency'] = $newFrequency;
			}
		}
		$schedulePayloadProvided = array_key_exists('schedule', $payload)
			&& $payload['schedule'] !== null;
		if ($newFrequency === self::FREQ_SCHEDULE) {
			// start/end/next_due are derived from the schedule; direct edits would
			// silently desynchronise the rule from its occurrence list.
			if (array_key_exists('startDate', $payload) || array_key_exists('endDate', $payload)) {
				throw new \InvalidArgumentException('startDate and endDate are derived from the schedule for schedule rules.');
			}
			if ($schedulePayloadProvided) {
				$schedule = $this->normaliseSchedulePayload($payload['schedule'], $decimals);
			} elseif ($currentFrequency === self::FREQ_SCHEDULE) {
				$schedule = self::decodeSchedule($row['schedule_json'] ?? null);
				if ($schedule === []) {
					throw new InternalErrorException();
				}
			} else {
				throw new \InvalidArgumentException('schedule is required when switching to schedule frequency.');
			}
			if ($schedulePayloadProvided) {
				$updates['schedule_json'] = self::encodeSchedule($schedule);
				$updates['start_date'] = $schedule[0]['date'];
				$updates['end_date'] = $schedule[count($schedule) - 1]['date'];
			}
			if ($currentFrequency !== self::FREQ_SCHEDULE || ((int)$row['interval_count']) !== 1) {
				$updates['interval_count'] = 1;
			}
			if (array_key_exists('nextDueDate', $payload)) {
				// Realign: snap to the earliest scheduled date on or after the
				// requested date so next_due_date always points at a real occurrence.
				$requested = $this->parseIsoDate((string)$payload['nextDueDate'], 'nextDueDate')->format('Y-m-d');
				$snapped = self::nextScheduleDateOnOrAfter($schedule, $requested);
				if ($snapped === null) {
					throw new \InvalidArgumentException('No scheduled date on or after the realign date.');
				}
				$updates['next_due_date'] = $snapped;
			} elseif ($schedulePayloadProvided || $currentFrequency !== self::FREQ_SCHEDULE) {
				// Schedule changed without an explicit realign: preserve progress.
				// Dates before the previous next-due stay consumed; newly added later
				// dates become reachable. An exhausted schedule deactivates the rule.
				$snapped = self::nextScheduleDateOnOrAfter($schedule, (string)$row['next_due_date']);
				if ($snapped !== null) {
					$updates['next_due_date'] = $snapped;
				} else {
					$last = $schedule[count($schedule) - 1]['date'];
					$updates['next_due_date'] = (new \DateTimeImmutable($last, new \DateTimeZone('UTC')))
						->modify('+1 day')->format('Y-m-d');
					if (!array_key_exists('isActive', $payload)) {
						$updates['is_active'] = false;
					}
				}
			}
		} else {
			if ($schedulePayloadProvided) {
				throw new \InvalidArgumentException('schedule is only allowed when frequency is schedule.');
			}
			if ($currentFrequency === self::FREQ_SCHEDULE && $newFrequency !== self::FREQ_SCHEDULE) {
				$updates['schedule_json'] = null;
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
		}
		if (array_key_exists('isActive', $payload)) {
			$updates['is_active'] = (bool)$payload['isActive'];
		}
		if (array_key_exists('postingMode', $payload) || array_key_exists('posting_mode', $payload)) {
			$updates['posting_mode'] = $this->normalisePostingMode($payload['postingMode'] ?? $payload['posting_mode']);
		}
		// Reactivating a completed schedule rule leaves next_due_date one day past
		// end_date, which blocks Generate until realigned. Rewind to the last
		// scheduled occurrence so Generate can resume (skipping dates that already
		// have a live planned row).
		if (array_key_exists('isActive', $payload)
			&& (bool)$payload['isActive']
			&& !(bool)$row['is_active']
			&& $newFrequency === self::FREQ_SCHEDULE
			&& !array_key_exists('next_due_date', $updates)) {
			$effectiveEnd = (string)($updates['end_date'] ?? $row['end_date'] ?? '');
			if ($effectiveEnd !== '' && (string)$row['next_due_date'] > $effectiveEnd) {
				$updates['next_due_date'] = $effectiveEnd;
			}
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
		// Only enforce the next-due window when this update actually moves the
		// schedule anchors. Completed rules legitimately store a next_due_date one
		// step past end_date; a title-only edit must not be rejected for that.
		$movedAnchors = array_key_exists('next_due_date', $updates)
			|| array_key_exists('start_date', $updates)
			|| array_key_exists('end_date', $updates);
		if ($movedAnchors && $newFrequency !== self::FREQ_SCHEDULE) {
			if ($effectiveNextDue < $effectiveStart) {
				throw new \InvalidArgumentException('nextDueDate must not be before startDate.');
			}
			if ($effectiveEnd !== null && $effectiveNextDue > $effectiveEnd
				&& array_key_exists('nextDueDate', $payload)) {
				throw new \InvalidArgumentException('nextDueDate must not be after endDate.');
			}
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
		if (!(bool)$row['is_active']) {
			return [
				'rule' => $this->hydrate($row, $workspace['currencyCode']),
				'occurrences' => [],
			];
		}
		$end = $through !== null && $through !== ''
			? $this->parseIsoDate($through, 'through')
			: (new \DateTimeImmutable($row['next_due_date']))->modify('+12 months');
		$ruleEnd = $row['end_date'] !== null ? new \DateTimeImmutable($row['end_date']) : null;
		if ($ruleEnd !== null && $end > $ruleEnd) {
			$end = $ruleEnd;
		}
		$schedule = self::scheduleForRow($row);
		$occurrences = [];
		$next = new \DateTimeImmutable((string)$row['next_due_date']);
		if ($schedule !== null) {
			$snapped = self::nextScheduleDateOnOrAfter($schedule, $next->format('Y-m-d'));
			// Exhausted schedule: place the cursor past the bound so no occurrences render.
			$next = $snapped !== null
				? new \DateTimeImmutable($snapped, new \DateTimeZone('UTC'))
				: $end->modify('+1 day');
		}
		$count = 0;
		while ($next <= $end && $count < self::MAX_PREVIEW) {
			$date = $next->format('Y-m-d');
			$occurrences[] = [
				'date' => $date,
				'amount' => $this->money->envelope(
					self::occurrenceAmountMinor($schedule, $date, (int)$row['amount_minor']),
					$workspace['currencyCode'],
				),
			];
			$next = $this->advanceForRow($row, $schedule, $next);
			$count++;
		}
		return [
			'rule' => $this->hydrate($row, $workspace['currencyCode']),
			'occurrences' => $occurrences,
		];
	}

	/**
	 * Materialise occurrence(s) and advance `next_due_date`.
	 *
	 * Options:
	 *  - `through` (YYYY-MM-DD): batch until that date (inclusive)
	 *  - `asPlanned` (bool): override posting mode for this call only
	 *
	 * Book mode creates real rows (or promotes an existing planned twin).
	 * Plan mode creates planned placeholders. Closed months are skipped and the
	 * cursor still advances so auto-due never stalls forever.
	 *
	 * @return array<string,mixed> single transaction (next mode) or batch details
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
		if ($through !== null) {
			// Bulk fill past "today" (Add full period) is manager-only.
			// Due catch-up uses through=today with dueCatchUp=true and stays contributor-OK.
			$todayIso = $this->workspaceTodayIso($workspace);
			$throughIso = $through->format('Y-m-d');
			$isDueCatchUp = !empty($options['dueCatchUp']) && $throughIso <= $todayIso;
			if (!$isDueCatchUp) {
				$this->access->ensureMinimumRole((int)$workspace['id'], $userId, AccessControlService::ROLE_MANAGER);
			}
		}
		if ($through !== null && $through < $next) {
			throw new \InvalidArgumentException('through must be on or after next due date.');
		}
		if ($ruleEnd !== null && $through !== null && $through > $ruleEnd) {
			$through = $ruleEnd;
		}

		$asPlanned = array_key_exists('asPlanned', $options)
			? (bool)$options['asPlanned']
			: ($this->postingModeForRow($row) === self::POSTING_PLAN);

		$createdIds = [];
		$firstCreated = null;
		$generatedCount = 0;
		$skippedCount = 0;
		$skippedClosedCount = 0;
		$promotedCount = 0;
		$generatedFrom = null;
		$generatedTo = null;
		$finalNext = $next;
		$loopEnd = $through ?? $next;

		$this->db->beginTransaction();
		try {
			$row = $this->loadRowForUpdate($ruleId);
			if ($row === null || (int)$row['workspace_id'] !== (int)$workspace['id']) {
				throw new AccessDeniedException();
			}
			if (!(bool)$row['is_active']) {
				throw new \InvalidArgumentException('Rule is inactive.');
			}
			$ruleEnd = $row['end_date'] !== null ? new \DateTimeImmutable($row['end_date']) : null;
			$finalNext = new \DateTimeImmutable((string)$row['next_due_date']);
			$schedule = self::scheduleForRow($row);
			if ($schedule !== null) {
				// Defensive: next_due_date is always derived from the schedule, but if
				// the two ever disagree, snap forward to a real occurrence instead of
				// booking an off-schedule date.
				$snapped = self::nextScheduleDateOnOrAfter($schedule, $finalNext->format('Y-m-d'));
				if ($snapped === null) {
					throw new \InvalidArgumentException('Rule has reached its end date.');
				}
				$finalNext = new \DateTimeImmutable($snapped, new \DateTimeZone('UTC'));
			}
			if ($ruleEnd !== null && $finalNext > $ruleEnd) {
				throw new \InvalidArgumentException('Rule has reached its end date.');
			}
			$loopEnd = $through ?? $finalNext;
			if ($through !== null && $through < $finalNext) {
				throw new \InvalidArgumentException('through must be on or after next due date.');
			}
			if ($ruleEnd !== null && $through !== null && $through > $ruleEnd) {
				$loopEnd = $ruleEnd;
			}
			if (!array_key_exists('asPlanned', $options)) {
				$asPlanned = $this->postingModeForRow($row) === self::POSTING_PLAN;
			}

			while ($finalNext <= $loopEnd) {
				if ($generatedCount >= self::MAX_GENERATE_BATCH) {
					throw new \InvalidArgumentException('Too many occurrences in one generate call. Shorten the period and try again.');
				}
				$bookingDate = $finalNext->format('Y-m-d');
				$ym = substr($bookingDate, 0, 7);
				if ($tx->isMonthClosed((int)$workspace['id'], $ym)) {
					$skippedClosedCount++;
					$skippedCount++;
					$finalNext = $this->advanceForRow($row, $schedule, $finalNext);
					if ($ruleEnd !== null && $finalNext > $ruleEnd) {
						break;
					}
					continue;
				}

				if ($tx->hasLiveOccurrenceForRecurringDate((int)$workspace['id'], $ruleId, $bookingDate)) {
					if (!$asPlanned) {
						$promoted = $tx->promotePlannedRecurringOccurrence(
							(int)$workspace['id'],
							$userId,
							$ruleId,
							$bookingDate,
							(string)$workspace['currencyCode'],
						);
						if ($promoted !== null) {
							$createdIds[] = (int)$promoted['id'];
							$firstCreated ??= $promoted;
							$generatedCount++;
							$promotedCount++;
							$generatedFrom ??= $finalNext;
							$generatedTo = $finalNext;
						} else {
							$skippedCount++;
						}
					} else {
						$skippedCount++;
					}
					$finalNext = $this->advanceForRow($row, $schedule, $finalNext);
					if ($ruleEnd !== null && $finalNext > $ruleEnd) {
						break;
					}
					continue;
				}

				$created = $tx->create((int)$workspace['id'], $userId, [
					'categoryId' => (int)$row['category_id'],
					'direction' => (string)$row['direction'],
					'bookingDate' => $bookingDate,
					'amountMinor' => self::occurrenceAmountMinor($schedule, $bookingDate, (int)$row['amount_minor']),
					'title' => (string)$row['title'],
					'isSpecial' => false,
					'isPlanned' => $asPlanned,
					'recurringRuleId' => $ruleId,
				], $workspace, $category);
				$createdIds[] = (int)$created['id'];
				$firstCreated ??= $created;
				$generatedCount++;
				$generatedFrom ??= $finalNext;
				$generatedTo = $finalNext;
				$finalNext = $this->advanceForRow($row, $schedule, $finalNext);
				if ($ruleEnd !== null && $finalNext > $ruleEnd) {
					break;
				}
			}

			$ruleUpdates = [
				'next_due_date' => $finalNext->format('Y-m-d'),
				'updated_at' => $this->utcNow(),
			];
			if ($ruleEnd !== null && $finalNext > $ruleEnd) {
				$ruleUpdates['is_active'] = false;
			}
			$qb = $this->db->getQueryBuilder();
			$qb->update('bc_recurring_rules');
			foreach ($ruleUpdates as $col => $value) {
				$type = match (true) {
					is_bool($value) => \PDO::PARAM_BOOL,
					default => \PDO::PARAM_STR,
				};
				$qb->set($col, $qb->createNamedParameter($value, $type));
			}
			$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($ruleId, \PDO::PARAM_INT)));
			$qb->executeStatement();
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}

		if ($through !== null && $generatedCount === 0 && $skippedCount === 0) {
			throw new \InvalidArgumentException('No occurrences could be generated for this period.');
		}
		if ($through === null && $generatedCount === 0) {
			if ($skippedClosedCount > 0) {
				throw new \InvalidArgumentException('Month is closed. Reopen it before generating this due date.');
			}
			if ($skippedCount > 0) {
				throw new \InvalidArgumentException('An entry already exists for the next due date.');
			}
			throw new \InvalidArgumentException('No transaction was generated.');
		}

		$details = [
			'count' => $generatedCount,
			'skipped' => $skippedCount,
			'skippedClosed' => $skippedClosedCount,
			'promoted' => $promotedCount,
			'asPlanned' => $asPlanned,
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

	/**
	 * Book (or plan) every due occurrence for one rule through the workspace's
	 * local "today". Used by the UI catch-up button and the background job.
	 *
	 * @return array{count:int, skipped:int, skippedClosed:int, promoted:int, nextDueDate:string, asPlanned:bool}|array<string,mixed>
	 */
	public function generateDue(
		int $ruleId,
		string $userId,
		array $workspace,
		TransactionService $tx,
		array $category,
	): array {
		$today = $this->workspaceTodayIso($workspace);
		$row = $this->loadRow($ruleId);
		if ($row === null || (int)$row['workspace_id'] !== (int)$workspace['id']) {
			throw new AccessDeniedException();
		}
		$next = (string)$row['next_due_date'];
		if ($next > $today) {
			return [
				'count' => 0,
				'skipped' => 0,
				'skippedClosed' => 0,
				'promoted' => 0,
				'asPlanned' => $this->postingModeForRow($row) === self::POSTING_PLAN,
				'transactionIds' => [],
				'nextDueDate' => $next,
			];
		}
		return $this->generate($ruleId, $userId, $workspace, $tx, $category, [
			'through' => $today,
			'dueCatchUp' => true,
		]);
	}

	/**
	 * Catch up every active rule in a workspace whose next due is on or before today.
	 *
	 * @return array{rulesProcessed:int, generated:int, skipped:int, skippedClosed:int, promoted:int, errors:list<array{ruleId:int, message:string}>}
	 */
	public function generateDueForWorkspace(
		int $workspaceId,
		string $userId,
		array $workspace,
		TransactionService $tx,
		CategoryService $categories,
	): array {
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_CONTRIBUTOR);
		$today = $this->workspaceTodayIso($workspace);
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'category_id')
			->from('bc_recurring_rules')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
			->andWhere($qb->expr()->lte('next_due_date', $qb->createNamedParameter($today)))
			->orderBy('next_due_date', 'ASC')
			->addOrderBy('id', 'ASC')
			->setMaxResults(self::MAX_DUE_RULES_PER_SWEEP);
		$result = $qb->executeQuery();
		$rules = [];
		while ($row = $result->fetch()) {
			$rules[] = $row;
		}
		$result->closeCursor();

		$out = [
			'rulesProcessed' => 0,
			'generated' => 0,
			'skipped' => 0,
			'skippedClosed' => 0,
			'promoted' => 0,
			'errors' => [],
		];
		foreach ($rules as $ruleRow) {
			$ruleId = (int)$ruleRow['id'];
			try {
				$category = $categories->loadForWorkspace((int)$ruleRow['category_id'], $workspaceId);
				if ($category === null || empty($category['isActive'])) {
					$out['errors'][] = ['ruleId' => $ruleId, 'message' => 'Category missing or inactive.'];
					continue;
				}
				$details = $this->generateDue($ruleId, $userId, $workspace, $tx, $category);
				$out['rulesProcessed']++;
				$out['generated'] += (int)($details['count'] ?? 0);
				$out['skipped'] += (int)($details['skipped'] ?? 0);
				$out['skippedClosed'] += (int)($details['skippedClosed'] ?? 0);
				$out['promoted'] += (int)($details['promoted'] ?? 0);
			} catch (\Throwable $e) {
				$out['errors'][] = [
					'ruleId' => $ruleId,
					'message' => $e->getMessage() !== '' ? $e->getMessage() : $e::class,
				];
			}
		}
		$this->audit->record($userId, 'recurring_due_batch', 'workspace', (string)$workspaceId, [
			'rulesProcessed' => $out['rulesProcessed'],
			'generated' => $out['generated'],
			'skipped' => $out['skipped'],
			'skippedClosed' => $out['skippedClosed'],
			'promoted' => $out['promoted'],
			'errorCount' => count($out['errors']),
		], $workspaceId);
		return $out;
	}

	/**
	 * Background sweep: auto-book active `book`-mode rules that are due.
	 * Plan-mode rules are never touched by the job (placeholders stay manual).
	 *
	 * @return array{rulesProcessed:int, generated:int, skipped:int, errors:int}
	 */
	public function processDueBookRules(
		TransactionService $tx,
		CategoryService $categories,
		WorkspaceService $workspaces,
	): array {
		$summary = [
			'rulesProcessed' => 0,
			'generated' => 0,
			'skipped' => 0,
			'errors' => 0,
		];
		// Candidate rules: active book mode. Workspace-local "today" is applied per rule.
		$qb = $this->db->getQueryBuilder();
		$qb->select('r.id', 'r.workspace_id', 'r.category_id', 'r.created_by', 'r.next_due_date', 'w.timezone', 'w.currency_code')
			->from('bc_recurring_rules', 'r')
			->innerJoin('r', 'bc_workspaces', 'w', $qb->expr()->eq('r.workspace_id', 'w.id'))
			->where($qb->expr()->eq('r.is_active', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('r.posting_mode', $qb->createNamedParameter(self::POSTING_BOOK)))
			->orderBy('r.next_due_date', 'ASC')
			->addOrderBy('r.id', 'ASC')
			->setMaxResults(self::MAX_DUE_RULES_PER_SWEEP);
		$result = $qb->executeQuery();
		$candidates = [];
		while ($row = $result->fetch()) {
			$candidates[] = $row;
		}
		$result->closeCursor();

		foreach ($candidates as $cand) {
			$workspaceId = (int)$cand['workspace_id'];
			$ruleId = (int)$cand['id'];
			try {
				$today = $this->todayIsoForTimezone((string)$cand['timezone']);
				if ((string)$cand['next_due_date'] > $today) {
					continue;
				}
				$actor = $this->resolveAutomationActor($workspaceId, (string)$cand['created_by']);
				if ($actor === null) {
					$summary['errors']++;
					$this->audit->record('system', 'recurring_due_skipped', 'recurring_rule', (string)$ruleId, [
						'reason' => 'no_actor',
						'workspaceId' => $workspaceId,
					], $workspaceId);
					continue;
				}
				$workspace = $workspaces->getForUser($workspaceId, $actor);
				$category = $categories->loadForWorkspace((int)$cand['category_id'], $workspaceId);
				if ($category === null || empty($category['isActive'])) {
					$summary['errors']++;
					continue;
				}
				$details = $this->generateDue($ruleId, $actor, $workspace, $tx, $category);
				$summary['rulesProcessed']++;
				$summary['generated'] += (int)($details['count'] ?? 0);
				$summary['skipped'] += (int)($details['skipped'] ?? 0);
			} catch (\Throwable $e) {
				$summary['errors']++;
				$this->audit->record('system', 'recurring_due_error', 'recurring_rule', (string)$ruleId, [
					'message' => $e->getMessage() !== '' ? $e->getMessage() : $e::class,
					'workspaceId' => $workspaceId,
				], $workspaceId);
			}
		}
		return $summary;
	}

	/**
	 * Schedule-aware successor of `advance()`. For schedule rules the next
	 * occurrence is the earliest scheduled date strictly after `$current`; when
	 * the list is exhausted the day after the last scheduled date is returned,
	 * which is by construction past `end_date` and terminates the generate loop
	 * (deactivating the rule) exactly like interval rules reaching their end.
	 *
	 * @param list<array{date:string, amountMinor:int|null}>|null $schedule decoded schedule, null for interval rules
	 */
	private function advanceForRow(array $row, ?array $schedule, \DateTimeImmutable $current): \DateTimeImmutable
	{
		if ($schedule === null) {
			return $this->advance($current, (string)$row['frequency'], (int)$row['interval_count']);
		}
		$next = self::nextScheduleDateAfter($schedule, $current->format('Y-m-d'));
		if ($next !== null) {
			return new \DateTimeImmutable($next, new \DateTimeZone('UTC'));
		}
		$last = $schedule[count($schedule) - 1]['date'];
		return (new \DateTimeImmutable($last, new \DateTimeZone('UTC')))->modify('+1 day');
	}

	/**
	 * Decode the schedule for a DB row, or null when the rule is interval-based.
	 *
	 * @return list<array{date:string, amountMinor:int|null}>|null
	 */
	public static function scheduleForRow(array $row): ?array
	{
		if ((string)($row['frequency'] ?? '') !== self::FREQ_SCHEDULE) {
			return null;
		}
		$schedule = self::decodeSchedule($row['schedule_json'] ?? null);
		if ($schedule === []) {
			throw new InternalErrorException();
		}
		return $schedule;
	}

	/**
	 * Parse and validate a user-supplied schedule list. Returns entries sorted
	 * ascending by date; per-entry amounts are optional and fall back to the
	 * rule's default amount at generate time.
	 *
	 * @return non-empty-list<array{date:string, amountMinor:int|null}>
	 */
	private function normaliseSchedulePayload(mixed $raw, int $decimals): array
	{
		if (!is_array($raw) || $raw === []) {
			throw new \InvalidArgumentException('schedule must be a non-empty list of dates.');
		}
		if (count($raw) > self::MAX_SCHEDULE_ENTRIES) {
			throw new \InvalidArgumentException('schedule supports at most ' . self::MAX_SCHEDULE_ENTRIES . ' dates.');
		}
		$entries = [];
		$seen = [];
		foreach (array_values($raw) as $index => $entry) {
			if (!is_array($entry)) {
				throw new \InvalidArgumentException('schedule entries must be objects with a date.');
			}
			$rawDate = trim((string)($entry['date'] ?? ''));
			// Strict calendar validation: PHP's date parser would silently roll
			// e.g. 2026-02-30 over to 2026-03-02, corrupting the schedule.
			if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $rawDate, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
				throw new \InvalidArgumentException('schedule[' . $index . '].date must be a valid calendar day in YYYY-MM-DD format.');
			}
			$date = $rawDate;
			if (isset($seen[$date])) {
				throw new \InvalidArgumentException('schedule contains the same date twice: ' . $date . '.');
			}
			$seen[$date] = true;
			$amountRaw = $entry['amount'] ?? ($entry['amountMinor'] ?? null);
			$amountMinor = null;
			if ($amountRaw !== null && $amountRaw !== '') {
				$amountMinor = $this->money->parseHumanAmount($amountRaw, $decimals);
			}
			$entries[] = ['date' => $date, 'amountMinor' => $amountMinor];
		}
		usort($entries, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));
		return $entries;
	}

	/**
	 * @param list<array{date:string, amountMinor:int|null}> $schedule
	 */
	public static function encodeSchedule(array $schedule): string
	{
		return json_encode(
			array_map(
				static fn (array $e): array => ['date' => $e['date'], 'amountMinor' => $e['amountMinor']],
				$schedule,
			),
			JSON_THROW_ON_ERROR,
		);
	}

	/**
	 * Defensive decoder: invalid JSON or malformed entries yield an empty list
	 * rather than crashing consumers. Output is sorted and duplicate-free.
	 *
	 * @return list<array{date:string, amountMinor:int|null}>
	 */
	public static function decodeSchedule(mixed $json): array
	{
		if (!is_string($json) || trim($json) === '') {
			return [];
		}
		try {
			$decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return [];
		}
		if (!is_array($decoded)) {
			return [];
		}
		$entries = [];
		foreach ($decoded as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			$date = (string)($entry['date'] ?? '');
			if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
				continue;
			}
			[$y, $m, $d] = array_map('intval', explode('-', $date));
			if (!checkdate($m, $d, $y)) {
				continue;
			}
			$amountMinor = $entry['amountMinor'] ?? null;
			if ($amountMinor !== null && (!is_int($amountMinor) || $amountMinor < 1)) {
				$amountMinor = null;
			}
			$entries[$date] = ['date' => $date, 'amountMinor' => $amountMinor];
		}
		ksort($entries);
		return array_values($entries);
	}

	/**
	 * Earliest scheduled date >= `$isoDate`, or null when exhausted.
	 *
	 * @param list<array{date:string, amountMinor:int|null}> $schedule sorted ascending
	 */
	public static function nextScheduleDateOnOrAfter(array $schedule, string $isoDate): ?string
	{
		foreach ($schedule as $entry) {
			if ($entry['date'] >= $isoDate) {
				return $entry['date'];
			}
		}
		return null;
	}

	/**
	 * Earliest scheduled date strictly after `$isoDate`, or null when exhausted.
	 *
	 * @param list<array{date:string, amountMinor:int|null}> $schedule sorted ascending
	 */
	public static function nextScheduleDateAfter(array $schedule, string $isoDate): ?string
	{
		foreach ($schedule as $entry) {
			if ($entry['date'] > $isoDate) {
				return $entry['date'];
			}
		}
		return null;
	}

	/**
	 * Amount for one occurrence: the per-date override when set, the rule's
	 * default amount otherwise (and always for interval rules).
	 *
	 * @param list<array{date:string, amountMinor:int|null}>|null $schedule
	 */
	public static function occurrenceAmountMinor(?array $schedule, string $isoDate, int $defaultMinor): int
	{
		if ($schedule === null) {
			return $defaultMinor;
		}
		foreach ($schedule as $entry) {
			if ($entry['date'] === $isoDate) {
				return $entry['amountMinor'] ?? $defaultMinor;
			}
		}
		return $defaultMinor;
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

	/**
	 * Re-read the rule inside an open transaction (portable row lock where supported).
	 */
	private function loadRowForUpdate(int $ruleId): ?array
	{
		if ($ruleId < 1) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('bc_recurring_rules')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($ruleId, \PDO::PARAM_INT)))
			->forUpdate();
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? null : $row;
	}

	private function hydrate(array $row, string $currencyCode): array
	{
		$schedule = null;
		if ((string)$row['frequency'] === self::FREQ_SCHEDULE) {
			$schedule = array_map(
				fn (array $entry): array => [
					'date' => $entry['date'],
					'amountMinor' => $entry['amountMinor'],
					'amount' => $entry['amountMinor'] !== null
						? $this->money->envelope($entry['amountMinor'], $currencyCode)
						: null,
				],
				self::decodeSchedule($row['schedule_json'] ?? null),
			);
		}
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
			'schedule' => $schedule,
			'postingMode' => $this->postingModeForRow($row),
			'isActive' => (bool)$row['is_active'],
			'createdBy' => (string)$row['created_by'],
			'createdAt' => (string)$row['created_at'],
			'updatedAt' => (string)$row['updated_at'],
		];
	}

	private function normalisePostingMode(mixed $raw): string
	{
		$mode = strtolower(trim((string)$raw));
		if (!in_array($mode, self::POSTING_MODES, true)) {
			throw new \InvalidArgumentException('postingMode must be book or plan.');
		}
		return $mode;
	}

	private function postingModeForRow(array $row): string
	{
		$mode = strtolower(trim((string)($row['posting_mode'] ?? self::POSTING_PLAN)));
		return in_array($mode, self::POSTING_MODES, true) ? $mode : self::POSTING_PLAN;
	}

	/**
	 * Calendar "today" in the workspace timezone (ISO date). Due comparisons and
	 * the auto-book job use this so a Berlin household is not booked a day early/late.
	 */
	public function workspaceTodayIso(array $workspace): string
	{
		return $this->todayIsoForTimezone((string)($workspace['timezone'] ?? 'UTC'));
	}

	private function todayIsoForTimezone(string $timezone): string
	{
		try {
			$tz = new \DateTimeZone($timezone !== '' ? $timezone : 'UTC');
		} catch (\Throwable) {
			$tz = new \DateTimeZone('UTC');
		}
		return $this->timeFactory->getDateTime('now', $tz)->format('Y-m-d');
	}

	/**
	 * Prefer the rule author; if gone, the earliest workspace manager.
	 */
	private function resolveAutomationActor(int $workspaceId, string $preferredUserId): ?string
	{
		if ($preferredUserId !== '') {
			try {
				$this->access->ensureMinimumRole($workspaceId, $preferredUserId, AccessControlService::ROLE_CONTRIBUTOR);
				return $preferredUserId;
			} catch (\Throwable) {
				// fall through to a living manager
			}
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id')
			->from('bc_workspace_members')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('role', $qb->createNamedParameter(AccessControlService::ROLE_MANAGER)))
			->orderBy('id', 'ASC')
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if ($row === false) {
			return null;
		}
		$uid = (string)$row['user_id'];
		try {
			$this->access->ensureMinimumRole($workspaceId, $uid, AccessControlService::ROLE_CONTRIBUTOR);
			return $uid;
		} catch (\Throwable) {
			return null;
		}
	}

	private function parseIsoDate(string $value, string $field): \DateTimeImmutable
	{
		$value = trim($value);
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
			throw new \InvalidArgumentException($field . ' must be in YYYY-MM-DD format.');
		}
		if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
			throw new \InvalidArgumentException($field . ' is not a valid date.');
		}
		return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->setTime(0, 0);
	}

	private function utcNow(): string
	{
		return $this->timeFactory->getDateTime('now', new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
	}
}
