<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Exception\ConflictException;
use OCA\BudgetCheck\Service\AccessControlService;
use OCA\BudgetCheck\Service\AuditLogService;
use OCA\BudgetCheck\Service\MoneyService;
use OCA\BudgetCheck\Service\TransactionService;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Update must refuse omitted/stale versions and race via SQL version CAS.
 */
final class TransactionUpdateCasTest extends TestCase
{
	private const WORKSPACE = [
		'id' => 3,
		'currencyCode' => 'EUR',
		'type' => 'household',
	];

	private const CATEGORY = [
		'id' => 1,
		'name' => 'Food',
		'type' => 'expense',
		'isActive' => true,
	];

	private const ROW = [
		'id' => 9,
		'workspace_id' => 3,
		'version' => 4,
		'deleted_at' => null,
		'booking_date' => '2026-07-10',
		'direction' => 'expense',
		'category_id' => 1,
		'title' => 'Groceries',
		'notes' => null,
		'is_special' => false,
		'external_ref' => null,
		'booking_status_id' => null,
		'amount_minor' => 1250,
		'entry_amount_basis' => 'gross',
		'vat_rate_bp' => 0,
		'net_amount_minor' => 1250,
		'vat_amount_minor' => 0,
		'gross_amount_minor' => 1250,
	];

	public function testMissingVersionThrows(): void
	{
		$svc = $this->newService(self::ROW);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('version is required for updates');
		$svc->update(9, 'alice', ['title' => 'Renamed'], self::WORKSPACE, self::CATEGORY);
	}

	public function testNullVersionThrows(): void
	{
		$svc = $this->newService(self::ROW);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('version is required for updates');
		$svc->update(9, 'alice', ['version' => null, 'title' => 'Renamed'], self::WORKSPACE, self::CATEGORY);
	}

	public function testEmptyVersionThrows(): void
	{
		$svc = $this->newService(self::ROW);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('version is required for updates');
		$svc->update(9, 'alice', ['version' => '', 'title' => 'Renamed'], self::WORKSPACE, self::CATEGORY);
	}

	public function testStaleExpectedVersionThrowsConflict(): void
	{
		$svc = $this->newService(self::ROW);
		$this->expectException(ConflictException::class);
		$svc->update(9, 'alice', ['version' => 3, 'title' => 'Renamed'], self::WORKSPACE, self::CATEGORY);
	}

	public function testSuccessfulUpdateUsesVersionCasAndBumps(): void
	{
		$seen = [];
		$setCols = [];
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static function ($field) use (&$seen) {
			$seen[] = 'eq:' . $field;
			return 'eq:' . $field;
		});
		$qb->method('expr')->willReturn($expr);
		$qb->method('update')->with('bc_transactions')->willReturnSelf();
		$qb->method('set')->willReturnCallback(static function ($col) use (&$setCols, $qb) {
			$setCols[] = $col;
			return $qb;
		});
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($v) => $v);
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->expects($this->once())->method('executeStatement')->willReturn(1);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$audit = $this->createMock(AuditLogService::class);
		$audit->expects($this->once())->method('record');

		$svc = $this->newService(self::ROW, $db, $audit);
		$out = $svc->update(
			9,
			'alice',
			['version' => 4, 'title' => 'Renamed'],
			self::WORKSPACE,
			self::CATEGORY,
		);

		self::assertSame(5, $out['version']);
		self::assertContains('eq:version', $seen);
		self::assertContains('eq:id', $seen);
		self::assertContains('version', $setCols);
		self::assertContains('title', $setCols);
	}

	public function testLostRaceOnWriteThrowsConflict(): void
	{
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$qb->method('expr')->willReturn($expr);
		$qb->method('update')->willReturnSelf();
		$qb->method('set')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($v) => $v);
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('executeStatement')->willReturn(0);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$svc = $this->newService(self::ROW, $db);
		$this->expectException(ConflictException::class);
		$svc->update(
			9,
			'alice',
			['version' => 4, 'title' => 'Renamed'],
			self::WORKSPACE,
			self::CATEGORY,
		);
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function newService(
		array $row,
		?IDBConnection $db = null,
		?AuditLogService $audit = null,
	): TransactionService {
		$svc = new class($row) extends TransactionService {
			/** @param array<string,mixed> $row */
			public function __construct(private array $row)
			{
			}

			protected function monthIsClosed(int $workspaceId, string $yearMonth): bool
			{
				return false;
			}

			protected function loadRow(int $transactionId): ?array
			{
				return $this->row;
			}

			public function loadHydrated(int $transactionId, string $currencyCode): array
			{
				return [
					'id' => $transactionId,
					'version' => ((int)$this->row['version']) + 1,
					'currencyCode' => $currencyCode,
				];
			}
		};

		$this->inject($svc, 'access', $this->createMock(AccessControlService::class));
		$this->inject($svc, 'money', new MoneyService());
		$this->inject($svc, 'db', $db ?? $this->createMock(IDBConnection::class));
		$this->inject($svc, 'audit', $audit ?? $this->createMock(AuditLogService::class));
		$time = $this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-27 12:00:00', new \DateTimeZone('UTC')));
		$this->inject($svc, 'timeFactory', $time);

		return $svc;
	}

	private function inject(object $instance, string $property, mixed $value): void
	{
		$prop = new \ReflectionProperty(TransactionService::class, $property);
		$prop->setAccessible(true);
		$prop->setValue($instance, $value);
	}
}
