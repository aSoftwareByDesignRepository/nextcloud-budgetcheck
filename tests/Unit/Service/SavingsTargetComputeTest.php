<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\SavingsTargetService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Pins §9 savings target modes: percentage, absolute, hybrid (max of both).
 */
final class SavingsTargetComputeTest extends TestCase
{
	private SavingsTargetService $svc;

	protected function setUp(): void
	{
		// computeTargetValue needs no collaborators; construct with nulls via
		// reflection-free stub: only Money/Access/DB are unused by compute.
		$this->svc = (new \ReflectionClass(SavingsTargetService::class))
			->newInstanceWithoutConstructor();
	}

	public function testNullTargetIsZero(): void
	{
		self::assertSame(0, $this->svc->computeTargetValue(null, 100_000));
	}

	public function testPercentageOfIncome(): void
	{
		// 10% of 100000 = 10000 (banker's round half-even on bp math)
		$target = [
			'targetMode' => SavingsTargetService::MODE_PERCENTAGE,
			'targetPercentBp' => 1000,
			'targetMinor' => null,
		];
		self::assertSame(10_000, $this->svc->computeTargetValue($target, 100_000));
	}

	public function testPercentageZeroIncomeIsZero(): void
	{
		$target = [
			'targetMode' => SavingsTargetService::MODE_PERCENTAGE,
			'targetPercentBp' => 2500,
			'targetMinor' => null,
		];
		self::assertSame(0, $this->svc->computeTargetValue($target, 0));
	}

	public function testAbsoluteIgnoresIncome(): void
	{
		$target = [
			'targetMode' => SavingsTargetService::MODE_ABSOLUTE,
			'targetPercentBp' => null,
			'targetMinor' => 42_00,
		];
		self::assertSame(42_00, $this->svc->computeTargetValue($target, 999_999));
	}

	public function testAbsoluteAcceptsEnvelopeShape(): void
	{
		$target = [
			'targetMode' => SavingsTargetService::MODE_ABSOLUTE,
			'targetPercentBp' => null,
			'targetMinor' => ['minor' => 15_00, 'currency' => 'EUR'],
		];
		self::assertSame(15_00, $this->svc->computeTargetValue($target, 0));
	}

	public function testHybridPicksMaxOfPercentAndAbsolute(): void
	{
		// 5% of 100000 = 5000; absolute 8000 → hybrid 8000
		$target = [
			'targetMode' => SavingsTargetService::MODE_HYBRID,
			'targetPercentBp' => 500,
			'targetMinor' => 8_000,
		];
		self::assertSame(8_000, $this->svc->computeTargetValue($target, 100_000));

		// 20% of 100000 = 20000; absolute 8000 → hybrid 20000
		$target['targetPercentBp'] = 2000;
		self::assertSame(20_000, $this->svc->computeTargetValue($target, 100_000));
	}

	public function testUnknownModeIsZero(): void
	{
		$target = [
			'targetMode' => 'bogus',
			'targetPercentBp' => 5000,
			'targetMinor' => 9_999,
		];
		self::assertSame(0, $this->svc->computeTargetValue($target, 100_000));
	}

	public function testValidateModeRejectsUnknown(): void
	{
		$method = new ReflectionMethod(SavingsTargetService::class, 'validateMode');
		$method->setAccessible(true);
		$this->expectException(\InvalidArgumentException::class);
		$method->invoke($this->svc, 'weekly');
	}

	public function testValidateYearMonthRejectsGarbage(): void
	{
		$method = new ReflectionMethod(SavingsTargetService::class, 'validateYearMonth');
		$method->setAccessible(true);
		$this->expectException(\InvalidArgumentException::class);
		$method->invoke($this->svc, '2026-13');
	}
}
