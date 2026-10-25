<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

use OCA\BudgetCheck\Exception\AccessDeniedException;
use OCA\BudgetCheck\Exception\ConflictException;
use OCA\BudgetCheck\Support\StrictBool;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Deterministic categorization rules (spec: payee-and-categorization-rules).
 *
 * Evaluation is a pure, in-memory linear scan: active rules sorted by
 * (priority ASC, id ASC), first match wins. Match types are contains /
 * equals / starts_with on the normalized subject — no regex, no scoring,
 * same input always yields the same category (NN-03).
 *
 * NH-02 adds an explicit per-rule `use_regex` opt-in: the pattern is
 * length-capped, syntax-validated at write time, evaluated with a low
 * backtrack limit, and only ever run against the short normalized subject.
 */
class CategorizationRuleService
{
	public const FIELDS = ['title', 'payee', 'notes'];
	public const MATCH_TYPES = ['contains', 'equals', 'starts_with'];
	public const DIRECTIONS = ['any', 'income', 'expense'];
	public const MAX_RULES_PER_WORKSPACE = 1000;
	private const REGEX_MAX_PATTERN = 128;
	private const REGEX_MAX_SUBJECT = 512;

	public function __construct(
		private IDBConnection $db,
		private AccessControlService $access,
		private ITimeFactory $timeFactory,
		private AuditLogService $audit,
		private CategoryService $categories,
		private PayeeService $payees,
	) {
	}

	// ------------------------------------------------------------------
	//  Evaluation (pure — no writes, no DB)
	// ------------------------------------------------------------------

	/**
	 * First matching rule for the given booking fields, or null.
	 *
	 * @param list<array<string,mixed>> $rules active rules pre-sorted by priority,id
	 * @param array{title?:string,payeeName?:string,notes?:string,direction?:string} $subject
	 */
	public function evaluate(array $rules, array $subject): ?array
	{
		$direction = strtolower(trim((string)($subject['direction'] ?? '')));
		foreach ($rules as $rule) {
			if (empty($rule['is_active'])) {
				continue;
			}
			$rd = (string)($rule['direction'] ?? 'any');
			if ($rd !== 'any' && $rd !== $direction) {
				continue;
			}
			$haystack = match ((string)$rule['match_field']) {
				'title' => (string)($subject['title'] ?? ''),
				'payee' => (string)($subject['payeeName'] ?? ''),
				'notes' => (string)($subject['notes'] ?? ''),
				default => '',
			};
			if ($haystack === '') {
				continue;
			}
			if ($this->matches($rule, $haystack)) {
				return $rule;
			}
		}
		return null;
	}

	private function matches(array $rule, string $haystack): bool
	{
		$hay = PayeeService::normalizeName($haystack);
		$needle = PayeeService::normalizeName((string)$rule['pattern']);
		if ($needle === '') {
			return false;
		}
		if (!empty($rule['use_regex'])) {
			return $this->matchesRegex((string)$rule['pattern'], mb_substr($haystack, 0, self::REGEX_MAX_SUBJECT));
		}
		return match ((string)$rule['match_type']) {
			'contains' => str_contains($hay, $needle),
			'equals' => $hay === $needle,
			'starts_with' => str_starts_with($hay, $needle),
			default => false,
		};
	}

	/**
	 * ReDoS-guarded regex evaluation (NH-02): the pattern is owner-authored,
	 * length-capped and syntax-checked at write time; at match time we lower
	 * the backtrack limit and treat any failure as "no match" — never fatal.
	 */
	private function matchesRegex(string $pattern, string $subject): bool
	{
		if ($pattern === '' || mb_strlen($pattern) > self::REGEX_MAX_PATTERN) {
			return false;
		}
		$prev = ini_get('pcre.backtrack_limit');
		ini_set('pcre.backtrack_limit', '10000');
		try {
			$result = @preg_match('`' . str_replace('`', '\\`', $pattern) . '`iu', $subject);
			return $result === 1;
		} finally {
			if ($prev !== false) {
				ini_set('pcre.backtrack_limit', (string)$prev);
			}
		}
	}

	// ------------------------------------------------------------------
	//  Queries
	// ------------------------------------------------------------------

	/** @return list<array<string,mixed>> rules sorted by (priority, id) */
	public function listForWorkspace(int $workspaceId, string $userId, bool $activeOnly = false): array
	{
		$this->access->ensureMembership($workspaceId, $userId);
		$qb = $this->db->getQueryBuilder();
		$qb->select('r.*')
			->selectAlias('c.name', 'category_name')
			->from('bc_category_rules', 'r')
			->leftJoin('r', 'bc_categories', 'c', $qb->expr()->eq('r.category_id', 'c.id'))
			->where($qb->expr()->eq('r.workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->orderBy('r.priority', 'ASC')
			->addOrderBy('r.id', 'ASC');
		if ($activeOnly) {
			$qb->andWhere($qb->expr()->eq('r.is_active', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)));
		}
		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$out[] = $this->hydrate($row);
		}
		$result->closeCursor();
		return $out;
	}

	/**
	 * CSV export of the workspace rule set (NH-03) — same safety contract as
	 * the transaction export: UTF-8 BOM, full quoting, formula-lead guard on
	 * every cell (patterns are user text and can start with =, +, -, @).
	 */
	public function exportCsv(int $workspaceId, string $userId): array
	{
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_MANAGER);
		$rules = $this->listForWorkspace($workspaceId, $userId);
		$csv = "\xEF\xBB\xBF" . $this->csvRow(['priority', 'match_field', 'match_type', 'pattern', 'direction', 'category', 'use_regex', 'is_active', 'hit_count']);
		foreach ($rules as $r) {
			$csv .= $this->csvRow([
				(string)$r['priority'],
				(string)$r['matchField'],
				(string)$r['matchType'],
				(string)$r['pattern'],
				(string)$r['direction'],
				(string)($r['categoryName'] ?? '#' . $r['categoryId']),
				$r['useRegex'] ? '1' : '0',
				$r['isActive'] ? '1' : '0',
				(string)($r['hitCount'] ?? 0),
			]);
		}
		return [
			'content' => $csv,
			'filename' => 'budgetcheck-categorization-rules.csv',
			'mimeType' => 'text/csv',
		];
	}

	private function csvRow(array $values): string
	{
		$parts = [];
		foreach ($values as $v) {
			$parts[] = '"' . str_replace('"', '""', $this->guardCsvCell($v)) . '"';
		}
		return implode(',', $parts) . "\r\n";
	}

	private function guardCsvCell(string $value): string
	{
		if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r", "\x0B"], true)) {
			return "'" . $value;
		}
		return $value;
	}

	/** Active rules only — the evaluation input for import/suggest paths. */
	public function activeRulesForWorkspace(int $workspaceId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('bc_category_rules')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
			->orderBy('priority', 'ASC')
			->addOrderBy('id', 'ASC');
		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$out[] = $row;
		}
		$result->closeCursor();
		return $out;
	}

	public function loadForWorkspace(int $ruleId, int $workspaceId): ?array
	{
		$row = $this->loadRow($ruleId);
		if ($row === null || (int)$row['workspace_id'] !== $workspaceId) {
			return null;
		}
		return $this->hydrate($row);
	}

	public function ownerWorkspaceId(int $ruleId): ?int
	{
		$row = $this->loadRow($ruleId);
		return $row === null ? null : (int)$row['workspace_id'];
	}

	/**
	 * Suggestion for the manual editor: evaluate rules against the current
	 * title/payee/notes/direction. Returns match info or null (empty inputs →
	 * null, never an error).
	 *
	 * @return array{ruleId:int,pattern:string,categoryId:int,categoryName:string}|null
	 */
	public function suggest(int $workspaceId, string $userId, array $subject): ?array
	{
		$this->access->ensureMembership($workspaceId, $userId);
		if (trim((string)($subject['title'] ?? '')) === ''
			&& trim((string)($subject['payeeName'] ?? '')) === ''
			&& trim((string)($subject['notes'] ?? '')) === '') {
			return null;
		}
		$rule = $this->evaluate($this->activeRulesForWorkspace($workspaceId), $subject);
		if ($rule === null) {
			return null;
		}
		$category = $this->categories->loadForWorkspace((int)$rule['category_id'], $workspaceId);
		// A rule pointing at a deactivated category suggests nothing — deactivated
		// categories cannot receive new bookings (parity with create-path guard).
		if ($category === null || empty($category['isActive'])) {
			return null;
		}
		if (($subject['direction'] ?? '') !== ''
			&& (string)($category['type'] ?? '') !== (string)$subject['direction']) {
			return null;
		}
		return [
			'ruleId' => (int)$rule['id'],
			'pattern' => (string)$rule['pattern'],
			'categoryId' => (int)$category['id'],
			'categoryName' => (string)$category['name'],
		];
	}

	// ------------------------------------------------------------------
	//  Writes (manager-gated)
	// ------------------------------------------------------------------

	public function create(int $workspaceId, string $userId, array $payload): array
	{
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_MANAGER);
		$this->assertRuleCap($workspaceId);
		$fields = $this->validatePayload($workspaceId, $payload, null);
		$this->db->beginTransaction();
		try {
			// Serialize against concurrent workspace delete and identical
			// creates: the dedupe check must run under the workspace row lock.
			WorkspaceRowLock::acquire($this->db, $workspaceId);
			// Idempotent create: an identical active rule is returned instead
			// of minting a duplicate row (double-submit / retry safety — there
			// is no unique index covering the match tuple).
			$existing = $this->findIdentical($workspaceId, $fields);
			if ($existing !== null) {
				$this->db->commit();
				return $this->hydrate($existing);
			}
			$now = $this->now();
			$qb = $this->db->getQueryBuilder();
		$qb->insert('bc_category_rules')
			->values([
				'workspace_id' => $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
				'category_id' => $qb->createNamedParameter($fields['category_id'], IQueryBuilder::PARAM_INT),
				'match_field' => $qb->createNamedParameter($fields['match_field']),
				'match_type' => $qb->createNamedParameter($fields['match_type']),
				'pattern' => $qb->createNamedParameter($fields['pattern']),
				'direction' => $qb->createNamedParameter($fields['direction']),
				'priority' => $qb->createNamedParameter($fields['priority'], IQueryBuilder::PARAM_INT),
				'use_regex' => $qb->createNamedParameter($fields['use_regex'], \PDO::PARAM_BOOL),
				'is_active' => $qb->createNamedParameter(true, \PDO::PARAM_BOOL),
				'hit_count' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'version' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
				'created_by' => $qb->createNamedParameter($userId),
				'updated_by' => $qb->createNamedParameter($userId),
				'created_at' => $qb->createNamedParameter($now),
				'updated_at' => $qb->createNamedParameter($now),
			]);
			$qb->executeStatement();
			$id = (int)$this->db->lastInsertId('bc_category_rules');
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
		$this->audit->record($userId, 'categorization_rule_created', 'categorization_rule', (string)$id, [
			'pattern' => $fields['pattern'],
			'categoryId' => $fields['category_id'],
		], $workspaceId);
		return $this->loadForWorkspace($id, $workspaceId) ?? [];
	}

	public function update(int $ruleId, string $userId, array $payload): array
	{
		$row = $this->loadRow($ruleId);
		if ($row === null) {
			throw new AccessDeniedException();
		}
		$workspaceId = (int)$row['workspace_id'];
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_MANAGER);
		// CAS: the client must send back the version it read (fail closed).
		// Missing or malformed tokens ("2.9", "2e3", " 5") reject the same way —
		// never cast-then-compare, which would silently bind a stale base.
		$version = $payload['version'] ?? null;
		if ((!is_int($version) && !ctype_digit((string)$version))
			|| (int)$version !== (int)$row['version']) {
			throw new ConflictException('version_conflict', 'The rule was changed by someone else. Reload and try again.');
		}
		$fields = $this->validatePayload($workspaceId, array_merge($row, $payload), $row);
		// Correction path: isActive lets a PUT reactivate a deactivated rule —
		// deactivate would otherwise be irreversible through the API.
		$isActive = (bool)$row['is_active'];
		if (array_key_exists('isActive', $payload) || array_key_exists('is_active', $payload)) {
			$isActive = StrictBool::field($payload, 'isActive') || StrictBool::field($payload, 'is_active');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_category_rules')
			->set('category_id', $qb->createNamedParameter($fields['category_id'], IQueryBuilder::PARAM_INT))
			->set('match_field', $qb->createNamedParameter($fields['match_field']))
			->set('match_type', $qb->createNamedParameter($fields['match_type']))
			->set('pattern', $qb->createNamedParameter($fields['pattern']))
			->set('direction', $qb->createNamedParameter($fields['direction']))
			->set('priority', $qb->createNamedParameter($fields['priority'], IQueryBuilder::PARAM_INT))
			->set('use_regex', $qb->createNamedParameter($fields['use_regex'], \PDO::PARAM_BOOL))
			->set('is_active', $qb->createNamedParameter($isActive, \PDO::PARAM_BOOL))
			->set('version', $qb->createNamedParameter((int)$row['version'] + 1, IQueryBuilder::PARAM_INT))
			->set('updated_by', $qb->createNamedParameter($userId))
			->set('updated_at', $qb->createNamedParameter($this->now()))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($ruleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('version', $qb->createNamedParameter((int)$row['version'], IQueryBuilder::PARAM_INT)));
		if ($qb->executeStatement() === 0) {
			throw new ConflictException('version_conflict', 'The rule was changed by someone else. Reload and try again.');
		}
		$this->audit->record($userId, 'categorization_rule_updated', 'categorization_rule', (string)$ruleId, [], $workspaceId);
		return $this->loadForWorkspace($ruleId, $workspaceId) ?? [];
	}

	public function deactivate(int $ruleId, string $userId): array
	{
		$row = $this->loadRow($ruleId);
		if ($row === null) {
			throw new AccessDeniedException();
		}
		$workspaceId = (int)$row['workspace_id'];
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_MANAGER);
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_category_rules')
			->set('is_active', $qb->createNamedParameter(false, \PDO::PARAM_BOOL))
			->set('version', $qb->createNamedParameter((int)$row['version'] + 1, IQueryBuilder::PARAM_INT))
			->set('updated_by', $qb->createNamedParameter($userId))
			->set('updated_at', $qb->createNamedParameter($this->now()))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($ruleId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		$this->audit->record($userId, 'categorization_rule_deactivated', 'categorization_rule', (string)$ruleId, [], $workspaceId);
		return $this->loadForWorkspace($ruleId, $workspaceId) ?? [];
	}

	/**
	 * Commit-time hit accounting: one +1 per imported row whose final category
	 * came from this rule. Preview never increments (US-104 AC-3).
	 *
	 * @param array<int,int> $hits ruleId => matched row count
	 */
	public function recordHits(array $hits): void
	{
		foreach ($hits as $ruleId => $count) {
			if ($count <= 0) {
				continue;
			}
			$qb = $this->db->getQueryBuilder();
			$qb->update('bc_category_rules')
				->set('hit_count', $qb->createFunction('`hit_count` + ' . (int)$count))
				->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$ruleId, IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();
		}
	}

	/**
	 * NH-01: apply rules to existing bookings. `$dryRun` returns the would-be
	 * changes without writing; apply updates only rows whose current category
	 * is the internal-uncategorized bucket (never rewrites a human choice).
	 *
	 * @return array{scanned:int,matched:int,updated:int,perRule:array<int,int>}
	 */
	public function backfill(int $workspaceId, string $userId, bool $dryRun): array
	{
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_MANAGER);
		$rules = $this->activeRulesForWorkspace($workspaceId);
		if ($rules === []) {
			return ['scanned' => 0, 'matched' => 0, 'updated' => 0, 'perRule' => []];
		}
		$uncatIds = $this->categories->internalUncategorizedCategoryIds($workspaceId);
		$closedYm = $this->closedYearMonths($workspaceId);
		$qb = $this->db->getQueryBuilder();
		$qb->select('t.id', 't.title', 't.notes', 't.direction', 't.category_id', 't.booking_date', 't.payee_id')
			->selectAlias('p.name', 'payee_name')
			->from('bc_transactions', 't')
			->leftJoin('t', 'bc_payees', 'p', $qb->expr()->eq('t.payee_id', 'p.id'))
			->where($qb->expr()->eq('t.workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('t.deleted_at'))
			->setMaxResults(20000);
		$result = $qb->executeQuery();
		$scanned = 0;
		$matched = 0;
		$updated = 0;
		$perRule = [];
		$candidates = [];
		$now = $this->now();
		while ($row = $result->fetch()) {
			$scanned++;
			$cid = (int)$row['category_id'];
			if (!in_array($cid, $uncatIds, true)) {
				continue;
			}
			// Closed months are sealed evidence — recategorizing a booked entry
			// would silently diverge the live ledger from the snapshot hash.
			// Identical in dry-run and apply so the preview cannot promise a
			// write the commit must refuse.
			$ym = substr((string)$row['booking_date'], 0, 7);
			if (isset($closedYm[$ym])) {
				continue;
			}
			$rule = $this->evaluate($rules, [
				'title' => (string)$row['title'],
				'payeeName' => (string)($row['payee_name'] ?? ''),
				'notes' => (string)$row['notes'] ?? '',
				'direction' => (string)$row['direction'],
			]);
			if ($rule === null) {
				continue;
			}
			$matched++;
			$perRule[(int)$rule['id']] = ($perRule[(int)$rule['id']] ?? 0) + 1;
			if (!$dryRun) {
				$candidates[] = [
					'id' => (int)$row['id'],
					'ym' => $ym,
					'categoryId' => (int)$rule['category_id'],
					'ruleId' => (int)$rule['id'],
				];
			}
		}
		$result->closeCursor();
		if (!$dryRun && $candidates !== []) {
			$this->db->beginTransaction();
			try {
				// Serialize the bulk rewrite against ledger writers and monthly
				// close — the whole batch commits or none of it does.
				WorkspaceRowLock::acquire($this->db, $workspaceId);
				// A month that closed between the outer scan and this lock must
				// not be rewritten either — re-read the sealed set under lock.
				$closedNow = $this->closedYearMonths($workspaceId);
				$hits = [];
				foreach ($candidates as $c) {
					if (isset($closedNow[$c['ym']])) {
						continue;
					}
					$u = $this->db->getQueryBuilder();
					$u->update('bc_transactions')
						->set('category_id', $u->createNamedParameter($c['categoryId'], IQueryBuilder::PARAM_INT))
						->set('updated_by', $u->createNamedParameter($userId))
						->set('updated_at', $u->createNamedParameter($now))
						->where($u->expr()->eq('id', $u->createNamedParameter($c['id'], IQueryBuilder::PARAM_INT)))
						// Still uncategorized? A writer outside the lock
						// contract must not be overwritten by a stale match.
						->andWhere($u->expr()->in('category_id', $u->createNamedParameter($uncatIds, IQueryBuilder::PARAM_INT_ARRAY)));
					$affected = $u->executeStatement();
					$updated += $affected;
					if ($affected > 0) {
						$hits[$c['ruleId']] = ($hits[$c['ruleId']] ?? 0) + 1;
					}
				}
				if ($hits !== []) {
					$this->recordHits($hits);
				}
				$this->db->commit();
			} catch (\Throwable $e) {
				if ($this->db->inTransaction()) {
					$this->db->rollBack();
				}
				throw $e;
			}
		}
		$this->audit->record($userId, $dryRun ? 'categorization_rules_backfill_preview' : 'categorization_rules_backfill_applied', 'workspace', (string)$workspaceId, [
			'scanned' => $scanned, 'matched' => $matched, 'updated' => $updated,
		], $workspaceId);
		return ['scanned' => $scanned, 'matched' => $matched, 'updated' => $updated, 'perRule' => $perRule];
	}

	// ------------------------------------------------------------------
	//  Internals
	// ------------------------------------------------------------------

	/**
	 * Closed (snapshotted) year-months for a workspace — the set of months
	 * whose ledger rows are sealed evidence.
	 *
	 * @return array<string,true> 'YYYY-MM' => true
	 */
	private function closedYearMonths(int $workspaceId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('year_month')
			->from('bc_monthly_snapshots')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$set = [];
		while ($row = $result->fetch()) {
			$set[(string)$row['year_month']] = true;
		}
		$result->closeCursor();
		return $set;
	}

	/**
	 * Exact-match lookup for idempotent create: same workspace, category,
	 * match tuple, direction, regex flag and activity state.
	 */
	private function findIdentical(int $workspaceId, array $fields): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('bc_category_rules')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('category_id', $qb->createNamedParameter($fields['category_id'], IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('match_field', $qb->createNamedParameter($fields['match_field'])))
			->andWhere($qb->expr()->eq('match_type', $qb->createNamedParameter($fields['match_type'])))
			->andWhere($qb->expr()->eq('pattern', $qb->createNamedParameter($fields['pattern'])))
			->andWhere($qb->expr()->eq('direction', $qb->createNamedParameter($fields['direction'])))
			->andWhere($qb->expr()->eq('use_regex', $qb->createNamedParameter($fields['use_regex'], \PDO::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
			->setMaxResults(1);
		$row = $qb->executeQuery()->fetch();
		return $row === false ? null : $row;
	}

	private function assertRuleCap(int $workspaceId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'c'))
			->from('bc_category_rules')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if ((int)($row['c'] ?? 0) >= self::MAX_RULES_PER_WORKSPACE) {
			throw new \InvalidArgumentException('This workspace already has the maximum of 1,000 categorization rules.');
		}
	}

	/**
	 * @return array{category_id:int,match_field:string,match_type:string,pattern:string,direction:string,priority:int,use_regex:bool}
	 */
	private function validatePayload(int $workspaceId, array $payload, ?array $existing): array
	{
		$matchField = strtolower(trim((string)($payload['matchField'] ?? $payload['match_field'] ?? '')));
		if (!in_array($matchField, self::FIELDS, true)) {
			throw new \InvalidArgumentException('matchField must be one of: title, payee, notes.');
		}
		$matchType = strtolower(trim((string)($payload['matchType'] ?? $payload['match_type'] ?? '')));
		if (!in_array($matchType, self::MATCH_TYPES, true)) {
			throw new \InvalidArgumentException('matchType must be one of: contains, equals, starts_with.');
		}
		$pattern = trim((string)($payload['pattern'] ?? ''));
		if ($pattern === '' || mb_strlen($pattern) > 255) {
			throw new \InvalidArgumentException('pattern must be 1–255 characters.');
		}
		$direction = strtolower(trim((string)($payload['direction'] ?? 'any')));
		if (!in_array($direction, self::DIRECTIONS, true)) {
			throw new \InvalidArgumentException('direction must be one of: any, income, expense.');
		}
		$useRegex = StrictBool::field($payload, 'useRegex') || StrictBool::field($payload, 'use_regex');
		if ($useRegex && (mb_strlen($pattern) > self::REGEX_MAX_PATTERN || @preg_match('`' . str_replace('`', '\\`', $pattern) . '`iu', '') === false)) {
			throw new \InvalidArgumentException('Regex pattern is invalid or longer than 128 characters.');
		}
		$priority = isset($payload['priority']) ? (int)$payload['priority'] : 100;
		$priority = max(-100000, min(100000, $priority));
		$categoryId = (int)($payload['categoryId'] ?? $payload['category_id'] ?? 0);
		$category = $this->categories->loadForWorkspace($categoryId, $workspaceId);
		if ($category === null) {
			throw new AccessDeniedException();
		}
		return [
			'category_id' => $categoryId,
			'match_field' => $matchField,
			'match_type' => $matchType,
			'pattern' => $pattern,
			'direction' => $direction,
			'priority' => $priority,
			'use_regex' => $useRegex,
		];
	}

	private function loadRow(int $ruleId): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('bc_category_rules')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($ruleId, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		return $row === false ? null : $row;
	}

	private function hydrate(array $row): array
	{
		return [
			'id' => (int)$row['id'],
			'workspaceId' => (int)$row['workspace_id'],
			'categoryId' => (int)$row['category_id'],
			'categoryName' => isset($row['category_name']) ? (string)$row['category_name'] : null,
			'matchField' => (string)$row['match_field'],
			'matchType' => (string)$row['match_type'],
			'pattern' => (string)$row['pattern'],
			'direction' => (string)$row['direction'],
			'priority' => (int)$row['priority'],
			'useRegex' => (bool)($row['use_regex'] ?? false),
			'isActive' => (bool)$row['is_active'],
			'hitCount' => (int)$row['hit_count'],
			'version' => (int)$row['version'],
			'createdBy' => (string)$row['created_by'],
			'createdAt' => (string)$row['created_at'],
			'updatedAt' => (string)$row['updated_at'],
		];
	}

	private function now(): string
	{
		return $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
	}
}
