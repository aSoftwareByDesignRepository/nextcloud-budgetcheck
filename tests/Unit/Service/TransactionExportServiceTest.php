<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\BookingStatusService;
use OCA\BudgetCheck\Service\CategoryService;
use OCA\BudgetCheck\Service\TransactionExportService;
use OCA\BudgetCheck\Service\TransactionService;
use OCA\BudgetCheck\Service\WorkspaceService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class TransactionExportServiceTest extends TestCase
{
	private WorkspaceService&MockObject $workspaces;
	private TransactionService&MockObject $transactions;
	private CategoryService&MockObject $categories;
	private BookingStatusService&MockObject $statuses;
	private TransactionExportService $service;

	protected function setUp(): void
	{
		$this->workspaces = $this->createMock(WorkspaceService::class);
		$this->transactions = $this->createMock(TransactionService::class);
		$this->categories = $this->createMock(CategoryService::class);
		$this->statuses = $this->createMock(BookingStatusService::class);
		$this->service = new TransactionExportService(
			$this->workspaces,
			$this->transactions,
			$this->categories,
			$this->statuses,
		);
	}

	/** @param list<array<string,mixed>> $rows */
	private function arrange(string $type = 'household', array $rows = []): void
	{
		$this->workspaces->method('getForUser')->willReturn([
			'id' => 7,
			'name' => 'My Ledger!',
			'type' => $type,
			'currencyCode' => 'EUR',
		]);
		$this->categories->method('listForWorkspace')->willReturn([
			['id' => 11, 'name' => 'Groceries'],
		]);
		if ($type === 'project') {
			$this->statuses->method('listForWorkspace')->willReturn([
				['id' => 5, 'name' => 'Booked'],
			]);
		}
		$this->transactions->method('exportRows')->willReturn([
			'items' => $rows,
			'total' => count($rows),
		]);
	}

	private function money(int $minor): array
	{
		return ['minor' => $minor, 'currency' => 'EUR', 'decimal' => number_format($minor / 100, 2, '.', ''), 'decimals' => 2];
	}

	private function tx(array $overrides = []): array
	{
		return array_merge([
			'id' => 42,
			'workspaceId' => 7,
			'categoryId' => 11,
			'bookingDate' => '2026-03-05',
			'amount' => $this->money(1250),
			'direction' => 'expense',
			'entryAmountBasis' => 'simple',
			'net' => null,
			'vatRateBp' => null,
			'vat' => null,
			'gross' => null,
			'taxCalculationLocked' => false,
			'title' => 'Weekly shop',
			'notes' => null,
			'isSpecial' => false,
			'externalRef' => null,
			'bookingStatusId' => null,
			'recurringRuleId' => null,
			'budgetId' => null,
			'isPlanned' => false,
			'isBillable' => false,
			'billingStatus' => 'open',
			'version' => 1,
			'createdBy' => 'owner',
			'updatedBy' => 'owner',
			'createdAt' => '2026-03-05 10:00:00',
			'updatedAt' => '2026-03-05 10:00:00',
			'deletedAt' => null,
		], $overrides);
	}

	// ── Format gate ─────────────────────────────────────────────────────

	public function testRejectsUnsupportedFormat(): void
	{
		$this->arrange();
		$this->expectException(\InvalidArgumentException::class);
		$this->service->build(7, 'u', 'xlsx', []);
	}

	public function testRejectsOverCapResult(): void
	{
		$this->workspaces->method('getForUser')->willReturn(['id' => 7, 'name' => 'x', 'type' => 'household', 'currencyCode' => 'EUR']);
		$this->categories->method('listForWorkspace')->willReturn([]);
		$this->transactions->method('exportRows')->willReturn([
			'items' => [],
			'total' => TransactionExportService::MAX_EXPORT_ROWS + 1,
		]);
		try {
			$this->service->build(7, 'u', 'csv', []);
			$this->fail('expected cap rejection');
		} catch (\InvalidArgumentException $e) {
			$this->assertStringContainsString((string)(TransactionExportService::MAX_EXPORT_ROWS + 1), $e->getMessage());
			$this->assertStringContainsString('Narrow the date range or filters', $e->getMessage());
		}
	}

	// ── CSV ─────────────────────────────────────────────────────────────

	private function csvRows(string $csv): array
	{
		$body = substr($csv, 3); // strip BOM
		$fh = fopen('php://memory', 'r+');
		$this->assertNotFalse($fh);
		fwrite($fh, $body);
		rewind($fh);
		$rows = [];
		while (($row = fgetcsv($fh)) !== false) {
			$rows[] = $row;
		}
		fclose($fh);
		return $rows;
	}

	public function testCsvStartsWithUtf8BomAndHeader(): void
	{
		$this->arrange('household', [$this->tx()]);
		$file = $this->service->build(7, 'u', 'csv', []);
		$this->assertSame('text/csv; charset=utf-8', $file['mimeType']);
		$this->assertSame("\xEF\xBB\xBF", substr($file['content'], 0, 3));
		$rows = $this->csvRows($file['content']);
		$this->assertSame(
			['bookingDate', 'title', 'direction', 'amount', 'currency', 'category', 'categoryId'],
			array_slice($rows[0], 0, 7),
		);
	}

	public function testCsvProjectAddsStatusAndBillingColumns(): void
	{
		$this->arrange('project', [$this->tx(['bookingStatusId' => 5])]);
		$rows = $this->csvRows($this->service->build(7, 'u', 'csv', [])['content']);
		$this->assertContains('bookingStatus', $rows[0]);
		$this->assertContains('bookingStatusId', $rows[0]);
		$this->assertContains('isBillable', $rows[0]);
		$this->assertContains('billingStatus', $rows[0]);
		$data = array_combine($rows[0], $rows[1]);
		$this->assertSame('Booked', $data['bookingStatus']);
		$this->assertSame('5', $data['bookingStatusId']);
	}

	public function testCsvHouseholdOmitsProjectColumns(): void
	{
		$this->arrange('household', [$this->tx()]);
		$rows = $this->csvRows($this->service->build(7, 'u', 'csv', [])['content']);
		$this->assertNotContains('bookingStatus', $rows[0]);
		$this->assertNotContains('isBillable', $rows[0]);
	}

	public function testCsvMapsCategoryNameAndValues(): void
	{
		$this->arrange('household', [$this->tx()]);
		$rows = $this->csvRows($this->service->build(7, 'u', 'csv', [])['content']);
		$data = array_combine($rows[0], $rows[1]);
		$this->assertSame('2026-03-05', $data['bookingDate']);
		$this->assertSame('Weekly shop', $data['title']);
		$this->assertSame('12.50', $data['amount']);
		$this->assertSame('EUR', $data['currency']);
		$this->assertSame('Groceries', $data['category']);
		$this->assertSame('11', $data['categoryId']);
		$this->assertSame('0', $data['isSpecial']);
		$this->assertSame('42', $data['id']);
	}

	public function testCsvQuotesCommasQuotesAndNewlines(): void
	{
		$this->arrange('household', [$this->tx([
			'title' => 'say "hi", ok',
			'notes' => "line1\nline2",
		])]);
		$csv = $this->service->build(7, 'u', 'csv', [])['content'];
		$this->assertStringContainsString('"say ""hi"", ok"', $csv);
		$rows = $this->csvRows($csv);
		$data = array_combine($rows[0], $rows[1]);
		$this->assertSame('say "hi", ok', $data['title']);
		$this->assertSame("line1\nline2", $data['notes']);
	}

	public function testCsvGuardsFormulaInjection(): void
	{
		$hostile = ['=SUM(A1:A2)', '+cmd|/c calc!A0', '-2+3', '@mention', "\tinjected", "\r\n=x", "\x0B=v"];
		$this->arrange('household', array_map(
			fn (string $payload) => $this->tx(['title' => $payload]),
			$hostile,
		));
		$rows = $this->csvRows($this->service->build(7, 'u', 'csv', [])['content']);
		foreach ($hostile as $i => $payload) {
			$data = array_combine($rows[0], $rows[$i + 1]);
			$this->assertSame("'" . $payload, $data['title'], 'payload: ' . json_encode($payload));
		}
	}

	public function testCsvTaxFieldsAndVatPercent(): void
	{
		$this->arrange('household', [$this->tx([
			'net' => $this->money(1000),
			'vat' => $this->money(190),
			'gross' => $this->money(1190),
			'vatRateBp' => 1900,
		])]);
		$rows = $this->csvRows($this->service->build(7, 'u', 'csv', [])['content']);
		$data = array_combine($rows[0], $rows[1]);
		$this->assertSame('10.00', $data['net']);
		$this->assertSame('1.90', $data['vat']);
		$this->assertSame('11.90', $data['gross']);
		$this->assertSame('19', $data['vatRatePercent']);
	}

	public function testCsvNullsRenderEmpty(): void
	{
		$this->arrange('household', [$this->tx()]);
		$rows = $this->csvRows($this->service->build(7, 'u', 'csv', [])['content']);
		$data = array_combine($rows[0], $rows[1]);
		$this->assertSame('', $data['notes']);
		$this->assertSame('', $data['externalRef']);
		$this->assertSame('', $data['net']);
		$this->assertSame('', $data['recurringRuleId']);
	}

	public function testFilenameIsSanitizedAndDated(): void
	{
		$this->arrange('household', []);
		$file = $this->service->build(7, 'u', 'csv', []);
		$this->assertMatchesRegularExpression('/^My_Ledger_transactions_\d{4}-\d{2}-\d{2}\.csv$/', $file['filename']);
		$ods = $this->service->build(7, 'u', 'ods', []);
		$this->assertStringEndsWith('.ods', $ods['filename']);
	}

	// ── ODS ─────────────────────────────────────────────────────────────

	private function unzipOds(string $content): array
	{
		$tmp = tempnam(sys_get_temp_dir(), 'ods-test-');
		file_put_contents($tmp, $content);
		$zip = new \ZipArchive();
		$this->assertTrue($zip->open($tmp), 'content is a valid zip');
		$entries = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$stat = $zip->statIndex($i);
			$entries[$stat['name']] = $stat['comp_method'] ?? null;
		}
		$first = $zip->statIndex(0)['name'];
		$xml = [];
		foreach (['content.xml', 'styles.xml', 'meta.xml', 'META-INF/manifest.xml', 'mimetype'] as $name) {
			$xml[$name] = $zip->getFromName($name);
		}
		$zip->close();
		unlink($tmp);
		return ['entries' => $entries, 'first' => $first, 'xml' => $xml];
	}

	public function testOdsPackageStructure(): void
	{
		$this->arrange('household', [$this->tx()]);
		$file = $this->service->build(7, 'u', 'ods', []);
		$this->assertSame(TransactionExportService::ODS_MIME, $file['mimeType']);
		$zip = $this->unzipOds($file['content']);
		$this->assertSame('mimetype', $zip['first'], 'mimetype must be the first entry');
		$this->assertSame(TransactionExportService::ODS_MIME, $zip['xml']['mimetype']);
		$this->assertArrayHasKey('content.xml', $zip['entries']);
		$this->assertArrayHasKey('META-INF/manifest.xml', $zip['entries']);
		// mimetype must be stored uncompressed (comp_method 0)
		$this->assertSame(0, (int)$zip['entries']['mimetype']);
	}

	public function testOdsContentXmlIsWellFormedWithTypedCells(): void
	{
		$this->arrange('project', [$this->tx([
			'title' => 'a <b> & "c"',
			'bookingStatusId' => 5,
			'isBillable' => true,
		])]);
		$file = $this->service->build(7, 'u', 'ods', []);
		$zip = $this->unzipOds($file['content']);
		$content = $zip['xml']['content.xml'];
		$this->assertNotFalse($content);
		$doc = simplexml_load_string($content);
		$this->assertNotFalse($doc, 'content.xml must be well-formed XML');
		$this->assertStringContainsString('office:value-type="date"', $content);
		$this->assertStringContainsString('office:date-value="2026-03-05"', $content);
		$this->assertStringContainsString('office:value-type="float" office:value="12.50"', $content);
		$this->assertStringContainsString('office:boolean-value="true"', $content);
		$this->assertStringContainsString('a &lt;b&gt; &amp; &quot;c&quot;', $content);
		$this->assertStringContainsString('manifest:file-entry', $zip['xml']['META-INF/manifest.xml']);
	}

	public function testOdsMultilineNotesBecomeLineBreaks(): void
	{
		$this->arrange('household', [$this->tx(['notes' => "one\r\ntwo"] )]);
		$zip = $this->unzipOds($this->service->build(7, 'u', 'ods', [])['content']);
		$this->assertStringContainsString('one<text:line-break/>two', $zip['xml']['content.xml']);
	}

	public function testOdsEmptyLedgerStillValid(): void
	{
		$this->arrange('household', []);
		$zip = $this->unzipOds($this->service->build(7, 'u', 'ods', [])['content']);
		$this->assertNotFalse(simplexml_load_string($zip['xml']['content.xml']));
	}
}
