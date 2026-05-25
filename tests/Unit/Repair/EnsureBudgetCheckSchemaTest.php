<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Repair;

use OCA\BudgetCheck\Migration\BudgetCheckTableCatalog;
use OCA\BudgetCheck\Repair\EnsureBudgetCheckSchema;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

final class EnsureBudgetCheckSchemaTest extends TestCase
{
	public function testSucceedsWhenAllTablesExist(): void
	{
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('tableExists')->willReturn(true);
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info');

		$step = new EnsureBudgetCheckSchema($connection);
		$step->run($output);
		self::assertSame(11, count(BudgetCheckTableCatalog::TABLES));
	}
}
