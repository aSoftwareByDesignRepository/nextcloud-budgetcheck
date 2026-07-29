<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Repair;

use OCA\BudgetCheck\Migration\BudgetCheckTableCatalog;
use OCA\BudgetCheck\Repair\EnsureBudgetCheckSchema;
use OCA\BudgetCheck\Repair\UninstallDropTables;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

final class EnsureBudgetCheckSchemaTest extends TestCase
{
	public function testSucceedsWhenAllTablesExist(): void
	{
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('tableExists')->willReturn(true);
		$config = $this->createMock(IConfig::class);
		$config->expects(self::once())
			->method('deleteAppValue')
			->with(UninstallDropTables::APP_ID, UninstallDropTables::REPAIR_PASS_KEY);
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info');

		$step = new EnsureBudgetCheckSchema($connection, $config);
		$step->run($output);
		self::assertSame(15, count(BudgetCheckTableCatalog::TABLES));
	}
}
