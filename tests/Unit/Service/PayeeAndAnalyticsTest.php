<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\AnalyticsService;
use OCA\BudgetCheck\Service\PayeeService;
use PHPUnit\Framework\TestCase;

final class PayeeAndAnalyticsTest extends TestCase
{
	public function testNormalizeNameCollapsesCaseWhitespaceAndCasefold(): void
	{
		self::assertSame('aldi süd', PayeeService::normalizeName('  ALDI   SÜD '));
		self::assertSame('rewe markt', PayeeService::normalizeName('Rewe Markt'));
		self::assertSame('', PayeeService::normalizeName('   '));
	}

	public function testNormalizeNameIsMultibyteSafe(): void
	{
		$norm = PayeeService::normalizeName('MÜLLER');
		self::assertSame($norm, PayeeService::normalizeName(mb_strtolower('MÜLLER')));
	}

	public function testEffectiveAmountSimpleBasisPassesThrough(): void
	{
		$ws = ['taxModeEnabled' => true, 'taxBudgetBasis' => 'net'];
		$row = ['amount_minor' => 11900, 'entry_amount_basis' => 'simple', 'net_amount_minor' => null, 'gross_amount_minor' => null];
		self::assertSame(11900, AnalyticsService::effectiveAmountMinor($ws, $row));
	}

	public function testEffectiveAmountNetBasisUsesNetWhenPresent(): void
	{
		$ws = ['taxModeEnabled' => true, 'taxBudgetBasis' => 'net'];
		$row = ['amount_minor' => 11900, 'entry_amount_basis' => 'net', 'net_amount_minor' => 10000, 'gross_amount_minor' => 11900];
		self::assertSame(10000, AnalyticsService::effectiveAmountMinor($ws, $row));
	}

	public function testEffectiveAmountGrossBasisUsesGrossWhenPresent(): void
	{
		$ws = ['taxModeEnabled' => true, 'taxBudgetBasis' => 'gross'];
		$row = ['amount_minor' => 10000, 'entry_amount_basis' => 'gross', 'net_amount_minor' => 10000, 'gross_amount_minor' => 11900];
		self::assertSame(11900, AnalyticsService::effectiveAmountMinor($ws, $row));
	}

	public function testEffectiveAmountFallsBackToAmountWhenSplitMissing(): void
	{
		$ws = ['taxModeEnabled' => true, 'taxBudgetBasis' => 'net'];
		$row = ['amount_minor' => 11900, 'entry_amount_basis' => 'net', 'net_amount_minor' => null, 'gross_amount_minor' => null];
		self::assertSame(11900, AnalyticsService::effectiveAmountMinor($ws, $row));
	}

	public function testEffectiveAmountIgnoresSplitWhenTaxModeOff(): void
	{
		$ws = ['taxModeEnabled' => false, 'taxBudgetBasis' => 'net'];
		$row = ['amount_minor' => 11900, 'entry_amount_basis' => 'net', 'net_amount_minor' => 10000, 'gross_amount_minor' => 11900];
		self::assertSame(11900, AnalyticsService::effectiveAmountMinor($ws, $row));
	}
}
