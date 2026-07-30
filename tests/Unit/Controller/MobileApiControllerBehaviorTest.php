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
use OCA\BudgetCheck\Service\ReceiptSuggest\ReceiptSuggestServiceInterface;
use OCA\BudgetCheck\Service\SummaryService;
use OCA\BudgetCheck\Service\TransactionAttachmentService;
use OCA\BudgetCheck\Service\TransactionService;
use OCA\BudgetCheck\Service\WorkspaceService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Behavioral gates: free bootstrap (no license) and cookie-only mutations → JSON 403.
 */
final class MobileApiControllerBehaviorTest extends TestCase
{
	/** @var IRequest&MockObject */
	private IRequest $request;
	/** @var AccessControlService&MockObject */
	private AccessControlService $access;
	/** @var IAppManager&MockObject */
	private IAppManager $appManager;
	/** @var IUserSession&MockObject */
	private IUserSession $userSession;
	/** @var TransactionService&MockObject */
	private TransactionService $transactions;

	private MobileApiController $controller;

	protected function setUp(): void
	{
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->access = $this->createMock(AccessControlService::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->transactions = $this->createMock(TransactionService::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$this->controller = new MobileApiController(
			$this->request,
			$this->userSession,
			$this->access,
			$this->createMock(WorkspaceService::class),
			$this->createMock(CategoryService::class),
			$this->transactions,
			$this->createMock(BookingStatusService::class),
			$this->createMock(SummaryService::class),
			$this->createMock(RecurringRuleService::class),
			$this->createMock(MobileIdempotencyService::class),
			$this->createMock(MobilePushService::class),
			$this->createMock(RateLimitService::class),
			$this->createMock(TransactionAttachmentService::class),
			$this->receiptSuggestMock(),
			$this->appManager,
			$l10n,
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testBootstrapIsFreeWithoutLicenseFields(): void
	{
		$this->access->method('currentUserId')->willReturn('alice');
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('Alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->appManager->method('isEnabledForUser')->with('notifications')->willReturn(false);

		$response = $this->controller->bootstrap();
		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertTrue($data['ok']);
		self::assertArrayHasKey('companion', $data);
		self::assertArrayHasKey('capabilities', $data);
		self::assertArrayNotHasKey('license', $data);
		self::assertArrayNotHasKey('licensed', $data);
		self::assertArrayNotHasKey('seats', $data);
		self::assertArrayNotHasKey('bdc2', $data);
		self::assertSame('alice', $data['user']['uid']);
		self::assertTrue($data['capabilities']['offlineCreate']);
	}

	public function testCookieOnlyCreateReturnsJsonForbiddenNotUncaught(): void
	{
		$this->access->method('currentUserId')->willReturn('alice');
		$this->request->method('getHeader')->willReturnCallback(
			static function (string $name): string {
				// Session cookie auth without Authorization / requesttoken
				return '';
			}
		);
		$this->request->method('getParam')->willReturn(null);
		$this->request->method('getParams')->willReturn([]);

		$this->transactions->expects(self::never())->method('create');

		$response = $this->controller->createTransaction(1);
		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['ok']);
		self::assertSame('FORBIDDEN', $data['error']['code']);
		self::assertSame('Access denied.', $data['message']);
		self::assertSame('Access denied.', $data['error']['message']);
	}

	public function testMissingTransactionReturnsJsonNotFound(): void
	{
		$this->access->method('currentUserId')->willReturn('alice');
		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->expects(self::once())
			->method('getForUser')
			->with(1, 'alice')
			->willReturn([
				'id' => 1,
				'name' => 'Home',
				'type' => 'household',
				'role' => 'contributor',
				'currencyCode' => 'EUR',
			]);
		$this->transactions->expects(self::once())
			->method('loadForWorkspace')
			->with(99, 1)
			->willReturn(null);

		$l10n = $this->createMock(IL10N::class);
		$controller = new MobileApiController(
			$this->request,
			$this->userSession,
			$this->access,
			$workspaces,
			$this->createMock(CategoryService::class),
			$this->transactions,
			$this->createMock(BookingStatusService::class),
			$this->createMock(SummaryService::class),
			$this->createMock(RecurringRuleService::class),
			$this->createMock(MobileIdempotencyService::class),
			$this->createMock(MobilePushService::class),
			$this->createMock(RateLimitService::class),
			$this->createMock(TransactionAttachmentService::class),
			$this->receiptSuggestMock(),
			$this->appManager,
			$l10n,
			$this->createMock(LoggerInterface::class),
		);

		$response = $controller->getTransaction(1, 99);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['ok']);
		self::assertSame('NOT_FOUND', $data['error']['code']);
		self::assertSame('Transaction not found.', $data['error']['message']);
	}

	public function testCookieOnlyDeleteReturnsJsonForbidden(): void
	{
		$this->access->method('currentUserId')->willReturn('alice');
		$this->request->method('getHeader')->willReturn('');
		$this->request->method('getParam')->willReturn(null);
		$this->request->method('getParams')->willReturn(['version' => 1]);

		$this->transactions->expects(self::never())->method('delete');

		$response = $this->controller->deleteTransaction(1, 9);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['ok']);
		self::assertSame('FORBIDDEN', $data['error']['code']);
	}

	public function testInvalidWorkspaceIdReturnsValidationJsonNotUncaught(): void
	{
		$this->access->method('currentUserId')->willReturn('alice');

		$response = $this->controller->home(0);
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['ok']);
		self::assertSame('VALIDATION', $data['error']['code']);
	}

	public function testBasicAuthCreatePassesChannelGateThenHitsWorkspace(): void
	{
		$this->access->method('currentUserId')->willReturn('alice');
		$this->request->method('getHeader')->willReturnCallback(
			static function (string $name): string {
				if (strcasecmp($name, 'Authorization') === 0) {
					return 'Basic ' . base64_encode('alice:app-password');
				}
				return '';
			}
		);
		$this->request->method('getParam')->willReturn(null);
		$this->request->method('getParams')->willReturn([
			'categoryId' => 1,
			'title' => 'Coffee',
			'amountMinor' => 350,
			'direction' => 'expense',
			'bookingDate' => '2026-07-01',
		]);

		$workspaces = $this->createMock(WorkspaceService::class);
		// Rebuild controller with workspace mock that throws AccessDenied so we stop after channel
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$rate = $this->createMock(RateLimitService::class);
		$rate->expects(self::once())->method('assertAllowed');

		$controller = new MobileApiController(
			$this->request,
			$this->userSession,
			$this->access,
			$workspaces,
			$this->createMock(CategoryService::class),
			$this->transactions,
			$this->createMock(BookingStatusService::class),
			$this->createMock(SummaryService::class),
			$this->createMock(RecurringRuleService::class),
			$this->createMock(MobileIdempotencyService::class),
			$this->createMock(MobilePushService::class),
			$rate,
			$this->createMock(TransactionAttachmentService::class),
			$this->receiptSuggestMock(),
			$this->appManager,
			$l10n,
			$this->createMock(LoggerInterface::class),
		);

		$workspaces->expects(self::once())
			->method('getForUser')
			->with(1, 'alice')
			->willThrowException(new \OCA\BudgetCheck\Exception\AccessDeniedException());

		$response = $controller->createTransaction(1);
		// Channel passed; ACL denied → still JSON 403 (proves channel did not reject first)
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame('FORBIDDEN', $response->getData()['error']['code']);
	}

	/** @return ReceiptSuggestServiceInterface&\PHPUnit\Framework\MockObject\MockObject */
	private function receiptSuggestMock(): ReceiptSuggestServiceInterface
	{
		$mock = $this->createMock(ReceiptSuggestServiceInterface::class);
		$mock->method('isAvailable')->willReturn(false);
		$mock->method('modesForUser')->willReturn([]);
		return $mock;
	}
}
