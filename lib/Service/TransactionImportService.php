<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

use OCA\BudgetCheck\Exception\AccessDeniedException;

class TransactionImportService
{
	private const MAX_ROWS_PER_IMPORT = 500;
	private const MAX_ERRORS_RETURNED = 100;

	public function __construct(
		private CategoryService $categories,
		private BookingStatusService $bookingStatuses,
		private TransactionService $transactions,
		private MoneyService $money,
		private AuditLogService $audit,
		private AccessControlService $access,
		private \OCP\IDBConnection $db,
	) {
	}

	/**
	 * @param list<array<string,mixed>> $rows
	 * @param array{expenseCategoryId?:int,incomeCategoryId?:int} $defaults
	 * @param array{skipDuplicates?:bool} $options
	 */
	public function preview(int $workspaceId, string $userId, array $workspace, array $rows, array $defaults = [], array $options = []): array
	{
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_CONTRIBUTOR);
		$normalizedRows = $this->normalizeRows($rows);
		$importOptions = $this->normalizeImportOptions($options);
		$ctx = $this->buildImportContext($workspaceId, $userId, $defaults);
		$skipIndexes = $this->resolveSkipIndexes($workspaceId, $workspace, $normalizedRows, $importOptions);

		$ok = 0;
		$skipped = count($skipIndexes);
		$errors = [];
		foreach ($normalizedRows as $i => $row) {
			if (isset($skipIndexes[$i])) {
				continue;
			}
			try {
				$this->validateRow($workspaceId, $userId, $workspace, $row, $ctx, $this->displayRowNumber($row, $i + 1));
				$ok++;
			} catch (\InvalidArgumentException|AccessDeniedException $e) {
				$errors[] = [
					'rowNumber' => $this->displayRowNumber($row, $i + 1),
					'message' => $e->getMessage(),
				];
			}
		}

		$this->audit->record($userId, 'transaction_import_previewed', 'workspace', (string)$workspaceId, [
			'rows' => count($normalizedRows),
			'valid' => $ok,
			'invalid' => count($errors),
			'skipped' => $skipped,
			'skipDuplicates' => $importOptions['skipDuplicates'],
			'skipFingerprintDuplicates' => $importOptions['skipFingerprintDuplicates'],
		], $workspaceId);

		return [
			'totalRows' => count($normalizedRows),
			'validRows' => $ok,
			'invalidRows' => count($errors),
			'skippedRows' => $skipped,
			'errors' => array_slice($errors, 0, self::MAX_ERRORS_RETURNED),
		];
	}

	/**
	 * @param list<array<string,mixed>> $rows
	 * @param array{expenseCategoryId?:int,incomeCategoryId?:int} $defaults
	 * @param array{skipDuplicates?:bool} $options
	 */
	public function commit(int $workspaceId, string $userId, array $workspace, array $rows, array $defaults = [], array $options = []): array
	{
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_CONTRIBUTOR);
		$normalizedRows = $this->normalizeRows($rows);
		$importOptions = $this->normalizeImportOptions($options);
		$ctx = $this->buildImportContext($workspaceId, $userId, $defaults);
		$skipIndexes = $this->resolveSkipIndexes($workspaceId, $workspace, $normalizedRows, $importOptions);

		$errors = [];
		$resolved = [];
		foreach ($normalizedRows as $i => $row) {
			if (isset($skipIndexes[$i])) {
				$resolved[$i] = null;
				continue;
			}
			try {
				$resolved[$i] = $this->validateRow($workspaceId, $userId, $workspace, $row, $ctx, $this->displayRowNumber($row, $i + 1));
			} catch (\InvalidArgumentException|AccessDeniedException $e) {
				$errors[] = [
					'rowNumber' => $this->displayRowNumber($row, $i + 1),
					'message' => $e->getMessage(),
				];
			}
		}
		if ($errors !== []) {
			return [
				'createdCount' => 0,
				'skippedCount' => 0,
				'errorCount' => count($errors),
				'errors' => array_slice($errors, 0, self::MAX_ERRORS_RETURNED),
			];
		}

		$createdCount = 0;
		$skippedCount = count($skipIndexes);
		$this->db->beginTransaction();
		try {
			foreach ($normalizedRows as $i => $row) {
				if (isset($skipIndexes[$i])) {
					continue;
				}
				$resolvedRow = $resolved[$i];
				if ($resolvedRow === null) {
					continue;
				}
				[$category, $status] = $resolvedRow;
				$payload = $row;
				$payload['categoryId'] = (int)$category['id'];
				$this->transactions->create($workspaceId, $userId, $payload, $workspace, $category, $status);
				$createdCount++;
			}
			$this->db->commit();
		} catch (\InvalidArgumentException|AccessDeniedException $e) {
			$this->db->rollBack();
			return [
				'createdCount' => 0,
				'skippedCount' => 0,
				'errorCount' => 1,
				'errors' => [[
					'rowNumber' => max(1, $createdCount + 1),
					'message' => $e->getMessage(),
				]],
			];
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		$this->audit->record($userId, 'transaction_import_committed', 'workspace', (string)$workspaceId, [
			'created' => $createdCount,
			'skipped' => $skippedCount,
			'skipDuplicates' => $importOptions['skipDuplicates'],
			'skipFingerprintDuplicates' => $importOptions['skipFingerprintDuplicates'],
		], $workspaceId);

		return [
			'createdCount' => $createdCount,
			'skippedCount' => $skippedCount,
			'errorCount' => 0,
			'errors' => [],
		];
	}

	/**
	 * @param list<array<string,mixed>> $rows
	 * @return list<array<string,mixed>>
	 */
	private function normalizeRows(array $rows): array
	{
		if ($rows === []) {
			throw new \InvalidArgumentException('rows must not be empty.');
		}
		if (count($rows) > self::MAX_ROWS_PER_IMPORT) {
			throw new \InvalidArgumentException('Too many rows. Maximum is 500 rows per import.');
		}
		$out = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				throw new \InvalidArgumentException('Each row must be an object.');
			}
			$out[] = [
				'bookingDate' => (string)($row['bookingDate'] ?? ''),
				'title' => (string)($row['title'] ?? ''),
				'direction' => strtolower(trim((string)($row['direction'] ?? ''))),
				'amount' => $row['amount'] ?? null,
				'category' => trim((string)($row['category'] ?? '')),
				'categoryId' => (int)($row['categoryId'] ?? 0),
				'bookingStatusId' => array_key_exists('bookingStatusId', $row) && $row['bookingStatusId'] !== null && $row['bookingStatusId'] !== ''
					? (int)$row['bookingStatusId']
					: null,
				'notes' => $row['notes'] ?? null,
				'isSpecial' => !empty($row['isSpecial']),
				'externalRef' => $row['externalRef'] ?? null,
				'sourceRowNumber' => max(0, (int)($row['sourceRowNumber'] ?? 0)),
			];
		}

		return $out;
	}

	/**
	 * @param array{skipDuplicates?:bool,skipFingerprintDuplicates?:bool} $options
	 * @return array{skipDuplicates:bool,skipFingerprintDuplicates:bool}
	 */
	private function normalizeImportOptions(array $options): array
	{
		$skipDuplicates = !empty($options['skipDuplicates']);
		return [
			'skipDuplicates' => $skipDuplicates,
			'skipFingerprintDuplicates' => $skipDuplicates && !empty($options['skipFingerprintDuplicates']),
		];
	}

	/**
	 * Rows to skip when duplicate skipping is enabled.
	 *
	 * @param list<array<string,mixed>> $rows
	 * @param array{skipDuplicates:bool,skipFingerprintDuplicates:bool} $importOptions
	 * @return array<int, true>
	 */
	private function resolveSkipIndexes(int $workspaceId, array $workspace, array $rows, array $importOptions): array
	{
		if (!$importOptions['skipDuplicates']) {
			return [];
		}

		$skip = [];
		$seenInBatch = [];
		$refsForLookup = [];
		foreach ($rows as $i => $row) {
			$ref = $this->normalizeExternalRefKey($row['externalRef'] ?? null);
			if ($ref === null) {
				continue;
			}
			if (isset($seenInBatch[$ref])) {
				$skip[$i] = true;
				continue;
			}
			$seenInBatch[$ref] = true;
			$refsForLookup[] = $ref;
		}

		if ($refsForLookup !== []) {
			$existing = array_fill_keys(
				$this->transactions->findExistingExternalRefs($workspaceId, $refsForLookup),
				true,
			);
			foreach ($rows as $i => $row) {
				if (isset($skip[$i])) {
					continue;
				}
				$ref = $this->normalizeExternalRefKey($row['externalRef'] ?? null);
				if ($ref !== null && isset($existing[$ref])) {
					$skip[$i] = true;
				}
			}
		}

		if (!$importOptions['skipFingerprintDuplicates']) {
			return $skip;
		}

		$decimals = $this->money->decimalsFor((string)($workspace['currencyCode'] ?? 'EUR'));
		$batchFingerprints = [];
		$fingerprintsForLookup = [];
		foreach ($rows as $i => $row) {
			if (isset($skip[$i])) {
				continue;
			}
			if ($this->normalizeExternalRefKey($row['externalRef'] ?? null) !== null) {
				continue;
			}
			$fingerprint = $this->fingerprintForRow($row, $decimals);
			if ($fingerprint === null) {
				continue;
			}
			if (isset($batchFingerprints[$fingerprint])) {
				$skip[$i] = true;
				continue;
			}
			$batchFingerprints[$fingerprint] = $i;
			$fingerprintsForLookup[] = $fingerprint;
		}

		if ($fingerprintsForLookup !== []) {
			$existingFingerprints = array_fill_keys(
				$this->transactions->findExistingFingerprintKeys($workspaceId, $fingerprintsForLookup),
				true,
			);
			foreach ($batchFingerprints as $fingerprint => $index) {
				if (isset($existingFingerprints[$fingerprint])) {
					$skip[$index] = true;
				}
			}
		}

		return $skip;
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function fingerprintForRow(array $row, int $decimals): ?string
	{
		$direction = (string)($row['direction'] ?? '');
		if ($direction !== CategoryService::TYPE_INCOME && $direction !== CategoryService::TYPE_EXPENSE) {
			return null;
		}
		$bookingDate = trim((string)($row['bookingDate'] ?? ''));
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bookingDate)) {
			return null;
		}
		$title = trim((string)($row['title'] ?? ''));
		if ($title === '') {
			return null;
		}
		try {
			$amountMinor = $this->money->parseHumanAmount($row['amount'] ?? null, $decimals);
		} catch (\InvalidArgumentException) {
			return null;
		}
		return TransactionService::importFingerprint($bookingDate, $amountMinor, $direction, $title);
	}

	private function normalizeExternalRefKey(mixed $value): ?string
	{
		if ($value === null) {
			return null;
		}
		$trimmed = trim((string)$value);
		return $trimmed === '' ? null : $trimmed;
	}

	private function displayRowNumber(array $row, int $fallback): int
	{
		$n = (int)($row['sourceRowNumber'] ?? 0);
		return $n > 0 ? $n : $fallback;
	}

	/**
	 * @param array{expenseCategoryId?:int,incomeCategoryId?:int} $defaults
	 * @return array{byName: array<string,list<array<string,mixed>>>, defaults: array{expenseCategoryId:int,incomeCategoryId:int}}
	 */
	private function buildImportContext(int $workspaceId, string $userId, array $defaults): array
	{
		$byName = [];
		foreach ($this->categories->listForWorkspace($workspaceId, $userId) as $category) {
			$key = mb_strtolower(trim((string)($category['name'] ?? '')));
			if ($key === '') {
				continue;
			}
			if (!isset($byName[$key])) {
				$byName[$key] = [];
			}
			$byName[$key][] = $category;
		}

		return [
			'byName' => $byName,
			'defaults' => [
				'expenseCategoryId' => max(0, (int)($defaults['expenseCategoryId'] ?? 0)),
				'incomeCategoryId' => max(0, (int)($defaults['incomeCategoryId'] ?? 0)),
			],
		];
	}

	/**
	 * @param array<string,mixed> $row
	 * @param array{byName: array<string,list<array<string,mixed>>>, defaults: array{expenseCategoryId:int,incomeCategoryId:int}} $ctx
	 * @return array{0:array<string,mixed>,1:?array<string,mixed>}
	 */
	private function validateRow(int $workspaceId, string $userId, array $workspace, array $row, array $ctx, int $rowNumber): array
	{
		$direction = (string)($row['direction'] ?? '');
		if ($direction !== CategoryService::TYPE_INCOME && $direction !== CategoryService::TYPE_EXPENSE) {
			throw new \InvalidArgumentException('Row ' . $rowNumber . ': direction must be income or expense.');
		}

		$category = $this->resolveCategoryForImport($workspaceId, $row, $direction, $ctx, $rowNumber);

		$status = null;
		$statusId = $row['bookingStatusId'];
		if ($statusId !== null) {
			if (($workspace['type'] ?? null) !== WorkspaceService::TYPE_PROJECT) {
				throw new \InvalidArgumentException('Row ' . $rowNumber . ': booking status is only allowed for project workspaces.');
			}
			$status = $this->bookingStatuses->loadActiveForWorkspace((int)$statusId, $workspaceId);
			if ($status === null) {
				throw new AccessDeniedException();
			}
		}

		$payload = $row;
		$payload['categoryId'] = (int)$category['id'];
		try {
			$this->transactions->validateCreatePayload($workspaceId, $userId, $payload, $workspace, $category, $status);
		} catch (\InvalidArgumentException $e) {
			throw new \InvalidArgumentException('Row ' . $rowNumber . ': ' . $e->getMessage());
		}
		return [$category, $status];
	}

	/**
	 * @param array<string,mixed> $row
	 * @param array{byName: array<string,list<array<string,mixed>>>, defaults: array{expenseCategoryId:int,incomeCategoryId:int}} $ctx
	 * @return array<string,mixed>
	 */
	private function resolveCategoryForImport(int $workspaceId, array $row, string $direction, array $ctx, int $rowNumber): array
	{
		$categoryId = (int)($row['categoryId'] ?? 0);
		if ($categoryId > 0) {
			$category = $this->categories->loadForWorkspace($categoryId, $workspaceId);
			if ($category === null) {
				throw new \InvalidArgumentException('Row ' . $rowNumber . ': category not found in this workspace.');
			}
			return $category;
		}

		$name = trim((string)($row['category'] ?? ''));
		if ($name !== '') {
			$key = mb_strtolower($name);
			$matches = $ctx['byName'][$key] ?? [];
			if ($matches === []) {
				throw new \InvalidArgumentException('Row ' . $rowNumber . ': unknown category "' . $name . '".');
			}
			$typed = array_values(array_filter(
				$matches,
				static fn (array $category): bool => (string)($category['type'] ?? '') === $direction,
			));
			if (count($typed) === 1) {
				return $typed[0];
			}
			if (count($typed) > 1) {
				throw new \InvalidArgumentException('Row ' . $rowNumber . ': category "' . $name . '" is ambiguous for ' . $direction . '.');
			}
			if (count($matches) === 1) {
				$category = $matches[0];
				if ((string)($category['type'] ?? '') !== $direction) {
					throw new \InvalidArgumentException(
						'Row ' . $rowNumber . ': category "' . $name . '" is for '
						. (string)($category['type'] ?? '') . ', but this row is ' . $direction . '.',
					);
				}
				return $category;
			}
			throw new \InvalidArgumentException('Row ' . $rowNumber . ': category "' . $name . '" is ambiguous.');
		}

		$defaultId = $direction === CategoryService::TYPE_INCOME
			? $ctx['defaults']['incomeCategoryId']
			: $ctx['defaults']['expenseCategoryId'];
		if ($defaultId > 0) {
			$category = $this->categories->loadForWorkspace($defaultId, $workspaceId);
			if ($category === null) {
				throw new \InvalidArgumentException('Row ' . $rowNumber . ': default category not found in this workspace.');
			}
			if ((string)($category['type'] ?? '') !== $direction) {
				throw new \InvalidArgumentException(
					'Row ' . $rowNumber . ': the default ' . $direction . ' category does not match this row.',
				);
			}
			return $category;
		}

		throw new \InvalidArgumentException(
			'Row ' . $rowNumber . ': category is required. Pick a default category for import or add a category column with category names.',
		);
	}
}
