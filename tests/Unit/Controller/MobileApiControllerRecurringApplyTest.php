<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Controller;

use OCA\BudgetCheck\Controller\MobileApiController;
use OCA\BudgetCheck\Service\AccessControlService;
use OCA\BudgetCheck\Service\BookingStatusService;
use OCA\BudgetCheck\Service\CategoryService;
use OCA\BudgetCheck\Service\MobileIdempotencyService;
use OCA\BudgetCheck\Service\MobilePushService;
use OCA\BudgetCheck\Service\RateLimitService;
use OCA\BudgetCheck\Service\RecurringRuleService;
use OCA\BudgetCheck\Service\SummaryService;
use OCA\BudgetCheck\Service\TransactionAttachmentService;
use OCA\BudgetCheck\Service\TransactionExportService;
use OCA\BudgetCheck\Service\TransactionService;
use OCA\BudgetCheck\Service\WorkspaceDeletionService;
use OCA\BudgetCheck\Service\WorkspaceService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Authentication\Token\IProvider;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Parity guard: POST .../recurring-suggestions/{ruleId}/apply must never return
 * a successful-looking payload when zero rows were written — the mobile client
 * would otherwise announce "Booked" over an empty commit (the same silent-drop
 * class as the dutycheck suggest-fill bug).
 */
final class MobileApiControllerRecurringApplyTest extends TestCase
{
	private function makeController(
		WorkspaceService&MockObject $workspaces,
		RecurringRuleService&MockObject $recurring,
		TransactionService&MockObject $transactions,
	): MobileApiController {
		$request = $this->createMock(IRequest::class);
		$request->method('passesCSRFCheck')->willReturn(true);

		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('alice');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$categories = $this->createMock(CategoryService::class);
		$categories->method('loadForWorkspace')->willReturn([
			'id' => 10,
			'name' => 'Rent',
			'type' => 'expense',
			'isActive' => true,
		]);

		return new MobileApiController(
			$request,
			$userSession,
			$this->createMock(IUserManager::class),
			$this->createMock(IProvider::class),
			$access,
			$workspaces,
			$this->createMock(WorkspaceDeletionService::class),
			$categories,
			$transactions,
			$this->createMock(BookingStatusService::class),
			$this->createMock(SummaryService::class),
			$recurring,
			$this->createMock(MobileIdempotencyService::class),
			$this->createMock(MobilePushService::class),
			$this->createMock(RateLimitService::class),
			$this->createMock(TransactionAttachmentService::class),
			$this->createMock(TransactionExportService::class),
			$this->createMock(IAppManager::class),
			$l10n,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function workspace(): array
	{
		return [
			'id' => 1,
			'name' => 'Home',
			'type' => 'household',
			'role' => 'manager',
			'currencyCode' => 'EUR',
			'currencyDecimals' => 2,
		];
	}

	private function dueRuleRow(): array
	{
		return [
			'id' => 5,
			'categoryId' => 10,
			'nextDueDate' => '2026-01-01',
		];
	}

	public function testApplyRejectsZeroWriteBatchFromClosedMonth(): void
	{
		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->method('getForUser')->willReturn($this->workspace());

		$recurring = $this->createMock(RecurringRuleService::class);
		$recurring->method('ownerWorkspaceId')->willReturn(1);
		$recurring->method('loadHydrated')->willReturn($this->dueRuleRow());
		$recurring->method('workspaceTodayIso')->willReturn('2026-01-15');
		$recurring->method('generateDue')->willReturn([
			'count' => 0,
			'skipped' => 1,
			'skippedClosed' => 1,
			'promoted' => 0,
			'transactionIds' => [],
			'nextDueDate' => '2026-02-01',
		]);

		$transactions = $this->createMock(TransactionService::class);
		$transactions->expects(self::never())->method('loadForWorkspace');

		$response = $this->makeController($workspaces, $recurring, $transactions)
			->applyRecurringSuggestion(1, 5);

		self::assertSame(422, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['ok']);
		self::assertSame('MONTH_CLOSED', $data['error']['code']);
		self::assertStringContainsString('closed', (string)$data['message']);
	}

	public function testApplyRejectsZeroWriteBatchFromExistingEntry(): void
	{
		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->method('getForUser')->willReturn($this->workspace());

		$recurring = $this->createMock(RecurringRuleService::class);
		$recurring->method('ownerWorkspaceId')->willReturn(1);
		$recurring->method('loadHydrated')->willReturn($this->dueRuleRow());
		$recurring->method('workspaceTodayIso')->willReturn('2026-01-15');
		$recurring->method('generateDue')->willReturn([
			'count' => 0,
			'skipped' => 1,
			'skippedClosed' => 0,
			'promoted' => 0,
			'transactionIds' => [],
			'nextDueDate' => '2026-02-01',
		]);

		$transactions = $this->createMock(TransactionService::class);
		$transactions->expects(self::never())->method('loadForWorkspace');

		$response = $this->makeController($workspaces, $recurring, $transactions)
			->applyRecurringSuggestion(1, 5);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['ok']);
		self::assertSame('VALIDATION', $data['error']['code']);
	}

	public function testApplyReturnsTransactionWhenDueBatchWrites(): void
	{
		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->method('getForUser')->willReturn($this->workspace());

		$recurring = $this->createMock(RecurringRuleService::class);
		$recurring->method('ownerWorkspaceId')->willReturn(1);
		$recurring->method('loadHydrated')->willReturn($this->dueRuleRow());
		$recurring->method('workspaceTodayIso')->willReturn('2026-01-15');
		$recurring->method('generateDue')->willReturn([
			'count' => 1,
			'skipped' => 0,
			'skippedClosed' => 0,
			'promoted' => 0,
			'transactionIds' => [7],
			'nextDueDate' => '2026-02-01',
		]);

		$transactions = $this->createMock(TransactionService::class);
		$transactions->expects(self::once())
			->method('loadForWorkspace')
			->with(7, 1)
			->willReturn(['id' => 7, 'title' => 'Rent']);

		$response = $this->makeController($workspaces, $recurring, $transactions)
			->applyRecurringSuggestion(1, 5);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertTrue($data['ok']);
		self::assertSame(7, $data['transaction']['id']);
	}
}
