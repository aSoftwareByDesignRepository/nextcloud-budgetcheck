<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * When a real ledger row arrives (manual entry or bank import), remove a single
 * matching planned placeholder that was generated from a recurring rule.
 *
 * Match keys: workspace, category, direction, exact amount, booking month equal
 * or adjacent (covers salary on the last weekday vs. income counted from the
 * first day of the next month).
 */
final class PlannedTransactionMatchService
{
	public function __construct(
		private IDBConnection $db,
		private AuditLogService $audit,
		private ITimeFactory $timeFactory,
	) {
	}

	public static function calendarMonthsAreAdjacent(string $dateA, string $dateB): bool
	{
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateA) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateB)) {
			return false;
		}
		$ymA = substr($dateA, 0, 7);
		$ymB = substr($dateB, 0, 7);
		if ($ymA === $ymB) {
			return true;
		}
		try {
			$a = new \DateTimeImmutable($ymA . '-01', new \DateTimeZone('UTC'));
			$b = new \DateTimeImmutable($ymB . '-01', new \DateTimeZone('UTC'));
		} catch (\Throwable) {
			return false;
		}
		$monthsA = ((int)$a->format('Y') * 12) + (int)$a->format('n');
		$monthsB = ((int)$b->format('Y') * 12) + (int)$b->format('n');

		return abs($monthsA - $monthsB) === 1;
	}

	/**
	 * @return int|null id of the soft-deleted planned transaction, if any
	 */
	public function replaceMatchingPlanned(
		int $workspaceId,
		string $userId,
		int $categoryId,
		string $direction,
		int $amountMinor,
		string $bookingDate,
		int $realTransactionId,
	): ?int {
		if ($categoryId < 1 || $amountMinor < 1 || $realTransactionId < 1) {
			return null;
		}
		if ($direction !== TransactionService::DIRECTION_INCOME && $direction !== TransactionService::DIRECTION_EXPENSE) {
			return null;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'booking_date', 'version')
			->from('bc_transactions')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('category_id', $qb->createNamedParameter($categoryId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('direction', $qb->createNamedParameter($direction)))
			->andWhere($qb->expr()->eq('amount_minor', $qb->createNamedParameter($amountMinor, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('is_planned', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
			->andWhere($qb->expr()->isNull('deleted_at'));
		$result = $qb->executeQuery();
		$candidates = [];
		while ($row = $result->fetch()) {
			$plannedDate = (string)($row['booking_date'] ?? '');
			if (!self::calendarMonthsAreAdjacent($plannedDate, $bookingDate)) {
				continue;
			}
			$candidates[] = [
				'id' => (int)$row['id'],
				'bookingDate' => $plannedDate,
				'version' => (int)$row['version'],
				'distance' => abs(strtotime($plannedDate) - strtotime($bookingDate)),
			];
		}
		$result->closeCursor();

		if ($candidates === []) {
			return null;
		}

		usort($candidates, static function (array $a, array $b): int {
			$dist = $a['distance'] <=> $b['distance'];
			if ($dist !== 0) {
				return $dist;
			}

			return $a['id'] <=> $b['id'];
		});

		$pick = $candidates[0];
		$this->softDeletePlanned((int)$pick['id'], (int)$pick['version'], $userId, $workspaceId, $realTransactionId);

		return (int)$pick['id'];
	}

	private function softDeletePlanned(int $transactionId, int $version, string $userId, int $workspaceId, int $replacedById): void
	{
		$now = $this->timeFactory->getDateTime('now', new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('bc_transactions')
			->set('deleted_at', $qb->createNamedParameter($now))
			->set('updated_by', $qb->createNamedParameter($userId))
			->set('updated_at', $qb->createNamedParameter($now))
			->set('version', $qb->createNamedParameter($version + 1, \PDO::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($transactionId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('version', $qb->createNamedParameter($version, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('is_planned', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
			->andWhere($qb->expr()->isNull('deleted_at'));
		$affected = $qb->executeStatement();
		if ($affected === 0) {
			return;
		}
		$this->audit->record($userId, 'planned_transaction_replaced', 'transaction', (string)$transactionId, [
			'replacedByTransactionId' => $replacedById,
		], $workspaceId);
	}
}
