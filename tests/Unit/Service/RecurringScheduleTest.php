<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Exception\InternalErrorException;
use OCA\BudgetCheck\Service\MoneyService;
use OCA\BudgetCheck\Service\RecurringRuleService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Pure-logic coverage for the "specific dates" (schedule) recurring rules
 * introduced for issue #11: payload normalisation, defensive decoding,
 * occurrence lookup, and per-date amount resolution.
 */
final class RecurringScheduleTest extends TestCase
{
	// ---- decodeSchedule ------------------------------------------------

	public function testDecodeScheduleParsesSortsAndDeduplicates(): void
	{
		$json = json_encode([
			['date' => '2026-11-20', 'amountMinor' => 30000],
			['date' => '2026-06-15', 'amountMinor' => null],
			['date' => '2026-08-03', 'amountMinor' => 75000],
			['date' => '2026-08-03', 'amountMinor' => 99999],
		]);
		$schedule = RecurringRuleService::decodeSchedule($json);
		$this->assertSame(['2026-06-15', '2026-08-03', '2026-11-20'], array_column($schedule, 'date'));
		$this->assertNull($schedule[0]['amountMinor']);
	}

	public function testDecodeScheduleRejectsGarbageInput(): void
	{
		$this->assertSame([], RecurringRuleService::decodeSchedule(null));
		$this->assertSame([], RecurringRuleService::decodeSchedule(''));
		$this->assertSame([], RecurringRuleService::decodeSchedule('not json'));
		$this->assertSame([], RecurringRuleService::decodeSchedule('"a string"'));
		$this->assertSame([], RecurringRuleService::decodeSchedule('{"date":"2026-01-01"}'));
	}

	public function testDecodeScheduleDropsInvalidCalendarDaysAndBadAmounts(): void
	{
		$json = json_encode([
			['date' => '2026-02-30', 'amountMinor' => 100],
			['date' => '2026-13-01', 'amountMinor' => 100],
			['date' => 'nonsense', 'amountMinor' => 100],
			['date' => '2026-02-28', 'amountMinor' => -5],
			['date' => '2026-03-01', 'amountMinor' => 'abc'],
			['date' => '2026-04-01', 'amountMinor' => 4200],
		]);
		$schedule = RecurringRuleService::decodeSchedule($json);
		$this->assertSame(['2026-02-28', '2026-03-01', '2026-04-01'], array_column($schedule, 'date'));
		$this->assertNull($schedule[0]['amountMinor']); // negative override discarded
		$this->assertNull($schedule[1]['amountMinor']); // non-int override discarded
		$this->assertSame(4200, $schedule[2]['amountMinor']);
	}

	public function testEncodeDecodeRoundTrip(): void
	{
		$schedule = [
			['date' => '2026-06-15', 'amountMinor' => null],
			['date' => '2026-08-03', 'amountMinor' => 75000],
		];
		$this->assertSame($schedule, RecurringRuleService::decodeSchedule(RecurringRuleService::encodeSchedule($schedule)));
	}

	// ---- occurrence lookup ---------------------------------------------

	public function testNextScheduleDateOnOrAfterAndStrictlyAfter(): void
	{
		$schedule = [
			['date' => '2026-06-15', 'amountMinor' => null],
			['date' => '2026-08-03', 'amountMinor' => 75000],
			['date' => '2026-11-20', 'amountMinor' => 30000],
		];
		$this->assertSame('2026-06-15', RecurringRuleService::nextScheduleDateOnOrAfter($schedule, '2026-01-01'));
		$this->assertSame('2026-06-15', RecurringRuleService::nextScheduleDateOnOrAfter($schedule, '2026-06-15'));
		$this->assertSame('2026-08-03', RecurringRuleService::nextScheduleDateOnOrAfter($schedule, '2026-06-16'));
		$this->assertNull(RecurringRuleService::nextScheduleDateOnOrAfter($schedule, '2026-11-21'));

		$this->assertSame('2026-08-03', RecurringRuleService::nextScheduleDateAfter($schedule, '2026-06-15'));
		$this->assertSame('2026-11-20', RecurringRuleService::nextScheduleDateAfter($schedule, '2026-08-03'));
		$this->assertNull(RecurringRuleService::nextScheduleDateAfter($schedule, '2026-11-20'));
	}

	// ---- per-date amounts ----------------------------------------------

	public function testOccurrenceAmountFallsBackToDefault(): void
	{
		$schedule = [
			['date' => '2026-06-15', 'amountMinor' => null],
			['date' => '2026-08-03', 'amountMinor' => 75000],
		];
		$this->assertSame(50000, RecurringRuleService::occurrenceAmountMinor($schedule, '2026-06-15', 50000));
		$this->assertSame(75000, RecurringRuleService::occurrenceAmountMinor($schedule, '2026-08-03', 50000));
		// unknown date and interval rules (null schedule) use the default
		$this->assertSame(50000, RecurringRuleService::occurrenceAmountMinor($schedule, '2026-09-09', 50000));
		$this->assertSame(50000, RecurringRuleService::occurrenceAmountMinor(null, '2026-06-15', 50000));
	}

	// ---- scheduleForRow --------------------------------------------------

	public function testScheduleForRowReturnsNullForIntervalRules(): void
	{
		$this->assertNull(RecurringRuleService::scheduleForRow([
			'frequency' => 'monthly',
			'schedule_json' => null,
		]));
	}

	public function testScheduleForRowThrowsOnCorruptScheduleRule(): void
	{
		$this->expectException(InternalErrorException::class);
		RecurringRuleService::scheduleForRow([
			'frequency' => 'schedule',
			'schedule_json' => 'broken',
		]);
	}

	// ---- normaliseSchedulePayload ----------------------------------------

	public function testNormalisePayloadSortsAndParsesHumanAmounts(): void
	{
		$result = $this->normalise([
			['date' => '2026-11-20', 'amount' => '300'],
			['date' => '2026-06-15', 'amount' => ''],
			['date' => '2026-08-03', 'amount' => '1.234,56'],
		]);
		$this->assertSame([
			['date' => '2026-06-15', 'amountMinor' => null],
			['date' => '2026-08-03', 'amountMinor' => 123456],
			['date' => '2026-11-20', 'amountMinor' => 30000],
		], $result);
	}

	public function testNormalisePayloadRejectsEmptyList(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->normalise([]);
	}

	public function testNormalisePayloadRejectsDuplicateDates(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/same date twice/');
		$this->normalise([
			['date' => '2026-06-15'],
			['date' => '2026-06-15'],
		]);
	}

	public function testNormalisePayloadRejectsInvalidDate(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->normalise([
			['date' => '2026-02-30'],
		]);
	}

	public function testNormalisePayloadRejectsTooManyEntries(): void
	{
		$entries = [];
		$day = new \DateTimeImmutable('2026-01-01', new \DateTimeZone('UTC'));
		for ($i = 0; $i <= RecurringRuleService::MAX_SCHEDULE_ENTRIES; $i++) {
			$entries[] = ['date' => $day->modify('+' . $i . ' days')->format('Y-m-d')];
		}
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/at most/');
		$this->normalise($entries);
	}

	public function testNormalisePayloadRejectsNegativeAmount(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->normalise([
			['date' => '2026-06-15', 'amount' => '-10'],
		]);
	}

	public function testParseIsoDateRejectsInvalidCalendarDay(): void
	{
		$service = new RecurringRuleService(
			$this->createMock(\OCP\IDBConnection::class),
			$this->createMock(\OCA\BudgetCheck\Service\AccessControlService::class),
			new MoneyService(),
			$this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class),
			$this->createMock(\OCA\BudgetCheck\Service\AuditLogService::class),
		);
		$method = new ReflectionMethod(RecurringRuleService::class, 'parseIsoDate');
		$method->setAccessible(true);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('startDate is not a valid date.');
		$method->invoke($service, '2026-02-30', 'startDate');
	}

	public function testParseIsoDateAcceptsValidDay(): void
	{
		$service = new RecurringRuleService(
			$this->createMock(\OCP\IDBConnection::class),
			$this->createMock(\OCA\BudgetCheck\Service\AccessControlService::class),
			new MoneyService(),
			$this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class),
			$this->createMock(\OCA\BudgetCheck\Service\AuditLogService::class),
		);
		$method = new ReflectionMethod(RecurringRuleService::class, 'parseIsoDate');
		$method->setAccessible(true);
		$parsed = $method->invoke($service, '2026-02-28', 'startDate');
		$this->assertSame('2026-02-28', $parsed->format('Y-m-d'));
	}

	/**
	 * @return list<array{date:string, amountMinor:int|null}>
	 */
	private function normalise(array $payload): array
	{
		$service = new RecurringRuleService(
			$this->createMock(\OCP\IDBConnection::class),
			$this->createMock(\OCA\BudgetCheck\Service\AccessControlService::class),
			new MoneyService(),
			$this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class),
			$this->createMock(\OCA\BudgetCheck\Service\AuditLogService::class),
		);
		$method = new ReflectionMethod(RecurringRuleService::class, 'normaliseSchedulePayload');
		$method->setAccessible(true);
		return $method->invoke($service, $payload, 2);
	}
}
