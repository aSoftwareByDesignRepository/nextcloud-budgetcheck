<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\CurrencyCatalog;
use OCA\BudgetCheck\Service\MoneyService;
use PHPUnit\Framework\TestCase;

final class CurrencyCatalogTest extends TestCase
{
	public function testPinnedIncludesRubUahKzt(): void
	{
		$cat = new CurrencyCatalog();
		foreach (['RUB', 'UAH', 'KZT'] as $code) {
			$this->assertContains($code, $cat->pinned());
			$this->assertTrue($cat->isSupported($code));
		}
	}

	public function testGroupedCoversAllSupportedCodes(): void
	{
		$cat = new CurrencyCatalog();
		$seen = [];
		foreach ($cat->grouped() as $row) {
			$this->assertArrayHasKey('label', $row);
			$this->assertArrayHasKey('items', $row);
			foreach ($row['items'] as $entry) {
				$this->assertArrayHasKey('code', $entry);
				$this->assertArrayHasKey('decimals', $entry);
				$seen[$entry['code']] = true;
			}
		}
		foreach ($cat->codes() as $code) {
			$this->assertArrayHasKey($code, $seen, "Grouped catalog must include {$code}");
		}
	}

	public function testMoneyServiceUsesSameDecimals(): void
	{
		$cat = new CurrencyCatalog();
		$money = new MoneyService($cat);
		$this->assertSame($cat->decimalsFor('JPY'), $money->decimalsFor('JPY'));
		$this->assertSame($cat->decimalsFor('RUB'), $money->decimalsFor('RUB'));
	}

	public function testNormalizeOrThrowRejectsUnsupported(): void
	{
		$cat = new CurrencyCatalog();
		$this->assertSame('EUR', $cat->normalizeOrThrow('eur'));
		$this->expectException(\InvalidArgumentException::class);
		$cat->normalizeOrThrow('XXX');
	}
}
