<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Exception\IdempotencyMismatchException;
use OCA\BudgetCheck\Service\MobileIdempotencyService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class MobileIdempotencyServiceTest extends TestCase
{
	/** @var IDBConnection&MockObject */
	private IDBConnection $db;
	/** @var ITimeFactory&MockObject */
	private ITimeFactory $time;
	private MobileIdempotencyService $service;

	protected function setUp(): void
	{
		$this->db = $this->createMock(IDBConnection::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturn(1_700_000_000);
		$this->service = new MobileIdempotencyService($this->db, $this->time);
	}

	public function testHashPayloadIsStableUnderKeyOrder(): void
	{
		$a = MobileIdempotencyService::hashPayload(['b' => 1, 'a' => 2]);
		$b = MobileIdempotencyService::hashPayload(['a' => 2, 'b' => 1]);
		self::assertSame($a, $b);
		self::assertNotSame(
			$a,
			MobileIdempotencyService::hashPayload(['a' => 2, 'b' => 3])
		);
	}

	public function testFindReplayReturnsNullWhenMissing(): void
	{
		$qb = $this->mockSelectReturning(false);
		$this->db->method('getQueryBuilder')->willReturn($qb);
		self::assertNull($this->service->findReplay('alex', 1, 'key-1', 'hash'));
	}

	public function testFindReplayReturnsBodyOnMatch(): void
	{
		$body = ['ok' => true, 'transaction' => ['id' => 9]];
		$qb = $this->mockSelectReturning([
			'request_hash' => 'abc',
			'response_json' => json_encode($body),
			'http_status' => 200,
		]);
		$this->db->method('getQueryBuilder')->willReturn($qb);
		$replay = $this->service->findReplay('alex', 1, 'key-1', 'abc');
		self::assertNotNull($replay);
		self::assertSame(200, $replay['httpStatus']);
		self::assertSame(9, $replay['body']['transaction']['id']);
	}

	public function testFindReplayThrowsOnHashMismatch(): void
	{
		$qb = $this->mockSelectReturning([
			'request_hash' => 'stored',
			'response_json' => '{"ok":true}',
			'http_status' => 200,
		]);
		$this->db->method('getQueryBuilder')->willReturn($qb);
		$this->expectException(IdempotencyMismatchException::class);
		$this->service->findReplay('alex', 1, 'key-1', 'different');
	}

	public function testRejectsEmptyIdempotencyKey(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->service->findReplay('alex', 1, '  ', 'hash');
	}

	/**
	 * @param array<string,mixed>|false $row
	 */
	private function mockSelectReturning(array|false $row): IQueryBuilder
	{
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn($row);
		$result->method('closeCursor');

		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('executeQuery')->willReturn($result);
		return $qb;
	}
}
