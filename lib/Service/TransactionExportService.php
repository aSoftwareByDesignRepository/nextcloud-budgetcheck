<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

/**
 * File export of the transaction ledger in two formats:
 *  - CSV  : UTF-8 + BOM, comma-delimited, every field quoted (RFC 4180) and
 *    guarded against spreadsheet formula injection. Column order leads with
 *    the importer's canonical headers so an exported file re-imports cleanly.
 *  - ODS  : a minimal, spec-conformant OpenDocument Spreadsheet package
 *    (mimetype stored uncompressed first, manifest, meta, styles, content)
 *    with real typed cells (date / float / boolean / string).
 *
 * Both formats are driven by one shared typed column model so the two files
 * can never disagree about which data a column carries.
 */
class TransactionExportService
{
	public const FORMAT_CSV = 'csv';
	public const FORMAT_ODS = 'ods';
	public const MAX_EXPORT_ROWS = 10000;
	public const ODS_MIME = 'application/vnd.oasis.opendocument.spreadsheet';

	public function __construct(
		private WorkspaceService $workspaces,
		private TransactionService $transactions,
		private CategoryService $categories,
		private BookingStatusService $bookingStatuses,
	) {
	}

	/**
	 * @param array<string,mixed> $filters same filter keys as the ledger list endpoint
	 * @return array{filename: string, mimeType: string, content: string}
	 */
	public function build(int $workspaceId, string $userId, string $format, array $filters): array
	{
		$format = strtolower(trim($format));
		if (!in_array($format, [self::FORMAT_CSV, self::FORMAT_ODS], true)) {
			throw new \InvalidArgumentException('Unsupported export format.');
		}
		$workspace = $this->workspaces->getForUser($workspaceId, $userId);
		$isProject = ($workspace['type'] ?? null) === WorkspaceService::TYPE_PROJECT;

		$categoryNames = [];
		foreach ($this->categories->listForWorkspace($workspaceId, $userId, true) as $category) {
			$categoryNames[(int)$category['id']] = (string)$category['name'];
		}
		$statusNames = [];
		if ($isProject) {
			foreach ($this->bookingStatuses->listForWorkspace($workspaceId, $userId, true) as $status) {
				$statusNames[(int)$status['id']] = (string)$status['name'];
			}
		}

		$result = $this->transactions->exportRows($workspaceId, $userId, $filters, $workspace, self::MAX_EXPORT_ROWS);
		if ($result['total'] > self::MAX_EXPORT_ROWS || count($result['items']) > self::MAX_EXPORT_ROWS) {
			throw new \InvalidArgumentException(sprintf(
				'Too many transactions to export (%d matched, maximum is %d). Narrow the date range or filters first.',
				max($result['total'], count($result['items'])),
				self::MAX_EXPORT_ROWS,
			));
		}

		$columns = $this->columns($isProject, $categoryNames, $statusNames);
		$rows = array_map(fn (array $tx): array => $this->rowCells($tx, $columns), $result['items']);

		if ($format === self::FORMAT_CSV) {
			$content = $this->buildCsv($columns, $rows);
			$mimeType = 'text/csv; charset=utf-8';
		} else {
			$content = $this->buildOds($columns, $rows);
			$mimeType = self::ODS_MIME;
		}
		return [
			'filename' => sprintf('%s_transactions_%s.%s', $this->safeName((string)($workspace['name'] ?? '')), gmdate('Y-m-d'), $format),
			'mimeType' => $mimeType,
			'content' => $content,
		];
	}

	/**
	 * One column contract for both serializers. Leading columns mirror the
	 * CSV importer's canonical headers so a plain export re-imports; the rest
	 * carries every meaningful booking detail. Internal metadata (row version,
	 * budget linkage, foreign keys that reveal structure) stays out.
	 *
	 * @param array<int,string> $categoryNames
	 * @param array<int,string> $statusNames
	 * @return list<array{header: string, type: string, get: callable(array<string,mixed>): mixed}>
	 */
	private function columns(bool $isProject, array $categoryNames, array $statusNames): array
	{
		$money = fn (?array $env) => $env === null ? null : $env['decimal'];
		$cols = [
			['header' => 'bookingDate', 'type' => 'date', 'get' => fn (array $r) => $r['bookingDate']],
			['header' => 'title', 'type' => 'string', 'get' => fn (array $r) => $r['title']],
			['header' => 'direction', 'type' => 'string', 'get' => fn (array $r) => $r['direction']],
			['header' => 'amount', 'type' => 'number', 'get' => fn (array $r) => $money($r['amount'] ?? null)],
			['header' => 'currency', 'type' => 'string', 'get' => fn (array $r) => $r['amount']['currency'] ?? null],
			['header' => 'category', 'type' => 'string', 'get' => fn (array $r) => $categoryNames[(int)$r['categoryId']] ?? null],
			['header' => 'categoryId', 'type' => 'number', 'get' => fn (array $r) => $r['categoryId']],
		];
		if ($isProject) {
			$cols[] = ['header' => 'bookingStatus', 'type' => 'string', 'get' => fn (array $r) => $r['bookingStatusId'] === null ? null : ($statusNames[(int)$r['bookingStatusId']] ?? null)];
			$cols[] = ['header' => 'bookingStatusId', 'type' => 'number', 'get' => fn (array $r) => $r['bookingStatusId']];
		}
		$cols[] = ['header' => 'notes', 'type' => 'string', 'get' => fn (array $r) => $r['notes']];
		$cols[] = ['header' => 'isSpecial', 'type' => 'bool', 'get' => fn (array $r) => $r['isSpecial']];
		$cols[] = ['header' => 'isPlanned', 'type' => 'bool', 'get' => fn (array $r) => $r['isPlanned']];
		$cols[] = ['header' => 'externalRef', 'type' => 'string', 'get' => fn (array $r) => $r['externalRef']];
		$cols[] = ['header' => 'net', 'type' => 'number', 'get' => fn (array $r) => $money($r['net'] ?? null)];
		$cols[] = ['header' => 'vatRatePercent', 'type' => 'number', 'get' => fn (array $r) => $r['vatRateBp'] === null ? null : (string)($r['vatRateBp'] / 100)];
		$cols[] = ['header' => 'vat', 'type' => 'number', 'get' => fn (array $r) => $money($r['vat'] ?? null)];
		$cols[] = ['header' => 'gross', 'type' => 'number', 'get' => fn (array $r) => $money($r['gross'] ?? null)];
		$cols[] = ['header' => 'entryAmountBasis', 'type' => 'string', 'get' => fn (array $r) => $r['entryAmountBasis']];
		if ($isProject) {
			$cols[] = ['header' => 'isBillable', 'type' => 'bool', 'get' => fn (array $r) => $r['isBillable']];
			$cols[] = ['header' => 'billingStatus', 'type' => 'string', 'get' => fn (array $r) => $r['billingStatus']];
		}
		$cols[] = ['header' => 'recurringRuleId', 'type' => 'number', 'get' => fn (array $r) => $r['recurringRuleId']];
		$cols[] = ['header' => 'createdBy', 'type' => 'string', 'get' => fn (array $r) => $r['createdBy']];
		$cols[] = ['header' => 'createdAt', 'type' => 'string', 'get' => fn (array $r) => $r['createdAt']];
		$cols[] = ['header' => 'updatedBy', 'type' => 'string', 'get' => fn (array $r) => $r['updatedBy']];
		$cols[] = ['header' => 'updatedAt', 'type' => 'string', 'get' => fn (array $r) => $r['updatedAt']];
		$cols[] = ['header' => 'id', 'type' => 'number', 'get' => fn (array $r) => $r['id']];
		return $cols;
	}

	/**
	 * @param list<array{header: string, type: string, get: callable}> $columns
	 * @return list<array{type: string, value: mixed}>
	 */
	private function rowCells(array $tx, array $columns): array
	{
		$cells = [];
		foreach ($columns as $col) {
			$cells[] = ['type' => $col['type'], 'value' => ($col['get'])($tx)];
		}
		return $cells;
	}

	// ── CSV ────────────────────────────────────────────────────────────────

	private function buildCsv(array $columns, array $rows): string
	{
		$out = "\xEF\xBB\xBF"; // BOM so spreadsheet apps detect UTF-8 correctly
		$headers = [];
		foreach ($columns as $col) {
			$headers[] = $col['header'];
		}
		$out .= $this->csvLine($headers);
		foreach ($rows as $cells) {
			$values = [];
			foreach ($cells as $cell) {
				$values[] = $this->csvValue($cell);
			}
			$out .= $this->csvLine($values);
		}
		return $out;
	}

	/**
	 * @param array{type: string, value: mixed} $cell
	 */
	private function csvValue(array $cell): string
	{
		if ($cell['value'] === null) {
			return '';
		}
		if ($cell['type'] === 'bool') {
			return $cell['value'] ? '1' : '0';
		}
		return (string)$cell['value'];
	}

	private function csvLine(array $values): string
	{
		$parts = [];
		foreach ($values as $v) {
			$parts[] = '"' . str_replace('"', '""', $this->guardFormula($v)) . '"';
		}
		return implode(',', $parts) . "\r\n";
	}

	/**
	 * Quoting alone does not stop a spreadsheet from evaluating a cell as a
	 * formula — values starting with these characters must be prefixed with a
	 * literal apostrophe, which spreadsheet apps display without.
	 */
	private function guardFormula(string $value): string
	{
		if ($value === '') {
			return $value;
		}
		if (in_array($value[0], ['=', '+', '-', '@', "\t", "\r", "\x0B"], true)) {
			return "'" . $value;
		}
		return $value;
	}

	// ── ODS ────────────────────────────────────────────────────────────────

	/**
	 * ODF requires the "mimetype" entry first and uncompressed so readers can
	 * sniff the type without inflating the archive.
	 */
	private function buildOds(array $columns, array $rows): string
	{
		$tmp = tempnam(sys_get_temp_dir(), 'bc-ods-');
		if ($tmp === false) {
			throw new \RuntimeException('Could not create a temporary file for the export.');
		}
		try {
			$zip = new \ZipArchive();
			if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
				throw new \RuntimeException('Could not build the export archive.');
			}
			$zip->addFromString('mimetype', self::ODS_MIME);
			$zip->setCompressionName('mimetype', \ZipArchive::CM_STORE);
			$zip->addFromString('META-INF/manifest.xml', $this->odsManifest());
			$zip->addFromString('meta.xml', $this->odsMeta());
			$zip->addFromString('styles.xml', $this->odsStyles());
			$zip->addFromString('content.xml', $this->odsContent($columns, $rows));
			$zip->close();
			$content = file_get_contents($tmp);
			if ($content === false) {
				throw new \RuntimeException('Could not read the export archive.');
			}
			return $content;
		} finally {
			@unlink($tmp);
		}
	}

	private function odsManifest(): string
	{
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.3">'
			. '<manifest:file-entry manifest:full-path="/" manifest:media-type="' . self::ODS_MIME . '"/>'
			. '<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>'
			. '<manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/>'
			. '<manifest:file-entry manifest:full-path="meta.xml" manifest:media-type="text/xml"/>'
			. '</manifest:manifest>';
	}

	private function odsMeta(): string
	{
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<office:document-meta xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
			. 'xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0" office:version="1.3">'
			. '<office:meta><meta:generator>BudgetCheck</meta:generator>'
			. '<meta:creation-date>' . gmdate('Y-m-d\TH:i:s\Z') . '</meta:creation-date>'
			. '</office:meta></office:document-meta>';
	}

	private function odsStyles(): string
	{
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<office:document-styles xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
			. 'xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" '
			. 'xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" office:version="1.3">'
			. '<office:styles>'
			. '<style:style style:name="ceHeader" style:family="table-cell">'
			. '<style:text-properties fo:font-weight="bold" style:font-weight-asian="bold" style:font-weight-complex="bold"/>'
			. '</style:style>'
			. '</office:styles></office:document-styles>';
	}

	private function odsContent(array $columns, array $rows): string
	{
		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<office:document-content '
			. 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
			. 'xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" '
			. 'xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" '
			. 'office:version="1.3">'
			. '<office:automatic-styles/>'
			. '<office:body><office:spreadsheet>'
			. '<table:table table:name="Transactions">'
			. '<table:table-column table:number-columns-repeated="' . count($columns) . '"/>';
		$xml .= '<table:table-row>';
		foreach ($columns as $col) {
			$xml .= '<table:table-cell table:style-name="ceHeader" office:value-type="string"><text:p>'
				. $this->xmlText($col['header']) . '</text:p></table:table-cell>';
		}
		$xml .= '</table:table-row>';
		foreach ($rows as $cells) {
			$xml .= '<table:table-row>';
			foreach ($cells as $cell) {
				$xml .= $this->odsCell($cell);
			}
			$xml .= '</table:table-row>';
		}
		return $xml . '</table:table></office:spreadsheet></office:body></office:document-content>';
	}

	/**
	 * Typed ODF cells. Formula injection is a non-issue here: ODS formulas are
	 * declared via table:formula attributes, never inferred from text content.
	 *
	 * @param array{type: string, value: mixed} $cell
	 */
	private function odsCell(array $cell): string
	{
		if ($cell['value'] === null) {
			return '<table:table-cell office:value-type="string"><text:p></text:p></table:table-cell>';
		}
		return match ($cell['type']) {
			'date' => '<table:table-cell office:value-type="date" office:date-value="'
				. $this->xml((string)$cell['value']) . '"><text:p>' . $this->xml((string)$cell['value']) . '</text:p></table:table-cell>',
			'bool' => '<table:table-cell office:value-type="boolean" office:boolean-value="'
				. ($cell['value'] ? 'true' : 'false') . '"><text:p>' . ($cell['value'] ? 'TRUE' : 'FALSE') . '</text:p></table:table-cell>',
			'number' => '<table:table-cell office:value-type="float" office:value="'
				. $this->xml((string)$cell['value']) . '"><text:p>' . $this->xml((string)$cell['value']) . '</text:p></table:table-cell>',
			default => '<table:table-cell office:value-type="string"><text:p>' . $this->xmlText((string)$cell['value']) . '</text:p></table:table-cell>',
		};
	}

	private function xml(string $value): string
	{
		return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
	}

	/**
	 * ODF collapses literal newlines inside text:p; real line breaks need
	 * explicit text:line-break elements or multi-line notes lose their shape.
	 */
	private function xmlText(string $value): string
	{
		$normalized = str_replace(["\r\n", "\r"], "\n", $value);
		$parts = array_map($this->xml(...), explode("\n", $normalized));
		return implode('<text:line-break/>', $parts);
	}

	private function safeName(string $name): string
	{
		$safe = trim((string)preg_replace('/[^A-Za-z0-9_-]+/', '_', $name), '_');
		return $safe === '' ? 'workspace' : $safe;
	}
}
