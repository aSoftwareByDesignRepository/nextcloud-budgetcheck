<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Controller;

use OCA\BudgetCheck\Controller\ApiController;
use OCA\BudgetCheck\Exception\AccessDeniedException;
use OCA\BudgetCheck\Service\AccessControlService;
use OCA\BudgetCheck\Service\AuditLogService;
use OCA\BudgetCheck\Service\BudgetPlannedService;
use OCA\BudgetCheck\Service\BudgetService;
use OCA\BudgetCheck\Service\BookingStatusService;
use OCA\BudgetCheck\Service\CategoryService;
use OCA\BudgetCheck\Service\CurrencyCatalog;
use OCA\BudgetCheck\Service\ImportPreferencesService;
use OCA\BudgetCheck\Service\MoneyService;
use OCA\BudgetCheck\Service\RateLimitService;
use OCA\BudgetCheck\Service\RecurringRuleService;
use OCA\BudgetCheck\Service\SavingsTargetService;
use OCA\BudgetCheck\Service\SnapshotService;
use OCA\BudgetCheck\Service\SummaryService;
use OCA\BudgetCheck\Service\SummaryViewPreferencesService;
use OCA\BudgetCheck\Service\TimezoneCatalog;
use OCA\BudgetCheck\Service\TransactionAttachmentService;
use OCA\BudgetCheck\Service\TransactionImportService;
use OCA\BudgetCheck\Service\TransactionService;
use OCA\BudgetCheck\Service\WorkspaceService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Happy-path + AuthZ-negative proofs for the eight web GET actions that were
 * still should_fix in the Atlas api-matrix (import/summary prefs, recurring,
 * budgets/defaults, savings target, admin directory search).
 */
final class ApiControllerReadHappyPathTest extends TestCase
{
	/** @var IRequest&MockObject */
	private IRequest $request;
	/** @var AccessControlService&MockObject */
	private AccessControlService $access;
	/** @var WorkspaceService&MockObject */
	private WorkspaceService $workspaces;
	/** @var ImportPreferencesService&MockObject */
	private ImportPreferencesService $importPrefs;
	/** @var SummaryViewPreferencesService&MockObject */
	private SummaryViewPreferencesService $summaryPrefs;
	/** @var RecurringRuleService&MockObject */
	private RecurringRuleService $recurring;
	/** @var BudgetService&MockObject */
	private BudgetService $budgets;
	/** @var SavingsTargetService&MockObject */
	private SavingsTargetService $savings;
	/** @var RateLimitService&MockObject */
	private RateLimitService $rateLimit;
	/** @var IUserManager&MockObject */
	private IUserManager $userManager;
	/** @var IGroupManager&MockObject */
	private IGroupManager $groupManager;

	private ApiController $controller;

	protected function setUp(): void
	{
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->access = $this->createMock(AccessControlService::class);
		$this->workspaces = $this->createMock(WorkspaceService::class);
		$this->importPrefs = $this->createMock(ImportPreferencesService::class);
		$this->summaryPrefs = $this->createMock(SummaryViewPreferencesService::class);
		$this->recurring = $this->createMock(RecurringRuleService::class);
		$this->budgets = $this->createMock(BudgetService::class);
		$this->savings = $this->createMock(SavingsTargetService::class);
		$this->rateLimit = $this->createMock(RateLimitService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$this->access->method('currentUserId')->willReturn('alice');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$budgetPlanned = new BudgetPlannedService(
			$this->createMock(IDBConnection::class),
			$this->access,
			$this->budgets,
			$this->createMock(CategoryService::class),
			$this->createMock(TransactionService::class),
			$this->createMock(SnapshotService::class),
			$this->createMock(AuditLogService::class),
			$this->createMock(ITimeFactory::class),
		);

		$this->controller = new ApiController(
			'budgetcheck',
			$this->request,
			$this->access,
			$this->workspaces,
			$this->createMock(CategoryService::class),
			$this->createMock(TransactionService::class),
			$this->createMock(TransactionAttachmentService::class),
			$this->recurring,
			$this->budgets,
			$budgetPlanned,
			$this->createMock(BookingStatusService::class),
			$this->createMock(TransactionImportService::class),
			$this->importPrefs,
			$this->summaryPrefs,
			$this->savings,
			$this->createMock(SummaryService::class),
			$this->createMock(SnapshotService::class),
			$this->createMock(AuditLogService::class),
			$this->rateLimit,
			$this->createMock(MoneyService::class),
			$this->createMock(CurrencyCatalog::class),
			$this->createMock(TimezoneCatalog::class),
			$this->userManager,
			$this->groupManager,
			$this->createMock(LoggerInterface::class),
			$l10n,
		);
	}

	/** @return array<string,mixed> */
	private function household(): array
	{
		return [
			'id' => 7,
			'type' => WorkspaceService::TYPE_HOUSEHOLD,
			'currencyCode' => 'EUR',
			'includeSpecialsInTotalsDefault' => false,
			'role' => AccessControlService::ROLE_MANAGER,
		];
	}

	public function testGetImportPreferencesHappyPath(): void
	{
		$prefs = ['directionMode' => 'auto', 'skipDuplicates' => false];
		$this->workspaces->expects($this->once())->method('getForUser')->with(7, 'alice')->willReturn($this->household());
		$this->importPrefs->expects($this->once())->method('get')->with(7, 'alice')->willReturn($prefs);

		$res = $this->controller->getImportPreferences(7);
		self::assertSame(Http::STATUS_OK, $res->getStatus());
		$body = $res->getData();
		self::assertTrue($body['ok'] ?? false);
		self::assertSame($prefs, $body['preferences']);
	}

	public function testGetImportPreferencesAuthzDenied(): void
	{
		$this->workspaces->method('getForUser')->willThrowException(new AccessDeniedException());
		$res = $this->controller->getImportPreferences(7);
		self::assertSame(Http::STATUS_FORBIDDEN, $res->getStatus());
	}

	public function testGetSummaryViewPreferencesHappyPath(): void
	{
		$prefs = ['includeSpecialsInTotals' => true];
		$this->workspaces->expects($this->once())->method('getForUser')->with(7, 'alice')->willReturn($this->household());
		$this->summaryPrefs->expects($this->once())->method('get')->with(7, 'alice', false)->willReturn($prefs);

		$res = $this->controller->getSummaryViewPreferences(7);
		self::assertSame(Http::STATUS_OK, $res->getStatus());
		self::assertSame($prefs, $res->getData()['preferences']);
	}

	public function testListRecurringRulesHappyPath(): void
	{
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $k, $default = null) => $k === 'workspaceId' ? 7 : $default
		);
		$this->workspaces->method('getForUser')->with(7, 'alice')->willReturn($this->household());
		$this->recurring->expects($this->once())->method('listForWorkspace')->with(7, 'alice', 'EUR')->willReturn([['id' => 1]]);

		$res = $this->controller->listRecurringRules();
		self::assertSame(Http::STATUS_OK, $res->getStatus());
		self::assertSame([['id' => 1]], $res->getData()['rules']);
	}

	public function testListBudgetsHappyPath(): void
	{
		$this->request->method('getParam')->willReturnCallback(
			static function (string $k, $default = null) {
				if ($k === 'workspaceId') {
					return 7;
				}
				if ($k === 'yearMonth') {
					return '2026-09';
				}
				return $default;
			}
		);
		$this->workspaces->method('getForUser')->with(7, 'alice')->willReturn($this->household());
		$this->budgets->expects($this->once())->method('listForMonth')->with(7, 'alice', '2026-09', 'EUR')->willReturn([['categoryId' => 1]]);

		$res = $this->controller->listBudgets();
		self::assertSame(Http::STATUS_OK, $res->getStatus());
		self::assertSame([['categoryId' => 1]], $res->getData()['budgets']);
	}

	public function testListBudgetDefaultsHappyPath(): void
	{
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $k, $default = null) => $k === 'workspaceId' ? 7 : $default
		);
		$this->workspaces->method('getForUser')->with(7, 'alice')->willReturn($this->household());
		$this->budgets->expects($this->once())->method('listDefaults')->with(7, 'alice', 'EUR')->willReturn([['categoryId' => 2]]);

		$res = $this->controller->listBudgetDefaults();
		self::assertSame(Http::STATUS_OK, $res->getStatus());
		self::assertSame([['categoryId' => 2]], $res->getData()['defaults']);
	}

	public function testGetSavingsTargetHappyPath(): void
	{
		$this->request->method('getParam')->willReturnCallback(
			static function (string $k, $default = null) {
				if ($k === 'workspaceId') {
					return 7;
				}
				if ($k === 'yearMonth') {
					return '2026-09';
				}
				return $default;
			}
		);
		$this->workspaces->method('getForUser')->with(7, 'alice')->willReturn($this->household());
		$this->savings->expects($this->once())->method('load')->with(7, 'alice', '2026-09', 'EUR')->willReturn(['amountMinor' => 100]);

		$res = $this->controller->getSavingsTarget();
		self::assertSame(Http::STATUS_OK, $res->getStatus());
		self::assertSame(['amountMinor' => 100], $res->getData()['savingsTarget']);
	}

	public function testSearchUsersHappyPathAsAppAdmin(): void
	{
		$this->access->method('isAppAdmin')->with('alice')->willReturn(true);
		$this->rateLimit->expects($this->once())->method('assertAllowed')->with('alice', 'user_search', 60, 60);
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $k, $default = null) => $k === 'q' ? 'al' : $default
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$user->method('getDisplayName')->willReturn('Alice');
		$user->method('isEnabled')->willReturn(true);
		$this->userManager->method('search')->with('al', 50, 0)->willReturn([$user]);
		$this->userManager->method('searchDisplayName')->willReturn([]);

		$res = $this->controller->searchUsers();
		self::assertSame(Http::STATUS_OK, $res->getStatus());
		self::assertSame('alice', $res->getData()['users'][0]['id']);
	}

	public function testSearchUsersAuthzDeniedForViewer(): void
	{
		$this->access->method('isAppAdmin')->willReturn(false);
		$this->access->method('workspacesForUser')->willReturn([7]);
		$this->access->method('role')->with(7, 'alice')->willReturn(AccessControlService::ROLE_VIEWER);

		$res = $this->controller->searchUsers();
		self::assertSame(Http::STATUS_FORBIDDEN, $res->getStatus());
	}

	public function testSearchGroupsHappyPathAsManager(): void
	{
		$this->access->method('isAppAdmin')->willReturn(false);
		$this->access->method('workspacesForUser')->willReturn([7]);
		$this->access->method('role')->with(7, 'alice')->willReturn(AccessControlService::ROLE_MANAGER);
		$this->rateLimit->expects($this->once())->method('assertAllowed')->with('alice', 'group_search', 60, 60);
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $k, $default = null) => $k === 'q' ? 'ops' : $default
		);
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn('ops');
		$group->method('getDisplayName')->willReturn('Ops');
		$this->groupManager->method('search')->with('ops', 25, 0)->willReturn([$group]);

		$res = $this->controller->searchGroups();
		self::assertSame(Http::STATUS_OK, $res->getStatus());
		self::assertSame('ops', $res->getData()['groups'][0]['id']);
	}

	public function testSearchGroupsAuthzDenied(): void
	{
		$this->access->method('isAppAdmin')->willReturn(false);
		$this->access->method('workspacesForUser')->willReturn([]);

		$res = $this->controller->searchGroups();
		self::assertSame(Http::STATUS_FORBIDDEN, $res->getStatus());
	}
}
