<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

use OCA\BudgetCheck\Exception\WorkspaceTypeMismatchException;
use OCP\IDBConnection;

/**
 * Build a lightweight XLSX workbook for a household yearly export.
 *
 * Workbook layout:
 * - "Overview" sheet: yearly totals + one row per month.
 * - One sheet per month: booking rows + monthly sums.
 */
class HouseholdYearlyExportService
{
	public function __construct(
		private WorkspaceService $workspaces,
		private SummaryService $summaries,
		private AccessControlService $access,
		private IDBConnection $db,
	) {
	}

	/**
	 * @return array{filename:string,mimeType:string,content:string}
	 */
	public function buildXlsx(int $workspaceId, string $userId, int $year): array
	{
		if ($year < 1900 || $year > 9999) {
			throw new \InvalidArgumentException('year is out of range.');
		}
		$workspace = $this->workspaces->getForUser($workspaceId, $userId);
		if (($workspace['type'] ?? null) !== WorkspaceService::TYPE_HOUSEHOLD) {
			throw new WorkspaceTypeMismatchException('household', (string)($workspace['type'] ?? ''), 'household_yearly_export');
		}
		$this->access->ensureMembership($workspaceId, $userId);
		$summary = $this->summaries->yearly($workspaceId, $userId, $year);
		$currencyDecimals = isset($workspace['currencyDecimals'])
			? (int)$workspace['currencyDecimals']
			: ((string)($workspace['currencyCode'] ?? '') === 'JPY' ? 0 : 2);

		$sheets = [];
		$sheets[] = [
			'name' => 'Overview',
			'rows' => $this->overviewRows($workspace, $summary),
		];
		for ($month = 1; $month <= 12; $month++) {
			$ym = sprintf('%04d-%02d', $year, $month);
			$sheets[] = [
				'name' => sprintf('%02d-%04d', $month, $year),
				'rows' => $this->monthRows($workspaceId, $ym, $currencyDecimals),
			];
		}

		$content = $this->buildWorkbook($sheets);
		$workspaceName = trim((string)($workspace['name'] ?? 'Household'));
		$safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $workspaceName) ?: 'household';
		return [
			'filename' => sprintf('%s_%d.xlsx', $safeName, $year),
			'mimeType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'content' => $content,
		];
	}

	/**
	 * @return array{filename:string,mimeType:string,content:string}
	 */
	public function buildProjectPeriodXlsx(int $workspaceId, string $userId): array
	{
		$workspace = $this->workspaces->getForUser($workspaceId, $userId);
		if (($workspace['type'] ?? null) !== WorkspaceService::TYPE_PROJECT) {
			throw new WorkspaceTypeMismatchException('project', (string)($workspace['type'] ?? ''), 'project_period_export');
		}
		$this->access->ensureMembership($workspaceId, $userId);
		$summary = $this->summaries->projectPeriod($workspaceId, $userId, null);
		$currencyDecimals = isset($workspace['currencyDecimals'])
			? (int)$workspace['currencyDecimals']
			: ((string)($workspace['currencyCode'] ?? '') === 'JPY' ? 0 : 2);

		[$start, $end] = $this->projectExportBounds($workspace);
		$bookings = $this->loadProjectBookings($workspaceId, $start, $end);
		$sheets = [
			[
				'name' => 'Overview',
				'rows' => $this->projectOverviewRows($workspace, $summary),
			],
			[
				'name' => 'Monthly totals',
				'rows' => $this->projectMonthlyTotalsRows($bookings, $currencyDecimals),
			],
			[
				'name' => 'Bookings',
				'rows' => $this->projectBookingRows($workspace, $summary, $bookings, $currencyDecimals),
			],
		];

		$content = $this->buildWorkbook($sheets);
		$workspaceName = trim((string)($workspace['name'] ?? 'Project'));
		$safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $workspaceName) ?: 'project';
		return [
			'filename' => sprintf('%s_project_export.xlsx', $safeName),
			'mimeType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'content' => $content,
		];
	}

	/**
	 * @param array<string,mixed> $workspace
	 * @param array<string,mixed> $summary
	 * @return list<list<string|int|float|null>>
	 */
	private function overviewRows(array $workspace, array $summary): array
	{
		$totals = is_array($summary['totals'] ?? null) ? $summary['totals'] : [];
		$months = is_array($summary['months'] ?? null) ? $summary['months'] : [];
		$currency = (string)($workspace['currencyCode'] ?? '');

		$rows = [
			['Workspace', (string)($workspace['name'] ?? '')],
			['Year', (int)($summary['year'] ?? 0)],
			['Currency', $currency],
			[],
			['Annual totals', ''],
			['Income', $this->envToMajor($totals['income'] ?? null)],
			['Expenses', $this->envToMajor($totals['expense'] ?? null)],
			['Net result', $this->envToMajor($totals['netResult'] ?? null)],
			['Savings target', $this->envToMajor($totals['savingsTarget'] ?? null)],
			['Savings achieved', $this->envToMajor($totals['savingsAchieved'] ?? null)],
			['Budget saldo', $this->envToMajor($totals['budgetSaldo'] ?? null)],
			['Not spent (under budget)', $this->envToMajor($totals['budgetUnspent'] ?? null)],
			['Overspent (over budget)', $this->envToMajor($totals['budgetOverspent'] ?? null)],
			['Months over budget', (int)($totals['overBudgetMonths'] ?? 0)],
			[],
			['Monthly overview', '', '', '', '', '', ''],
			['Month', 'Income', 'Expenses', 'Net result', 'Savings target', 'Savings achieved', 'Budget saldo'],
		];
		foreach ($months as $month) {
			if (!is_array($month)) {
				continue;
			}
			$rows[] = [
				(string)($month['yearMonth'] ?? ''),
				$this->envToMajor($month['income'] ?? null),
				$this->envToMajor($month['expense'] ?? null),
				$this->envToMajor($month['netResult'] ?? null),
				$this->envToMajor($month['savingsTarget'] ?? null),
				$this->envToMajor($month['savingsAchieved'] ?? null),
				$this->envToMajor(($month['budget'] ?? null)['saldo'] ?? null),
			];
		}
		return $rows;
	}

	/**
	 * @return list<list<string|int|float|null>>
	 */
	private function monthRows(int $workspaceId, string $yearMonth, int $currencyDecimals): array
	{
		[$from, $to] = $this->monthBounds($yearMonth);
		$qb = $this->db->getQueryBuilder();
		$qb->select(
			't.booking_date',
			't.direction',
			't.amount_minor',
			't.title',
			't.notes',
			'c.name AS category_name'
		)
			->from('bc_transactions', 't')
			->leftJoin('t', 'bc_categories', 'c', 'c.id = t.category_id')
			->where($qb->expr()->eq('t.workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->isNull('t.deleted_at'))
			->andWhere($qb->expr()->gte('t.booking_date', $qb->createNamedParameter($from)))
			->andWhere($qb->expr()->lte('t.booking_date', $qb->createNamedParameter($to)))
			->orderBy('t.booking_date', 'ASC')
			->addOrderBy('t.id', 'ASC');
		$result = $qb->executeQuery();

		$rows = [
			['Month', $yearMonth],
			[],
			['Date', 'Direction', 'Category', 'Title', 'Amount', 'Notes'],
		];
		$incomeMinor = 0;
		$expenseMinor = 0;
		while ($row = $result->fetch()) {
			$direction = (string)($row['direction'] ?? '');
			$amountMinor = (int)($row['amount_minor'] ?? 0);
			if ($direction === TransactionService::DIRECTION_INCOME) {
				$incomeMinor += $amountMinor;
			} else {
				$expenseMinor += $amountMinor;
			}
			$signedMinor = $direction === TransactionService::DIRECTION_EXPENSE ? -$amountMinor : $amountMinor;
			$rows[] = [
				(string)($row['booking_date'] ?? ''),
				$direction,
				(string)($row['category_name'] ?? ''),
				(string)($row['title'] ?? ''),
				$this->minorToMajor($signedMinor, $currencyDecimals),
				(string)($row['notes'] ?? ''),
			];
		}
		$result->closeCursor();
		$rows[] = [];
		$rows[] = ['Income total', $this->minorToMajor($incomeMinor, $currencyDecimals)];
		$rows[] = ['Expense total', $this->minorToMajor($expenseMinor, $currencyDecimals)];
		$rows[] = ['Net result', $this->minorToMajor($incomeMinor - $expenseMinor, $currencyDecimals)];
		return $rows;
	}

	/**
	 * @return array{0:string,1:string}
	 */
	private function monthBounds(string $yearMonth): array
	{
		$start = new \DateTimeImmutable($yearMonth . '-01 00:00:00');
		$end = $start->modify('last day of this month');
		return [$start->format('Y-m-d'), $end->format('Y-m-d')];
	}

	/**
	 * @param array<string,mixed> $workspace
	 * @return array{0:\DateTimeImmutable,1:\DateTimeImmutable}
	 */
	private function projectExportBounds(array $workspace): array
	{
		$start = $workspace['projectStartDate'] !== null
			? new \DateTimeImmutable((string)$workspace['projectStartDate'])
			: new \DateTimeImmutable('1970-01-01');
		$end = $workspace['projectEndDate'] !== null
			? new \DateTimeImmutable((string)$workspace['projectEndDate'])
			: new \DateTimeImmutable('today');
		return [$start, $end];
	}

	/**
	 * @param array<string,mixed> $workspace
	 * @param array<string,mixed> $summary
	 * @return list<list<string|int|float|null>>
	 */
	private function projectOverviewRows(array $workspace, array $summary): array
	{
		$totals = is_array($summary['totals'] ?? null) ? $summary['totals'] : [];
		$allTime = is_array($summary['allTime'] ?? null) ? $summary['allTime'] : [];
		$window = is_array($summary['window'] ?? null) ? $summary['window'] : [];
		$rows = [
			['Workspace', (string)($workspace['name'] ?? '')],
			['Type', 'Project'],
			['Currency', (string)($workspace['currencyCode'] ?? '')],
			['Window from', (string)($window['from'] ?? '')],
			['Window to', (string)($window['to'] ?? '')],
			[],
			['Period totals', ''],
			['Income', $this->envToMajor($totals['income'] ?? null)],
			['Expenses', $this->envToMajor($totals['expense'] ?? null)],
			['Net result', $this->envToMajor($totals['netResult'] ?? null)],
			['Special income', $this->envToMajor($totals['specialIncome'] ?? null)],
			['Special expense', $this->envToMajor($totals['specialExpense'] ?? null)],
			[],
			['All-time project totals', ''],
			['All-time expenses', $this->envToMajor($allTime['expense'] ?? null)],
			['Project cap', $this->envToMajor($allTime['cap'] ?? null)],
			['Remaining headroom', $this->envToMajor($allTime['remainingHeadroom'] ?? null)],
		];
		if (is_array($totals['tax'] ?? null)) {
			$rows[] = [];
			$rows[] = ['Tax totals', (string)($totals['taxBasis'] ?? '')];
			$rows[] = ['Tax net total', $this->envToMajor(($totals['tax'] ?? [])['net'] ?? null)];
			$rows[] = ['Tax VAT total', $this->envToMajor(($totals['tax'] ?? [])['vat'] ?? null)];
			$rows[] = ['Tax gross total', $this->envToMajor(($totals['tax'] ?? [])['gross'] ?? null)];
		}
		return $rows;
	}

	/**
	 * @param list<array<string,mixed>> $bookings
	 * @return list<list<string|int|float|null>>
	 */
	private function projectMonthlyTotalsRows(array $bookings, int $currencyDecimals): array
	{
		$byMonth = [];
		foreach ($bookings as $row) {
			$month = substr((string)($row['booking_date'] ?? ''), 0, 7);
			if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
				continue;
			}
			if (!isset($byMonth[$month])) {
				$byMonth[$month] = ['income' => 0, 'expense' => 0, 'count' => 0];
			}
			$dir = (string)($row['direction'] ?? '');
			$minor = (int)($row['amount_minor'] ?? 0);
			if ($dir === TransactionService::DIRECTION_INCOME) {
				$byMonth[$month]['income'] += $minor;
			} else {
				$byMonth[$month]['expense'] += $minor;
			}
			$byMonth[$month]['count']++;
		}
		ksort($byMonth, SORT_STRING);
		$rows = [['Month', 'Income', 'Expense', 'Net result', 'Bookings']];
		foreach ($byMonth as $month => $entry) {
			$rows[] = [
				$month,
				$this->minorToMajor((int)$entry['income'], $currencyDecimals),
				$this->minorToMajor((int)$entry['expense'], $currencyDecimals),
				$this->minorToMajor((int)$entry['income'] - (int)$entry['expense'], $currencyDecimals),
				(int)$entry['count'],
			];
		}
		return $rows;
	}

	/**
	 * @param array<string,mixed> $workspace
	 * @param array<string,mixed> $summary
	 * @param list<array<string,mixed>> $bookings
	 * @return list<list<string|int|float|null>>
	 */
	private function projectBookingRows(array $workspace, array $summary, array $bookings, int $currencyDecimals): array
	{
		$window = is_array($summary['window'] ?? null) ? $summary['window'] : [];
		$rows = [
			['Workspace', (string)($workspace['name'] ?? '')],
			['Window', (string)($window['from'] ?? '') . ' - ' . (string)($window['to'] ?? '')],
			[],
			['Date', 'Direction', 'Category', 'Status', 'Title', 'Amount', 'Notes', 'Special'],
		];
		foreach ($bookings as $row) {
			$direction = (string)($row['direction'] ?? '');
			$minor = (int)($row['amount_minor'] ?? 0);
			$signed = $direction === TransactionService::DIRECTION_EXPENSE ? -$minor : $minor;
			$rows[] = [
				(string)($row['booking_date'] ?? ''),
				$direction,
				(string)($row['category_name'] ?? ''),
				(string)($row['status_name'] ?? ''),
				(string)($row['title'] ?? ''),
				$this->minorToMajor($signed, $currencyDecimals),
				(string)($row['notes'] ?? ''),
				((int)($row['is_special'] ?? 0)) === 1 ? 'yes' : 'no',
			];
		}
		return $rows;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function loadProjectBookings(int $workspaceId, \DateTimeImmutable $start, \DateTimeImmutable $end): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select(
			't.booking_date',
			't.direction',
			't.amount_minor',
			't.title',
			't.notes',
			't.is_special',
			'c.name AS category_name',
			'bs.name AS status_name'
		)
			->from('bc_transactions', 't')
			->leftJoin('t', 'bc_categories', 'c', 'c.id = t.category_id')
			->leftJoin('t', 'bc_booking_statuses', 'bs', 'bs.id = t.booking_status_id')
			->where($qb->expr()->eq('t.workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->isNull('t.deleted_at'))
			->andWhere($qb->expr()->gte('t.booking_date', $qb->createNamedParameter($start->format('Y-m-d'))))
			->andWhere($qb->expr()->lte('t.booking_date', $qb->createNamedParameter($end->format('Y-m-d'))))
			->orderBy('t.booking_date', 'ASC')
			->addOrderBy('t.id', 'ASC');
		$result = $qb->executeQuery();
		$rows = [];
		while ($row = $result->fetch()) {
			$rows[] = $row;
		}
		$result->closeCursor();
		return $rows;
	}

	private function envToMajor(mixed $env): ?float
	{
		if (!is_array($env)) {
			return null;
		}
		$minor = isset($env['minor']) ? (int)$env['minor'] : 0;
		$decimals = isset($env['decimals']) ? (int)$env['decimals'] : 2;
		return $this->minorToMajor($minor, $decimals);
	}

	private function minorToMajor(int $minor, int $decimals): float
	{
		$factor = 10 ** max(0, $decimals);
		return $minor / $factor;
	}

	/**
	 * @param list<array{name:string,rows:list<list<string|int|float|null>>}> $sheets
	 */
	private function buildWorkbook(array $sheets): string
	{
		$tmp = tempnam(sys_get_temp_dir(), 'bc-xlsx-');
		if ($tmp === false) {
			throw new \RuntimeException('Could not create temporary file.');
		}
		$zip = new \ZipArchive();
		if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
			@unlink($tmp);
			throw new \RuntimeException('Could not initialize XLSX archive.');
		}
		$zip->addFromString('[Content_Types].xml', $this->contentTypesXml(count($sheets)));
		$zip->addFromString('_rels/.rels', $this->rootRelsXml());
		$zip->addFromString('xl/workbook.xml', $this->workbookXml($sheets));
		$zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml(count($sheets)));
		$zip->addFromString('xl/styles.xml', $this->stylesXml());
		for ($i = 0, $n = count($sheets); $i < $n; $i++) {
			$zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $this->sheetXml($sheets[$i]['rows']));
		}
		$zip->close();
		$content = (string)file_get_contents($tmp);
		@unlink($tmp);
		return $content;
	}

	private function contentTypesXml(int $sheetCount): string
	{
		$sheetOverrides = '';
		for ($i = 1; $i <= $sheetCount; $i++) {
			$sheetOverrides .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
		}
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
			. $sheetOverrides
			. '</Types>';
	}

	private function rootRelsXml(): string
	{
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '</Relationships>';
	}

	/**
	 * @param list<array{name:string,rows:list<list<string|int|float|null>>}> $sheets
	 */
	private function workbookXml(array $sheets): string
	{
		$sheetNodes = '';
		for ($i = 0, $n = count($sheets); $i < $n; $i++) {
			$name = $this->xml((string)$sheets[$i]['name']);
			$sheetNodes .= '<sheet name="' . $name . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
		}
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheets>' . $sheetNodes . '</sheets>'
			. '</workbook>';
	}

	private function workbookRelsXml(int $sheetCount): string
	{
		$rels = '';
		for ($i = 1; $i <= $sheetCount; $i++) {
			$rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
		}
		$rels .= '<Relationship Id="rId' . ($sheetCount + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. $rels
			. '</Relationships>';
	}

	private function stylesXml(): string
	{
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts>'
			. '<fonts count="2">'
			. '<font><sz val="11"/><name val="Calibri"/></font>'
			. '<font><b/><sz val="11"/><name val="Calibri"/></font>'
			. '</fonts>'
			. '<fills count="3">'
			. '<fill><patternFill patternType="none"/></fill>'
			. '<fill><patternFill patternType="gray125"/></fill>'
			. '<fill><patternFill patternType="solid"><fgColor rgb="FFF3F6FA"/><bgColor indexed="64"/></patternFill></fill>'
			. '</fills>'
			. '<borders count="2">'
			. '<border><left/><right/><top/><bottom/><diagonal/></border>'
			. '<border><left style="thin"><color rgb="FFD9D9D9"/></left><right style="thin"><color rgb="FFD9D9D9"/></right><top style="thin"><color rgb="FFD9D9D9"/></top><bottom style="thin"><color rgb="FFD9D9D9"/></bottom><diagonal/></border>'
			. '</borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="6">'
			. '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
			. '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
			. '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
			. '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'
			. '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
			. '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
			. '</cellXfs>'
			. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
			. '</styleSheet>';
	}

	/**
	 * @param list<list<string|int|float|null>> $rows
	 */
	private function sheetXml(array $rows): string
	{
		[$headerRows, $sectionRows, $firstTableHeaderRow, $maxCols] = $this->analyzeRows($rows);
		$body = '';
		for ($r = 0, $rn = count($rows); $r < $rn; $r++) {
			$row = $rows[$r];
			$body .= '<row r="' . ($r + 1) . '">';
			for ($c = 0, $cn = count($row); $c < $cn; $c++) {
				$value = $row[$c];
				if ($value === null || $value === '') {
					continue;
				}
				$ref = $this->cellRef($c + 1, $r + 1);
				if (is_int($value) || is_float($value)) {
					$style = in_array($r + 1, $headerRows, true)
						? '2'
						: (is_float($value) ? '4' : '3');
					$body .= '<c r="' . $ref . '" s="' . $style . '"><v>' . $value . '</v></c>';
				} else {
					$text = $this->sanitizeForSpreadsheet((string)$value);
					$style = '0';
					if (in_array($r + 1, $headerRows, true)) {
						$style = '2';
					} elseif (in_array($r + 1, $sectionRows, true)) {
						$style = '5';
					} elseif ($text !== '') {
						$style = '3';
					}
					$body .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . $this->xml($text) . '</t></is></c>';
				}
			}
			$body .= '</row>';
		}
		$cols = $this->columnWidthXml(max(1, $maxCols));
		$sheetViews = '';
		if ($firstTableHeaderRow !== null) {
			$split = $firstTableHeaderRow;
			$sheetViews = '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . $split . '" topLeftCell="A' . ($split + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
		}
		$autoFilter = '';
		if ($firstTableHeaderRow !== null && $maxCols > 1) {
			$endCol = $this->cellRef($maxCols, 1);
			$endCol = preg_replace('/\d+$/', '', $endCol) ?: 'A';
			$autoFilter = '<autoFilter ref="A' . $firstTableHeaderRow . ':' . $endCol . $firstTableHeaderRow . '"/>';
		}
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. $sheetViews
			. $cols
			. '<sheetData>' . $body . '</sheetData>'
			. $autoFilter
			. '</worksheet>';
	}

	/**
	 * @param list<list<string|int|float|null>> $rows
	 * @return array{0:list<int>,1:list<int>,2:?int,3:int}
	 */
	private function analyzeRows(array $rows): array
	{
		$headerRows = [];
		$sectionRows = [];
		$firstTableHeaderRow = null;
		$maxCols = 1;
		for ($i = 0, $n = count($rows); $i < $n; $i++) {
			$row = $rows[$i];
			$maxCols = max($maxCols, count($row));
			$lineNo = $i + 1;
			if ($row === [] || !isset($row[0]) || $row[0] === null || $row[0] === '') {
				continue;
			}
			$nonEmpty = 0;
			foreach ($row as $cell) {
				if ($cell !== null && $cell !== '') {
					$nonEmpty++;
				}
			}
			$first = is_string($row[0]) ? trim($row[0]) : '';
			$prevEmpty = $i > 0 ? $this->isEmptyRow($rows[$i - 1]) : false;
			if ($nonEmpty >= 3 && $prevEmpty) {
				$headerRows[] = $lineNo;
				if ($firstTableHeaderRow === null) {
					$firstTableHeaderRow = $lineNo;
				}
				continue;
			}
			if ($nonEmpty <= 2 && $prevEmpty && $first !== '') {
				$sectionRows[] = $lineNo;
				continue;
			}
		}
		return [array_values(array_unique($headerRows)), array_values(array_unique($sectionRows)), $firstTableHeaderRow, $maxCols];
	}

	/**
	 * @param list<string|int|float|null> $row
	 */
	private function isEmptyRow(array $row): bool
	{
		foreach ($row as $cell) {
			if ($cell !== null && $cell !== '') {
				return false;
			}
		}
		return true;
	}

	private function columnWidthXml(int $maxCols): string
	{
		$cols = '<cols>';
		for ($i = 1; $i <= $maxCols; $i++) {
			$width = 16;
			if ($i === 1) {
				$width = 18;
			} elseif ($i >= 3 && $i <= 5) {
				$width = 20;
			} elseif ($i >= 6) {
				$width = 28;
			}
			$cols .= '<col min="' . $i . '" max="' . $i . '" width="' . $width . '" customWidth="1"/>';
		}
		$cols .= '</cols>';
		return $cols;
	}

	private function cellRef(int $col, int $row): string
	{
		$letters = '';
		while ($col > 0) {
			$mod = ($col - 1) % 26;
			$letters = chr(65 + $mod) . $letters;
			$col = intdiv($col - 1, 26);
		}
		return $letters . $row;
	}

	private function xml(string $value): string
	{
		return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	}

	private function sanitizeForSpreadsheet(string $value): string
	{
		// Excel formula injection guard for untrusted text fields.
		if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
			$value = "'" . $value;
		}
		// XML 1.0 disallows ASCII control characters except TAB/CR/LF.
		return (string)preg_replace('/[^\P{C}\t\r\n]/u', '', $value);
	}
}
