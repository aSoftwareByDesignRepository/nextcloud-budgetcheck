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
		// Critical for the JS layer: must be a list (numerically indexed) with
		// {label, items}, not an associative map. Otherwise json_encode emits
		// `{}` and Array.prototype.forEach silently skips everything.
		$this->assertSame(0, array_key_first($grouped));
		foreach ($grouped as $row) {
			$this->assertIsArray($row);
			$this->assertArrayHasKey('label', $row);
			$this->assertArrayHasKey('items', $row);
			$this->assertIsString($row['label']);
			$this->assertIsArray($row['items']);
			$this->assertNotEmpty($row['items']);
			foreach ($row['items'] as $tz) {
				$this->assertIsString($tz);
				$this->assertContains($tz, \DateTimeZone::listIdentifiers(), 'TimezoneCatalog must only emit valid IANA identifiers.');
			}
		}
	}

	public function testFlatContainsEverything(): void
	{
		$cat = new TimezoneCatalog();
		$flat = $cat->flat();
		$this->assertContains('UTC', $flat);
		$this->assertContains('Europe/Berlin', $flat);
	}

	public function testIsValidAcceptsKnownAndAnyIanaZone(): void
	{
		$cat = new TimezoneCatalog();
		$this->assertTrue($cat->isValid('Europe/Berlin'));
		$this->assertTrue($cat->isValid('UTC'));
		// Pacific/Tarawa is not in our curated list but is a valid IANA id.
		$this->assertTrue($cat->isValid('Pacific/Tarawa'));
		$this->assertFalse($cat->isValid('Mars/Olympus_Mons'));
	}
}
