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
use OCP\AppFramework\Http\JSONResponse;
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
use ReflectionClass;

/**
 * Used-function coverage: every public ApiController action must be invoked
 * (not merely reflected). Also supplies authentic happy-path proofs for the
 * web api-matrix mutating/read routes.
 */
final class ApiControllerInvokeCoverageTest extends TestCase
{
	/** @var IRequest&MockObject */
	private IRequest $request;
	/** @var AccessControlService&MockObject */
	private AccessControlService $access;
	/** @var WorkspaceService&MockObject */
	private WorkspaceService $workspaces;
	/** @var \OCA\BudgetCheck\Service\WorkspaceDeletionService&MockObject */
	private \OCA\BudgetCheck\Service\WorkspaceDeletionService $workspaceDeletion;
	/** @var CategoryService&MockObject */
	private CategoryService $categories;
	/** @var TransactionService&MockObject */
	private TransactionService $transactions;
	/** @var TransactionAttachmentService&MockObject */
	private TransactionAttachmentService $attachments;
	/** @var RecurringRuleService&MockObject */
	private RecurringRuleService $recurring;
	/** @var BudgetService&MockObject */
	private BudgetService $budgets;
	/** @var BookingStatusService&MockObject */
	private BookingStatusService $bookingStatuses;
	/** @var TransactionImportService&MockObject */
	private TransactionImportService $imports;
	/** @var ImportPreferencesService&MockObject */
	private ImportPreferencesService $importPrefs;
	/** @var SummaryViewPreferencesService&MockObject */
	private SummaryViewPreferencesService $summaryPrefs;
	/** @var SavingsTargetService&MockObject */
	private SavingsTargetService $savings;
	/** @var SummaryService&MockObject */
	private SummaryService $summaries;
	/** @var SnapshotService&MockObject */
	private SnapshotService $snapshots;
	/** @var AuditLogService&MockObject */
	private AuditLogService $audit;
	/** @var RateLimitService&MockObject */
	private RateLimitService $rateLimit;

	/** @var IUserManager&MockObject */
	private IUserManager $userManager;
	/** @var IGroupManager&MockObject */
	private IGroupManager $groupManager;

	private ApiController $controller;

	/** @var array<string,mixed> */
	private array $params = [];

	protected function setUp(): void
	{
		parent::setUp();
		$this->params = [];
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParams')->willReturnCallback(fn (): array => $this->params);
		$this->request->method('getParam')->willReturnCallback(
			function (string $key, $default = null) {
				return array_key_exists($key, $this->params) ? $this->params[$key] : $default;
			}
		);
		$this->request->method('getMethod')->willReturn('POST');
		$this->request->method('getRequestUri')->willReturn('/apps/budgetcheck/api/test');

		$this->access = $this->createMock(AccessControlService::class);
		$this->access->method('currentUserId')->willReturn('alice');
		$this->access->method('normalisePrivacyMode')->willReturn(AccessControlService::PRIVACY_STANDARD);
		$this->access->method('canCreateWorkspace')->willReturn(true);
		$this->access->method('isAppAdmin')->willReturn(true);
		$this->access->method('favoriteWorkspaceIds')->willReturn([7]);
		$this->access->method('getAppPolicy')->willReturn(['appAdminUserIds' => ['alice']]);
		$this->access->method('saveAppPolicy')->willReturn(['appAdminUserIds' => ['alice']]);
		$this->access->method('saveFavoriteWorkspaceIds')->willReturnCallback(static function (): void {});

		$ws = [
			'id' => 7,
			'type' => WorkspaceService::TYPE_HOUSEHOLD,
			'currencyCode' => 'EUR',
			'includeSpecialsInTotalsDefault' => false,
			'generatePlannedFromBudgetsDefault' => false,
			'role' => AccessControlService::ROLE_MANAGER,
		];
		$this->workspaces = $this->createMock(WorkspaceService::class);
		$this->workspaces->method('listForUser')->willReturn([$ws]);
		$this->workspaces->method('getForUser')->willReturn($ws);
		$this->workspaces->method('createWorkspace')->willReturn($ws);
		$this->workspaces->method('updateWorkspace')->willReturn($ws);
		$this->workspaces->method('updateTaxMode')->willReturn($ws);
		$this->workspaces->method('listMembers')->willReturn([]);
		$this->workspaces->method('addMember')->willReturn(['id' => 1]);
		$this->workspaces->method('updateMember')->willReturn(['id' => 1]);
		$this->workspaces->method('removeMember')->willReturn(['deleted' => true]);
		$this->workspaces->method('addGroupMember')->willReturn(['id' => 1]);
		$this->workspaces->method('updateGroupMember')->willReturn(['id' => 1]);
		$this->workspaces->method('removeGroupMember')->willReturn(['deleted' => true]);

		$this->workspaceDeletion = $this->createMock(\OCA\BudgetCheck\Service\WorkspaceDeletionService::class);
		$this->workspaceDeletion->method('previewImpact')->willReturn([
			'workspaceId' => 7,
			'name' => 'Home',
			'type' => 'household',
			'transactionCount' => 0,
			'attachmentCount' => 0,
			'memberCount' => 1,
			'groupCount' => 0,
			'closedMonthCount' => 0,
			'billableInvoicedCount' => 0,
			'billablePaidCount' => 0,
			'hasInvoiceCheckLinks' => false,
		]);
		$this->workspaceDeletion->method('deleteWorkspace')->willReturn([
			'deleted' => true,
			'id' => 7,
			'name' => 'Home',
			'type' => 'household',
			'impact' => [],
		]);

		$cat = ['id' => 3, 'name' => 'Food', 'groupKey' => 'expense', 'workspace_id' => 7];
		$this->categories = $this->createMock(CategoryService::class);
		$this->categories->method('listForWorkspace')->willReturn([$cat]);
		$this->categories->method('distinctGroupKeys')->willReturn(['expense']);
		$this->categories->method('create')->willReturn($cat);
		$this->categories->method('update')->willReturn($cat);
		$this->categories->method('deactivate')->willReturn($cat);
		$this->categories->method('loadForWorkspace')->willReturn($cat);

		$this->transactions = $this->createMock(TransactionService::class);
		$this->transactions->method('listForWorkspace')->willReturn(['transactions' => []]);
		$this->transactions->method('create')->willReturn(['id' => 9, 'version' => 1]);
		$this->transactions->method('update')->willReturn(['id' => 9, 'version' => 2]);
		$this->transactions->method('delete')->willReturn(true);
		$this->transactions->method('ownerWorkspaceId')->willReturn(7);
		$this->transactions->method('loadForWorkspace')->willReturn([
			'id' => 9,
			'workspace_id' => 7,
			'category_id' => 3,
			'version' => 1,
		]);
		$this->transactions->method('countBookingsInCalendarMonth')->willReturn(0);
		$this->transactions->method('ledgerYearMonthBounds')->willReturn(['from' => '2026-01', 'to' => '2026-09']);

		$this->attachments = $this->createMock(TransactionAttachmentService::class);
		$this->attachments->method('listForTransaction')->willReturn([]);
		$this->attachments->method('upload')->willReturn(['id' => 1]);
		$this->attachments->method('delete')->willReturnCallback(static function (): void {});
		$this->attachments->method('replace')->willReturn(['id' => 1]);

		$this->recurring = $this->createMock(RecurringRuleService::class);
		$this->recurring->method('listForWorkspace')->willReturn([]);
		$this->recurring->method('create')->willReturn(['id' => 5, 'categoryId' => 3]);
		$this->recurring->method('update')->willReturn(['id' => 5, 'categoryId' => 3]);
		$this->recurring->method('delete')->willReturn(true);
		$this->recurring->method('ownerWorkspaceId')->willReturn(7);
		$this->recurring->method('loadHydrated')->willReturn([
			'id' => 5,
			'categoryId' => 3,
			'endDate' => '2026-12-31',
		]);
		$this->recurring->method('generate')->willReturn(['id' => 99]);

		$this->budgets = $this->createMock(BudgetService::class);
		$this->budgets->method('listForMonth')->willReturn([]);
		$this->budgets->method('listDefaults')->willReturn([]);
		$this->budgets->method('bulkUpsertDefaults')->willReturn([]);
		$this->budgets->method('bulkUpsert')->willReturn([]);

		$this->bookingStatuses = $this->createMock(BookingStatusService::class);
		$this->bookingStatuses->method('listForWorkspace')->willReturn([]);
		$this->bookingStatuses->method('create')->willReturn(['id' => 1]);
		$this->bookingStatuses->method('update')->willReturn(['id' => 1]);
		$this->bookingStatuses->method('deactivate')->willReturn(['id' => 1]);

		$this->imports = $this->createMock(TransactionImportService::class);
		$this->imports->method('preview')->willReturn(['rows' => []]);
		$this->imports->method('commit')->willReturn(['created' => 0]);

		$this->importPrefs = $this->createMock(ImportPreferencesService::class);
		$this->importPrefs->method('get')->willReturn(['directionMode' => 'auto']);
		$this->importPrefs->method('save')->willReturn(['directionMode' => 'auto']);

		$this->summaryPrefs = $this->createMock(SummaryViewPreferencesService::class);
		$this->summaryPrefs->method('get')->willReturn(['includeSpecialsInTotals' => false]);
		$this->summaryPrefs->method('save')->willReturn(['includeSpecialsInTotals' => false]);

		$this->savings = $this->createMock(SavingsTargetService::class);
		$this->savings->method('load')->willReturn(['amountMinor' => 0]);
		$this->savings->method('save')->willReturn(['amountMinor' => 100]);

		$this->summaries = $this->createMock(SummaryService::class);
		$this->summaries->method('household')->willReturn(['warnings' => []]);
		$this->summaries->method('yearly')->willReturn(['year' => 2026]);
		$this->summaries->method('projectPeriod')->willReturn(['warnings' => []]);

		$this->snapshots = $this->createMock(SnapshotService::class);
		$this->snapshots->method('close')->willReturn(['closed' => true]);
		$this->snapshots->method('reopen')->willReturn(['reopened' => true]);
		$this->snapshots->method('isMonthClosed')->willReturn(false);

		$this->audit = $this->createMock(AuditLogService::class);
		$this->rateLimit = $this->createMock(RateLimitService::class);
		$this->rateLimit->method('assertAllowed')->willReturnCallback(static function (): void {});

		$this->userManager = $this->createMock(IUserManager::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$user->method('getDisplayName')->willReturn('Alice');
		$user->method('isEnabled')->willReturn(true);
		$this->userManager->method('search')->willReturn([$user]);
		$this->userManager->method('searchDisplayName')->willReturn([]);

		$this->groupManager = $this->createMock(IGroupManager::class);
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn('ops');
		$group->method('getDisplayName')->willReturn('Ops');
		$this->groupManager->method('search')->willReturn([$group]);

		$this->budgets->method('plannedMapForMonth')->willReturn([]);
		$this->categories->method('internalUncategorizedCategoryId')->willReturn(null);
		$this->access->method('ensureMinimumRole')->willReturn(AccessControlService::ROLE_MANAGER);

		$db = $this->createMock(IDBConnection::class);
		$db->method('beginTransaction');
		$db->method('commit');
		$db->method('inTransaction')->willReturn(false);
		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('isNull')->willReturn('isNull');
		$expr->method('isNotNull')->willReturn('isNotNull');
		$expr->method('gte')->willReturn('gte');
		$expr->method('lte')->willReturn('lte');
		$result = $this->createMock(\OCP\DB\IResult::class);
		$result->method('fetch')->willReturn(false);
		$result->method('closeCursor');
		$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('orderBy')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn('p');
		$qb->method('executeQuery')->willReturn($result);
		$db->method('getQueryBuilder')->willReturn($qb);

		$budgetPlanned = new BudgetPlannedService(
			$db,
			$this->access,
			$this->budgets,
			$this->categories,
			$this->transactions,
			$this->snapshots,
			$this->audit,
			$this->createMock(ITimeFactory::class),
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$this->controller = new ApiController(
			'budgetcheck',
			$this->request,
			$this->access,
			$this->workspaces,
			$this->workspaceDeletion,
			$this->categories,
			$this->transactions,
			$this->attachments,
			$this->recurring,
			$this->budgets,
			$budgetPlanned,
			$this->bookingStatuses,
			$this->imports,
			$this->importPrefs,
			$this->summaryPrefs,
			$this->savings,
			$this->summaries,
			$this->snapshots,
			$this->audit,
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

	private function assertOk(JSONResponse $res, string $method): void
	{
		self::assertSame(
			Http::STATUS_OK,
			$res->getStatus(),
			$method . ' expected 200, got ' . $res->getStatus() . ' body=' . json_encode($res->getData())
		);
		self::assertTrue($res->getData()['ok'] ?? false, $method . ' missing ok:true');
	}

	public function testEveryPublicActionIsInvokedWithHappyPath(): void
	{
		$ref = new ReflectionClass(ApiController::class);
		$public = [];
		foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
			if ($m->getDeclaringClass()->getName() !== ApiController::class) {
				continue;
			}
			if ($m->getName() === '__construct') {
				continue;
			}
			$public[] = $m->getName();
		}
		sort($public);

		$invoked = [];

		// --- reads / simple ---
		$this->params = [];
		$this->assertOk($this->controller->listWorkspaces(), 'listWorkspaces');
		$invoked[] = 'listWorkspaces';

		$this->assertOk($this->controller->getWorkspaceFavorites(), 'getWorkspaceFavorites');
		$invoked[] = 'getWorkspaceFavorites';

		$this->params = ['workspaceIds' => [7]];
		$this->assertOk($this->controller->saveWorkspaceFavorites(), 'saveWorkspaceFavorites');
		$invoked[] = 'saveWorkspaceFavorites';

		$this->params = ['name' => 'Home', 'type' => 'household', 'currencyCode' => 'EUR'];
		$this->assertOk($this->controller->createWorkspace(), 'createWorkspace');
		$invoked[] = 'createWorkspace';

		$this->params = [];
		$this->assertOk($this->controller->getWorkspace(7), 'getWorkspace');
		$invoked[] = 'getWorkspace';

		$this->params = ['name' => 'Home2'];
		$this->assertOk($this->controller->updateWorkspace(7), 'updateWorkspace');
		$invoked[] = 'updateWorkspace';

		$this->params = [];
		$this->assertOk($this->controller->previewWorkspaceDelete(7), 'previewWorkspaceDelete');
		$invoked[] = 'previewWorkspaceDelete';

		$this->params = ['confirmName' => 'Home'];
		$this->assertOk($this->controller->deleteWorkspace(7), 'deleteWorkspace');
		$invoked[] = 'deleteWorkspace';

		$this->params = ['taxModeEnabled' => true];
		$this->assertOk($this->controller->updateTaxMode(7), 'updateTaxMode');
		$invoked[] = 'updateTaxMode';

		$this->params = [];
		$this->assertOk($this->controller->listMembers(7), 'listMembers');
		$invoked[] = 'listMembers';

		$this->params = ['workspaceId' => 7];
		$this->assertOk($this->controller->listBookingStatuses(), 'listBookingStatuses');
		$invoked[] = 'listBookingStatuses';

		$this->params = ['workspaceId' => 7, 'name' => 'Done'];
		$this->assertOk($this->controller->createBookingStatus(), 'createBookingStatus');
		$invoked[] = 'createBookingStatus';

		$this->params = ['name' => 'Done2'];
		$this->assertOk($this->controller->updateBookingStatus(1), 'updateBookingStatus');
		$invoked[] = 'updateBookingStatus';

		$this->params = [];
		$this->assertOk($this->controller->deactivateBookingStatus(1), 'deactivateBookingStatus');
		$invoked[] = 'deactivateBookingStatus';

		$this->params = ['userId' => 'bob', 'role' => 'viewer'];
		$this->assertOk($this->controller->addMember(7), 'addMember');
		$invoked[] = 'addMember';

		$this->params = ['role' => 'contributor'];
		$this->assertOk($this->controller->updateMember(1), 'updateMember');
		$invoked[] = 'updateMember';

		$this->params = [];
		$this->assertOk($this->controller->removeMember(1), 'removeMember');
		$invoked[] = 'removeMember';

		$this->params = ['groupId' => 'ops', 'role' => 'viewer'];
		$this->assertOk($this->controller->addGroupMember(7), 'addGroupMember');
		$invoked[] = 'addGroupMember';

		$this->params = ['role' => 'contributor'];
		$this->assertOk($this->controller->updateGroupMember(1), 'updateGroupMember');
		$invoked[] = 'updateGroupMember';

		$this->params = [];
		$this->assertOk($this->controller->removeGroupMember(1), 'removeGroupMember');
		$invoked[] = 'removeGroupMember';

		$this->params = ['workspaceId' => 7];
		$this->assertOk($this->controller->listCategories(), 'listCategories');
		$invoked[] = 'listCategories';

		$this->params = ['workspaceId' => 7, 'name' => 'Food', 'groupKey' => 'expense'];
		$this->assertOk($this->controller->createCategory(), 'createCategory');
		$invoked[] = 'createCategory';

		$this->params = ['name' => 'Groceries'];
		$this->assertOk($this->controller->updateCategory(3), 'updateCategory');
		$invoked[] = 'updateCategory';

		$this->params = [];
		$this->assertOk($this->controller->deactivateCategory(3), 'deactivateCategory');
		$invoked[] = 'deactivateCategory';

		$this->params = ['workspaceId' => 7];
		$this->assertOk($this->controller->listTransactions(), 'listTransactions');
		$invoked[] = 'listTransactions';

		$this->params = [
			'workspaceId' => 7,
			'categoryId' => 3,
			'direction' => 'expense',
			'amount' => '10.00',
			'bookingDate' => '2026-09-01',
		];
		$this->assertOk($this->controller->createTransaction(), 'createTransaction');
		$invoked[] = 'createTransaction';

		$this->params = ['workspaceId' => 7, 'rows' => []];
		$this->assertOk($this->controller->previewTransactionImport(), 'previewTransactionImport');
		$invoked[] = 'previewTransactionImport';

		$this->params = ['workspaceId' => 7, 'rows' => [], 'defaults' => [], 'options' => []];
		$this->assertOk($this->controller->commitTransactionImport(), 'commitTransactionImport');
		$invoked[] = 'commitTransactionImport';

		$this->params = [];
		$this->assertOk($this->controller->getImportPreferences(7), 'getImportPreferences');
		$invoked[] = 'getImportPreferences';

		$this->params = ['directionMode' => 'auto'];
		$this->assertOk($this->controller->saveImportPreferences(7), 'saveImportPreferences');
		$invoked[] = 'saveImportPreferences';

		$this->params = [];
		$this->assertOk($this->controller->getSummaryViewPreferences(7), 'getSummaryViewPreferences');
		$invoked[] = 'getSummaryViewPreferences';

		$this->params = ['includeSpecialsInTotals' => false];
		$this->assertOk($this->controller->saveSummaryViewPreferences(7), 'saveSummaryViewPreferences');
		$invoked[] = 'saveSummaryViewPreferences';

		$this->params = ['version' => 1, 'title' => 'x'];
		$this->assertOk($this->controller->updateTransaction(9), 'updateTransaction');
		$invoked[] = 'updateTransaction';

		$this->params = ['version' => 1];
		$this->assertOk($this->controller->deleteTransaction(9), 'deleteTransaction');
		$invoked[] = 'deleteTransaction';

		$this->params = [];
		$this->assertOk($this->controller->listTransactionAttachments(9), 'listTransactionAttachments');
		$invoked[] = 'listTransactionAttachments';

		$_FILES['file'] = [
			'name' => 'a.pdf',
			'type' => 'application/pdf',
			'tmp_name' => '/tmp/x',
			'error' => UPLOAD_ERR_OK,
			'size' => 10,
		];
		$this->assertOk($this->controller->uploadTransactionAttachment(9), 'uploadTransactionAttachment');
		$invoked[] = 'uploadTransactionAttachment';

		$this->params = [];
		$this->assertOk($this->controller->deleteTransactionAttachment(1), 'deleteTransactionAttachment');
		$invoked[] = 'deleteTransactionAttachment';

		$this->assertOk($this->controller->replaceTransactionAttachment(1), 'replaceTransactionAttachment');
		$invoked[] = 'replaceTransactionAttachment';
		unset($_FILES['file']);

		$this->params = ['workspaceId' => 7];
		$this->assertOk($this->controller->listRecurringRules(), 'listRecurringRules');
		$invoked[] = 'listRecurringRules';

		$this->params = [
			'workspaceId' => 7,
			'categoryId' => 3,
			'title' => 'Rent',
			'amount' => '100',
			'direction' => 'expense',
		];
		$this->assertOk($this->controller->createRecurringRule(), 'createRecurringRule');
		$invoked[] = 'createRecurringRule';

		$this->params = ['title' => 'Rent2'];
		$this->assertOk($this->controller->updateRecurringRule(5), 'updateRecurringRule');
		$invoked[] = 'updateRecurringRule';

		$this->params = [];
		$this->assertOk($this->controller->deleteRecurringRule(5), 'deleteRecurringRule');
		$invoked[] = 'deleteRecurringRule';

		$this->params = ['mode' => 'next'];
		$this->assertOk($this->controller->generateFromRecurringRule(5), 'generateFromRecurringRule');
		$invoked[] = 'generateFromRecurringRule';

		$this->params = ['workspaceId' => 7, 'yearMonth' => '2026-09'];
		$this->assertOk($this->controller->listBudgets(), 'listBudgets');
		$invoked[] = 'listBudgets';

		$this->params = ['workspaceId' => 7];
		$this->assertOk($this->controller->listBudgetDefaults(), 'listBudgetDefaults');
		$invoked[] = 'listBudgetDefaults';

		$this->params = ['workspaceId' => 7, 'rows' => []];
		$this->assertOk($this->controller->bulkUpsertBudgetDefaults(), 'bulkUpsertBudgetDefaults');
		$invoked[] = 'bulkUpsertBudgetDefaults';

		$this->params = ['workspaceId' => 7, 'yearMonth' => '2026-09', 'rows' => [], 'generatePlanned' => false];
		$this->assertOk($this->controller->bulkUpsertBudgets(), 'bulkUpsertBudgets');
		$invoked[] = 'bulkUpsertBudgets';

		$this->params = ['workspaceId' => 7, 'yearMonth' => '2026-09'];
		$plannedRes = $this->controller->generatePlannedFromBudgets();
		$this->assertOk($plannedRes, 'generatePlannedFromBudgets');
		self::assertSame(
			['created' => 0, 'updated' => 0, 'removed' => 0, 'skipped' => 0],
			$plannedRes->getData()['plannedSync'] ?? null,
			'generatePlannedFromBudgets must return syncMonth happy payload'
		);
		$invoked[] = 'generatePlannedFromBudgets';

		$this->params = ['workspaceId' => 7, 'yearMonth' => '2026-09'];
		$this->assertOk($this->controller->getSavingsTarget(), 'getSavingsTarget');
		$invoked[] = 'getSavingsTarget';

		$this->params = ['workspaceId' => 7, 'yearMonth' => '2026-09', 'amount' => '50'];
		$this->assertOk($this->controller->saveSavingsTarget(), 'saveSavingsTarget');
		$invoked[] = 'saveSavingsTarget';

		$this->params = ['workspaceId' => 7, 'yearMonth' => '2026-09'];
		$this->assertOk($this->controller->monthlySummary(), 'monthlySummary');
		$invoked[] = 'monthlySummary';

		$this->params = ['workspaceId' => 7, 'yearMonth' => '2026-09'];
		$this->assertOk($this->controller->monthlyClose(), 'monthlyClose');
		$invoked[] = 'monthlyClose';

		$this->params = ['workspaceId' => 7, 'yearMonth' => '2026-09'];
		$this->assertOk($this->controller->monthlyReopen(), 'monthlyReopen');
		$invoked[] = 'monthlyReopen';

		$this->params = ['workspaceId' => 7, 'year' => 2026];
		$this->assertOk($this->controller->yearlySummary(), 'yearlySummary');
		$invoked[] = 'yearlySummary';

		$this->params = ['workspaceId' => 7];
		$this->assertOk($this->controller->projectPeriodSummary(), 'projectPeriodSummary');
		$invoked[] = 'projectPeriodSummary';

		$this->params = [];
		$this->assertOk($this->controller->getAppPolicy(), 'getAppPolicy');
		$invoked[] = 'getAppPolicy';

		$this->params = ['appAdminUserIds' => ['alice'], 'settingsSection' => 'admins'];
		$this->assertOk($this->controller->saveAppPolicy(), 'saveAppPolicy');
		$invoked[] = 'saveAppPolicy';

		$this->params = ['q' => 'al'];
		$this->assertOk($this->controller->searchUsers(), 'searchUsers');
		$invoked[] = 'searchUsers';

		$this->params = ['q' => 'op'];
		$this->assertOk($this->controller->searchGroups(), 'searchGroups');
		$invoked[] = 'searchGroups';

		sort($invoked);
		self::assertSame(
			$public,
			$invoked,
			'Every public ApiController action must be invoked. Missing: '
			. implode(',', array_diff($public, $invoked))
			. ' Extra: ' . implode(',', array_diff($invoked, $public))
		);
	}

	/**
	 * Controller whose collaborators deny membership/object access via AccessDeniedException.
	 * AuthZ for many routes lives in services (updateWorkspace, categories, …), not only getForUser.
	 */
	private function controllerWithWorkspaceDenied(): ApiController
	{
		$deny = static function (): never {
			throw new AccessDeniedException();
		};

		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('alice');
		$access->method('normalisePrivacyMode')->willReturn(AccessControlService::PRIVACY_STANDARD);
		$access->method('isAppAdmin')->willReturn(false);
		$access->method('canCreateWorkspace')->willReturn(false);
		$access->method('saveAppPolicy')->willReturnCallback($deny);
		$access->method('getAppPolicy')->willReturnCallback($deny);

		$workspaces = $this->createMock(WorkspaceService::class);
		foreach ([
			'getForUser', 'createWorkspace', 'updateWorkspace', 'updateTaxMode', 'listMembers',
			'addMember', 'updateMember', 'removeMember', 'addGroupMember', 'updateGroupMember',
			'removeGroupMember', 'listForUser',
		] as $m) {
			$workspaces->method($m)->willReturnCallback($deny);
		}

		$workspaceDeletion = $this->createMock(\OCA\BudgetCheck\Service\WorkspaceDeletionService::class);
		$workspaceDeletion->method('previewImpact')->willReturnCallback($deny);
		$workspaceDeletion->method('deleteWorkspace')->willReturnCallback($deny);

		$categories = $this->createMock(CategoryService::class);
		foreach (['listForWorkspace', 'create', 'update', 'deactivate', 'loadForWorkspace', 'distinctGroupKeys'] as $m) {
			$categories->method($m)->willReturnCallback($deny);
		}

		$transactions = $this->createMock(TransactionService::class);
		foreach ([
			'listForWorkspace', 'create', 'update', 'delete', 'ownerWorkspaceId', 'loadForWorkspace',
			'countBookingsInCalendarMonth', 'ledgerYearMonthBounds',
		] as $m) {
			$transactions->method($m)->willReturnCallback($deny);
		}

		$attachments = $this->createMock(TransactionAttachmentService::class);
		foreach (['listForTransaction', 'upload', 'delete', 'replace'] as $m) {
			$attachments->method($m)->willReturnCallback($deny);
		}

		$recurring = $this->createMock(RecurringRuleService::class);
		foreach ([
			'listForWorkspace', 'create', 'update', 'delete', 'ownerWorkspaceId', 'loadHydrated', 'generate',
		] as $m) {
			$recurring->method($m)->willReturnCallback($deny);
		}

		$budgets = $this->createMock(BudgetService::class);
		foreach (['listForMonth', 'listDefaults', 'bulkUpsertDefaults', 'bulkUpsert'] as $m) {
			$budgets->method($m)->willReturnCallback($deny);
		}

		$bookingStatuses = $this->createMock(BookingStatusService::class);
		foreach (['listForWorkspace', 'create', 'update', 'deactivate'] as $m) {
			$bookingStatuses->method($m)->willReturnCallback($deny);
		}

		$imports = $this->createMock(TransactionImportService::class);
		$imports->method('preview')->willReturnCallback($deny);
		$imports->method('commit')->willReturnCallback($deny);

		$importPrefs = $this->createMock(ImportPreferencesService::class);
		$importPrefs->method('get')->willReturnCallback($deny);
		$importPrefs->method('save')->willReturnCallback($deny);

		$summaryPrefs = $this->createMock(SummaryViewPreferencesService::class);
		$summaryPrefs->method('get')->willReturnCallback($deny);
		$summaryPrefs->method('save')->willReturnCallback($deny);

		$savings = $this->createMock(SavingsTargetService::class);
		$savings->method('load')->willReturnCallback($deny);
		$savings->method('save')->willReturnCallback($deny);

		$summaries = $this->createMock(SummaryService::class);
		$summaries->method('household')->willReturnCallback($deny);
		$summaries->method('yearly')->willReturnCallback($deny);
		$summaries->method('projectPeriod')->willReturnCallback($deny);

		$snapshots = $this->createMock(SnapshotService::class);
		$snapshots->method('close')->willReturnCallback($deny);
		$snapshots->method('reopen')->willReturnCallback($deny);

		$rateLimit = $this->createMock(RateLimitService::class);
		$rateLimit->method('assertAllowed')->willReturnCallback(static function (): void {});

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$audit = $this->createMock(AuditLogService::class);

		return new ApiController(
			'budgetcheck',
			$this->request,
			$access,
			$workspaces,
			$workspaceDeletion,
			$categories,
			$transactions,
			$attachments,
			$recurring,
			$budgets,
			new BudgetPlannedService(
				$this->createMock(IDBConnection::class),
				$access,
				$budgets,
				$categories,
				$transactions,
				$snapshots,
				$audit,
				$this->createMock(ITimeFactory::class),
			),
			$bookingStatuses,
			$imports,
			$importPrefs,
			$summaryPrefs,
			$savings,
			$summaries,
			$snapshots,
			$audit,
			$rateLimit,
			$this->createMock(MoneyService::class),
			$this->createMock(CurrencyCatalog::class),
			$this->createMock(TimezoneCatalog::class),
			$this->userManager,
			$this->groupManager,
			$this->createMock(LoggerInterface::class),
			$l10n,
		);
	}

	private function assertForbidden(JSONResponse $res, string $label): void
	{
		self::assertSame(
			Http::STATUS_FORBIDDEN,
			$res->getStatus(),
			$label . ' expected 403, body=' . json_encode($res->getData())
		);
		self::assertSame('access_denied', $res->getData()['error']['code'] ?? null, $label);
	}

	public function testMutatingAuthzNegativeCreateTransaction(): void
	{
		$controller = $this->controllerWithWorkspaceDenied();
		$this->params = [
			'workspaceId' => 7,
			'categoryId' => 3,
			'direction' => 'expense',
			'amount' => '1',
			'bookingDate' => '2026-09-01',
		];
		$this->assertForbidden($controller->createTransaction(), 'createTransaction');
	}

	public function testMutatingAndObjectLevelAuthzNegatives(): void
	{
		$c = $this->controllerWithWorkspaceDenied();

		$this->params = ['name' => 'X', 'type' => 'household', 'currencyCode' => 'EUR'];
		$this->assertForbidden($c->createWorkspace(), 'createWorkspace');

		$this->params = ['name' => 'X'];
		$this->assertForbidden($c->updateWorkspace(7), 'updateWorkspace');
		$this->params = [];
		$this->assertForbidden($c->previewWorkspaceDelete(7), 'previewWorkspaceDelete');
		$this->params = ['confirmName' => 'X'];
		$this->assertForbidden($c->deleteWorkspace(7), 'deleteWorkspace');
		$this->params = ['taxModeEnabled' => false];
		$this->assertForbidden($c->updateTaxMode(7), 'updateTaxMode');

		$this->params = ['workspaceId' => 7, 'name' => 'S', 'color' => '#000'];
		$this->assertForbidden($c->createBookingStatus(), 'createBookingStatus');
		$this->params = ['name' => 'S'];
		$this->assertForbidden($c->updateBookingStatus(1), 'updateBookingStatus');
		$this->assertForbidden($c->deactivateBookingStatus(1), 'deactivateBookingStatus');

		$this->params = ['userId' => 'bob', 'role' => 'viewer'];
		$this->assertForbidden($c->addMember(7), 'addMember');
		$this->params = ['role' => 'viewer'];
		$this->assertForbidden($c->updateMember(1), 'updateMember');
		$this->assertForbidden($c->removeMember(1), 'removeMember');
		$this->params = ['gid' => 'ops', 'role' => 'viewer'];
		$this->assertForbidden($c->addGroupMember(7), 'addGroupMember');
		$this->params = ['role' => 'viewer'];
		$this->assertForbidden($c->updateGroupMember(1), 'updateGroupMember');
		$this->assertForbidden($c->removeGroupMember(1), 'removeGroupMember');

		$this->params = ['workspaceId' => 7, 'name' => 'Food', 'groupKey' => 'expense'];
		$this->assertForbidden($c->createCategory(), 'createCategory');
		$this->params = ['name' => 'Food'];
		$this->assertForbidden($c->updateCategory(3), 'updateCategory');
		$this->assertForbidden($c->deactivateCategory(3), 'deactivateCategory');

		$this->params = [
			'workspaceId' => 7,
			'categoryId' => 3,
			'direction' => 'expense',
			'amount' => '1',
			'bookingDate' => '2026-09-01',
		];
		$this->assertForbidden($c->createTransaction(), 'createTransaction');
		$this->params = ['amount' => '2'];
		$this->assertForbidden($c->updateTransaction(9), 'updateTransaction');
		$this->assertForbidden($c->deleteTransaction(9), 'deleteTransaction');

		$this->params = ['workspaceId' => 7, 'rows' => []];
		$this->assertForbidden($c->previewTransactionImport(), 'previewTransactionImport');
		$this->assertForbidden($c->commitTransactionImport(), 'commitTransactionImport');
		$this->params = ['directionMode' => 'auto'];
		$this->assertForbidden($c->saveImportPreferences(7), 'saveImportPreferences');
		$this->params = ['includeSpecialsInTotals' => false];
		$this->assertForbidden($c->saveSummaryViewPreferences(7), 'saveSummaryViewPreferences');

		$this->params = [];
		$this->assertForbidden($c->uploadTransactionAttachment(9), 'uploadTransactionAttachment');
		$this->assertForbidden($c->deleteTransactionAttachment(1), 'deleteTransactionAttachment');
		$_FILES['file'] = [
			'name' => 'x.pdf',
			'type' => 'application/pdf',
			'tmp_name' => '/tmp/x',
			'error' => 0,
			'size' => 1,
		];
		try {
			$this->assertForbidden($c->replaceTransactionAttachment(1), 'replaceTransactionAttachment');
		} finally {
			unset($_FILES['file']);
		}

		$this->params = [
			'workspaceId' => 7,
			'categoryId' => 3,
			'frequency' => 'monthly',
			'startDate' => '2026-01-01',
			'amount' => '10',
			'direction' => 'expense',
		];
		$this->assertForbidden($c->createRecurringRule(), 'createRecurringRule');
		$this->params = ['amount' => '11'];
		$this->assertForbidden($c->updateRecurringRule(5), 'updateRecurringRule');
		$this->assertForbidden($c->deleteRecurringRule(5), 'deleteRecurringRule');
		$this->params = ['bookingDate' => '2026-09-01'];
		$this->assertForbidden($c->generateFromRecurringRule(5), 'generateFromRecurringRule');

		$this->params = ['workspaceId' => 7, 'rows' => []];
		$this->assertForbidden($c->bulkUpsertBudgetDefaults(), 'bulkUpsertBudgetDefaults');
		$this->params = ['workspaceId' => 7, 'yearMonth' => '2026-09', 'rows' => [], 'generatePlanned' => false];
		$this->assertForbidden($c->bulkUpsertBudgets(), 'bulkUpsertBudgets');
		$this->params = ['workspaceId' => 7, 'yearMonth' => '2026-09'];
		$this->assertForbidden($c->generatePlannedFromBudgets(), 'generatePlannedFromBudgets');
		$this->params = ['workspaceId' => 7, 'yearMonth' => '2026-09', 'amount' => '50'];
		$this->assertForbidden($c->saveSavingsTarget(), 'saveSavingsTarget');

		$this->params = ['workspaceId' => 7, 'yearMonth' => '2026-09'];
		$this->assertForbidden($c->monthlyClose(), 'monthlyClose');
		$this->assertForbidden($c->monthlyReopen(), 'monthlyReopen');

		$this->params = ['appAdminUserIds' => ['alice']];
		$this->assertForbidden($c->saveAppPolicy(), 'saveAppPolicy');
		$this->assertForbidden($c->getAppPolicy(), 'getAppPolicy');

		$this->params = ['workspaceIds' => [7]];
		$this->assertForbidden($c->saveWorkspaceFavorites(), 'saveWorkspaceFavorites');

		// Object-level reads also deny when workspace membership fails.
		$this->assertForbidden($c->getWorkspace(7), 'getWorkspace');
		$this->assertForbidden($c->listMembers(7), 'listMembers');
		$this->params = ['workspaceId' => 7];
		$this->assertForbidden($c->listCategories(), 'listCategories');
		$this->assertForbidden($c->listTransactions(), 'listTransactions');
		$this->assertForbidden($c->listTransactionAttachments(9), 'listTransactionAttachments');
		$this->assertForbidden($c->getImportPreferences(7), 'getImportPreferences');
		$this->assertForbidden($c->getSummaryViewPreferences(7), 'getSummaryViewPreferences');
		$this->params = ['workspaceId' => 7, 'yearMonth' => '2026-09'];
		$this->assertForbidden($c->listBudgets(), 'listBudgets');
		$this->params = ['workspaceId' => 7];
		$this->assertForbidden($c->listBudgetDefaults(), 'listBudgetDefaults');
		$this->params = ['workspaceId' => 7, 'yearMonth' => '2026-09'];
		$this->assertForbidden($c->getSavingsTarget(), 'getSavingsTarget');
		$this->assertForbidden($c->monthlySummary(), 'monthlySummary');
		$this->params = ['workspaceId' => 7, 'year' => 2026];
		$this->assertForbidden($c->yearlySummary(), 'yearlySummary');
		$this->params = ['workspaceId' => 7];
		$this->assertForbidden($c->projectPeriodSummary(), 'projectPeriodSummary');
		$this->params = ['workspaceId' => 7];
		$this->assertForbidden($c->listBookingStatuses(), 'listBookingStatuses');
		$this->params = ['workspaceId' => 7];
		$this->assertForbidden($c->listRecurringRules(), 'listRecurringRules');
	}
}
