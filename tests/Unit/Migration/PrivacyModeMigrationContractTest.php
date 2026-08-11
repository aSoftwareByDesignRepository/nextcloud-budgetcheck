<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Migration;

use OCA\BudgetCheck\Migration\Version1021Date20260811120000;
use PHPUnit\Framework\TestCase;

final class PrivacyModeMigrationContractTest extends TestCase
{
	public function testMigrationClassExistsAndIsIdempotentGuarded(): void
	{
		self::assertTrue(class_exists(Version1021Date20260811120000::class));
		$src = (string)file_get_contents(
			dirname(__DIR__, 3) . '/lib/Migration/Version1021Date20260811120000.php'
		);
		self::assertStringContainsString('hasTable', $src);
		self::assertStringContainsString('hasColumn', $src);
		self::assertStringContainsString('hasIndex', $src);
		self::assertStringContainsString("'length' => 16", $src);
	}
}
