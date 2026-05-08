<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\AccessControlService;
use OCA\BudgetCheck\Service\HouseholdYearlyExportService;
use OCA\BudgetCheck\Service\SummaryService;
use OCA\BudgetCheck\Service\WorkspaceService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class HouseholdYearlyExportServiceTest extends TestCase
{
	private HouseholdYearlyExportService $service;

	protected function setUp(): void
	{
		$this->service = new HouseholdYearlyExportService(
			$this->createMock(WorkspaceService::class),
			$this->createMock(SummaryService::class),
			$this->createMock(AccessControlService::class),
			$this->createMock(IDBConnection::class),
		);
	}

	public function testSanitizeForSpreadsheetBlocksFormulaInjectionPrefixes(): void
	{
		$this->assertSame("'=2+2", $this->invokePrivate('sanitizeForSpreadsheet', '=2+2'));
		$this->assertSame("'+SUM(A1:A2)", $this->invokePrivate('sanitizeForSpreadsheet', '+SUM(A1:A2)'));
		$this->assertSame("'-10", $this->invokePrivate('sanitizeForSpreadsheet', '-10'));
		$this->assertSame("'@A1", $this->invokePrivate('sanitizeForSpreadsheet', '@A1'));
	}

	public function testSanitizeForSpreadsheetStripsDisallowedControlCharacters(): void
	{
		$input = "Budget\x00Title\x1F";
		$this->assertSame('BudgetTitle', $this->invokePrivate('sanitizeForSpreadsheet', $input));
	}

	public function testCellRefSupportsSingleAndMultiLetterColumns(): void
	{
		$this->assertSame('A1', $this->invokePrivate('cellRef', 1, 1));
		$this->assertSame('Z2', $this->invokePrivate('cellRef', 26, 2));
		$this->assertSame('AA3', $this->invokePrivate('cellRef', 27, 3));
		$this->assertSame('AB10', $this->invokePrivate('cellRef', 28, 10));
	}

	private function invokePrivate(string $method, mixed ...$args): mixed
	{
		$ref = new \ReflectionClass($this->service);
		$m = $ref->getMethod($method);
		$m->setAccessible(true);
		return $m->invoke($this->service, ...$args);
	}
}
