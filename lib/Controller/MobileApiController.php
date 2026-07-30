<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Controller;

use OCA\BudgetCheck\AppInfo\Application;
use OCA\BudgetCheck\Capabilities;
use OCA\BudgetCheck\Exception\AccessDeniedException;
use OCA\BudgetCheck\Exception\BudgetCheckException;
use OCA\BudgetCheck\Exception\ConflictException;
use OCA\BudgetCheck\Exception\IdempotencyMismatchException;
use OCA\BudgetCheck\Exception\InternalErrorException;
use OCA\BudgetCheck\Exception\NotAuthenticatedException;
use OCA\BudgetCheck\Exception\NotFoundException;
use OCA\BudgetCheck\Exception\RateLimitExceededException;
use OCA\BudgetCheck\Exception\ValidationException;
use OCA\BudgetCheck\Exception\WorkspaceTypeMismatchException;
use OCA\BudgetCheck\Service\AccessControlService;
use OCA\BudgetCheck\Service\BookingStatusService;
use OCA\BudgetCheck\Service\CategoryService;
use OCA\BudgetCheck\Service\MobileErrorCodes;
use OCA\BudgetCheck\Service\MobileHomeKpi;
use OCA\BudgetCheck\Service\MobileIdempotencyService;
use OCA\BudgetCheck\Service\MobileMutationChannel;
use OCA\BudgetCheck\Service\MobilePushService;
use OCA\BudgetCheck\Service\RateLimitService;
use OCA\BudgetCheck\Service\RecurringRuleService;
use OCA\BudgetCheck\Service\SummaryService;
use OCA\BudgetCheck\Service\TransactionAttachmentService;
use OCA\BudgetCheck\Service\TransactionService;
use OCA\BudgetCheck\Service\WorkspaceService;
use OCA\BudgetCheck\Service\ReceiptSuggest\ReceiptSuggestServiceInterface;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Free BudgetCheck Mobile API (`/api/mobile/v1/*`).
 *
 * - No license / seat / payment-required gates (COMPANION-APP.md v2.0).
 * - Mutations: NoCSRFRequired for Basic app-password; cookie-only browsers
 *   still need a valid requesttoken (assertSafeMutationChannel).
 * - ACL, CAS version, closed-month, tax, IDOR: identical to web services.
 */
class MobileApiController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly AccessControlService $access,
		private readonly WorkspaceService $workspaces,
		private readonly CategoryService $categories,
		private readonly TransactionService $transactions,
		private readonly BookingStatusService $bookingStatuses,
		private readonly SummaryService $summaries,
		private readonly RecurringRuleService $recurring,
		private readonly MobileIdempotencyService $idempotency,
		private readonly MobilePushService $push,
		private readonly RateLimitService $rateLimit,
		private readonly TransactionAttachmentService $attachments,
		private readonly ReceiptSuggestServiceInterface $receiptSuggest,
		private readonly IAppManager $appManager,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function bootstrap(): JSONResponse
	{
		return $this->safe(function (string $userId): array {
			$user = $this->userSession->getUser();
			$displayName = $user?->getDisplayName() ?? $userId;
			$pushAvailable = $this->appManager->isEnabledForUser('notifications');
			return [
				'serverTime' => (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM),
				'companion' => [
					'min' => Capabilities::COMPANION_MIN,
					'api' => Capabilities::COMPANION_API,
				],
				'user' => [
					'uid' => $userId,
					'displayName' => $displayName,
				],
				'capabilities' => [
					'offlineCreate' => true,
					'push' => $pushAvailable,
					'tax' => true,
					'recurringSuggestions' => true,
					'attachments' => true,
					'receiptSuggest' => $this->receiptSuggest->isAvailable($userId),
					'receiptSuggestModes' => $this->receiptSuggest->modesForUser($userId),
				],
			];
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function listWorkspaces(): JSONResponse
	{
		return $this->safe(function (string $userId): array {
			$includeInactive = $this->boolParam('includeInactive');
			$workspaces = $this->workspaces->listForUser($userId, $includeInactive);
			$favorites = $this->access->favoriteWorkspaceIds($userId);
			$favSet = array_fill_keys($favorites, true);
			$out = [];
			foreach ($workspaces as $ws) {
				$id = (int)$ws['id'];
				$out[] = [
					'id' => $id,
					'name' => (string)$ws['name'],
					'type' => (string)$ws['type'],
					'currencyCode' => (string)$ws['currencyCode'],
					'currencyDecimals' => (int)($ws['currencyDecimals'] ?? 2),
					'timezone' => (string)$ws['timezone'],
					'taxModeEnabled' => (bool)$ws['taxModeEnabled'],
					'taxBudgetBasis' => (string)$ws['taxBudgetBasis'],
					'defaultVatRateBp' => $ws['defaultVatRateBp'] ?? null,
					'projectStartDate' => $ws['projectStartDate'] ?? null,
					'projectEndDate' => $ws['projectEndDate'] ?? null,
					'projectTotalCapMinor' => $ws['projectTotalCapMinor'] ?? null,
					'activeCalendarYearMonth' => $ws['activeCalendarYearMonth'] ?? null,
					'isFavorite' => isset($favSet[$id]),
					'role' => (string)($ws['role'] ?? AccessControlService::ROLE_VIEWER),
					'isActive' => (bool)($ws['isActive'] ?? true),
				];
			}
			return [
				'workspaces' => $out,
				'lastUsedWorkspaceId' => $this->access->lastUsedWorkspace($userId),
				'favoriteWorkspaceIds' => $favorites,
			];
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function home(int $workspaceId): JSONResponse
	{
		return $this->safe(function (string $userId) use ($workspaceId): array {
			$workspaceId = $this->validateId($workspaceId);
			$workspace = $this->workspaces->getForUser($workspaceId, $userId);
			$this->access->rememberLastUsedWorkspace($userId, $workspaceId);
			$type = (string)$workspace['type'];
			if ($type === WorkspaceService::TYPE_HOUSEHOLD) {
				$ym = (string)$this->request->getParam('yearMonth', '');
				if ($ym === '') {
					$ym = (string)($workspace['activeCalendarYearMonth'] ?? date('Y-m'));
				}
				$summary = $this->summaries->household($workspaceId, $userId, $ym);
				$summary['warnings'] = $this->localizeWarnings($summary['warnings'] ?? []);
				$totals = is_array($summary['totals'] ?? null) ? $summary['totals'] : [];
				$available = $this->envelopeMinor($totals['availableAfterSavings'] ?? null);
				$income = $this->envelopeMinor($totals['income'] ?? null);
				$expense = $this->envelopeMinor($totals['expense'] ?? null);
				$net = $this->envelopeMinor($totals['netResult'] ?? null);
				return [
					'scope' => [
						'workspaceId' => $workspaceId,
						'name' => (string)$workspace['name'],
						'type' => $type,
						'role' => (string)$workspace['role'],
						'yearMonth' => $ym,
						'timezone' => (string)$workspace['timezone'],
						'currencyCode' => (string)$workspace['currencyCode'],
						'currencyDecimals' => (int)($workspace['currencyDecimals'] ?? 2),
						'taxModeEnabled' => (bool)$workspace['taxModeEnabled'],
					],
					'dominantKpi' => [
						'key' => MobileHomeKpi::dominantKey($type, false),
						'label' => $this->l10n->t('Available after savings'),
						'amountMinor' => $available,
					],
					'secondary' => [
						'incomeMinor' => $income,
						'expenseMinor' => $expense,
						'netMinor' => $net,
					],
					'budgetChips' => $this->budgetChipsFromSummary($summary),
					'warnings' => $summary['warnings'],
					'summary' => $summary,
				];
			}

			$ym = $this->request->getParam('yearMonth');
			$ym = is_string($ym) && $ym !== '' ? $ym : null;
			$summary = $this->summaries->projectPeriod($workspaceId, $userId, $ym);
			$summary['warnings'] = $this->localizeWarnings($summary['warnings'] ?? []);
			$allTime = is_array($summary['allTime'] ?? null) ? $summary['allTime'] : [];
			$cap = $workspace['projectTotalCapMinor'] ?? null;
			$spend = $this->envelopeMinor($allTime['expense'] ?? null);
			if ($spend === 0 && isset($allTime['expenseMinor'])) {
				$spend = (int)$allTime['expenseMinor'];
			}
			$incomeAll = $this->envelopeMinor($allTime['income'] ?? null);
			$headroom = $cap !== null ? ((int)$cap - $spend) : null;
			return [
				'scope' => [
					'workspaceId' => $workspaceId,
					'name' => (string)$workspace['name'],
					'type' => $type,
					'role' => (string)$workspace['role'],
					'projectStartDate' => $workspace['projectStartDate'] ?? null,
					'projectEndDate' => $workspace['projectEndDate'] ?? null,
					'yearMonth' => $ym,
					'timezone' => (string)$workspace['timezone'],
					'currencyCode' => (string)$workspace['currencyCode'],
					'currencyDecimals' => (int)($workspace['currencyDecimals'] ?? 2),
					'taxModeEnabled' => (bool)$workspace['taxModeEnabled'],
				],
				'dominantKpi' => [
					'key' => MobileHomeKpi::dominantKey($type, $cap !== null),
					'label' => $cap !== null
						? $this->l10n->t('Spend vs cap')
						: $this->l10n->t('Spend to date'),
					'amountMinor' => $spend,
					'capMinor' => $cap !== null ? (int)$cap : null,
					'headroomMinor' => $headroom,
				],
				'secondary' => [
					'incomeMinor' => $incomeAll,
					'expenseMinor' => $spend,
				],
				'budgetChips' => $this->budgetChipsFromSummary($summary),
				'warnings' => $summary['warnings'],
				'summary' => $summary,
			];
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function listCategories(int $workspaceId): JSONResponse
	{
		return $this->safe(function (string $userId) use ($workspaceId): array {
			$workspaceId = $this->validateId($workspaceId);
			$this->workspaces->getForUser($workspaceId, $userId);
			return [
				'categories' => $this->categories->listForWorkspace($workspaceId, $userId, false),
			];
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function listBookingStatuses(int $workspaceId): JSONResponse
	{
		return $this->safe(function (string $userId) use ($workspaceId): array {
			$workspaceId = $this->validateId($workspaceId);
			return [
				'bookingStatuses' => $this->bookingStatuses->listForWorkspace($workspaceId, $userId, false),
			];
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function listTransactions(int $workspaceId): JSONResponse
	{
		return $this->safe(function (string $userId) use ($workspaceId): array {
			$workspaceId = $this->validateId($workspaceId);
			$workspace = $this->workspaces->getForUser($workspaceId, $userId);
			$filters = [
				'from' => $this->stringParam('from'),
				'to' => $this->stringParam('to'),
				'categoryId' => $this->intParamOrNull('categoryId'),
				'q' => $this->stringParam('q'),
				'uncategorized' => $this->boolParamOrNull('uncategorized'),
				'statusId' => $this->intParamOrNull('statusId'),
				'limit' => (int)$this->request->getParam('limit', 50),
				'offset' => (int)$this->request->getParam('offset', 0),
			];
			return [
				'transactions' => $this->transactions->listForWorkspace($workspaceId, $userId, $filters, $workspace),
			];
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getTransaction(int $workspaceId, int $txId): JSONResponse
	{
		return $this->safe(function (string $userId) use ($workspaceId, $txId): array {
			$workspaceId = $this->validateId($workspaceId);
			$txId = $this->validateId($txId);
			$this->workspaces->getForUser($workspaceId, $userId);
			$tx = $this->transactions->loadForWorkspace($txId, $workspaceId);
			if ($tx === null) {
				throw new NotFoundException('Transaction not found.');
			}
			return ['transaction' => $tx];
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function createTransaction(int $workspaceId): JSONResponse
	{
		return $this->safe(function (string $userId) use ($workspaceId): array {
			$workspaceId = $this->validateId($workspaceId);
			$this->assertSafeMutationChannel();
			$payload = $this->payload();
			$payload['workspaceId'] = $workspaceId;
			$this->rateLimit->assertAllowed($userId, 'mobile_transaction_write', 240, 300);

			$idemKey = trim((string)$this->request->getHeader('Idempotency-Key'));
			$requestHash = MobileIdempotencyService::hashPayload($payload);
			if ($idemKey !== '') {
				$replay = $this->idempotency->findReplay($userId, $workspaceId, $idemKey, $requestHash);
				if ($replay !== null) {
					return $replay['body'];
				}
			}

			$workspace = $this->workspaces->getForUser($workspaceId, $userId);
			$category = $this->resolveCategory((int)($payload['categoryId'] ?? 0), $workspaceId);
			$bookingStatus = $this->resolveBookingStatus($payload['bookingStatusId'] ?? null, $workspaceId, $workspace);
			$tx = $this->transactions->create($workspaceId, $userId, $payload, $workspace, $category, $bookingStatus);
			$body = ['transaction' => $tx];
			if ($idemKey !== '') {
				$this->idempotency->store($userId, $workspaceId, $idemKey, $requestHash, ['ok' => true] + $body, 200);
			}
			return $body;
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function updateTransaction(int $workspaceId, int $txId): JSONResponse
	{
		return $this->safe(function (string $userId) use ($workspaceId, $txId): array {
			$workspaceId = $this->validateId($workspaceId);
			$txId = $this->validateId($txId);
			$this->assertSafeMutationChannel();
			$payload = $this->payload();
			$this->rateLimit->assertAllowed($userId, 'mobile_transaction_write', 240, 300);
			$workspace = $this->workspaces->getForUser($workspaceId, $userId);
			$existing = $this->transactions->loadForWorkspace($txId, $workspaceId);
			if ($existing === null) {
				throw new NotFoundException('Transaction not found.');
			}
			$categoryId = isset($payload['categoryId'])
				? (int)$payload['categoryId']
				: (int)$existing['category_id'];
			$category = $this->resolveCategory($categoryId, $workspaceId);
			$bookingStatus = array_key_exists('bookingStatusId', $payload)
				? $this->resolveBookingStatus($payload['bookingStatusId'], $workspaceId, $workspace)
				: null;
			return [
				'transaction' => $this->transactions->update($txId, $userId, $payload, $workspace, $category, $bookingStatus),
			];
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function deleteTransaction(int $workspaceId, int $txId): JSONResponse
	{
		return $this->safe(function (string $userId) use ($workspaceId, $txId): array {
			$workspaceId = $this->validateId($workspaceId);
			$txId = $this->validateId($txId);
			$this->assertSafeMutationChannel();
			$this->rateLimit->assertAllowed($userId, 'mobile_transaction_write', 240, 300);
			$workspace = $this->workspaces->getForUser($workspaceId, $userId);
			$existing = $this->transactions->loadForWorkspace($txId, $workspaceId);
			if ($existing === null) {
				throw new NotFoundException('Transaction not found.');
			}
			$payload = $this->payload();
			if (!array_key_exists('version', $payload) || $payload['version'] === null || $payload['version'] === '') {
				throw new \InvalidArgumentException('version is required for deletes.');
			}
			$expectedVersion = (int)$payload['version'];
			$this->transactions->delete($txId, $userId, $workspace, $expectedVersion);
			return ['deleted' => true, 'id' => $txId];
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function listRecurringSuggestions(int $workspaceId): JSONResponse
	{
		return $this->safe(function (string $userId) use ($workspaceId): array {
			$workspaceId = $this->validateId($workspaceId);
			$workspace = $this->workspaces->getForUser($workspaceId, $userId);
			$rules = $this->recurring->listForWorkspace($workspaceId, $userId, (string)$workspace['currencyCode']);
			$suggestions = [];
			foreach ($rules as $rule) {
				if (!(bool)($rule['isActive'] ?? $rule['is_active'] ?? true)) {
					continue;
				}
				$suggestions[] = [
					'id' => (int)($rule['id'] ?? 0),
					'title' => (string)($rule['title'] ?? $rule['name'] ?? ''),
					'direction' => (string)($rule['direction'] ?? ''),
					'amountMinor' => (int)($rule['amountMinor'] ?? $rule['amount_minor'] ?? 0),
					'categoryId' => (int)($rule['categoryId'] ?? $rule['category_id'] ?? 0),
					'nextDueDate' => $rule['nextDueDate'] ?? $rule['next_due_date'] ?? null,
					'frequency' => (string)($rule['frequency'] ?? ''),
				];
			}
			return ['suggestions' => $suggestions];
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function applyRecurringSuggestion(int $workspaceId, int $ruleId): JSONResponse
	{
		return $this->safe(function (string $userId) use ($workspaceId, $ruleId): array {
			$workspaceId = $this->validateId($workspaceId);
			$ruleId = $this->validateId($ruleId);
			$this->assertSafeMutationChannel();
			$workspace = $this->workspaces->getForUser($workspaceId, $userId);
			$this->rateLimit->assertAllowed($userId, 'mobile_recurring_apply', 60, 300);
			$ownerWs = $this->recurring->ownerWorkspaceId($ruleId);
			if ($ownerWs === null || $ownerWs !== $workspaceId) {
				throw new AccessDeniedException();
			}
			$ruleRow = $this->recurring->loadHydrated($ruleId, (string)$workspace['currencyCode']);
			$category = $this->resolveCategory((int)$ruleRow['categoryId'], $workspaceId);
			$created = $this->recurring->generate($ruleId, $userId, $workspace, $this->transactions, $category);
			return ['transaction' => $created];
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function registerPushToken(): JSONResponse
	{
		return $this->safe(function (string $userId): array {
			$this->assertSafeMutationChannel();
			$payload = $this->payload();
			$token = (string)($payload['pushToken'] ?? $payload['token'] ?? '');
			$platform = (string)($payload['platform'] ?? 'android');
			$this->push->register($userId, $token, $platform);
			return ['registered' => true];
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function unregisterPushToken(): JSONResponse
	{
		return $this->safe(function (string $userId): array {
			$this->assertSafeMutationChannel();
			$payload = $this->payload();
			$token = (string)($payload['pushToken'] ?? $payload['token'] ?? '');
			$this->push->unregister($userId, $token);
			return ['unregistered' => true];
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function listTransactionAttachments(int $workspaceId, int $txId): JSONResponse
	{
		return $this->safe(function (string $userId) use ($workspaceId, $txId): array {
			$workspaceId = $this->validateId($workspaceId);
			$txId = $this->validateId($txId);
			$this->workspaces->getForUser($workspaceId, $userId);
			$tx = $this->transactions->loadForWorkspace($txId, $workspaceId);
			if ($tx === null) {
				throw new NotFoundException('Transaction not found.');
			}
			return [
				'attachments' => $this->attachments->listForTransaction($txId, $userId),
			];
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function uploadTransactionAttachment(int $workspaceId, int $txId): JSONResponse
	{
		return $this->safe(function (string $userId) use ($workspaceId, $txId): array {
			$workspaceId = $this->validateId($workspaceId);
			$txId = $this->validateId($txId);
			$this->assertSafeMutationChannel();
			$this->workspaces->getForUser($workspaceId, $userId);
			$tx = $this->transactions->loadForWorkspace($txId, $workspaceId);
			if ($tx === null) {
				throw new NotFoundException('Transaction not found.');
			}
			$this->rateLimit->assertAllowed($userId, 'mobile_transaction_attachment_write', 120, 300);
			if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
				throw new \InvalidArgumentException('No file was uploaded.');
			}
			$attachment = $this->attachments->upload($txId, $userId, $_FILES['file']);
			return ['attachment' => $attachment];
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function deleteTransactionAttachment(int $workspaceId, int $attachmentId): JSONResponse
	{
		return $this->safe(function (string $userId) use ($workspaceId, $attachmentId): array {
			$workspaceId = $this->validateId($workspaceId);
			$attachmentId = $this->validateId($attachmentId);
			$this->assertSafeMutationChannel();
			$this->workspaces->getForUser($workspaceId, $userId);
			$this->rateLimit->assertAllowed($userId, 'mobile_transaction_attachment_write', 120, 300);
			$this->attachments->delete($attachmentId, $userId);
			return ['deleted' => true, 'id' => $attachmentId];
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function createReceiptSuggestion(int $workspaceId): JSONResponse
	{
		return $this->safe(function (string $userId) use ($workspaceId): array {
			$workspaceId = $this->validateId($workspaceId);
			$this->assertSafeMutationChannel();
			$this->rateLimit->assertAllowed($userId, 'mobile_receipt_suggest', 30, 300);
			if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
				throw new \InvalidArgumentException('No file was uploaded.');
			}
			return $this->receiptSuggest->startFromUpload($workspaceId, $userId, $_FILES['file']);
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getReceiptSuggestion(int $workspaceId, int $jobId): JSONResponse
	{
		return $this->safe(function (string $userId) use ($workspaceId, $jobId): array {
			$workspaceId = $this->validateId($workspaceId);
			$jobId = $this->validateId($jobId);
			return $this->receiptSuggest->poll($workspaceId, $userId, $jobId);
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function acceptReceiptSuggestion(int $workspaceId, int $jobId): JSONResponse
	{
		return $this->safe(function (string $userId) use ($workspaceId, $jobId): array {
			$workspaceId = $this->validateId($workspaceId);
			$jobId = $this->validateId($jobId);
			$this->assertSafeMutationChannel();
			$this->rateLimit->assertAllowed($userId, 'mobile_receipt_suggest_accept', 60, 300);
			$payload = $this->payload();
			$suggestion = is_array($payload['suggestion'] ?? null) ? $payload['suggestion'] : $payload;
			return $this->receiptSuggest->accept($workspaceId, $userId, $jobId, $suggestion);
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function cancelReceiptSuggestion(int $workspaceId, int $jobId): JSONResponse
	{
		return $this->safe(function (string $userId) use ($workspaceId, $jobId): array {
			$workspaceId = $this->validateId($workspaceId);
			$jobId = $this->validateId($jobId);
			$this->assertSafeMutationChannel();
			$this->receiptSuggest->cancelJob($workspaceId, $userId, $jobId);
			return ['cancelled' => true, 'jobId' => $jobId];
		});
	}

	/**
	 * Mutations accept Basic/Bearer app-password OR a valid CSRF requesttoken.
	 * Cookie-only sessions without a token are rejected (CSRF hardening).
	 */
	private function assertSafeMutationChannel(): void
	{
		$auth = (string)$this->request->getHeader('Authorization');
		$tokenHeader = (string)$this->request->getHeader('requesttoken');
		$tokenParam = (string)($this->request->getParam('requesttoken') ?? '');
		if (!MobileMutationChannel::isSafe($auth, $tokenHeader, $tokenParam)) {
			throw new AccessDeniedException();
		}
	}

	/**
	 * @param callable(string):array $operation
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
					'code' => 'VALIDATION',
					'fields' => $e->getFields(),
				],
			], Http::STATUS_BAD_REQUEST);
		} catch (\InvalidArgumentException $e) {
			$code = MobileErrorCodes::fromInvalidArgument($e->getMessage());
			$status = MobileErrorCodes::httpStatusFor($code);
			return $this->error($e->getMessage(), $status, $code);
		} catch (WorkspaceTypeMismatchException $e) {
			return new JSONResponse([
				'ok' => false,
				'message' => 'This operation does not apply to this workspace type.',
				'error' => [
					'code' => 'WORKSPACE_TYPE_MISMATCH',
					'workspaceType' => $e->getActualType(),
					'expectedType' => $e->getExpectedType(),
					'operation' => $e->getOperation(),
				],
			], 422);
		} catch (NotAuthenticatedException $e) {
			return $this->error('Authentication required.', Http::STATUS_UNAUTHORIZED, 'not_authenticated');
		} catch (NotFoundException $e) {
			return $this->error($e->getMessage() !== '' ? $e->getMessage() : 'Resource not found.', Http::STATUS_NOT_FOUND, 'NOT_FOUND');
		} catch (AccessDeniedException $e) {
			return $this->error('Access denied.', Http::STATUS_FORBIDDEN, 'FORBIDDEN');
		} catch (RateLimitExceededException $e) {
			return $this->error('Too many requests. Please wait a moment and try again.', 429, 'rate_limit_exceeded');
		} catch (IdempotencyMismatchException $e) {
			return $this->error('Idempotency key was reused with a different request.', Http::STATUS_CONFLICT, 'IDEMPOTENCY_MISMATCH');
		} catch (ConflictException $e) {
			return $this->error('This entry changed since you opened it. Reload and retry.', Http::STATUS_CONFLICT, 'VERSION_CONFLICT');
		} catch (InternalErrorException $e) {
			$this->logger->warning('budgetcheck mobile internal_error', ['exception' => $e]);
			return $this->error('Request could not be completed.', Http::STATUS_INTERNAL_SERVER_ERROR, 'internal_error');
		} catch (BudgetCheckException $e) {
			$this->logger->warning('budgetcheck mobile domain exception', ['exception' => $e]);
			return $this->error('Request could not be completed.', Http::STATUS_INTERNAL_SERVER_ERROR, 'internal_error');
		} catch (\Throwable $e) {
			$this->logger->warning('budgetcheck mobile unexpected error', ['exception' => $e]);
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
			'error' => [
				'code' => $code,
				'message' => $message,
			],
		], $status);
	}

	/**
	 * @return array<string, mixed>
	 */
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

	private function stringParam(string $name): ?string
	{
		$value = $this->request->getParam($name);
		if ($value === null || $value === '') {
			return null;
		}
		return (string)$value;
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
		return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
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
		$v = strtolower((string)$value);
		if (in_array($v, ['1', 'true', 'yes', 'on'], true)) {
			return true;
		}
		if (in_array($v, ['0', 'false', 'no', 'off'], true)) {
			return false;
		}
		return null;
	}

	/**
	 * @return array<string, mixed>
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

	/**
	 * @param array<string, mixed> $workspace
	 * @return array<string, mixed>|null
	 */
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

	/**
	 * @param array<string, mixed> $summary
	 * @return list<array<string, mixed>>
	 */
	private function budgetChipsFromSummary(array $summary): array
	{
		$budget = is_array($summary['budget'] ?? null) ? $summary['budget'] : [];
		$byCategory = $budget['byCategory'] ?? null;
		if (!is_array($byCategory)) {
			return [];
		}
		$chips = [];
		foreach ($byCategory as $row) {
			if (!is_array($row)) {
				continue;
			}
			if (!(bool)($row['hasBudget'] ?? false)) {
				continue;
			}
			$planned = $this->envelopeMinor($row['planned'] ?? null);
			$actual = $this->envelopeMinor($row['actual'] ?? null);
			$chips[] = [
				'categoryId' => (int)($row['categoryId'] ?? 0),
				'categoryName' => (string)($row['name'] ?? ''),
				'plannedMinor' => $planned,
				'actualMinor' => $actual,
				'leftMinor' => $planned - $actual,
			];
			if (count($chips) >= 12) {
				break;
			}
		}
		return $chips;
	}

	private function envelopeMinor(mixed $envelope): int
	{
		if (is_array($envelope) && isset($envelope['minor'])) {
			return (int)$envelope['minor'];
		}
		if (is_int($envelope)) {
			return $envelope;
		}
		return 0;
	}

	/**
	 * @param list<array<string, mixed>> $warnings
	 * @return list<array<string, mixed>>
	 */
	private function localizeWarnings(array $warnings): array
	{
		$out = [];
		foreach ($warnings as $w) {
			$code = (string)($w['code'] ?? '');
			$meta = is_array($w['meta'] ?? null) ? $w['meta'] : [];
			$recovery = [
				'action' => 'transactions',
				'filter' => null,
			];
			switch ($code) {
				case 'budget_overspent':
					$name = (string)($meta['categoryName'] ?? '');
					$planned = max(1, (int)($meta['plannedMinor'] ?? 1));
					$actual = (int)($meta['actualMinor'] ?? 0);
					$pct = ($actual / $planned) * 100.0;
					$out[] = array_merge($w, [
						'title' => $this->l10n->t('Over budget'),
						'message' => $this->l10n->t('%1$s spent %2$.0f%% of its monthly budget.', [$name, $pct]),
						'recovery' => ['action' => 'home', 'filter' => null],
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
						'recovery' => ['action' => 'home', 'filter' => null],
					]);
					break;
				case 'uncategorized_expense':
					$count = (int)($meta['count'] ?? 0);
					$out[] = array_merge($w, [
						'title' => $this->l10n->t('Uncategorized expenses'),
						'message' => $this->l10n->n(
							'%n uncategorized expense remains without a category.',
							'%n uncategorized expenses remain without a category.',
							$count
						),
						'recovery' => ['action' => 'transactions', 'filter' => 'uncategorized'],
					]);
					break;
				case 'available_after_savings_negative':
					$out[] = array_merge($w, [
						'title' => $this->l10n->t('Tight month'),
						'message' => $this->l10n->t('Available after savings is negative this month.'),
						'recovery' => $recovery,
					]);
					break;
				case 'project_cap_exceeded':
					$out[] = array_merge($w, [
						'title' => $this->l10n->t('Over project cap'),
						'message' => $this->l10n->t('Project spending has exceeded the total cap.'),
						'recovery' => ['action' => 'home', 'filter' => null],
					]);
					break;
				case 'project_cap_near':
					$out[] = array_merge($w, [
						'title' => $this->l10n->t('Near project cap'),
						'message' => $this->l10n->t('Project spending is approaching the total cap.'),
						'recovery' => ['action' => 'home', 'filter' => null],
					]);
					break;
				default:
					$out[] = array_merge($w, [
						'title' => (string)($w['title'] ?? $this->l10n->t('Notice')),
						'message' => (string)($w['message'] ?? $code),
						'recovery' => $recovery,
					]);
			}
		}
		return $out;
	}
}
