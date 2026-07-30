<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service\ReceiptSuggest;

use OCA\BudgetCheck\Service\AccessControlService;
use OCA\BudgetCheck\Service\CategoryService;
use OCA\BudgetCheck\Service\ReceiptSuggest\ReceiptSuggestAcceptGuard;
use OCA\BudgetCheck\Service\ReceiptSuggest\ReceiptSuggestAcceptLockInterface;
use OCA\BudgetCheck\Service\ReceiptSuggest\ReceiptSuggestAvailability;
use OCA\BudgetCheck\Service\ReceiptSuggest\ReceiptSuggestConstants;
use OCA\BudgetCheck\Service\ReceiptSuggest\ReceiptSuggestJobStore;
use OCA\BudgetCheck\Service\ReceiptSuggest\ReceiptSuggestMetrics;
use OCA\BudgetCheck\Service\ReceiptSuggest\ReceiptSuggestMetricsInterface;
use OCA\BudgetCheck\Service\ReceiptSuggest\ReceiptSuggestPromptBuilder;
use OCA\BudgetCheck\Service\ReceiptSuggest\ReceiptSuggestService;
use OCA\BudgetCheck\Service\ReceiptSuggest\ReceiptSuggestStagingStore;
use OCA\BudgetCheck\Service\ReceiptSuggest\ReceiptSuggestionPipeline;
use OCA\BudgetCheck\Service\ReceiptSuggest\ReceiptTaskProcessingGateway;
use OCA\BudgetCheck\Service\TransactionAttachmentService;
use OCA\BudgetCheck\Service\TransactionService;
use OCA\BudgetCheck\Service\WorkspaceService;
use OCP\TaskProcessing\Task;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ReceiptSuggestServiceTest extends TestCase
{
	/** @var ReceiptTaskProcessingGateway&MockObject */
	private ReceiptTaskProcessingGateway $tasks;
	/** @var ReceiptSuggestStagingStore&MockObject */
	private ReceiptSuggestStagingStore $staging;
	/** @var ReceiptSuggestJobStore&MockObject */
	private ReceiptSuggestJobStore $jobs;
	/** @var AccessControlService&MockObject */
	private AccessControlService $access;
	/** @var WorkspaceService&MockObject */
	private WorkspaceService $workspaces;
	/** @var CategoryService&MockObject */
	private CategoryService $categories;
	/** @var TransactionService&MockObject */
	private TransactionService $transactions;
	/** @var TransactionAttachmentService&MockObject */
	private TransactionAttachmentService $attachments;
	/** @var ReceiptSuggestAcceptLockInterface&MockObject */
	private ReceiptSuggestAcceptLockInterface $acceptLock;
	/** @var ReceiptSuggestMetricsInterface&MockObject */
	private ReceiptSuggestMetricsInterface $metrics;

	private ReceiptSuggestService $service;

	protected function setUp(): void
	{
		parent::setUp();
		$this->tasks = $this->createMock(ReceiptTaskProcessingGateway::class);
		$this->staging = $this->createMock(ReceiptSuggestStagingStore::class);
		$this->jobs = $this->createMock(ReceiptSuggestJobStore::class);
		$this->access = $this->createMock(AccessControlService::class);
		$this->workspaces = $this->createMock(WorkspaceService::class);
		$this->categories = $this->createMock(CategoryService::class);
		$this->transactions = $this->createMock(TransactionService::class);
		$this->attachments = $this->createMock(TransactionAttachmentService::class);
		$this->acceptLock = $this->createMock(ReceiptSuggestAcceptLockInterface::class);
		$this->metrics = $this->createMock(ReceiptSuggestMetricsInterface::class);
		$this->acceptLock->method('tryAcquire')->willReturn(true);

		$this->service = new ReceiptSuggestService(
			$this->tasks,
			new ReceiptSuggestAvailability(),
			$this->staging,
			$this->jobs,
			new ReceiptSuggestPromptBuilder(),
			new ReceiptSuggestionPipeline(),
			new ReceiptSuggestAcceptGuard(),
			$this->access,
			$this->workspaces,
			$this->categories,
			$this->transactions,
			$this->attachments,
			$this->acceptLock,
			$this->metrics,
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testIsAvailableFalseWithoutProviders(): void
	{
		$this->tasks->method('availableTaskTypeIds')->willReturn([]);
		$this->assertFalse($this->service->isAvailable('alice'));
		$this->assertSame([], $this->service->modesForUser('alice'));
	}

	public function testIsAvailableWithAnalyzeImages(): void
	{
		$this->tasks->method('availableTaskTypeIds')->willReturn([ReceiptSuggestConstants::TASK_ANALYZE_IMAGES]);
		$this->assertTrue($this->service->isAvailable('alice'));
		$this->assertSame(
			[ReceiptSuggestConstants::SOURCE_ANALYZE_IMAGES],
			$this->service->modesForUser('alice'),
		);
	}

	public function testStartSchedulesAnalyzeImagesAndStoresJob(): void
	{
		$this->access->expects($this->once())
			->method('ensureMinimumRole')
			->with(5, 'alice', AccessControlService::ROLE_CONTRIBUTOR);
		$this->workspaces->method('getForUser')->willReturn([
			'id' => 5,
			'currencyCode' => 'EUR',
			'type' => 'household',
		]);
		$this->tasks->method('availableTaskTypeIds')->willReturn([ReceiptSuggestConstants::TASK_ANALYZE_IMAGES]);
		$this->jobs->method('activeJobId')->willReturn(null);
		$this->categories->method('listForWorkspace')->willReturn([
			['id' => 10, 'name' => 'Food', 'type' => 'expense', 'isActive' => true],
		]);
		$this->staging->method('storeUpload')->willReturn([
			'fileId' => 99,
			'path' => '/alice/files/.budgetcheck-receipt-suggest/x.jpg',
			'fileName' => 'receipt.jpg',
			'mimeType' => 'image/jpeg',
			'size' => 1200,
		]);
		$this->tasks->expects($this->once())
			->method('schedule')
			->with(
				ReceiptSuggestConstants::TASK_ANALYZE_IMAGES,
				$this->callback(static function (array $input): bool {
					return ($input['images'][0] ?? null) === 99
						&& is_string($input['input'] ?? null)
						&& str_contains($input['input'], '10=Food');
				}),
				'alice',
				$this->anything(),
			)
			->willReturn(42);
		$this->jobs->expects($this->once())->method('save')->with($this->callback(static function (array $meta): bool {
			return (int)$meta['jobId'] === 42
				&& (int)$meta['workspaceId'] === 5
				&& $meta['phase'] === ReceiptSuggestService::PHASE_VISION
				&& (int)$meta['fileId'] === 99;
		}));

		$result = $this->service->startFromUpload(5, 'alice', [
			'name' => 'receipt.jpg',
			'tmp_name' => '/tmp/x',
			'size' => 10,
			'error' => \UPLOAD_ERR_OK,
			'type' => 'image/jpeg',
		]);
		$this->assertSame(['jobId' => 42, 'status' => 'pending'], $result);
	}

	public function testPollReturnsPendingWhileRunning(): void
	{
		$this->access->expects($this->once())
			->method('ensureMinimumRole')
			->with(5, 'alice', AccessControlService::ROLE_VIEWER);
		$this->jobs->method('get')->willReturn($this->sampleMeta());
		$this->tasks->method('getUserTask')->willReturn([
			'id' => 42,
			'status' => Task::STATUS_RUNNING,
			'output' => null,
			'error' => null,
		]);

		$out = $this->service->poll(5, 'alice', 42);
		$this->assertSame('pending', $out['status']);
		$this->assertSame(42, $out['jobId']);
	}

	public function testPollReadyRunsQualityPipeline(): void
	{
		$this->jobs->method('get')->willReturn($this->sampleMeta());
		$payload = [
			'title' => 'REWE',
			'merchant' => 'REWE',
			'merchantConfidence' => 0.9,
			'bookingDate' => (new \DateTimeImmutable('today'))->modify('-1 day')->format('Y-m-d'),
			'currencyCode' => 'EUR',
			'totalMinor' => 1234,
			'direction' => 'expense',
			'lines' => [[
				'label' => 'Total',
				'amountMinor' => 1234,
				'categoryId' => 10,
				'confidence' => 0.9,
			]],
		];
		$this->tasks->method('getUserTask')->willReturn([
			'id' => 42,
			'status' => Task::STATUS_SUCCESSFUL,
			'output' => ['output' => json_encode($payload, JSON_THROW_ON_ERROR)],
			'error' => null,
		]);
		$this->jobs->expects($this->once())->method('save');

		$out = $this->service->poll(5, 'alice', 42);
		$this->assertSame(ReceiptSuggestConstants::STATUS_READY, $out['status']);
		$this->assertSame(1234, $out['totalMinor']);
		$this->assertSame(10, $out['lines'][0]['categoryId']);
	}

	public function testPollLowQualityCleansUp(): void
	{
		$this->jobs->method('get')->willReturn($this->sampleMeta());
		$this->tasks->method('getUserTask')->willReturn([
			'id' => 42,
			'status' => Task::STATUS_SUCCESSFUL,
			'output' => ['output' => 'not json at all'],
			'error' => null,
		]);
		$this->staging->expects($this->once())->method('delete')->with('alice', 99);
		$this->jobs->expects($this->once())->method('delete')->with('alice', 42, 5);

		$out = $this->service->poll(5, 'alice', 42);
		$this->assertSame(ReceiptSuggestConstants::STATUS_FAILED, $out['status']);
	}

	public function testRejectsSecondActiveJob(): void
	{
		$this->workspaces->method('getForUser')->willReturn(['id' => 5, 'currencyCode' => 'EUR']);
		$this->tasks->method('availableTaskTypeIds')->willReturn([ReceiptSuggestConstants::TASK_ANALYZE_IMAGES]);
		$this->jobs->method('activeJobId')->willReturn(7);
		$this->jobs->method('get')->willReturn(array_merge($this->sampleMeta(), [
			'jobId' => 7,
			'createdAt' => time(),
		]));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('already in progress');
		$this->service->startFromUpload(5, 'alice', [
			'name' => 'a.jpg',
			'tmp_name' => '/tmp/x',
			'size' => 1,
			'error' => \UPLOAD_ERR_OK,
		]);
	}

	public function testAcceptBusyDoesNotTouchLedger(): void
	{
		$lock = $this->createMock(ReceiptSuggestAcceptLockInterface::class);
		$lock->method('tryAcquire')->willReturn(false);
		$metrics = $this->createMock(ReceiptSuggestMetricsInterface::class);
		$metrics->expects($this->once())->method('increment')->with(ReceiptSuggestMetrics::ACCEPT_BUSY);

		$service = new ReceiptSuggestService(
			$this->tasks,
			new ReceiptSuggestAvailability(),
			$this->staging,
			$this->jobs,
			new ReceiptSuggestPromptBuilder(),
			new ReceiptSuggestionPipeline(),
			new ReceiptSuggestAcceptGuard(),
			$this->access,
			$this->workspaces,
			$this->categories,
			$this->transactions,
			$this->attachments,
			$lock,
			$metrics,
			$this->createMock(LoggerInterface::class),
		);

		$this->jobs->expects($this->never())->method('get');
		$this->transactions->expects($this->never())->method('create');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('already being saved');
		$service->accept(5, 'alice', 42, ['status' => 'ready']);
	}

	public function testAcceptClaimsJobBeforeCreate(): void
	{
		$payload = [
			'status' => 'ready',
			'title' => 'REWE',
			'merchant' => 'REWE',
			'merchantConfidence' => 0.9,
			'bookingDate' => (new \DateTimeImmutable('today'))->modify('-1 day')->format('Y-m-d'),
			'currencyCode' => 'EUR',
			'totalMinor' => 1234,
			'direction' => 'expense',
			'lines' => [[
				'label' => 'Total',
				'amountMinor' => 1234,
				'categoryId' => 10,
				'confidence' => 0.9,
			]],
		];
		$this->jobs->method('get')->willReturn($this->sampleMeta());
		$this->workspaces->method('getForUser')->willReturn([
			'id' => 5,
			'currencyCode' => 'EUR',
			'type' => 'household',
		]);
		$this->categories->method('listForWorkspace')->willReturn([
			['id' => 10, 'name' => 'Food', 'type' => 'expense', 'isActive' => true],
		]);
		$this->categories->method('loadForWorkspace')->willReturn([
			'id' => 10, 'name' => 'Food', 'type' => 'expense', 'isActive' => true,
		]);
		$this->staging->method('readBytes')->willReturn('fakepng');
		$this->jobs->expects($this->once())->method('delete')->with('alice', 42, 5);
		$this->transactions->expects($this->once())->method('create')->willReturn(['id' => 77, 'version' => 1]);
		$this->attachments->expects($this->once())->method('attachFromBinary')->willReturn(['id' => 1]);
		$this->staging->expects($this->once())->method('delete')->with('alice', 99);
		$this->acceptLock->expects($this->once())->method('tryAcquire')->with('alice', 42)->willReturn(true);
		$this->acceptLock->expects($this->once())->method('release')->with('alice', 42);
		$this->metrics->expects($this->once())->method('increment')->with(ReceiptSuggestMetrics::ACCEPTED);

		$out = $this->service->accept(5, 'alice', 42, $payload);
		$this->assertCount(1, $out['transactions']);
		$this->assertSame(77, $out['transactions'][0]['id']);
	}

	/** @return array<string, mixed> */
	private function sampleMeta(): array
	{
		return [
			'jobId' => 42,
			'workspaceId' => 5,
			'userId' => 'alice',
			'source' => ReceiptSuggestConstants::SOURCE_ANALYZE_IMAGES,
			'phase' => ReceiptSuggestService::PHASE_VISION,
			'taskId' => 42,
			'fileId' => 99,
			'fileName' => 'receipt.jpg',
			'mimeType' => 'image/jpeg',
			'categoryIds' => [10],
			'currencyCode' => 'EUR',
			'createdAt' => time(),
			'customId' => 'bc-rs-5-abc',
		];
	}
}
