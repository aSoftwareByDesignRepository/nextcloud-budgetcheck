<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\MoneyService;
use PHPUnit\Framework\TestCase;

/**
 * Money handling is the most security- and audit-critical part of BudgetCheck.
 * These tests pin the exact arithmetic so refactors cannot silently change
 * rounding, ranges, or parser behaviour.
 */
final class MoneyServiceTest extends TestCase
{
	private MoneyService $money;

	protected function setUp(): void
	{
		$this->money = new MoneyService();
	}

	/** @dataProvider parseHumanCases */
	public function testParseHumanAmountAccepts(string $input, int $expectedMinor, int $decimals): void
	{
		$this->assertSame($expectedMinor, $this->money->parseHumanAmount($input, $decimals));
	}

	/** @return array<string, array{0:string,1:int,2:int}> */
	public static function parseHumanCases(): array
	{
		return [
			'plain integer'        => ['1234', 123_400, 2],
			'one decimal'          => ['1234.5', 123_450, 2],
			'two decimals'         => ['1234.56', 123_456, 2],
			'german thousands'     => ['1.234,56', 123_456, 2],
			'english thousands'    => ['1,234.56', 123_456, 2],
			'space thousands'      => ['1 234,56', 123_456, 2],
			'nbsp thousands'       => ["1\u{00A0}234,56", 123_456, 2],
			'leading zero'         => ['00012,3', 1_230, 2],
			'JPY zero decimals'    => ['1234', 1_234, 0],
			'truncates extra'      => ['1.234,5678', 123_456, 2],
		];
	}

	/** @dataProvider parseRejectCases */
	public function testParseHumanAmountRejects(mixed $input): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->money->parseHumanAmount($input);
	}

	/** @return array<string, array{0:mixed}> */
	public static function parseRejectCases(): array
	{
		return [
			'empty string'     => [''],
			'whitespace only'  => ['   '],
			'negative sign'    => ['-1,00'],
			'positive sign'    => ['+1,00'],
			'currency prefix'  => ['€10,00'],
			'letters'          => ['abc'],
			'scientific'       => ['1e3'],
			'multiple decimal' => ['1.2.3'],
			'array'            => [[]],
			'object'           => [new \stdClass()],
		];
	}

	public function testIntegerInputBypassesParser(): void
	{
		$this->assertSame(50_000, $this->money->parseHumanAmount(50_000));
	}

	public function testIntegerInputEnforcesRange(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->money->parseHumanAmount(0);
	}

	public function testMaxAmountAccepted(): void
	{
		// The integer pathway accepts the cap exactly. The string pathway is
		// covered by `testParseHumanAmountAccepts`; passing the cap as a string
		// to parseHumanAmount would multiply by the currency's minor factor.
		$this->assertSame(MoneyService::MAX_AMOUNT_MINOR, $this->money->parseHumanAmount(MoneyService::MAX_AMOUNT_MINOR));
	}

	public function testMaxAmountPlusOneRejected(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->money->parseHumanAmount(MoneyService::MAX_AMOUNT_MINOR + 1);
	}

	public function testEnvelopeShape(): void
	{
		$out = $this->money->envelope(123_456, 'eur');
		$this->assertSame(['minor' => 123_456, 'currency' => 'EUR', 'decimal' => '1234.56'], $out);
	}

	public function testEnvelopeForJpyHas0Decimals(): void
	{
		$out = $this->money->envelope(1_234, 'JPY');
		$this->assertSame('JPY', $out['currency']);
		$this->assertSame(1_234, $out['minor']);
		$this->assertSame('1234', $out['decimal']);
	}

	public function testToDecimalStringHandlesNegativeAndSmallValues(): void
	{
		$this->assertSame('-0.05', $this->money->toDecimalString(-5, 2));
		$this->assertSame('0.00', $this->money->toDecimalString(0, 2));
		$this->assertSame('-1.23', $this->money->toDecimalString(-123, 2));
	}

	public function testConvertTaxSimple(): void
	{
		$this->assertSame(['net' => 1000, 'vat' => 0, 'gross' => 1000], $this->money->convertTax(1000, 0, 'simple'));
	}

	public function testConvertTaxNet19Percent(): void
	{
		$this->assertSame(['net' => 10_000, 'vat' => 1900, 'gross' => 11_900], $this->money->convertTax(10_000, 1900, 'net'));
	}

	public function testConvertTaxGross19Percent(): void
	{
		$out = $this->money->convertTax(11_900, 1900, 'gross');
		$this->assertSame(10_000, $out['net']);
		$this->assertSame(1900, $out['vat']);
		$this->assertSame(11_900, $out['gross']);
		$this->assertSame($out['gross'], $out['net'] + $out['vat']);
	}

	/**
	 * Banker's rounding (PHP_ROUND_HALF_EVEN) means halves go to the closest
	 * even integer. This guards against silently switching to half-up.
	 */
	public function testConvertTaxBankersRounding(): void
	{
		// 50 minor units net at 1.00% VAT → 0.5 → rounds to 0 (nearest even).
		$this->assertSame(0, $this->money->convertTax(50, 100, 'net')['vat']);
		// 150 minor units net at 1.00% VAT → 1.5 → rounds to 2 (nearest even).
		$this->assertSame(2, $this->money->convertTax(150, 100, 'net')['vat']);
	}

	public function testConvertTaxRejectsNegativeAmount(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->money->convertTax(-1, 1900, 'net');
	}

	public function testConvertTaxRejectsBadVatRate(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->money->convertTax(100, MoneyService::MAX_VAT_RATE_BP + 1, 'net');
	}

	public function testConvertTaxRejectsUnknownBasis(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->money->convertTax(100, 1900, 'unknown');
	}

	public function testVatRatePercentFormatting(): void
	{
		$this->assertSame('0%', $this->money->vatRatePercent(0));
		$this->assertSame('19%', $this->money->vatRatePercent(1900));
		$this->assertSame('19.50%', $this->money->vatRatePercent(1950));
		$this->assertSame('7.05%', $this->money->vatRatePercent(705));
	}
}
