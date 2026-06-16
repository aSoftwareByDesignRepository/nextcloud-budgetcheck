<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

use OCA\BudgetCheck\Exception\AccessDeniedException;

class TransactionImportService
{
	private const MAX_ROWS_PER_IMPORT = 500;

	public function __construct(
		private CategoryService $categories,
		private BookingStatusService $bookingStatuses,
		private TransactionService $transactions,
		private AuditLogService $audit,
		private AccessControlService $access,
		private \OCP\IDBConnection $db,
	) {
	}

	/**
	 * @param list<array<string,mixed>> $rows
	 * @param array{expenseCategoryId?:int,incomeCategoryId?:int} $defaults
	 */
	public function preview(int $workspaceId, string $userId, array $workspace, array $rows, array $defaults = []): array
	{
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_CONTRIBUTOR);
		$normalizedRows = $this->normalizeRows($rows);
		$ctx = $this->buildImportContext($workspaceId, $userId, $defaults);

		$ok = 0;
		$errors = [];
		foreach ($normalizedRows as $i => $row) {
			try {
				$this->validateRow($workspaceId, $userId, $workspace, $row, $ctx, $i + 1);
				$ok++;
			} catch (\InvalidArgumentException|AccessDeniedException $e) {
				$errors[] = [
					'rowNumber' => $i + 1,
					'message' => $e->getMessage(),
				];
			}
		}

		$this->audit->record($userId, 'transaction_import_previewed', 'workspace', (string)$workspaceId, [
			'rows' => count($normalizedRows),
			'valid' => $ok,
			'invalid' => count($errors),
		], $workspaceId);

		return [
			'totalRows' => count($normalizedRows),
			'validRows' => $ok,
			'invalidRows' => count($errors),
			'errors' => array_slice($errors, 0, 100),
		];
	}

	/**
	 * @param list<array<string,mixed>> $rows
	 * @param array{expenseCategoryId?:int,incomeCategoryId?:int} $defaults
	 */
	public function commit(int $workspaceId, string $userId, array $workspace, array $rows, array $defaults = []): array
	{
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_CONTRIBUTOR);
		$normalizedRows = $this->normalizeRows($rows);
		$ctx = $this->buildImportContext($workspaceId, $userId, $defaults);

		$errors = [];
		$resolved = [];
		foreach ($normalizedRows as $i => $row) {
			try {
				$resolved[] = $this->validateRow($workspaceId, $userId, $workspace, $row, $ctx, $i + 1);
			} catch (\InvalidArgumentException|AccessDeniedException $e) {
				$errors[] = [
					'rowNumber' => $i + 1,
					'message' => $e->getMessage(),
				];
			}
		}
		if ($errors !== []) {
			return [
				'createdCount' => 0,
				'errorCount' => count($errors),
				'errors' => array_slice($errors, 0, 100),
			];
		}

		$createdCount = 0;
		$this->db->beginTransaction();
		try {
			foreach ($normalizedRows as $i => $row) {
				[$category, $status] = $resolved[$i];
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
		], $workspaceId);

		return [
			'createdCount' => $createdCount,
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
			];
		}

		return $out;
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
				throw new \InvalidArgumentException('bookingStatusId is only allowed for project workspaces.');
			}
			$status = $this->bookingStatuses->loadActiveForWorkspace((int)$statusId, $workspaceId);
			if ($status === null) {
				throw new AccessDeniedException();
			}
		}

		$payload = $row;
		$payload['categoryId'] = (int)$category['id'];
		$this->transactions->validateCreatePayload($workspaceId, $userId, $payload, $workspace, $category, $status);
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
