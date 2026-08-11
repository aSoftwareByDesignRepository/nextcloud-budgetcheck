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
		self::assertArrayHasKey('canCreateWorkspace', $data['capabilities']);
	}

	public function testCreateWorkspaceRequiresAppAdminForStandard(): void
	{
		$this->access->method('currentUserId')->willReturn('alice');
		$this->access->method('normalisePrivacyMode')->willReturn('standard');
		$this->access->method('canCreateWorkspace')->with('alice', 'standard')->willReturn(false);
		$this->request->method('getHeader')->willReturnCallback(
			static function (string $name): string {
				if (strcasecmp($name, 'Authorization') === 0) {
					return 'Basic ' . base64_encode('alice:app-password');
				}
				return '';
			}
		);
		$this->request->method('getParam')->willReturn(null);
		$this->request->method('getParams')->willReturn(['name' => 'Nope', 'type' => 'household']);

		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->expects(self::never())->method('createWorkspace');
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
			$this->appManager,
			$l10n,
			$this->createMock(LoggerInterface::class),
		);

		$response = $controller->createWorkspace();
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame('FORBIDDEN', $response->getData()['error']['code']);
	}

	public function testCreatePrivateWorkspaceAllowedWhenDoorPasser(): void
	{
		$this->access->method('currentUserId')->willReturn('alice');
		$this->access->method('normalisePrivacyMode')->willReturn('private');
		$this->access->method('canCreateWorkspace')->willReturnCallback(
			static fn (string $uid, string $mode): bool => $uid === 'alice' && $mode === 'private'
		);
		$this->access->method('canCreateAnyWorkspace')->with('alice')->willReturn(true);
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
			'name' => 'Home',
			'type' => 'household',
			'privacyMode' => 'private',
			'primaryPlanningYear' => 2026,
		]);

		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->expects(self::once())
			->method('createWorkspace')
			->with('alice', self::callback(static function (array $payload): bool {
				return ($payload['privacyMode'] ?? null) === 'private';
			}))
			->willReturn([
				'id' => 9,
				'name' => 'Home',
				'type' => 'household',
				'privacyMode' => 'private',
				'role' => 'manager',
				'currencyCode' => 'EUR',
				'currencyDecimals' => 2,
				'timezone' => 'Europe/Berlin',
			]);
		$rate = $this->createMock(RateLimitService::class);
		$rate->expects(self::once())->method('assertAllowed');
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
			$rate,
			$this->createMock(TransactionAttachmentService::class),
			$this->appManager,
			$l10n,
			$this->createMock(LoggerInterface::class),
		);

		$response = $controller->createWorkspace();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertTrue($data['ok']);
		self::assertSame('private', $data['workspace']['privacyMode']);
	}

	public function testUpdateWorkspacePrivacyMode(): void
	{
		$this->access->method('currentUserId')->willReturn('alice');
		$this->access->method('normalisePrivacyMode')->willReturn('private');
		$this->access->method('favoriteWorkspaceIds')->with('alice')->willReturn([9]);
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
			'privacyMode' => 'private',
		]);

		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->expects(self::once())
			->method('updateWorkspace')
			->with(9, 'alice', self::callback(static function (array $payload): bool {
				return ($payload['privacyMode'] ?? null) === 'private'
					&& count($payload) === 1;
			}))
			->willReturn([
				'id' => 9,
				'name' => 'Home',
				'type' => 'household',
				'privacyMode' => 'private',
				'role' => 'manager',
				'currencyCode' => 'EUR',
				'currencyDecimals' => 2,
				'timezone' => 'Europe/Berlin',
				'capabilities' => [
					'canManagePrivacy' => true,
					'canAssignGroups' => false,
				],
			]);
		$rate = $this->createMock(RateLimitService::class);
		$rate->expects(self::once())->method('assertAllowed');
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
			$rate,
			$this->createMock(TransactionAttachmentService::class),
			$this->appManager,
			$l10n,
			$this->createMock(LoggerInterface::class),
		);

		$response = $controller->updateWorkspace(9);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertTrue($data['ok']);
		self::assertSame('private', $data['workspace']['privacyMode']);
		self::assertTrue($data['workspace']['isFavorite']);
		self::assertTrue($data['workspace']['capabilities']['canManagePrivacy']);
		self::assertFalse($data['workspace']['capabilities']['canAssignGroups']);
	}

	public function testUpdateWorkspaceRejectsEmptyPatch(): void
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
			'name' => 'ignored',
		]);
		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->expects(self::never())->method('updateWorkspace');
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
			$this->appManager,
			$l10n,
			$this->createMock(LoggerInterface::class),
		);

		$response = $controller->updateWorkspace(9);
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('VALIDATION', $response->getData()['error']['code']);
	}

	public function testMonthlySummaryMapsMinorUnits(): void
	{
		$this->access->method('currentUserId')->willReturn('alice');
		$this->request->method('getParam')->willReturnCallback(
			static function (string $name, $default = null) {
				return $name === 'yearMonth' ? '2026-07' : $default;
			}
		);

		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->method('getForUser')->willReturn([
			'id' => 1,
			'name' => 'Home',
			'type' => 'household',
			'role' => 'manager',
			'currencyCode' => 'EUR',
			'currencyDecimals' => 2,
			'activeCalendarYearMonth' => '2026-07',
		]);
		$summaries = $this->createMock(SummaryService::class);
		$summaries->expects(self::once())
			->method('household')
			->with(1, 'alice', '2026-07')
			->willReturn([
				'yearMonth' => '2026-07',
				'isClosed' => false,
				'totals' => [
					'income' => ['minor' => 10000],
					'expense' => ['minor' => 4000],
					'netResult' => ['minor' => 6000],
					'availableAfterSavings' => ['minor' => 5000],
				],
				'budget' => [
					'plannedTotal' => ['minor' => 3000],
					'actualTotal' => ['minor' => 2000],
					'remaining' => ['minor' => 1000],
					'byCategory' => [],
				],
				'warnings' => [],
			]);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$controller = new MobileApiController(
			$this->request,
			$this->userSession,
			$this->access,
			$workspaces,
			$this->createMock(CategoryService::class),
			$this->transactions,
			$this->createMock(BookingStatusService::class),
			$summaries,
			$this->createMock(RecurringRuleService::class),
			$this->createMock(MobileIdempotencyService::class),
			$this->createMock(MobilePushService::class),
			$this->createMock(RateLimitService::class),
			$this->createMock(TransactionAttachmentService::class),
			$this->appManager,
			$l10n,
			$this->createMock(LoggerInterface::class),
		);

		$response = $controller->monthlySummary(1);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertTrue($data['ok']);
		self::assertSame('2026-07', $data['yearMonth']);
		self::assertSame(10000, $data['incomeMinor']);
		self::assertSame(4000, $data['expenseMinor']);
		self::assertSame(5000, $data['availableAfterSavingsMinor']);
	}

	public function testPeriodSummaryRejectsHouseholdViaService(): void
	{
		$this->access->method('currentUserId')->willReturn('alice');
		$this->request->method('getParam')->willReturn(null);

		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->method('getForUser')->willReturn([
			'id' => 2,
			'name' => 'Home',
			'type' => 'household',
			'currencyCode' => 'EUR',
			'currencyDecimals' => 2,
		]);
		$summaries = $this->createMock(SummaryService::class);
		$summaries->expects(self::once())
			->method('projectPeriod')
			->willThrowException(new \OCA\BudgetCheck\Exception\WorkspaceTypeMismatchException('project', 'household', 'project_period_summary'));

		$l10n = $this->createMock(IL10N::class);
		$controller = new MobileApiController(
			$this->request,
			$this->userSession,
			$this->access,
			$workspaces,
			$this->createMock(CategoryService::class),
			$this->transactions,
			$this->createMock(BookingStatusService::class),
			$summaries,
			$this->createMock(RecurringRuleService::class),
			$this->createMock(MobileIdempotencyService::class),
			$this->createMock(MobilePushService::class),
			$this->createMock(RateLimitService::class),
			$this->createMock(TransactionAttachmentService::class),
			$this->appManager,
			$l10n,
			$this->createMock(LoggerInterface::class),
		);

		$response = $controller->periodSummary(2);
		self::assertSame(422, $response->getStatus());
		self::assertSame('WORKSPACE_TYPE_MISMATCH', $response->getData()['error']['code']);
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
		$this->request->method('passesCSRFCheck')->willReturn(false);
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

	public function testForgedRequesttokenWithoutValidCsrfIsForbidden(): void
	{
		$this->access->method('currentUserId')->willReturn('alice');
		$this->request->method('getHeader')->willReturnCallback(
			static function (string $name): string {
				return $name === 'requesttoken' ? 'forged-not-validated' : '';
			}
		);
		$this->request->method('passesCSRFCheck')->willReturn(false);
		$this->request->method('getParam')->willReturn('forged-not-validated');
		$this->request->method('getParams')->willReturn([]);

		$this->transactions->expects(self::never())->method('create');

		$response = $this->controller->createTransaction(1);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame('FORBIDDEN', $response->getData()['error']['code']);
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
		$this->request->method('passesCSRFCheck')->willReturn(false);
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
	public function testYearlySummaryMapsMonths(): void
	{
		$this->access->method('currentUserId')->willReturn('alice');
		$this->request->method('getParam')->willReturnCallback(
			static function (string $name, $default = null) {
				return $name === 'year' ? '2026' : $default;
			}
		);

		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->method('getForUser')->willReturn([
			'id' => 1,
			'name' => 'Home',
			'type' => 'household',
			'currencyCode' => 'EUR',
			'currencyDecimals' => 2,
		]);
		$summaries = $this->createMock(SummaryService::class);
		$summaries->expects(self::once())
			->method('yearly')
			->with(1, 'alice', 2026)
			->willReturn([
				'year' => 2026,
				'totals' => [
					'income' => ['minor' => 12000],
					'expense' => ['minor' => 5000],
					'netResult' => ['minor' => 7000],
					'overBudgetMonths' => 1,
				],
				'months' => [[
					'yearMonth' => '2026-01',
					'income' => ['minor' => 1000],
					'expense' => ['minor' => 400],
					'netResult' => ['minor' => 600],
					'availableAfterSavings' => ['minor' => 500],
					'overBudget' => true,
					'budget' => [
						'overspent' => ['minor' => 150],
					],
					'isClosed' => false,
				]],
			]);

		$l10n = $this->createMock(IL10N::class);
		$controller = new MobileApiController(
			$this->request,
			$this->userSession,
			$this->access,
			$workspaces,
			$this->createMock(CategoryService::class),
			$this->transactions,
			$this->createMock(BookingStatusService::class),
			$summaries,
			$this->createMock(RecurringRuleService::class),
			$this->createMock(MobileIdempotencyService::class),
			$this->createMock(MobilePushService::class),
			$this->createMock(RateLimitService::class),
			$this->createMock(TransactionAttachmentService::class),
			$this->appManager,
			$l10n,
			$this->createMock(LoggerInterface::class),
		);

		$response = $controller->yearlySummary(1);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame(2026, $data['year']);
		self::assertSame(12000, $data['incomeMinor']);
		self::assertCount(1, $data['months']);
		self::assertTrue($data['months'][0]['overBudget']);
		self::assertSame(150, $data['months'][0]['budgetOverspentMinor']);
	}

	public function testCookieOnlyCreateWorkspaceReturnsJsonForbidden(): void
	{
		$this->access->method('currentUserId')->willReturn('alice');
		$this->access->method('isAppAdmin')->willReturn(true);
		$this->request->method('getHeader')->willReturn('');
		$this->request->method('passesCSRFCheck')->willReturn(false);
		$this->request->method('getParam')->willReturn(null);
		$this->request->method('getParams')->willReturn(['name' => 'X', 'type' => 'household']);

		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->expects(self::never())->method('createWorkspace');
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
			$this->appManager,
			$l10n,
			$this->createMock(LoggerInterface::class),
		);

		$response = $controller->createWorkspace();
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame('FORBIDDEN', $response->getData()['error']['code']);
	}

	public function testMonthlySummaryRejectsProjectViaService(): void
	{
		$this->access->method('currentUserId')->willReturn('alice');
		$this->request->method('getParam')->willReturn('2026-07');

		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->method('getForUser')->willReturn([
			'id' => 3,
			'name' => 'Build',
			'type' => 'project',
			'currencyCode' => 'EUR',
			'currencyDecimals' => 2,
			'activeCalendarYearMonth' => null,
		]);
		$summaries = $this->createMock(SummaryService::class);
		$summaries->expects(self::once())
			->method('household')
			->willThrowException(new \OCA\BudgetCheck\Exception\WorkspaceTypeMismatchException('household', 'project', 'monthly_summary'));

		$l10n = $this->createMock(IL10N::class);
		$controller = new MobileApiController(
			$this->request,
			$this->userSession,
			$this->access,
			$workspaces,
			$this->createMock(CategoryService::class),
			$this->transactions,
			$this->createMock(BookingStatusService::class),
			$summaries,
			$this->createMock(RecurringRuleService::class),
			$this->createMock(MobileIdempotencyService::class),
			$this->createMock(MobilePushService::class),
			$this->createMock(RateLimitService::class),
			$this->createMock(TransactionAttachmentService::class),
			$this->appManager,
			$l10n,
			$this->createMock(LoggerInterface::class),
		);

		$response = $controller->monthlySummary(3);
		self::assertSame(422, $response->getStatus());
		self::assertSame('WORKSPACE_TYPE_MISMATCH', $response->getData()['error']['code']);
	}

	public function testHomeYearScopeUsesHouseholdSpan(): void
	{
		$this->access->method('currentUserId')->willReturn('alice');
		$this->request->method('getParam')->willReturnCallback(
			static function (string $name, $default = null) {
				return match ($name) {
					'scope' => 'year',
					'year' => '2025',
					default => $default,
				};
			}
		);

		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->method('getForUser')->willReturn([
			'id' => 1,
			'name' => 'Home',
			'type' => 'household',
			'role' => 'manager',
			'currencyCode' => 'EUR',
			'currencyDecimals' => 2,
			'timezone' => 'UTC',
			'taxModeEnabled' => false,
			'activeCalendarYearMonth' => '2026-07',
		]);
		$this->access->method('rememberLastUsedWorkspace');

		$summaries = $this->createMock(SummaryService::class);
		$summaries->expects(self::once())
			->method('householdSpan')
			->with(1, 'alice', '2025-01', '2025-12', true)
			->willReturn([
				'yearMonth' => null,
				'fromYearMonth' => '2025-01',
				'toYearMonth' => '2025-12',
				'isClosed' => false,
				'totals' => [
					'income' => ['minor' => 10000],
					'expense' => ['minor' => 4000],
					'netResult' => ['minor' => 6000],
					'availableAfterSavings' => ['minor' => 5000],
				],
				'budget' => [
					'plannedTotal' => ['minor' => 0],
					'actualTotal' => ['minor' => 0],
					'remaining' => ['minor' => 0],
					'byCategory' => [],
				],
				'warnings' => [],
			]);
		$summaries->expects(self::never())->method('household');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$controller = new MobileApiController(
			$this->request,
			$this->userSession,
			$this->access,
			$workspaces,
			$this->createMock(CategoryService::class),
			$this->transactions,
			$this->createMock(BookingStatusService::class),
			$summaries,
			$this->createMock(RecurringRuleService::class),
			$this->createMock(MobileIdempotencyService::class),
			$this->createMock(MobilePushService::class),
			$this->createMock(RateLimitService::class),
			$this->createMock(TransactionAttachmentService::class),
			$this->appManager,
			$l10n,
			$this->createMock(LoggerInterface::class),
		);

		$response = $controller->home(1);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame('year', $data['scope']['timeScope']);
		self::assertSame(2025, $data['scope']['year']);
		self::assertSame(5000, $data['dominantKpi']['amountMinor']);
	}

	public function testHomeAllScopeUsesLedgerBounds(): void
	{
		$this->access->method('currentUserId')->willReturn('alice');
		$this->request->method('getParam')->willReturnCallback(
			static function (string $name, $default = null) {
				return $name === 'scope' ? 'all' : $default;
			}
		);

		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->method('getForUser')->willReturn([
			'id' => 1,
			'name' => 'Home',
			'type' => 'household',
			'role' => 'manager',
			'currencyCode' => 'EUR',
			'currencyDecimals' => 2,
			'timezone' => 'UTC',
			'taxModeEnabled' => false,
			'activeCalendarYearMonth' => '2026-07',
		]);
		$this->access->method('rememberLastUsedWorkspace');

		$this->transactions->expects(self::once())
			->method('ledgerYearMonthBounds')
			->with(1)
			->willReturn(['firstYearMonth' => '2024-03', 'lastYearMonth' => '2026-02']);

		$summaries = $this->createMock(SummaryService::class);
		$summaries->expects(self::once())
			->method('householdSpan')
			->with(1, 'alice', '2024-03', '2026-02', false)
			->willReturn([
				'yearMonth' => null,
				'fromYearMonth' => '2024-03',
				'toYearMonth' => '2026-02',
				'isClosed' => false,
				'totals' => [
					'income' => ['minor' => 20000],
					'expense' => ['minor' => 8000],
					'netResult' => ['minor' => 12000],
					'availableAfterSavings' => ['minor' => 12000],
				],
				'budget' => [
					'plannedTotal' => ['minor' => 0],
					'actualTotal' => ['minor' => 0],
					'remaining' => ['minor' => 0],
					'byCategory' => [],
				],
				'warnings' => [],
			]);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$controller = new MobileApiController(
			$this->request,
			$this->userSession,
			$this->access,
			$workspaces,
			$this->createMock(CategoryService::class),
			$this->transactions,
			$this->createMock(BookingStatusService::class),
			$summaries,
			$this->createMock(RecurringRuleService::class),
			$this->createMock(MobileIdempotencyService::class),
			$this->createMock(MobilePushService::class),
			$this->createMock(RateLimitService::class),
			$this->createMock(TransactionAttachmentService::class),
			$this->appManager,
			$l10n,
			$this->createMock(LoggerInterface::class),
		);

		$response = $controller->home(1);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame('all', $data['scope']['timeScope']);
		self::assertSame('2024-03', $data['scope']['fromYearMonth']);
		self::assertSame('2026-02', $data['scope']['toYearMonth']);
	}

	public function testHomeRejectsMalformedYear(): void
	{
		$this->access->method('currentUserId')->willReturn('alice');
		$this->request->method('getParam')->willReturnCallback(
			static function (string $name, $default = null) {
				return match ($name) {
					'scope' => 'year',
					'year' => '2025e0',
					default => $default,
				};
			}
		);

		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->method('getForUser')->willReturn([
			'id' => 1,
			'name' => 'Home',
			'type' => 'household',
			'role' => 'manager',
			'currencyCode' => 'EUR',
			'currencyDecimals' => 2,
			'timezone' => 'UTC',
			'taxModeEnabled' => false,
			'activeCalendarYearMonth' => '2026-07',
		]);
		$this->access->method('rememberLastUsedWorkspace');

		$summaries = $this->createMock(SummaryService::class);
		$summaries->expects(self::never())->method('householdSpan');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$controller = new MobileApiController(
			$this->request,
			$this->userSession,
			$this->access,
			$workspaces,
			$this->createMock(CategoryService::class),
			$this->transactions,
			$this->createMock(BookingStatusService::class),
			$summaries,
			$this->createMock(RecurringRuleService::class),
			$this->createMock(MobileIdempotencyService::class),
			$this->createMock(MobilePushService::class),
			$this->createMock(RateLimitService::class),
			$this->createMock(TransactionAttachmentService::class),
			$this->appManager,
			$l10n,
			$this->createMock(LoggerInterface::class),
		);

		$response = $controller->home(1);
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('VALIDATION', $response->getData()['error']['code']);
	}

}
