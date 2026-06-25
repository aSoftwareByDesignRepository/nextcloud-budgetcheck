<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Integration;

use OCA\BudgetCheck\Repair\EnsureBudgetCheckSchema;
use OCA\BudgetCheck\Repair\UninstallDropTables;
use OCA\BudgetCheck\Repair\BackupBeforeUpdate;
use OCP\Migration\IOutput;
use Test\TestCase;

class UpgradeRepairIntegrationTest extends TestCase
{
	public function testInstallAndPostMigrationRepairStepsResolveFromContainer(): void
	{
		foreach ([
			EnsureBudgetCheckSchema::class,
			UninstallDropTables::class,
			BackupBeforeUpdate::class,
		] as $class) {
			$step = \OC::$server->get($class);
			$this->assertInstanceOf($class, $step);
		}
	}

	public function testEnsureBudgetCheckSchemaRunsWithoutFatal(): void
	{
		/** @var EnsureBudgetCheckSchema $step */
		$step = \OC::$server->get(EnsureBudgetCheckSchema::class);
		$output = $this->createMock(IOutput::class);
		$output->method('info');

		$step->run($output);
		$this->addToAssertionCount(1);
	}
}
