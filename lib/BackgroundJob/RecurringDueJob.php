<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\BackgroundJob;

use OCA\BudgetCheck\Service\CategoryService;
use OCA\BudgetCheck\Service\RecurringRuleService;
use OCA\BudgetCheck\Service\TransactionService;
use OCA\BudgetCheck\Service\UpgradeBackupService;
use OCA\BudgetCheck\Service\WorkspaceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Auto-book due recurring rules in `book` posting mode (issue #18).
 *
 * Runs hourly. Plan-mode rules are never touched — placeholders stay explicit.
 * Each rule is processed under FOR UPDATE inside {@see RecurringRuleService::generate}
 * so concurrent UI Generate / job sweeps cannot double-post the same date.
 */
final class RecurringDueJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private RecurringRuleService $recurring,
		private TransactionService $transactions,
		private CategoryService $categories,
		private WorkspaceService $workspaces,
		private LoggerInterface $logger,
		private ILockingProvider $locking,
	) {
		parent::__construct($time);
		$this->setInterval(60 * 60);
	}

	protected function run($argument): void
	{
		// Shared lock on the backup key: a forced restore holds it exclusively —
		// the sweep skips rather than posting transactions into tables
		// mid-restore (writes between truncate and insert are lost).
		try {
			$this->locking->acquireLock(
				UpgradeBackupService::LOCK_KEY,
				ILockingProvider::LOCK_SHARED,
				'BudgetCheck recurring due sweep',
			);
		} catch (LockedException) {
			$this->logger->info('BudgetCheck: skipping recurring due sweep — upgrade backup/restore in progress.');
			return;
		}
		try {
			$this->runSweep();
		} finally {
			$this->locking->releaseLock(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED);
		}
	}

	private function runSweep(): void
	{
		try {
			$summary = $this->recurring->processDueBookRules(
				$this->transactions,
				$this->categories,
				$this->workspaces,
			);
			if (($summary['generated'] ?? 0) > 0 || ($summary['errors'] ?? 0) > 0) {
				$this->logger->info('BudgetCheck recurring due sweep finished', $summary);
			}
		} catch (\Throwable $e) {
			// best-effort: job-level catch — the sweep is idempotent and the next
			// cron run retries; a throw here would only mark this run failed
			// without changing what the next run does.
			$this->logger->error('BudgetCheck recurring due sweep failed: ' . $e->getMessage(), [
				'exception' => $e,
			]);
		}
	}
}
