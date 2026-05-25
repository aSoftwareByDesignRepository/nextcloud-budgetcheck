<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\TimezoneCatalog;
use PHPUnit\Framework\TestCase;

final class TimezoneCatalogTest extends TestCase
{
	public function testGroupedReturnsListOfLabelItemRecords(): void
	{
		$cat = new TimezoneCatalog();
		$grouped = $cat->grouped();
		$this->assertNotEmpty($grouped);
		$this->assertSame(0, array_key_first($grouped));
		$flatCount = 0;
		foreach ($grouped as $row) {
			$this->assertIsArray($row);
			$this->assertArrayHasKey('label', $row);
			$this->assertArrayHasKey('items', $row);
			$this->assertIsString($row['label']);
			$this->assertIsArray($row['items']);
			$this->assertNotEmpty($row['items']);
			foreach ($row['items'] as $tz) {
				$this->assertIsString($tz);
				$this->assertTrue($cat->isValid($tz));
				$flatCount++;
			}
		}
		$this->assertSame(count($cat->all()), $flatCount);
	}

	public function testPinnedIncludesMoscowYekaterinburgTashkent(): void
	{
		$cat = new TimezoneCatalog();
		$pinned = $cat->pinned();
		foreach (['Europe/Moscow', 'Asia/Yekaterinburg', 'Asia/Tashkent'] as $required) {
			$this->assertContains($required, $pinned);
			$this->assertTrue($cat->isValid($required));
		}
	}

	public function testIsValidAcceptsAnyIanaZone(): void
	{
		$cat = new TimezoneCatalog();
		$this->assertTrue($cat->isValid('Pacific/Tarawa'));
		$this->assertFalse($cat->isValid('Mars/Olympus_Mons'));
	}

	public function testNormalizeOrThrow(): void
	{
		$cat = new TimezoneCatalog();
		$this->assertSame('Europe/Berlin', $cat->normalizeOrThrow('  Europe/Berlin  '));
		$this->expectException(\InvalidArgumentException::class);
		$cat->normalizeOrThrow('Not/A/Zone');
	}
}
