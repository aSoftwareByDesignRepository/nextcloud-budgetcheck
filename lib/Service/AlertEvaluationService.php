<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\L10N\IFactory;

/**
 * Pure alert evaluators (spec: alert-notifications §4).
 *
 * Each evaluator maps (workspace, members, now) → alert candidates; the
 * dispatcher is the only thing that sends. Evaluators never write state.
 */
class AlertEvaluationService
{
	public function __construct(
		private IDBConnection $db,
		private SummaryService $summaries,
		private WorkspaceService $workspaces,
		private AlertPreferencesService $prefs,
		private AlertDispatchService $dispatch,
		private MoneyService $money,
		private ITimeFactory $timeFactory,
		private IFactory $l10nFactory,
	) {
	}

	/**
	 * Full sweep for one workspace: threshold + digest + savings for every
	 * member with the type enabled. Recurring alerts are fired by the job
	 * inline when generation runs (they need the just-posted counts).
	 */
	public function evaluateWorkspace(int $workspaceId, \DateTimeImmutable $now): array
	{
		$workspace = $this->loadWorkspaceRow($workspaceId);
		if ($workspace === null || empty($workspace['is_active']) || ($workspace['type'] ?? '') !== WorkspaceService::TYPE_HOUSEHOLD) {
			return ['sent' => 0];
		}
		$members = $this->memberUserIds($workspaceId);
		$sent = 0;
		foreach ($members as $uid) {
			try {
				$this->accessCheck($workspaceId, $uid);
				$sent += $this->evaluateBudgetThresholds($workspaceId, $uid, $now);
				$sent += $this->evaluateMonthlyDigest($workspaceId, $uid, $now);
				$sent += $this->evaluateSavings($workspaceId, $uid, $now);
			} catch (\Throwable) {
				continue; // non-member or deleted user mid-sweep — skip silently
			}
		}
		return ['sent' => $sent];
	}

	/**
	 * Budget threshold steps: configured percent (default 80) then hard-wired
	 * 100. Dedupe key per (category, yearMonth, step) — a step never re-fires.
	 */
	public function evaluateBudgetThresholds(int $workspaceId, string $uid, \DateTimeImmutable $now): int
	{
		if (!$this->prefs->typeEnabled($uid, AlertPreferencesService::TYPE_BUDGET)) {
			return 0;
		}
		$ym = $now->format('Y-m');
		try {
			$summary = $this->summaries->household($workspaceId, $uid, $ym);
		} catch (\Throwable) {
			return 0;
		}
		$l = $this->l10nFactory->get('budgetcheck');
		$sent = 0;
		foreach ((array)($summary['budget']['byCategory'] ?? []) as $row) {
			$planned = (int)(($row['planned']['minor'] ?? 0));
			$actual = (int)(($row['actual']['minor'] ?? 0));
			if ($planned <= 0 || ($row['isSavingsTransfer'] ?? false) || ($row['direction'] ?? '') !== 'expense') {
				continue;
			}
			$pct = ($actual / $planned) * 100;
			$cid = (int)$row['categoryId'];
			$steps = [1 => $this->prefs->thresholdForCategory($uid, $workspaceId, $cid), 2 => 100];
			foreach ($steps as $step => $threshold) {
				if ($pct < $threshold || $threshold >= 100 && $step === 1) {
					continue;
				}
				$key = $cid . ':' . $ym . ':' . $step;
				$name = (string)$row['name'];
				$subject = $step === 2
					? $l->t('%1$s is over its %2$s budget (%3$s%%)', [$name, $this->monthLabel($ym), (string)round($pct)])
					: $l->t('%1$s has reached %2$s%% of the %3$s budget', [$name, (string)round($pct), $this->monthLabel($ym)]);
				if ($this->dispatch->dispatch($uid, $workspaceId, AlertPreferencesService::TYPE_BUDGET, $key, [
					'subject' => $subject,
					'route' => 'budgetcheck.page.monthly',
					'params' => ['yearMonth' => $ym],
				])) {
					$sent++;
				}
				break; // highest reached step only — one alert per evaluation
			}
		}
		return $sent;
	}

	/**
	 * Monthly digest on/after the 1st — once per user per workspace per month.
	 */
	public function evaluateMonthlyDigest(int $workspaceId, string $uid, \DateTimeImmutable $now): int
	{
		if (!$this->prefs->typeEnabled($uid, AlertPreferencesService::TYPE_DIGEST)) {
			return 0;
		}
		if ((int)$now->format('j') < 1) {
			return 0;
		}
		// Digest covers the month that just closed.
		$prev = $now->modify('first day of last month');
		$ym = $prev->format('Y-m');
		try {
			$summary = $this->summaries->household($workspaceId, $uid, $ym);
		} catch (\Throwable) {
			return 0;
		}
		$totals = (array)($summary['totals'] ?? []);
		if ((int)(($totals['income']['minor'] ?? 0)) === 0 && (int)(($totals['expense']['minor'] ?? 0)) === 0) {
			return 0; // empty month — nothing to digest
		}
		$l = $this->l10nFactory->get('budgetcheck');
		$subject = $l->t('%1$s: Income %2$s · Expense %3$s · Net %4$s', [
			$this->monthLabel($ym),
			$this->fmt($totals['income'] ?? null),
			$this->fmt($totals['expense'] ?? null),
			$this->fmt($totals['netResult'] ?? null),
		]);
		return $this->dispatch->dispatch($uid, $workspaceId, AlertPreferencesService::TYPE_DIGEST, $ym . ':digest', [
			'subject' => $subject,
			'route' => 'budgetcheck.page.monthly',
			'params' => ['yearMonth' => $ym],
		]) ? 1 : 0;
	}

	/**
	 * Savings-goal status at month-close evaluation (reached or missed —
	 * neutral copy, never guilt). "Missed" only fires when a target existed.
	 */
	public function evaluateSavings(int $workspaceId, string $uid, \DateTimeImmutable $now): int
	{
		if (!$this->prefs->typeEnabled($uid, AlertPreferencesService::TYPE_SAVINGS)) {
			return 0;
		}
		$prev = $now->modify('first day of last month');
		$ym = $prev->format('Y-m');
		try {
			$summary = $this->summaries->household($workspaceId, $uid, $ym);
		} catch (\Throwable) {
			return 0;
		}
		$target = (int)(($summary['totals']['savingsTarget']['minor'] ?? 0));
		if ($target <= 0) {
			return 0; // no target → no evaluation, no shaming
		}
		$achieved = (int)(($summary['totals']['savingsAchieved']['minor'] ?? 0));
		$reached = $achieved >= $target;
		$l = $this->l10nFactory->get('budgetcheck');
		$subject = $reached
			? $l->t('%1$s: savings target reached (%2$s of %3$s)', [$this->monthLabel($ym), $this->fmt($summary['totals']['savingsAchieved']), $this->fmt($summary['totals']['savingsTarget'])])
			: $l->t('%1$s: savings target not met (%2$s of %3$s)', [$this->monthLabel($ym), $this->fmt($summary['totals']['savingsAchieved']), $this->fmt($summary['totals']['savingsTarget'])]);
		return $this->dispatch->dispatch($uid, $workspaceId, AlertPreferencesService::TYPE_SAVINGS, $ym . ':savings', [
			'subject' => $subject,
			'route' => 'budgetcheck.page.monthly',
			'params' => ['yearMonth' => $ym],
		]) ? 1 : 0;
	}

	/**
	 * Recurring posted/failed alerts — called by the due sweep with its own
	 * result. Posted = one summary notification per member; failures go to
	 * managers only (US-402 AC-2).
	 *
	 * @param array{generated?:int, errors?:list<array{ruleId:int,message:string}>} $result
	 */
	public function notifyRecurringResult(int $workspaceId, array $result, \DateTimeImmutable $now): int
	{
		$generated = (int)($result['generated'] ?? 0);
		$errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
		$ym = $now->format('Y-m');
		$l = $this->l10nFactory->get('budgetcheck');
		$sent = 0;
		if ($generated > 0) {
			foreach ($this->memberUserIds($workspaceId) as $uid) {
				if (!$this->prefs->canSend($uid, AlertPreferencesService::TYPE_RECURRING)) {
					continue;
				}
				$subject = $l->n('%n recurring booking posted', '%n recurring bookings posted', $generated);
				$sent += $this->dispatch->dispatch($uid, $workspaceId, AlertPreferencesService::TYPE_RECURRING, $ym . ':recurring:' . $generated, [
					'subject' => $subject,
					'route' => 'budgetcheck.page.transactions',
				]) ? 1 : 0;
			}
		}
		foreach ($errors as $err) {
			foreach ($this->managerUserIds($workspaceId) as $uid) {
				if (!$this->prefs->canSend($uid, AlertPreferencesService::TYPE_RECURRING)) {
					continue;
				}
				$ruleId = (int)($err['ruleId'] ?? 0);
				$subject = $l->t('A recurring rule failed to post — review the rule.', []);
				$sent += $this->dispatch->dispatch($uid, $workspaceId, AlertPreferencesService::TYPE_RECURRING, $ruleId . ':' . $ym . ':fail', [
					'subject' => $subject,
					'route' => 'budgetcheck.page.settingsSection',
					'params' => ['section' => 'recurring'],
				]) ? 1 : 0;
			}
		}
		return $sent;
	}

	/** Workspace member uids (individual rows — group members resolved via ACL). */
	private function memberUserIds(int $workspaceId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id')
			->from('bc_workspace_members')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$out[] = (string)$row['user_id'];
		}
		$result->closeCursor();
		return $out;
	}

	private function managerUserIds(int $workspaceId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id')
			->from('bc_workspace_members')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('role', $qb->createNamedParameter(AccessControlService::ROLE_MANAGER)));
		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$out[] = (string)$row['user_id'];
		}
		$result->closeCursor();
		return $out;
	}

	private function accessCheck(int $workspaceId, string $uid): void
	{
		// Members only — a user removed mid-sweep must not receive alerts.
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id')
			->from('bc_workspace_members')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)));
		if ($qb->executeQuery()->fetch() === false) {
			throw new \RuntimeException('not a member');
		}
	}

	private function loadWorkspaceRow(int $workspaceId): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('bc_workspaces')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		return $row === false ? null : $row;
	}

	private function monthLabel(string $ym): string
	{
		$dt = \DateTimeImmutable::createFromFormat('Y-m-d', $ym . '-01');
		return $dt === false ? $ym : $dt->format('F Y');
	}

	private function fmt(mixed $env): string
	{
		return (string)($env['formatted'] ?? $env['minor'] ?? '0');
	}
}
