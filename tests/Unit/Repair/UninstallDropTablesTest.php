<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Repair;

use OCA\BudgetCheck\Migration\BudgetCheckTableCatalog;
use OCA\BudgetCheck\Repair\UninstallDropTables;
use PHPUnit\Framework\TestCase;

final class UninstallDropTablesTest extends TestCase
{
	public function testCatalogMatchesExplicitUninstallDropList(): void
	{
		self::assertSame([
			'bc_audit_log',
			'bc_booking_statuses',
			'bc_budget_defaults',
			'bc_budgets',
			'bc_categories',
			'bc_monthly_snapshots',
			'bc_recurring_rules',
			'bc_savings_targets',
			'bc_transactions',
			'bc_tx_attachments',
			'bc_workspace_groups',
			'bc_workspace_members',
			'bc_workspaces',
		], BudgetCheckTableCatalog::TABLES);
	}

	public function testRepairStepNameIsDescriptive(): void
	{
		$step = new UninstallDropTables(
			$this->createMock(\OCP\IDBConnection::class),
			$this->createMock(\OCP\IConfig::class),
			$this->createMock(\OCP\Files\IRootFolder::class),
		);
		self::assertStringContainsString('budgetcheck', strtolower($step->getName()));
		self::assertStringContainsString('uninstall', strtolower($step->getName()));
	}
}
