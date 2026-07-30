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
 * Delete must refuse stale versions and race with concurrent updates via CAS.
 */
final class TransactionDeleteCasTest extends TestCase
{
	private const WORKSPACE = ['id' => 3];

	private const ROW = [
		'id' => 9,
		'workspace_id' => 3,
		'version' => 4,
		'deleted_at' => null,
		'booking_date' => '2026-07-10',
	];

	public function testMissingExpectedVersionThrows(): void
	{
		$svc = $this->newService(self::ROW);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('version is required');
		$svc->delete(9, 'alice', self::WORKSPACE, null);
	}

	public function testStaleExpectedVersionThrowsConflict(): void
	{
		$svc = $this->newService(self::ROW);
		$this->expectException(ConflictException::class);
		$svc->delete(9, 'alice', self::WORKSPACE, 3);
	}

	public function testSuccessfulDeleteUsesVersionAndDeletedAtCas(): void
	{
		$seen = [];
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static function ($field) use (&$seen) {
			$seen[] = 'eq:' . $field;
			return 'eq:' . $field;
		});
		$expr->method('isNull')->willReturnCallback(static function ($field) use (&$seen) {
			$seen[] = 'null:' . $field;
			return 'null:' . $field;
		});
		$qb->method('expr')->willReturn($expr);
		$qb->method('update')->with('bc_transactions')->willReturnSelf();
		$qb->method('set')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($v) => $v);
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->expects($this->once())->method('executeStatement')->willReturn(1);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$audit = $this->createMock(AuditLogService::class);
		$audit->expects($this->once())->method('record');

		$svc = $this->newService(self::ROW, $db, $audit);
		self::assertTrue($svc->delete(9, 'alice', self::WORKSPACE, 4));
		self::assertContains('eq:version', $seen);
		self::assertContains('null:deleted_at', $seen);
	}

	public function testLostRaceOnWriteThrowsConflict(): void
	{
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('isNull')->willReturn('null');
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
		$svc->delete(9, 'alice', self::WORKSPACE, 4);
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
		};

		$this->inject($svc, 'access', $this->createMock(AccessControlService::class));
		$this->inject($svc, 'money', new MoneyService());
		$this->inject($svc, 'db', $db ?? $this->createMock(IDBConnection::class));
		$this->inject($svc, 'audit', $audit ?? $this->createMock(AuditLogService::class));
		$this->inject($svc, 'attachments', null);
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
