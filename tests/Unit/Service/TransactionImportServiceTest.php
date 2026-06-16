<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\AccessControlService;
use OCA\BudgetCheck\Service\AuditLogService;
use OCA\BudgetCheck\Service\BookingStatusService;
use OCA\BudgetCheck\Service\CategoryService;
use OCA\BudgetCheck\Service\MoneyService;
use OCA\BudgetCheck\Service\TransactionImportService;
use OCA\BudgetCheck\Service\TransactionService;
use OCA\BudgetCheck\Service\WorkspaceService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class TransactionImportServiceTest extends TestCase
{
	public function testPreviewAcceptsEuropeanAmountFormat(): void
	{
		$expenseCategory = ['id' => 10, 'name' => 'Groceries', 'type' => CategoryService::TYPE_EXPENSE];
		$transactions = $this->createMock(TransactionService::class);
		$transactions->method('findExistingExternalRefs')->willReturn([]);
		$transactions->expects($this->once())
			->method('validateCreatePayload')
			->with(
				1,
				'user1',
				$this->callback(static function (array $payload): bool {
					return $payload['amount'] === '1.234,56';
				}),
				$this->isType('array'),
				$expenseCategory,
				null,
			);

		$service = $this->makeService([$expenseCategory], $transactions);
		$workspace = $this->sampleWorkspace();

		$result = $service->preview(1, 'user1', $workspace, [[
			'bookingDate' => '2026-01-15',
			'title' => 'Supermarket',
			'direction' => CategoryService::TYPE_EXPENSE,
			'amount' => '1.234,56',
		]], [
			'expenseCategoryId' => 10,
		]);

		$this->assertSame(1, $result['validRows']);
		$this->assertSame(0, $result['invalidRows']);
		$this->assertSame(0, $result['skippedRows']);
	}

	public function testPreviewRejectsInvalidAmountWithRowNumber(): void
	{
		$expenseCategory = ['id' => 10, 'name' => 'Groceries', 'type' => CategoryService::TYPE_EXPENSE];
		$transactions = $this->makeTransactionServiceForValidation();
		$service = $this->makeService([$expenseCategory], $transactions);
		$workspace = $this->sampleWorkspace();

		$result = $service->preview(1, 'user1', $workspace, [[
			'bookingDate' => '2026-01-15',
			'title' => 'Broken amount',
			'direction' => CategoryService::TYPE_EXPENSE,
			'amount' => '1.234.56',
			'sourceRowNumber' => 42,
		]], [
			'expenseCategoryId' => 10,
		]);

		$this->assertSame(0, $result['validRows']);
		$this->assertSame(1, $result['invalidRows']);
		$this->assertSame(42, (int)($result['errors'][0]['rowNumber'] ?? 0));
		$this->assertStringStartsWith('Row 42:', (string)($result['errors'][0]['message'] ?? ''));
	}

	public function testPreviewAcceptsEnglishThousandsFormat(): void
	{
		$incomeCategory = ['id' => 20, 'name' => 'Salary', 'type' => CategoryService::TYPE_INCOME];
		$transactions = $this->createMock(TransactionService::class);
		$transactions->method('findExistingExternalRefs')->willReturn([]);
		$transactions->expects($this->once())
			->method('validateCreatePayload')
			->with(
				1,
				'user1',
				$this->callback(static function (array $payload): bool {
					return $payload['amount'] === '1,234.56';
				}),
				$this->isType('array'),
				$incomeCategory,
				null,
			);

		$service = $this->makeService([$incomeCategory], $transactions);
		$result = $service->preview(1, 'user1', $this->sampleWorkspace(), [[
			'bookingDate' => '2026-01-31',
			'title' => 'Payroll',
			'direction' => CategoryService::TYPE_INCOME,
			'amount' => '1,234.56',
		]], [
			'incomeCategoryId' => 20,
		]);

		$this->assertSame(1, $result['validRows']);
	}

	public function testPreviewSkipsDuplicateExternalRefWhenEnabled(): void
	{
		$expenseCategory = ['id' => 10, 'name' => 'Groceries', 'type' => CategoryService::TYPE_EXPENSE];
		$transactions = $this->createMock(TransactionService::class);
		$transactions->method('findExistingExternalRefs')->willReturn(['BANK-001']);
		$transactions->expects($this->once())->method('validateCreatePayload');

		$service = $this->makeService([$expenseCategory], $transactions);
		$result = $service->preview(1, 'user1', $this->sampleWorkspace(), [
			[
				'bookingDate' => '2026-01-01',
				'title' => 'Duplicate',
				'direction' => CategoryService::TYPE_EXPENSE,
				'amount' => '10,00',
				'externalRef' => 'BANK-001',
			],
			[
				'bookingDate' => '2026-01-02',
				'title' => 'New',
				'direction' => CategoryService::TYPE_EXPENSE,
				'amount' => '20,00',
				'externalRef' => 'BANK-002',
			],
		], ['expenseCategoryId' => 10], ['skipDuplicates' => true]);

		$this->assertSame(1, $result['validRows']);
		$this->assertSame(1, $result['skippedRows']);
		$this->assertSame(0, $result['invalidRows']);
	}

	public function testPreviewUsesDefaultCategoryForEmptyCategoryCell(): void
	{
		$expenseCategory = ['id' => 10, 'name' => 'Groceries', 'type' => CategoryService::TYPE_EXPENSE];
		$transactions = $this->createMock(TransactionService::class);
		$transactions->method('findExistingExternalRefs')->willReturn([]);
		$transactions->expects($this->once())
			->method('validateCreatePayload')
			->with(
				1,
				'user1',
				$this->callback(static function (array $payload): bool {
					return (int)($payload['categoryId'] ?? 0) === 10;
				}),
				$this->isType('array'),
				$expenseCategory,
				null,
			);

		$service = $this->makeService([$expenseCategory], $transactions);
		$result = $service->preview(1, 'user1', $this->sampleWorkspace(), [[
			'bookingDate' => '2026-01-15',
			'title' => 'No category in row',
			'direction' => CategoryService::TYPE_EXPENSE,
			'amount' => '12,50',
			'category' => '',
		]], ['expenseCategoryId' => 10]);

		$this->assertSame(1, $result['validRows']);
	}

	public function testPreviewSkipsFingerprintDuplicateWhenEnabled(): void
	{
		$expenseCategory = ['id' => 10, 'name' => 'Groceries', 'type' => CategoryService::TYPE_EXPENSE];
		$transactions = $this->createMock(TransactionService::class);
		$transactions->method('findExistingExternalRefs')->willReturn([]);
		$transactions->method('findExistingFingerprintKeys')->willReturn(['2026-01-01|1000|expense|coffee']);
		$transactions->expects($this->once())->method('validateCreatePayload');

		$service = $this->makeService([$expenseCategory], $transactions);
		$result = $service->preview(1, 'user1', $this->sampleWorkspace(), [
			[
				'bookingDate' => '2026-01-01',
				'title' => 'Coffee',
				'direction' => CategoryService::TYPE_EXPENSE,
				'amount' => '10,00',
			],
			[
				'bookingDate' => '2026-01-02',
				'title' => 'Rent',
				'direction' => CategoryService::TYPE_EXPENSE,
				'amount' => '20,00',
			],
		], ['expenseCategoryId' => 10], ['skipDuplicates' => true, 'skipFingerprintDuplicates' => true]);

		$this->assertSame(1, $result['validRows']);
		$this->assertSame(1, $result['skippedRows']);
		$this->assertSame(0, $result['invalidRows']);
	}

	/**
	 * @param list<array<string,mixed>> $categories
	 */
	private function makeService(array $categories, TransactionService $transactions): TransactionImportService
	{
		$categoriesSvc = $this->createMock(CategoryService::class);
		$categoriesSvc->method('listForWorkspace')->willReturn($categories);
		$categoriesSvc->method('loadForWorkspace')->willReturnCallback(
			static function (int $id, int $workspaceId) use ($categories): ?array {
				foreach ($categories as $category) {
					if ((int)$category['id'] === $id) {
						return $category;
					}
				}
				return null;
			},
		);

		$access = $this->createMock(AccessControlService::class);
		$audit = $this->createMock(AuditLogService::class);
		$bookingStatuses = $this->createMock(BookingStatusService::class);
		$db = $this->createMock(IDBConnection::class);

		return new TransactionImportService(
			$categoriesSvc,
			$bookingStatuses,
			$transactions,
			new MoneyService(),
			$audit,
			$access,
			$db,
		);
	}

	private function makeTransactionServiceForValidation(): TransactionService
	{
		$ref = new \ReflectionClass(TransactionService::class);
		/** @var TransactionService $svc */
		$svc = $ref->newInstanceWithoutConstructor();

		$moneyProp = new \ReflectionProperty(TransactionService::class, 'money');
		$moneyProp->setAccessible(true);
		$moneyProp->setValue($svc, new MoneyService());

		$access = $this->createMock(AccessControlService::class);
		$accessProp = new \ReflectionProperty(TransactionService::class, 'access');
		$accessProp->setAccessible(true);
		$accessProp->setValue($svc, $access);

		return $svc;
	}

	/** @return array<string,mixed> */
	private function sampleWorkspace(): array
	{
		return [
			'id' => 1,
			'type' => WorkspaceService::TYPE_HOUSEHOLD,
			'currencyCode' => 'EUR',
			'taxModeEnabled' => false,
			'defaultVatRateBp' => 0,
		];
	}
}
