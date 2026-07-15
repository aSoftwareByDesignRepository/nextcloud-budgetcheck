<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Controller;

use OCA\BudgetCheck\Exception\BudgetCheckException;
use OCA\BudgetCheck\Exception\AccessDeniedException;
use OCA\BudgetCheck\Exception\ConflictException;
use OCA\BudgetCheck\Exception\InternalErrorException;
use OCA\BudgetCheck\Exception\NotAuthenticatedException;
use OCA\BudgetCheck\Exception\RateLimitExceededException;
use OCA\BudgetCheck\Exception\ValidationException;
use OCA\BudgetCheck\Exception\WorkspaceTypeMismatchException;
use OCA\BudgetCheck\Service\AccessControlService;
use OCA\BudgetCheck\Service\AuditLogService;
use OCA\BudgetCheck\Service\BudgetPlannedService;
use OCA\BudgetCheck\Service\BudgetService;
use OCA\BudgetCheck\Service\BookingStatusService;
use OCA\BudgetCheck\Service\CategoryService;
use OCA\BudgetCheck\Service\CurrencyCatalog;
use OCA\BudgetCheck\Service\ImportPreferencesService;
use OCA\BudgetCheck\Service\SummaryViewPreferencesService;
use OCA\BudgetCheck\Service\MoneyService;
use OCA\BudgetCheck\Service\RateLimitService;
use OCA\BudgetCheck\Service\RecurringRuleService;
use OCA\BudgetCheck\Service\SavingsTargetService;
use OCA\BudgetCheck\Service\SnapshotService;
use OCA\BudgetCheck\Service\SummaryService;
use OCA\BudgetCheck\Service\TimezoneCatalog;
use OCA\BudgetCheck\Service\TransactionImportService;
use OCA\BudgetCheck\Service\TransactionAttachmentService;
use OCA\BudgetCheck\Service\TransactionService;
use OCA\BudgetCheck\Service\WorkspaceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * BudgetCheck JSON API controller.
 *
 * The controller stays intentionally thin:
 *  - Resolve and validate the active workspace + caller.
 *  - Hand off to the right domain service.
 *  - Map exceptions to HTTP statuses with stable error codes.
 *
 * Domain outcomes use typed {@see BudgetCheckException} subclasses (never string
 * matching on {@see \RuntimeException}) so auditors and refactors stay safe.
 *
 * Error code conventions (§12.3):
 *  - 400 invalid input we can locate to a field
 *  - 401 not authenticated ({@see AccessControlService::currentUserId} via `safe`)
 *  - 403 access_denied (not a member, not an app admin)
 *  - 404 cannot prove the resource exists for this user
 *  - 409 optimistic-locking conflict
 *  - 422 NOT_APPLICABLE_FOR_WORKSPACE_TYPE
 *  - 429 rate_limit_exceeded
 *  - 500 anything else (logged, never leaks internals)
 */
class ApiController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private AccessControlService $access,
		private WorkspaceService $workspaces,
		private CategoryService $categories,
		private TransactionService $transactions,
		private TransactionAttachmentService $transactionAttachments,
		private RecurringRuleService $recurring,
		private BudgetService $budgets,
		private BudgetPlannedService $budgetPlanned,
		private BookingStatusService $bookingStatuses,
		private TransactionImportService $transactionImport,
		private ImportPreferencesService $importPreferences,
		private SummaryViewPreferencesService $summaryViewPrefs,
		private SavingsTargetService $savings,
		private SummaryService $summaries,
		private SnapshotService $snapshots,
		private AuditLogService $audit,
		private RateLimitService $rateLimit,
		private MoneyService $money,
		private CurrencyCatalog $currencyCatalog,
		private TimezoneCatalog $timezoneCatalog,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private LoggerInterface $logger,
		private IL10N $l10n,
	) {
		parent::__construct($appName, $request);
	}

	// ------------------------------------------------------------------
	//  Workspaces and members
	// ------------------------------------------------------------------

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function listWorkspaces(): JSONResponse
	{
		return $this->safe(function (string $userId): array {
			$includeInactive = $this->boolParam('includeInactive');
			$workspaces = $this->workspaces->listForUser($userId, $includeInactive);
			$workspaceIds = array_map(static fn (array $w): int => (int)$w['id'], $workspaces);
			$favoriteWorkspaceIds = array_values(array_map('intval', array_intersect(
				$this->access->favoriteWorkspaceIds($userId),
				$workspaceIds
			)));
			$this->access->saveFavoriteWorkspaceIds($userId, $favoriteWorkspaceIds);
			return [
				'workspaces' => $workspaces,
				'lastUsedWorkspaceId' => $this->access->lastUsedWorkspace($userId),
				'favoriteWorkspaceIds' => $favoriteWorkspaceIds,
				'capabilities' => [
					'canCreateWorkspace' => $this->access->isAppAdmin($userId),
					'currencyCatalog' => $this->currencyCatalog->forApi(),
					'timezoneCatalog' => $this->timezoneCatalog->forApi(),
					'defaultCurrency' => $this->access->getDefaultCurrency(),
					'defaultTimezone' => $this->access->getDefaultTimezone(),
				],
			];
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getWorkspaceFavorites(): JSONResponse
	{
		return $this->safe(function (string $userId): array {
			$activeIds = array_map(static fn (array $w): int => (int)$w['id'], $this->workspaces->listForUser($userId));
			$favorites = array_values(array_map('intval', array_intersect(
				$this->access->favoriteWorkspaceIds($userId),
				$activeIds
			)));
			$this->access->saveFavoriteWorkspaceIds($userId, $favorites);
			return ['favoriteWorkspaceIds' => $favorites];
		});
	}

	#[NoAdminRequired]
	public function saveWorkspaceFavorites(): JSONResponse
	{
		return $this->safe(function (string $userId): array {
			$this->rateLimit->assertAllowed($userId, 'workspace_favorites_write', 90, 300);
			$raw = $this->payload()['workspaceIds'] ?? null;
			if (!is_array($raw)) {
				throw new \InvalidArgumentException('workspaceIds must be an array.');
			}
			$activeIds = array_map(static fn (array $w): int => (int)$w['id'], $this->workspaces->listForUser($userId));
			$clean = [];
			foreach ($raw as $id) {
				if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int)$id > 0) {
					$clean[] = (int)$id;
				}
			}
			$clean = array_values(array_unique(array_intersect($clean, $activeIds)));
			$this->access->saveFavoriteWorkspaceIds($userId, $clean);
			return ['favoriteWorkspaceIds' => $clean];
		});
	}

	#[NoAdminRequired]
	public function createWorkspace(): JSONResponse
	{
		return $this->safe(function (string $userId): array {
			if (!$this->access->isAppAdmin($userId)) {
				throw new AccessDeniedException();
			}
			$this->rateLimit->assertAllowed($userId, 'workspace_create', 10, 600);
			return ['workspace' => $this->workspaces->createWorkspace($userId, $this->payload())];
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getWorkspace(int $id): JSONResponse
	{
		$id = $this->validateId($id);
		return $this->safe(fn (string $u): array => ['workspace' => $this->workspaces->getForUser($id, $u)]);
	}

	#[NoAdminRequired]
	public function updateWorkspace(int $id): JSONResponse
	{
		$id = $this->validateId($id);
		return $this->safe(function (string $userId) use ($id): array {
			$this->rateLimit->assertAllowed($userId, 'workspace_update', 30, 300);
			return ['workspace' => $this->workspaces->updateWorkspace($id, $userId, $this->payload())];
		});
	}

	#[NoAdminRequired]
	public function updateTaxMode(int $id): JSONResponse
	{
		$id = $this->validateId($id);
		return $this->safe(function (string $userId) use ($id): array {
			$this->rateLimit->assertAllowed($userId, 'workspace_tax_mode', 20, 300);
			return ['workspace' => $this->workspaces->updateTaxMode($id, $userId, $this->payload())];
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function listMembers(int $id): JSONResponse
	{
		$id = $this->validateId($id);
		return $this->safe(fn (string $u): array => ['members' => $this->workspaces->listMembers($id, $u)]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function listBookingStatuses(): JSONResponse
	{
		return $this->safe(function (string $userId): array {
			$workspaceId = $this->resolveWorkspaceId();
			$includeInactive = $this->boolParam('includeInactive');
			return ['statuses' => $this->bookingStatuses->listForWorkspace($workspaceId, $userId, $includeInactive)];
		});
	}

	#[NoAdminRequired]
	public function createBookingStatus(): JSONResponse
	{
		return $this->safe(function (string $userId): array {
			$this->rateLimit->assertAllowed($userId, 'booking_status_write', 40, 300);
			$workspaceId = (int)($this->payload()['workspaceId'] ?? 0);
			if ($workspaceId < 1) {
				throw new \InvalidArgumentException('workspaceId is required.');
			}
			return ['status' => $this->bookingStatuses->create($workspaceId, $userId, $this->payload())];
		});
	}

	#[NoAdminRequired]
	public function updateBookingStatus(int $id): JSONResponse
	{
		$id = $this->validateId($id);
		return $this->safe(function (string $userId) use ($id): array {
			$this->rateLimit->assertAllowed($userId, 'booking_status_write', 40, 300);
			return ['status' => $this->bookingStatuses->update($id, $userId, $this->payload())];
		});
	}

	#[NoAdminRequired]
	public function deactivateBookingStatus(int $id): JSONResponse
	{
		$id = $this->validateId($id);
		return $this->safe(function (string $userId) use ($id): array {
			$this->rateLimit->assertAllowed($userId, 'booking_status_write', 40, 300);
			return ['status' => $this->bookingStatuses->deactivate($id, $userId)];
		});
	}

	#[NoAdminRequired]
	public function addMember(int $id): JSONResponse
	{
		$id = $this->validateId($id);
		return $this->safe(function (string $userId) use ($id): array {
			$this->rateLimit->assertAllowed($userId, 'member_write', 30, 300);
			return ['members' => $this->workspaces->addMember($id, $userId, $this->payload())];
		});
	}

	#[NoAdminRequired]
	public function updateMember(int $id): JSONResponse
	{
		$id = $this->validateId($id);
		return $this->safe(function (string $userId) use ($id): array {
			$this->rateLimit->assertAllowed($userId, 'member_write', 30, 300);
			return ['members' => $this->workspaces->updateMember($id, $userId, $this->payload())];
		});
	}

	#[NoAdminRequired]
	public function removeMember(int $id): JSONResponse
	{
		$id = $this->validateId($id);
		return $this->safe(function (string $userId) use ($id): array {
			$this->rateLimit->assertAllowed($userId, 'member_write', 30, 300);
			return ['members' => $this->workspaces->removeMember($id, $userId)];
		});
	}

	#[NoAdminRequired]
	public function addGroupMember(int $id): JSONResponse
	{
		$id = $this->validateId($id);
		return $this->safe(function (string $userId) use ($id): array {
			$this->rateLimit->assertAllowed($userId, 'member_write', 30, 300);
			return ['members' => $this->workspaces->addGroupMember($id, $userId, $this->payload())];
		});
	}

	#[NoAdminRequired]
	public function updateGroupMember(int $id): JSONResponse
	{
		$id = $this->validateId($id);
		return $this->safe(function (string $userId) use ($id): array {
			$this->rateLimit->assertAllowed($userId, 'member_write', 30, 300);
			return ['members' => $this->workspaces->updateGroupMember($id, $userId, $this->payload())];
		});
	}

	#[NoAdminRequired]
	public function removeGroupMember(int $id): JSONResponse
	{
		$id = $this->validateId($id);
		return $this->safe(function (string $userId) use ($id): array {
			$this->rateLimit->assertAllowed($userId, 'member_write', 30, 300);
			return ['members' => $this->workspaces->removeGroupMember($id, $userId)];
		});
	}

	// ------------------------------------------------------------------
	//  Categories
	// ------------------------------------------------------------------

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function listCategories(): JSONResponse
	{
		return $this->safe(function (string $userId): array {
			$workspaceId = $this->resolveWorkspaceId();
			$includeInactive = $this->boolParam('includeInactive');
			return [
				'categories' => $this->presentCategoriesToClient(
					$this->categories->listForWorkspace($workspaceId, $userId, $includeInactive),
				),
				'groupKeys' => $this->categories->distinctGroupKeys($workspaceId, $userId),
			];
		});
	}

	#[NoAdminRequired]
	public function createCategory(): JSONResponse
	{
		return $this->safe(function (string $userId): array {
			$this->rateLimit->assertAllowed($userId, 'category_write', 60, 300);
			$workspaceId = (int)($this->payload()['workspaceId'] ?? 0);
			if ($workspaceId < 1) {
				throw new \InvalidArgumentException('workspaceId is required.');
			}
			return ['category' => $this->presentCategoryToClient(
				$this->categories->create($workspaceId, $userId, $this->payload()),
			)];
		});
	}

	#[NoAdminRequired]
	public function updateCategory(int $id): JSONResponse
	{
		$id = $this->validateId($id);
		return $this->safe(function (string $userId) use ($id): array {
			$this->rateLimit->assertAllowed($userId, 'category_write', 60, 300);
			return ['category' => $this->presentCategoryToClient(
				$this->categories->update($id, $userId, $this->payload()),
			)];
		});
	}

	#[NoAdminRequired]
	public function deactivateCategory(int $id): JSONResponse
	{
		$id = $this->validateId($id);
		return $this->safe(function (string $userId) use ($id): array {
			$this->rateLimit->assertAllowed($userId, 'category_write', 60, 300);
			return ['category' => $this->presentCategoryToClient(
				$this->categories->deactivate($id, $userId),
			)];
		});
	}

	// ------------------------------------------------------------------
	//  Transactions
	// ------------------------------------------------------------------

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function listTransactions(): JSONResponse
	{
		return $this->safe(function (string $userId): array {
			$workspaceId = $this->resolveWorkspaceId();
			$workspace = $this->workspaces->getForUser($workspaceId, $userId);
			$filters = [
				'from' => $this->request->getParam('from'),
				'to' => $this->request->getParam('to'),
				'categoryId' => $this->intParamOrNull('categoryId'),
				'groupKey' => $this->request->getParam('groupKey'),
				'q' => $this->request->getParam('q'),
				'isSpecial' => $this->boolParamOrNull('isSpecial'),
				'uncategorized' => $this->boolParam('uncategorized'),
				'statusId' => $this->intParamOrNull('statusId'),
				'limit' => (int)$this->request->getParam('limit', 100),
				'offset' => (int)$this->request->getParam('offset', 0),
			];
			return $this->transactions->listForWorkspace($workspaceId, $userId, $filters, $workspace);
		});
	}

	#[NoAdminRequired]
	public function createTransaction(): JSONResponse
	{
		return $this->safe(function (string $userId): array {
			$payload = $this->payload();
			$workspaceId = (int)($payload['workspaceId'] ?? 0);
			if ($workspaceId < 1) {
				throw new \InvalidArgumentException('workspaceId is required.');
			}
			$this->rateLimit->assertAllowed($userId, 'transaction_write', 240, 300);
			$workspace = $this->workspaces->getForUser($workspaceId, $userId);
			$category = $this->resolveCategory((int)($payload['categoryId'] ?? 0), $workspaceId);
			$bookingStatus = $this->resolveBookingStatus(($payload['bookingStatusId'] ?? null), $workspaceId, $workspace);
			return ['transaction' => $this->transactions->create($workspaceId, $userId, $payload, $workspace, $category, $bookingStatus)];
		});
	}

	#[NoAdminRequired]
	public function previewTransactionImport(): JSONResponse
	{
		return $this->safe(function (string $userId): array {
			$payload = $this->payload();
			$workspaceId = (int)($payload['workspaceId'] ?? 0);
			if ($workspaceId < 1) {
				throw new \InvalidArgumentException('workspaceId is required.');
			}
			$rows = $payload['rows'] ?? null;
			if (!is_array($rows)) {
				throw new \InvalidArgumentException('rows must be an array.');
			}
			$this->rateLimit->assertAllowed($userId, 'transaction_import_preview', 120, 600);
			$workspace = $this->workspaces->getForUser($workspaceId, $userId);
			$defaults = is_array($payload['defaults'] ?? null) ? $payload['defaults'] : [];
			$options = is_array($payload['options'] ?? null) ? $payload['options'] : [];
			return ['preview' => $this->transactionImport->preview($workspaceId, $userId, $workspace, $rows, $defaults, $options)];
		});
	}

	#[NoAdminRequired]
	public function commitTransactionImport(): JSONResponse
	{
		return $this->safe(function (string $userId): array {
			$payload = $this->payload();
			$workspaceId = (int)($payload['workspaceId'] ?? 0);
			if ($workspaceId < 1) {
				throw new \InvalidArgumentException('workspaceId is required.');
			}
			$rows = $payload['rows'] ?? null;
			if (!is_array($rows)) {
				throw new \InvalidArgumentException('rows must be an array.');
			}
			$this->rateLimit->assertAllowed($userId, 'transaction_import_commit', 60, 600);
			$workspace = $this->workspaces->getForUser($workspaceId, $userId);
			$defaults = is_array($payload['defaults'] ?? null) ? $payload['defaults'] : [];
			$options = is_array($payload['options'] ?? null) ? $payload['options'] : [];
			return ['result' => $this->transactionImport->commit($workspaceId, $userId, $workspace, $rows, $defaults, $options)];
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getImportPreferences(int $workspaceId): JSONResponse
	{
		$workspaceId = $this->validateId($workspaceId);
		return $this->safe(function (string $userId) use ($workspaceId): array {
			$this->workspaces->getForUser($workspaceId, $userId);
			return ['preferences' => $this->importPreferences->get($workspaceId, $userId)];
		});
	}

	#[NoAdminRequired]
	public function saveImportPreferences(int $workspaceId): JSONResponse
	{
		$workspaceId = $this->validateId($workspaceId);
		return $this->safe(function (string $userId) use ($workspaceId): array {
			$this->workspaces->getForUser($workspaceId, $userId);
			$this->rateLimit->assertAllowed($userId, 'import_preferences_write', 60, 300);
			return ['preferences' => $this->importPreferences->save($workspaceId, $userId, $this->payload())];
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getSummaryViewPreferences(int $workspaceId): JSONResponse
	{
		$workspaceId = $this->validateId($workspaceId);
		return $this->safe(function (string $userId) use ($workspaceId): array {
			$workspace = $this->workspaces->getForUser($workspaceId, $userId);
			if (($workspace['type'] ?? '') !== WorkspaceService::TYPE_HOUSEHOLD) {
				throw new WorkspaceTypeMismatchException('Summary view preferences apply to household workspaces only.');
			}
			$default = (bool)($workspace['includeSpecialsInTotalsDefault'] ?? false);
			return [
				'preferences' => $this->summaryViewPrefs->get($workspaceId, $userId, $default),
			];
		});
	}

	#[NoAdminRequired]
	public function saveSummaryViewPreferences(int $workspaceId): JSONResponse
	{
		$workspaceId = $this->validateId($workspaceId);
		return $this->safe(function (string $userId) use ($workspaceId): array {
			$workspace = $this->workspaces->getForUser($workspaceId, $userId);
			if (($workspace['type'] ?? '') !== WorkspaceService::TYPE_HOUSEHOLD) {
				throw new WorkspaceTypeMismatchException('Summary view preferences apply to household workspaces only.');
			}
			$this->rateLimit->assertAllowed($userId, 'summary_view_prefs_write', 120, 300);
			$default = (bool)($workspace['includeSpecialsInTotalsDefault'] ?? false);
			$prefs = $this->summaryViewPrefs->save($workspaceId, $userId, $this->payload(), $default);
			return [
				'preferences' => $prefs,
				'workspace' => $this->workspaces->getForUser($workspaceId, $userId),
			];
		});
	}

	#[NoAdminRequired]
	public function updateTransaction(int $id): JSONResponse
	{
		$id = $this->validateId($id);
		return $this->safe(function (string $userId) use ($id): array {
			$payload = $this->payload();
			$existing = $this->transactions->loadForWorkspace($id, $this->ownerWorkspaceForTransaction($id));
			if ($existing === null) {
				throw new AccessDeniedException();
			}
			$workspaceId = (int)$existing['workspace_id'];
			$workspace = $this->workspaces->getForUser($workspaceId, $userId);
			$this->rateLimit->assertAllowed($userId, 'transaction_write', 240, 300);
			$categoryId = isset($payload['categoryId'])
				? (int)$payload['categoryId']
				: (int)$existing['category_id'];
			$category = $this->resolveCategory($categoryId, $workspaceId);
			$bookingStatus = array_key_exists('bookingStatusId', $payload)
				? $this->resolveBookingStatus($payload['bookingStatusId'], $workspaceId, $workspace)
				: null;
			return ['transaction' => $this->transactions->update($id, $userId, $payload, $workspace, $category, $bookingStatus)];
		});
	}

	#[NoAdminRequired]
	public function deleteTransaction(int $id): JSONResponse
	{
		$id = $this->validateId($id);
		return $this->safe(function (string $userId) use ($id): array {
			$workspaceId = $this->ownerWorkspaceForTransaction($id);
			$workspace = $this->workspaces->getForUser($workspaceId, $userId);
			$this->rateLimit->assertAllowed($userId, 'transaction_write', 240, 300);
			$this->transactions->delete($id, $userId, $workspace);
			return ['deleted' => true, 'id' => $id];
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function listTransactionAttachments(int $transactionId): JSONResponse
	{
		$transactionId = $this->validateId($transactionId);
		return $this->safe(function (string $userId) use ($transactionId): array {
			$this->ownerWorkspaceForTransaction($transactionId);
			return [
				'attachments' => $this->transactionAttachments->listForTransaction($transactionId, $userId),
			];
		});
	}

	#[NoAdminRequired]
	public function uploadTransactionAttachment(int $transactionId): JSONResponse
	{
		$transactionId = $this->validateId($transactionId);
		return $this->safe(function (string $userId) use ($transactionId): array {
			$this->ownerWorkspaceForTransaction($transactionId);
			$this->rateLimit->assertAllowed($userId, 'transaction_attachment_write', 120, 300);
			if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
				throw new \InvalidArgumentException('No file was uploaded.');
			}
			$attachment = $this->transactionAttachments->upload($transactionId, $userId, $_FILES['file']);
			return ['attachment' => $attachment];
		});
	}

	#[NoAdminRequired]
	public function deleteTransactionAttachment(int $id): JSONResponse
	{
		$id = $this->validateId($id);
		return $this->safe(function (string $userId) use ($id): array {
			$this->rateLimit->assertAllowed($userId, 'transaction_attachment_write', 120, 300);
			$this->transactionAttachments->delete($id, $userId);
			return ['deleted' => true, 'id' => $id];
		});
	}

	#[NoAdminRequired]
	public function replaceTransactionAttachment(int $id): JSONResponse
	{
		$id = $this->validateId($id);
		return $this->safe(function (string $userId) use ($id): array {
			$this->rateLimit->assertAllowed($userId, 'transaction_attachment_write', 120, 300);
			if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
				throw new \InvalidArgumentException('No file was uploaded.');
			}
			$attachment = $this->transactionAttachments->replace($id, $userId, $_FILES['file']);
			return ['attachment' => $attachment];
		});
	}

	// ------------------------------------------------------------------
	//  Recurring rules
	// ------------------------------------------------------------------

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function listRecurringRules(): JSONResponse
	{
		return $this->safe(function (string $userId): array {
			$workspaceId = $this->resolveWorkspaceId();
			$workspace = $this->workspaces->getForUser($workspaceId, $userId);
			return [
				'rules' => $this->recurring->listForWorkspace($workspaceId, $userId, $workspace['currencyCode']),
			];
		});
	}

	#[NoAdminRequired]
	public function createRecurringRule(): JSONResponse
	{
		return $this->safe(function (string $userId): array {
			$payload = $this->payload();
			$workspaceId = (int)($payload['workspaceId'] ?? 0);
			if ($workspaceId < 1) {
				throw new \InvalidArgumentException('workspaceId is required.');
			}
			$this->rateLimit->assertAllowed($userId, 'recurring_write', 30, 300);
			$workspace = $this->workspaces->getForUser($workspaceId, $userId);
			$category = $this->resolveCategory((int)($payload['categoryId'] ?? 0), $workspaceId);
			return ['rule' => $this->recurring->create($workspaceId, $userId, $payload, $workspace, $category)];
		});
	}

	#[NoAdminRequired]
	public function updateRecurringRule(int $id): JSONResponse
	{
		$id = $this->validateId($id);
		return $this->safe(function (string $userId) use ($id): array {
			$payload = $this->payload();
			$workspaceId = $this->ownerWorkspaceForRecurringRule($id);
			$workspace = $this->workspaces->getForUser($workspaceId, $userId);
			$this->rateLimit->assertAllowed($userId, 'recurring_write', 30, 300);
			$category = null;
			if (isset($payload['categoryId'])) {
				$category = $this->resolveCategory((int)$payload['categoryId'], $workspaceId);
			}
			return ['rule' => $this->recurring->update($id, $userId, $payload, $workspace, $category)];
		});
	}

	#[NoAdminRequired]
	public function deleteRecurringRule(int $id): JSONResponse
	{
		$id = $this->validateId($id);
		return $this->safe(function (string $userId) use ($id): array {
			$workspaceId = $this->ownerWorkspaceForRecurringRule($id);
			$workspace = $this->workspaces->getForUser($workspaceId, $userId);
			$this->rateLimit->assertAllowed($userId, 'recurring_write', 30, 300);
			$this->recurring->delete($id, $userId, $workspace);
			return ['deleted' => true, 'id' => $id];
		});
	}

	#[NoAdminRequired]
	public function generateFromRecurringRule(int $id): JSONResponse
	{
		$id = $this->validateId($id);
		return $this->safe(function (string $userId) use ($id): array {
			$payload = $this->payload();
			$workspaceId = $this->ownerWorkspaceForRecurringRule($id);
			$workspace = $this->workspaces->getForUser($workspaceId, $userId);
			$this->rateLimit->assertAllowed($userId, 'recurring_generate', 60, 300);
			$ruleRow = $this->recurring->loadHydrated($id, $workspace['currencyCode']);
			$category = $this->resolveCategory((int)$ruleRow['categoryId'], $workspaceId);
			$mode = strtolower(trim((string)($payload['mode'] ?? 'next')));
			if ($mode === 'full_period') {
				$endDate = isset($ruleRow['endDate']) ? (string)$ruleRow['endDate'] : '';
				if ($endDate === '') {
					throw new \InvalidArgumentException('Rule has no end date.');
				}
				return ['generated' => $this->recurring->generate($id, $userId, $workspace, $this->transactions, $category, ['through' => $endDate])];
			}
			return ['transaction' => $this->recurring->generate($id, $userId, $workspace, $this->transactions, $category)];
		});
	}

	// ------------------------------------------------------------------
	//  Budgets and savings target
	// ------------------------------------------------------------------

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function listBudgets(): JSONResponse
	{
		return $this->safe(function (string $userId): array {
			$workspaceId = $this->resolveWorkspaceId();
			$ym = (string)$this->request->getParam('yearMonth', '');
			$workspace = $this->workspaces->getForUser($workspaceId, $userId);
			$out = [
				'budgets' => $this->budgets->listForMonth($workspaceId, $userId, $ym, $workspace['currencyCode']),
				'workspace' => $workspace,
			];
			if (($workspace['type'] ?? null) === WorkspaceService::TYPE_PROJECT) {
				$txCount = $ym !== '' && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)
					? $this->transactions->countBookingsInCalendarMonth($workspaceId, $ym)
					: 0;
				$out['ledgerYearMonthSpan'] = $this->transactions->ledgerYearMonthBounds($workspaceId);
				$out['monthLedger'] = [
					'transactionCount' => $txCount,
					'hasIncomeOrExpense' => $txCount > 0,
				];
			}
			return $out;
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function listBudgetDefaults(): JSONResponse
	{
		return $this->safe(function (string $userId): array {
			$workspaceId = $this->resolveWorkspaceId();
			$workspace = $this->workspaces->getForUser($workspaceId, $userId);
			return ['defaults' => $this->budgets->listDefaults($workspaceId, $userId, $workspace['currencyCode'])];
		});
	}

	#[NoAdminRequired]
	public function bulkUpsertBudgetDefaults(): JSONResponse
	{
		return $this->safe(function (string $userId): array {
			$payload = $this->payload();
			$workspaceId = (int)($payload['workspaceId'] ?? 0);
			if ($workspaceId < 1) {
				throw new \InvalidArgumentException('workspaceId is required.');
			}
			$rows = $payload['rows'] ?? [];
			if (!is_array($rows)) {
				throw new \InvalidArgumentException('rows must be an array.');
			}
			$workspace = $this->workspaces->getForUser($workspaceId, $userId);
			$this->rateLimit->assertAllowed($userId, 'budget_write', 60, 300);
			return ['defaults' => $this->budgets->bulkUpsertDefaults($workspaceId, $userId, $rows, $workspace)];
		});
	}

	#[NoAdminRequired]
	public function bulkUpsertBudgets(): JSONResponse
	{
		return $this->safe(function (string $userId): array {
			$payload = $this->payload();
			$workspaceId = (int)($payload['workspaceId'] ?? 0);
			$ym = (string)($payload['yearMonth'] ?? '');
			if ($workspaceId < 1) {
				throw new \InvalidArgumentException('workspaceId is required.');
			}
			$rows = $payload['rows'] ?? [];
			if (!is_array($rows)) {
				throw new \InvalidArgumentException('rows must be an array.');
			}
			$workspace = $this->workspaces->getForUser($workspaceId, $userId);
			$this->rateLimit->assertAllowed($userId, 'budget_write', 60, 300);
			$budgets = $this->budgets->bulkUpsert($workspaceId, $userId, $ym, $rows, $workspace);
			$out = ['budgets' => $budgets];
			$generate = !empty($payload['generatePlanned']);
			if (!$generate && array_key_exists('generatePlanned', $payload) === false) {
				$generate = (bool)($workspace['generatePlannedFromBudgetsDefault'] ?? false);
			}
			if ($generate && ($workspace['type'] ?? null) === WorkspaceService::TYPE_HOUSEHOLD) {
				$out['plannedSync'] = $this->budgetPlanned->syncMonth($workspaceId, $userId, $ym, $workspace);
			}
			return $out;
		});
	}

	#[NoAdminRequired]
	public function generatePlannedFromBudgets(): JSONResponse
	{
		return $this->safe(function (string $userId): array {
			$payload = $this->payload();
			$workspaceId = (int)($payload['workspaceId'] ?? 0);
			$ym = (string)($payload['yearMonth'] ?? '');
			if ($workspaceId < 1) {
				throw new \InvalidArgumentException('workspaceId is required.');
			}
			if ($ym === '') {
				throw new \InvalidArgumentException('yearMonth is required.');
			}
			$workspace = $this->workspaces->getForUser($workspaceId, $userId);
			$this->rateLimit->assertAllowed($userId, 'budget_planned_generate', 30, 300);
			return [
				'plannedSync' => $this->budgetPlanned->syncMonth($workspaceId, $userId, $ym, $workspace),
			];
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getSavingsTarget(): JSONResponse
	{
		return $this->safe(function (string $userId): array {
			$workspaceId = $this->resolveWorkspaceId();
			$ym = (string)$this->request->getParam('yearMonth', '');
			$workspace = $this->workspaces->getForUser($workspaceId, $userId);
			if ($workspace['type'] !== WorkspaceService::TYPE_HOUSEHOLD) {
				throw new WorkspaceTypeMismatchException('household', $workspace['type'], 'savings_target_read');
			}
			return ['savingsTarget' => $this->savings->load($workspaceId, $userId, $ym, $workspace['currencyCode'])];
		});
	}

	#[NoAdminRequired]
	public function saveSavingsTarget(): JSONResponse
	{
		return $this->safe(function (string $userId): array {
			$payload = $this->payload();
			$workspaceId = (int)($payload['workspaceId'] ?? 0);
			if ($workspaceId < 1) {
				throw new \InvalidArgumentException('workspaceId is required.');
			}
			$workspace = $this->workspaces->getForUser($workspaceId, $userId);
			if ($workspace['type'] !== WorkspaceService::TYPE_HOUSEHOLD) {
				throw new WorkspaceTypeMismatchException('household', $workspace['type'], 'savings_target_write');
			}
			$this->rateLimit->assertAllowed($userId, 'savings_write', 60, 300);
			return ['savingsTarget' => $this->savings->save($workspaceId, $userId, $payload, $workspace['currencyCode'])];
		});
	}

	// ------------------------------------------------------------------
	//  Summaries and snapshots
	// ------------------------------------------------------------------

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function monthlySummary(): JSONResponse
	{
		return $this->safe(function (string $userId): array {
			$workspaceId = $this->resolveWorkspaceId();
			$ym = (string)$this->request->getParam('yearMonth', '');
			$summary = $this->summaries->household($workspaceId, $userId, $ym);
			$summary['warnings'] = $this->localizeWarnings($summary['warnings'] ?? []);
			return ['summary' => $summary];
		});
	}

	#[NoAdminRequired]
	public function monthlyClose(): JSONResponse
	{
		return $this->safe(function (string $userId): array {
			$payload = $this->payload();
			$workspaceId = (int)($payload['workspaceId'] ?? 0);
			$ym = (string)($payload['yearMonth'] ?? '');
			if ($workspaceId < 1) {
				throw new \InvalidArgumentException('workspaceId is required.');
			}
			$this->rateLimit->assertAllowed($userId, 'monthly_close', 10, 300);
			return ['snapshot' => $this->snapshots->close($workspaceId, $userId, $ym)];
		});
	}

	#[NoAdminRequired]
	public function monthlyReopen(): JSONResponse
	{
		return $this->safe(function (string $userId): array {
			$payload = $this->payload();
			$workspaceId = (int)($payload['workspaceId'] ?? 0);
			$ym = (string)($payload['yearMonth'] ?? '');
			if ($workspaceId < 1) {
				throw new \InvalidArgumentException('workspaceId is required.');
			}
			$this->rateLimit->assertAllowed($userId, 'monthly_reopen', 10, 300);
			return ['result' => $this->snapshots->reopen($workspaceId, $userId, $ym)];
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function yearlySummary(): JSONResponse
	{
		return $this->safe(function (string $userId): array {
			$workspaceId = $this->resolveWorkspaceId();
			$year = (int)$this->request->getParam('year', (int)date('Y'));
			$summary = $this->summaries->yearly($workspaceId, $userId, $year);
			return ['summary' => $summary];
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function projectPeriodSummary(): JSONResponse
	{
		return $this->safe(function (string $userId): array {
			$workspaceId = $this->resolveWorkspaceId();
			$ym = $this->request->getParam('yearMonth');
			$ym = is_string($ym) && $ym !== '' ? $ym : null;
			$summary = $this->summaries->projectPeriod($workspaceId, $userId, $ym);
			$summary['warnings'] = $this->localizeWarnings($summary['warnings'] ?? []);
			return ['summary' => $summary];
		});
	}

	// ------------------------------------------------------------------
	//  App-admin directory + policy
	// ------------------------------------------------------------------

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getAppPolicy(): JSONResponse
	{
		return $this->safe(function (string $userId): array {
			if (!$this->access->isAppAdmin($userId)) {
				throw new AccessDeniedException();
			}
			return ['policy' => $this->access->getAppPolicy()];
		});
	}

	#[NoAdminRequired]
	public function saveAppPolicy(): JSONResponse
	{
		return $this->safe(function (string $userId): array {
			if (!$this->access->isAppAdmin($userId)) {
				throw new AccessDeniedException();
			}
			$this->rateLimit->assertAllowed($userId, 'app_policy_save', 20, 300);
			$payload = $this->payload();
			// Accept the comma-separated string form used by the settings UI as
			// well as a real array; both produce the same canonical list.
			if (isset($payload['appAdminUserIds']) && is_string($payload['appAdminUserIds'])) {
				$ids = array_values(array_filter(array_map('trim', explode(',', $payload['appAdminUserIds'])), static fn ($v): bool => $v !== ''));
				$payload['appAdminUserIds'] = $ids;
			}
			$before = $this->access->getAppPolicy();
			$policy = $this->access->saveAppPolicy($payload);
			$this->audit->record($userId, 'app_policy_saved', 'app_policy', 'global', [
				'oldHash' => hash('sha256', json_encode($before, JSON_THROW_ON_ERROR)),
				'newHash' => hash('sha256', json_encode($policy, JSON_THROW_ON_ERROR)),
			]);
			return ['policy' => $policy];
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function searchUsers(): JSONResponse
	{
		return $this->safe(function (string $userId): array {
			// Manager (or app admin treated as manager everywhere) is needed to
			// search the directory; we don't reveal the user list to viewers
			// because that would leak presence information.
			$canSearch = $this->access->isAppAdmin($userId);
			if (!$canSearch) {
				$workspaces = $this->access->workspacesForUser($userId);
				foreach ($workspaces as $wid) {
					if ($this->access->role($wid, $userId) === AccessControlService::ROLE_MANAGER) {
						$canSearch = true;
						break;
					}
				}
			}
			if (!$canSearch) {
				throw new AccessDeniedException();
			}
			$this->rateLimit->assertAllowed($userId, 'user_search', 60, 60);
			$q = trim((string)$this->request->getParam('q', ''));
			if (mb_strlen($q) < 2) {
				return ['users' => []];
			}
			$candidates = array_merge(
				$this->userManager->search($q, 50, 0),
				$this->userManager->searchDisplayName($q, 50, 0)
			);
			$users = [];
			foreach ($candidates as $user) {
				$uid = $user->getUID();
				if (isset($users[$uid])) {
					continue;
				}
				$users[$uid] = [
					'id' => $uid,
					'displayName' => $user->getDisplayName(),
					'enabled' => $user->isEnabled(),
				];
				if (count($users) >= 25) {
					break;
				}
			}
			return ['users' => array_values($users)];
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function searchGroups(): JSONResponse
	{
		return $this->safe(function (string $userId): array {
			// App admins and workspace managers may search groups so managers can
			// grant a group access to their own workspace. Mirrors searchUsers so
			// we never reveal the directory to viewers/contributors.
			$canSearch = $this->access->isAppAdmin($userId);
			if (!$canSearch) {
				foreach ($this->access->workspacesForUser($userId) as $wid) {
					if ($this->access->role($wid, $userId) === AccessControlService::ROLE_MANAGER) {
						$canSearch = true;
						break;
					}
				}
			}
			if (!$canSearch) {
				throw new AccessDeniedException();
			}
			$this->rateLimit->assertAllowed($userId, 'group_search', 60, 60);
			$q = trim((string)$this->request->getParam('q', ''));
			if (mb_strlen($q) < 2) {
				return ['groups' => []];
			}
			$groups = $this->groupManager->search($q, 25, 0);
			$out = [];
			foreach ($groups as $group) {
				$out[] = [
					'id' => $group->getGID(),
					'displayName' => $group->getDisplayName(),
				];
			}
			return ['groups' => $out];
		});
	}

	// ------------------------------------------------------------------
	//  Helpers
	// ------------------------------------------------------------------

	private function resolveWorkspaceId(): int
	{
		$wid = (int)$this->request->getParam('workspaceId', 0);
		if ($wid < 1) {
			throw new \InvalidArgumentException('workspaceId is required.');
		}
		return $wid;
	}

	/**
	 * @param array<string,mixed> $category
	 * @return array<string,mixed>
	 */
	private function presentCategoryToClient(array $category): array
	{
		if (($category['groupKey'] ?? null) !== CategoryService::GROUP_INTERNAL_UNCATEGORIZED) {
			return $category;
		}
		$out = $category;
		$out['name'] = $this->l10n->t('Uncategorized');
		return $out;
	}

	/**
	 * @param list<array<string,mixed>> $categories
	 * @return list<array<string,mixed>>
	 */
	private function presentCategoriesToClient(array $categories): array
	{
		return array_map(fn (array $c): array => $this->presentCategoryToClient($c), $categories);
	}

	/**
	 * Resolve a category and ensure it belongs to the given workspace. We
	 * deliberately return `access_denied` for cross-workspace IDs so probing
	 * cannot distinguish "wrong workspace" from "does not exist".
	 *
	 * @return array<string,mixed>
	 */
	private function resolveCategory(int $categoryId, int $workspaceId): array
	{
		if ($categoryId < 1) {
			throw new \InvalidArgumentException('categoryId is required.');
		}
		$category = $this->categories->loadForWorkspace($categoryId, $workspaceId);
		if ($category === null) {
			throw new AccessDeniedException();
		}
		return $category;
	}

	private function resolveBookingStatus(mixed $statusId, int $workspaceId, array $workspace): ?array
	{
		if ($statusId === null || $statusId === '' || (is_int($statusId) && $statusId === 0) || (is_string($statusId) && trim($statusId) === '0')) {
			return null;
		}
		if (($workspace['type'] ?? null) !== WorkspaceService::TYPE_PROJECT) {
			throw new \InvalidArgumentException('Booking statuses are available only in project workspaces.');
		}
		if (!is_int($statusId) && !ctype_digit((string)$statusId)) {
			throw new \InvalidArgumentException('bookingStatusId must be an integer.');
		}
		$status = $this->bookingStatuses->loadActiveForWorkspace((int)$statusId, $workspaceId);
		if ($status === null) {
			throw new AccessDeniedException();
		}
		return $status;
	}

	private function ownerWorkspaceForTransaction(int $transactionId): int
	{
		// Resolve the owning workspace. We do not check membership here; the
		// caller passes the result to WorkspaceService::getForUser() which
		// throws access_denied for non-members. Nonexistent rows return the
		// same generic access_denied so existence cannot be probed.
		$workspaceId = $this->transactions->ownerWorkspaceId($transactionId);
		if ($workspaceId === null) {
			throw new AccessDeniedException();
		}
		return $workspaceId;
	}

	private function ownerWorkspaceForRecurringRule(int $ruleId): int
	{
		$workspaceId = $this->recurring->ownerWorkspaceId($ruleId);
		if ($workspaceId === null) {
			throw new AccessDeniedException();
		}
		return $workspaceId;
	}

	private function payload(): array
	{
		$params = $this->request->getParams();
		return is_array($params) ? $params : [];
	}

	private function validateId(int $id): int
	{
		if ($id < 1) {
			throw new \InvalidArgumentException('Invalid id.');
		}
		return $id;
	}

	private function intParamOrNull(string $name): ?int
	{
		$value = $this->request->getParam($name);
		if ($value === null || $value === '') {
			return null;
		}
		if (!is_numeric($value)) {
			return null;
		}
		return (int)$value;
	}

	private function boolParam(string $name): bool
	{
		$value = $this->request->getParam($name);
		if (is_bool($value)) {
			return $value;
		}
		if ($value === null || $value === '') {
			return false;
		}
		$value = strtolower((string)$value);
		return in_array($value, ['1', 'true', 'yes', 'on'], true);
	}

	private function boolParamOrNull(string $name): ?bool
	{
		$value = $this->request->getParam($name);
		if ($value === null || $value === '') {
			return null;
		}
		if (is_bool($value)) {
			return $value;
		}
		$value = strtolower((string)$value);
		if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
			return true;
		}
		if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
			return false;
		}
		return null;
	}

	/**
	 * Wrap every endpoint with the same error mapping. The closure receives the
	 * resolved user id (or throws if anonymous).
	 */
	private function safe(callable $operation): JSONResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$result = $operation($userId);
			return $this->ok(is_array($result) ? $result : []);
		} catch (ValidationException $e) {
			return new JSONResponse([
				'ok' => false,
				'message' => $e->getMessage(),
				'error' => [
					'code' => 'invalid_input',
					'fields' => $e->getFields(),
				],
			], Http::STATUS_BAD_REQUEST);
		} catch (\InvalidArgumentException $e) {
			return $this->error($e->getMessage(), Http::STATUS_BAD_REQUEST, 'invalid_input');
		} catch (WorkspaceTypeMismatchException $e) {
			return new JSONResponse([
				'ok' => false,
				'error' => [
					'code' => 'NOT_APPLICABLE_FOR_WORKSPACE_TYPE',
					'workspaceType' => $e->getActualType(),
					'expectedType' => $e->getExpectedType(),
					'operation' => $e->getOperation(),
				],
				'message' => 'This operation does not apply to this workspace type.',
			], 422);
		} catch (NotAuthenticatedException $e) {
			$this->recordAccessDenied('not_authenticated');
			return $this->error('Authentication required.', Http::STATUS_UNAUTHORIZED, 'not_authenticated');
		} catch (AccessDeniedException $e) {
			$this->recordAccessDenied('access_denied');
			return $this->error('Access denied.', Http::STATUS_FORBIDDEN, 'access_denied');
		} catch (RateLimitExceededException $e) {
			return $this->error('Too many requests. Please wait a moment and try again.', 429, 'rate_limit_exceeded');
		} catch (ConflictException $e) {
			return $this->error('This entry changed since you opened it. Reload and retry.', Http::STATUS_CONFLICT, 'version_conflict');
		} catch (InternalErrorException $e) {
			$this->logger->warning('budgetcheck internal_error', ['exception' => $e]);
			return $this->error('Request could not be completed.', Http::STATUS_INTERNAL_SERVER_ERROR, 'internal_error');
		} catch (BudgetCheckException $e) {
			$this->logger->warning('budgetcheck unhandled domain exception', ['exception' => $e]);
			return $this->error('Request could not be completed.', Http::STATUS_INTERNAL_SERVER_ERROR, 'internal_error');
		} catch (\Throwable $e) {
			$this->logger->warning('budgetcheck unexpected error', ['exception' => $e]);
			return $this->error('Request could not be completed.', Http::STATUS_INTERNAL_SERVER_ERROR, 'internal_error');
		}
	}

	private function ok(array $data): JSONResponse
	{
		return new JSONResponse(['ok' => true] + $data);
	}

	private function error(string $message, int $status, string $code): JSONResponse
	{
		return new JSONResponse([
			'ok' => false,
			'message' => $message,
			'error' => ['code' => $code],
		], $status);
	}

	/**
	 * @param list<array<string,mixed>> $warnings
	 * @return list<array<string,mixed>>
	 */
	private function localizeWarnings(array $warnings): array
	{
		$out = [];
		foreach ($warnings as $w) {
			$code = (string)($w['code'] ?? '');
			$meta = is_array($w['meta'] ?? null) ? $w['meta'] : [];
			switch ($code) {
				case 'budget_overspent':
					$name = (string)($meta['categoryName'] ?? '');
					$planned = max(1, (int)($meta['plannedMinor'] ?? 1));
					$actual = (int)($meta['actualMinor'] ?? 0);
					$pct = ($actual / $planned) * 100.0;
					$out[] = array_merge($w, [
						'title' => $this->l10n->t('Over budget'),
						'message' => $this->l10n->t('%1$s spent %2$.0f%% of its monthly budget.', [$name, $pct]),
					]);
					break;
				case 'budget_near_limit':
					$name = (string)($meta['categoryName'] ?? '');
					$planned = max(1, (int)($meta['plannedMinor'] ?? 1));
					$actual = (int)($meta['actualMinor'] ?? 0);
					$pct = ($actual / $planned) * 100.0;
					$out[] = array_merge($w, [
						'title' => $this->l10n->t('Near budget limit'),
						'message' => $this->l10n->t('%1$s has reached %2$.0f%% of its monthly budget.', [$name, $pct]),
					]);
					break;
				case 'uncategorized_expense':
					$count = (int)($meta['count'] ?? 0);
					$out[] = array_merge($w, [
						'title' => $this->l10n->t('Uncategorized expenses'),
						'message' => $this->l10n->n(
							'%n uncategorized expense remains without a category. It counts toward the total but not toward any budget.',
							'%n uncategorized expenses remain without a category. They count toward the total but not toward any budget.',
							$count
						),
					]);
					break;
				case 'available_after_savings_negative':
					$out[] = array_merge($w, [
						'title' => $this->l10n->t('Available after savings is negative'),
						'message' => $this->l10n->t('Income minus expense minus savings target is below zero this month.'),
					]);
					break;
				case 'large_special_expense':
					$specialTitle = trim((string)($meta['specialTitle'] ?? ''));
					$label = $specialTitle !== ''
						? $specialTitle
						: $this->l10n->t('A special transaction');
					$out[] = array_merge($w, [
						'title' => $this->l10n->t('Large special expense'),
						'message' => $this->l10n->t('%s exceeds the configured large-expense threshold.', [$label]),
					]);
					break;
				case 'project_cap_exceeded':
					$ratio = (float)($meta['capMinor'] ?? 1) > 0
						? ((int)($meta['allTimeSpendMinor'] ?? 0) / (int)($meta['capMinor'] ?? 1)) * 100.0
						: 0.0;
					$out[] = array_merge($w, [
						'title' => $this->l10n->t('Project cap exceeded'),
						'message' => $this->l10n->t('All-time project spend is %1$.0f%% of the cap.', [$ratio]),
					]);
					break;
				case 'project_cap_near':
					$ratio = (float)($meta['ratio'] ?? 0) * 100.0;
					$out[] = array_merge($w, [
						'title' => $this->l10n->t('Approaching project cap'),
						'message' => $this->l10n->t('Project spend has reached %1$.0f%% of the cap.', [$ratio]),
					]);
					break;
				default:
					$out[] = $w;
			}
		}
		return $out;
	}

	private function recordAccessDenied(string $reason): void
	{
		try {
			$actor = $this->access->currentUserId();
			$this->audit->record(
				$actor === '' ? 'anonymous' : $actor,
				'access_denied',
				'api',
				$this->request->getMethod() . ' ' . $this->request->getRequestUri(),
				['reason' => $reason]
			);
		} catch (\Throwable) {
			// Audit recording must never crash a denied request.
		}
	}
}
