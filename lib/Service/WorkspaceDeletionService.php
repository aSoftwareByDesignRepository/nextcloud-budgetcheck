<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

use OCA\BudgetCheck\Exception\AccessDeniedException;
use OCA\BudgetCheck\Exception\ValidationException;
use OCA\BudgetCheck\Util\BillingStatus;
use OCP\IDBConnection;

/**
 * Hard-delete a workspace and every ledger artefact scoped to it.
 *
 * Security invariants:
 *  - Opaque access_denied for non-members (same as {@see WorkspaceService::getForUser}).
 *  - Manager gate via {@see AccessControlService::ensureMinimumRole} (app-admin
 *    break-glass on standard workspaces; individual manager only on private).
 *  - Exact confirmName match enforced server-side (UI alone is not trusted).
 *  - Exclusive {@see WorkspaceRowLock} + DB transaction for cascade atomicity.
 *  - Attachment files purged best-effort after commit (DB wins over FS).
 *  - InvoiceCheck documents are not deleted; settlement links on BC txs vanish
 *    with the ledger — callers must surface that in confirm copy.
 */
class WorkspaceDeletionService
{
	/** Child tables deleted by workspace_id (attachments handled separately). */
	private const CHILD_TABLES = [
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
	];

	public function __construct(
		private IDBConnection $db,
		private AccessControlService $access,
		private WorkspaceService $workspaces,
		private TransactionAttachmentService $attachments,
		private AuditLogService $audit,
		private ImportPreferencesService $importPreferences,
		private SummaryViewPreferencesService $summaryViewPrefs,
	) {
	}

	/**
	 * Impact summary for confirm UI (manager-only).
	 *
	 * @return array{
	 *   workspaceId:int,
	 *   name:string,
	 *   type:string,
	 *   transactionCount:int,
	 *   attachmentCount:int,
	 *   memberCount:int,
	 *   groupCount:int,
	 *   closedMonthCount:int,
	 *   billableInvoicedCount:int,
	 *   billablePaidCount:int,
	 *   hasInvoiceCheckLinks:bool
	 * }
	 */
	public function previewImpact(int $workspaceId, string $userId): array
	{
		$workspace = $this->requireManagerWorkspace($workspaceId, $userId);
		return $this->buildImpact($workspace);
	}

	/**
	 * Permanently delete the workspace after confirmName matches.
	 *
	 * @return array{deleted:true,id:int,name:string,type:string,impact:array<string,mixed>}
	 */
	public function deleteWorkspace(int $workspaceId, string $userId, string $confirmName): array
	{
		$workspace = $this->requireManagerWorkspace($workspaceId, $userId);
		$expected = (string)$workspace['name'];
		if (!$this->confirmNameMatches($expected, $confirmName)) {
			throw new ValidationException(
				'Type the workspace name exactly to confirm deletion.',
				['confirmName' => 'Type the workspace name exactly to confirm deletion.'],
			);
		}

		$impact = $this->buildImpact($workspace);
		$memberUserIds = $this->listMemberUserIds($workspaceId);
		if (!in_array($userId, $memberUserIds, true)) {
			$memberUserIds[] = $userId;
		}

		$attachmentFiles = [];
		$this->db->beginTransaction();
		try {
			WorkspaceRowLock::acquire($this->db, $workspaceId);

			// Re-check existence under lock (concurrent delete).
			if ($this->workspaces->loadById($workspaceId) === null) {
				throw new AccessDeniedException();
			}

			$attachmentFiles = $this->attachments->collectAndDeleteRowsForWorkspace($workspaceId);

			foreach (self::CHILD_TABLES as $table) {
				$this->deleteByWorkspaceId($table, $workspaceId);
			}

			$this->audit->record(
				$userId,
				'workspace_deleted',
				'workspace',
				(string)$workspaceId,
				[
					'name' => $expected,
					'type' => (string)$workspace['type'],
					'privacyMode' => (string)($workspace['privacyMode'] ?? AccessControlService::PRIVACY_STANDARD),
					'transactionCount' => $impact['transactionCount'],
					'attachmentCount' => $impact['attachmentCount'],
					'billableInvoicedCount' => $impact['billableInvoicedCount'],
					'billablePaidCount' => $impact['billablePaidCount'],
				],
				$workspaceId,
			);

			$qb = $this->db->getQueryBuilder();
			$qb->delete('bc_workspaces')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)));
			$deleted = $qb->executeStatement();
			if ($deleted !== 1) {
				throw new AccessDeniedException();
			}

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		$this->access->forgetPrivacyModeCache($workspaceId);
		$this->attachments->purgeCollectedFiles($attachmentFiles);
		$this->pruneUserPointers($memberUserIds, $workspaceId);

		return [
			'deleted' => true,
			'id' => $workspaceId,
			'name' => $expected,
			'type' => (string)$workspace['type'],
			'impact' => $impact,
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function requireManagerWorkspace(int $workspaceId, string $userId): array
	{
		// Opaque deny for non-members / missing ids.
		$workspace = $this->workspaces->getForUser($workspaceId, $userId);
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_MANAGER);
		return $workspace;
	}

	private function confirmNameMatches(string $expected, string $provided): bool
	{
		$expectedNorm = trim($expected);
		$providedNorm = trim($provided);
		if ($expectedNorm === '' || $providedNorm === '') {
			return false;
		}
		return hash_equals($expectedNorm, $providedNorm);
	}

	/**
	 * @param array<string,mixed> $workspace
	 * @return array{
	 *   workspaceId:int,
	 *   name:string,
	 *   type:string,
	 *   transactionCount:int,
	 *   attachmentCount:int,
	 *   memberCount:int,
	 *   groupCount:int,
	 *   closedMonthCount:int,
	 *   billableInvoicedCount:int,
	 *   billablePaidCount:int,
	 *   hasInvoiceCheckLinks:bool
	 * }
	 */
	private function buildImpact(array $workspace): array
	{
		$workspaceId = (int)$workspace['id'];
		$txCount = $this->countWhere('bc_transactions', $workspaceId);
		$attachmentCount = $this->countAttachmentsForWorkspace($workspaceId);
		$invoiced = $this->countBillableStatus($workspaceId, BillingStatus::INVOICED);
		$paid = $this->countBillableStatus($workspaceId, BillingStatus::PAID);

		return [
			'workspaceId' => $workspaceId,
			'name' => (string)$workspace['name'],
			'type' => (string)$workspace['type'],
			'transactionCount' => $txCount,
			'attachmentCount' => $attachmentCount,
			'memberCount' => $this->countWhere('bc_workspace_members', $workspaceId),
			'groupCount' => $this->countWhere('bc_workspace_groups', $workspaceId),
			'closedMonthCount' => $this->countWhere('bc_monthly_snapshots', $workspaceId),
			'billableInvoicedCount' => $invoiced,
			'billablePaidCount' => $paid,
			'hasInvoiceCheckLinks' => ($invoiced + $paid) > 0,
		];
	}

	private function countWhere(string $table, int $workspaceId): int
	{
		if (!$this->db->tableExists($table)) {
			return 0;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'c'))
			->from($table)
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['c'] ?? 0);
	}

	private function countAttachmentsForWorkspace(int $workspaceId): int
	{
		if (!$this->db->tableExists('bc_tx_attachments') || !$this->db->tableExists('bc_transactions')) {
			return 0;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'c'))
			->from('bc_tx_attachments', 'a')
			->innerJoin('a', 'bc_transactions', 't', $qb->expr()->eq('a.transaction_id', 't.id'))
			->where($qb->expr()->eq('t.workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['c'] ?? 0);
	}

	private function countBillableStatus(int $workspaceId, string $status): int
	{
		if (!$this->db->tableExists('bc_transactions')) {
			return 0;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'c'))
			->from('bc_transactions')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('is_billable', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('billing_status', $qb->createNamedParameter($status)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['c'] ?? 0);
	}

	/**
	 * @return list<string>
	 */
	private function listMemberUserIds(int $workspaceId): array
	{
		if (!$this->db->tableExists('bc_workspace_members')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id')
			->from('bc_workspace_members')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)));
		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$uid = (string)($row['user_id'] ?? '');
			if ($uid !== '') {
				$out[] = $uid;
			}
		}
		$result->closeCursor();
		return array_values(array_unique($out));
	}

	private function deleteByWorkspaceId(string $table, int $workspaceId): void
	{
		if (!$this->db->tableExists($table)) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete($table)
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * @param list<string> $userIds
	 */
	private function pruneUserPointers(array $userIds, int $workspaceId): void
	{
		foreach ($userIds as $uid) {
			if ($uid === '') {
				continue;
			}
			$this->access->forgetLastUsedWorkspace($uid, $workspaceId);
			$this->access->removeFavoriteWorkspaceId($uid, $workspaceId);
			$this->importPreferences->clearForUserWorkspace($uid, $workspaceId);
			$this->summaryViewPrefs->clearForUserWorkspace($uid, $workspaceId);
		}
	}
}
