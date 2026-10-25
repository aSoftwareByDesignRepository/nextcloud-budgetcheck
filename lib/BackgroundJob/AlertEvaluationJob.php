<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\BackgroundJob;

use OCA\BudgetCheck\Service\AlertDispatchService;
use OCA\BudgetCheck\Service\AlertEvaluationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Daily alert sweep (spec: alert-notifications MH-04): budget thresholds,
 * monthly digest, savings status — plus alert-log retention pruning.
 * Workspaces are batched; per-member sends honor prefs + dedupe inside the
 * evaluation service. A workspace that throws never fails the whole job.
 */
final class AlertEvaluationJob extends TimedJob
{
	private const BATCH = 200;

	public function __construct(
		ITimeFactory $time,
		private IDBConnection $db,
		private AlertEvaluationService $evaluator,
		private AlertDispatchService $dispatch,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 60 * 60);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void
	{
		$now = new \DateTimeImmutable('now');
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')
				->from('bc_workspaces')
				->where($qb->expr()->eq('is_active', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
				->andWhere($qb->expr()->eq('type', $qb->createNamedParameter('household')))
				->setMaxResults(self::BATCH);
			$result = $qb->executeQuery();
			$workspaceIds = [];
			while ($row = $result->fetch()) {
				$workspaceIds[] = (int)$row['id'];
			}
			$result->closeCursor();

			$sent = 0;
			foreach ($workspaceIds as $wid) {
				try {
					$sent += (int)($this->evaluator->evaluateWorkspace($wid, $now)['sent'] ?? 0);
				} catch (\Throwable $e) {
					$this->logger->info('budgetcheck alert eval skipped workspace', [
						'workspaceId' => $wid,
						'exception' => $e,
					]);
				}
			}
			$pruned = $this->dispatch->prune();
			if ($sent > 0 || $pruned > 0) {
				$this->logger->info('BudgetCheck alert sweep finished', ['sent' => $sent, 'pruned' => $pruned]);
			}
		} catch (\Throwable $e) {
			$this->logger->error('BudgetCheck alert sweep failed: ' . $e->getMessage(), ['exception' => $e]);
		}
	}
}
