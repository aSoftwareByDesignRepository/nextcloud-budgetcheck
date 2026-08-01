<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\MoneyService;
use OCA\BudgetCheck\Service\SavingsTargetService;
use PHPUnit\Framework\TestCase;

/**
 * Pure savings-target computation + mode validation (no DB).
 */
final class SavingsTargetServiceTest extends TestCase
{
	public function testComputePercentageOfIncome(): void
	{
		$svc = self::newService();
		$value = $svc->computeTargetValue([
			'targetMode' => SavingsTargetService::MODE_PERCENTAGE,
			'targetPercentBp' => 1000, // 10%
			'targetMinor' => null,
		], 50_000);
		self::assertSame(5_000, $value);
	}

	public function testComputeAbsoluteIgnoresIncome(): void
	{
		$svc = self::newService();
		$value = $svc->computeTargetValue([
			'targetMode' => SavingsTargetService::MODE_ABSOLUTE,
			'targetPercentBp' => null,
			'targetMinor' => 12_345,
		], 99_999);
		self::assertSame(12_345, $value);
	}

	public function testComputeHybridPicksMax(): void
	{
		$svc = self::newService();
		// 10% of 100000 = 10000; absolute 15000 → max 15000
		$value = $svc->computeTargetValue([
			'targetMode' => SavingsTargetService::MODE_HYBRID,
			'targetPercentBp' => 1000,
			'targetMinor' => 15_000,
		], 100_000);
		self::assertSame(15_000, $value);

		// 20% of 100000 = 20000; absolute 15000 → max 20000
		$value2 = $svc->computeTargetValue([
			'targetMode' => SavingsTargetService::MODE_HYBRID,
			'targetPercentBp' => 2000,
			'targetMinor' => 15_000,
		], 100_000);
		self::assertSame(20_000, $value2);
	}

	public function testComputeNullTargetIsZero(): void
	{
		$svc = self::newService();
		self::assertSame(0, $svc->computeTargetValue(null, 10_000));
	}

	public function testEnvelopeAbsoluteMinorSupported(): void
	{
		$svc = self::newService();
		$value = $svc->computeTargetValue([
			'targetMode' => SavingsTargetService::MODE_ABSOLUTE,
			'targetMinor' => ['minor' => 777],
		], 1);
		self::assertSame(777, $value);
	}

	public function testValidateModeRejectsUnknown(): void
	{
		$svc = self::newService();
		$ref = new \ReflectionMethod(SavingsTargetService::class, 'validateMode');
		$ref->setAccessible(true);
		$this->expectException(\InvalidArgumentException::class);
		$ref->invoke($svc, 'bogus');
	}

	public function testPercentageModeRequiresPercent(): void
	{
		$svc = self::newService();
		$access = new \ReflectionProperty(SavingsTargetService::class, 'access');
		$access->setAccessible(true);
		$accessMock = $this->createMock(\OCA\BudgetCheck\Service\AccessControlService::class);
		$accessMock->expects($this->once())->method('ensureMinimumRole');
		$access->setValue($svc, $accessMock);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('targetPercentBp is required');
		$svc->save(1, 'alice', [
			'yearMonth' => '2026-07',
			'targetMode' => SavingsTargetService::MODE_PERCENTAGE,
		], 'EUR');
	}

	private static function newService(): SavingsTargetService
	{
		$ref = new \ReflectionClass(SavingsTargetService::class);
		/** @var SavingsTargetService $svc */
		$svc = $ref->newInstanceWithoutConstructor();
		$money = new \ReflectionProperty(SavingsTargetService::class, 'money');
		$money->setAccessible(true);
		$money->setValue($svc, new MoneyService());
		return $svc;
	}
}
