<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Integration;

use OCA\BudgetCheck\Repair\SeedPlannedForecastDemo;
use OCA\BudgetCheck\Service\BudgetPlannedService;
use OCA\BudgetCheck\Service\BudgetService;
use OCA\BudgetCheck\Service\CategoryService;
use OCA\BudgetCheck\Service\SummaryService;
use OCA\BudgetCheck\Service\WorkspaceService;
use Test\TestCase;

/**
 * End-to-end planned-forecast path: seed demo data, then verify SummaryService output.
 */
final class PlannedForecastDemoIntegrationTest extends TestCase
{
	private const USER = 'root';

	protected function setUp(): void
	{
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud is not bootstrapped (run inside Docker with NEXTCLOUD_ROOT).');
		}
	}

	public function testSeededJulySummaryExposesPlannedForecastSeparatelyFromCashFlow(): void
	{
		$workspaceId = $this->resolveDemoWorkspaceId();
		$seeder = $this->seeder();
		$result = $seeder->run($workspaceId, self::USER);

		$july = $result['julySummary'];
		$this->assertSame('2026-07', $july['yearMonth']);
		$this->assertSame(0, $july['totals']['incomeMinor'], 'Cash flow income must exclude planned placeholders.');
		$this->assertSame(5_000_00, $july['planned']['incomeTargetMinor']);
		$this->assertSame(5_000_00, $july['planned']['ledgerIncomeMinor']);
		$this->assertSame(1_900_00, $july['planned']['ledgerExpenseMinor']);
		$this->assertSame(3, $july['planned']['entryCount']);
		$this->assertSame(1_900_00, $july['budgetPlannedMinor']);
		$this->assertSame(3, $july['activity']['plannedCount'] ?? -1);
		$this->assertGreaterThanOrEqual(0, $july['activity']['count'] ?? -1);

		$august = $this->summary()->household($workspaceId, self::USER, '2026-08');
		$this->assertSame(5_000_00, (int)($august['planned']['incomeTarget']['minor'] ?? 0));
		$this->assertSame(3, (int)($august['planned']['ledger']['entryCount'] ?? 0));
		$this->assertSame(0, (int)($august['totals']['income']['minor'] ?? -1));
	}

	private function resolveDemoWorkspaceId(): int
	{
		$qb = \OC::$server->get(\OCP\IDBConnection::class)->getQueryBuilder();
		$qb->select('id')
			->from('bc_workspaces')
			->where($qb->expr()->eq('type', $qb->createNamedParameter('household')))
			->orderBy('id', 'ASC')
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if ($row === false) {
			$this->markTestSkipped('No household workspace in database.');
		}
		return (int)$row['id'];
	}

	private function seeder(): SeedPlannedForecastDemo
	{
		$server = \OC::$server;
		return new SeedPlannedForecastDemo(
			$server->get(\OCP\IDBConnection::class),
			$server->get(WorkspaceService::class),
			$server->get(CategoryService::class),
			$server->get(BudgetService::class),
			$server->get(BudgetPlannedService::class),
			$server->get(SummaryService::class),
		);
	}

	private function summary(): SummaryService
	{
		return \OC::$server->get(SummaryService::class);
	}
}
