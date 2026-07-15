<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\AccessControlService;
use OCA\BudgetCheck\Service\BudgetService;
use OCA\BudgetCheck\Service\MoneyService;
use OCA\BudgetCheck\Service\PlannedTransactionMatchService;
use OCA\BudgetCheck\Service\TransactionService;
use PHPUnit\Framework\TestCase;

/**
 * The month-close explainer promises: "New, edit, and delete are blocked until
 * a manager reopens the month." These tests pin the create/update/budget write
 * gates so the promise holds server-side, not just in the UI.
 */
final class ClosedMonthWriteLockTest extends TestCase
{
	private const WORKSPACE = [
		'id' => 7,
		'type' => 'household',
		'currencyCode' => 'EUR',
		'taxModeEnabled' => false,
	];

	private const CATEGORY = [
		'id' => 11,
		'name' => 'Groceries',
		'type' => 'expense',
		'isActive' => true,
	];

	public function testCreateRealBookingRejectedInClosedMonth(): void
	{
		$svc = $this->newTransactionService(closedMonths: ['2026-06']);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('closed month');
		$svc->create(7, 'alice', [
			'direction' => 'expense',
			'bookingDate' => '2026-06-15',
			'amountMinor' => 1250,
			'title' => 'REWE',
		], self::WORKSPACE, self::CATEGORY);
	}

	public function testCreatePlannedEntryRejectedInClosedMonth(): void
	{
		$svc = $this->newTransactionService(closedMonths: ['2026-06']);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Month is closed');
		$svc->create(7, 'alice', [
			'direction' => 'expense',
			'bookingDate' => '2026-06-01',
			'amountMinor' => 1250,
			'title' => 'Groceries',
			'isPlanned' => true,
			'budgetId' => 3,
		], self::WORKSPACE, self::CATEGORY);
	}

	public function testCreateAllowedInOpenMonthUpToClosedGate(): void
	{
		// With no closed months the create path must get past the lock. The
		// collaborators behind the gate (timeFactory/db) are deliberately left
		// uninitialised, so reaching them raises \Error — while a rejection by
		// the gate itself would raise \InvalidArgumentException instead.
		$svc = $this->newTransactionService(closedMonths: []);

		$this->expectException(\Error::class);
		$svc->create(7, 'alice', [
			'direction' => 'expense',
			'bookingDate' => '2026-06-15',
			'amountMinor' => 1250,
			'title' => 'REWE',
		], self::WORKSPACE, self::CATEGORY);
	}

	public function testUpdateRejectedWhenRowMonthIsClosed(): void
	{
		$svc = $this->newTransactionService(closedMonths: ['2026-06'], row: [
			'id' => 42,
			'workspace_id' => 7,
			'booking_date' => '2026-06-15',
			'deleted_at' => null,
			'version' => 1,
			'direction' => 'expense',
			'category_id' => 11,
		]);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('closed month');
		$svc->update(42, 'alice', ['title' => 'Changed'], self::WORKSPACE);
	}

	public function testUpdateRejectedWhenMovingIntoClosedMonth(): void
	{
		$svc = $this->newTransactionService(closedMonths: ['2026-08'], row: [
			'id' => 42,
			'workspace_id' => 7,
			'booking_date' => '2026-07-10',
			'deleted_at' => null,
			'version' => 1,
			'direction' => 'expense',
			'category_id' => 11,
		]);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('closed month');
		$svc->update(42, 'alice', ['bookingDate' => '2026-08-15'], self::WORKSPACE);
	}

	public function testBudgetBulkUpsertRejectedInClosedMonth(): void
	{
		$svc = $this->newBudgetService(closed: true);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Month is closed');
		$svc->bulkUpsert(7, 'alice', '2026-06', [
			['categoryId' => 11, 'plannedMinor' => 10000],
		], self::WORKSPACE);
	}

	public function testMatchCandidatesInClosedMonthsAreSkipped(): void
	{
		$candidates = [
			['id' => 1, 'version' => 1, 'yearMonth' => '2026-06', 'distance' => 5],
			['id' => 2, 'version' => 1, 'yearMonth' => '2026-07', 'distance' => 9],
		];

		$filtered = PlannedTransactionMatchService::filterCandidatesByClosedMonths(
			$candidates,
			['2026-06' => true],
		);

		$this->assertCount(1, $filtered);
		$this->assertSame(2, $filtered[0]['id']);

		$pick = PlannedTransactionMatchService::pickClosestCandidate($filtered);
		$this->assertNotNull($pick);
		$this->assertSame(2, $pick['id']);
	}

	public function testMatchCandidatesUnchangedWithoutClosedMonths(): void
	{
		$candidates = [
			['id' => 1, 'version' => 1, 'yearMonth' => '2026-06', 'distance' => 5],
		];
		$this->assertSame(
			$candidates,
			PlannedTransactionMatchService::filterCandidatesByClosedMonths($candidates, []),
		);
	}

	/**
	 * @param list<string> $closedMonths
	 * @param array<string,mixed>|null $row
	 */
	private function newTransactionService(array $closedMonths, ?array $row = null): TransactionService
	{
		$svc = new class($closedMonths, $row) extends TransactionService {
			/** @param list<string> $closedMonths */
			public function __construct(private array $closedMonths, private ?array $row)
			{
				// Parent constructor intentionally skipped; collaborators are
				// injected via reflection below.
			}

			protected function monthIsClosed(int $workspaceId, string $yearMonth): bool
			{
				return in_array($yearMonth, $this->closedMonths, true);
			}

			protected function loadRow(int $transactionId): ?array
			{
				return $this->row;
			}
		};

		$this->inject($svc, TransactionService::class, 'access', $this->createMock(AccessControlService::class));
		$this->inject($svc, TransactionService::class, 'money', new MoneyService());

		return $svc;
	}

	private function newBudgetService(bool $closed): BudgetService
	{
		$svc = new class($closed) extends BudgetService {
			public function __construct(private bool $closed)
			{
			}

			protected function monthIsClosed(int $workspaceId, string $yearMonth): bool
			{
				return $this->closed;
			}
		};

		$this->inject($svc, BudgetService::class, 'access', $this->createMock(AccessControlService::class));
		$this->inject($svc, BudgetService::class, 'money', new MoneyService());

		return $svc;
	}

	private function inject(object $instance, string $class, string $property, mixed $value): void
	{
		$prop = new \ReflectionProperty($class, $property);
		$prop->setAccessible(true);
		$prop->setValue($instance, $value);
	}
}
