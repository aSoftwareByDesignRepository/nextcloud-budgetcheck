<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Controller;

use OCA\BudgetCheck\Exception\AccessDeniedException;
use OCA\BudgetCheck\Exception\NotAuthenticatedException;
use OCA\BudgetCheck\Exception\RateLimitExceededException;
use OCA\BudgetCheck\Exception\WorkspaceTypeMismatchException;
use OCA\BudgetCheck\Service\AccessControlService;
use OCA\BudgetCheck\Service\HouseholdYearlyExportService;
use OCA\BudgetCheck\Service\RateLimitService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\IRequest;

class ExportController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private AccessControlService $access,
		private HouseholdYearlyExportService $exportService,
		private RateLimitService $rateLimit,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function householdYearly(): DataDownloadResponse|DataDisplayResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$workspaceId = (int)$this->request->getParam('workspaceId', 0);
			$year = (int)$this->request->getParam('year', (int)date('Y'));
			if ($workspaceId < 1) {
				return $this->error('workspaceId is required.', Http::STATUS_BAD_REQUEST);
			}
			$this->rateLimit->assertAllowed($userId, 'household_yearly_export', 20, 300);
			$file = $this->exportService->buildXlsx($workspaceId, $userId, $year);
			$response = new DataDownloadResponse($file['content'], $file['filename'], $file['mimeType']);
			$response->addHeader('X-Content-Type-Options', 'nosniff');
			return $response;
		} catch (\InvalidArgumentException $e) {
			return $this->error($e->getMessage(), Http::STATUS_BAD_REQUEST);
		} catch (WorkspaceTypeMismatchException $e) {
			return $this->error('This operation does not apply to this workspace type.', 422);
		} catch (RateLimitExceededException) {
			return $this->error('Too many export requests. Please wait a moment and try again.', 429);
		} catch (NotAuthenticatedException) {
			return $this->error('Authentication required.', Http::STATUS_UNAUTHORIZED);
		} catch (AccessDeniedException) {
			return $this->error('Access denied.', Http::STATUS_FORBIDDEN);
		} catch (\Throwable) {
			return $this->error('Request could not be completed.', Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function projectPeriod(): DataDownloadResponse|DataDisplayResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$workspaceId = (int)$this->request->getParam('workspaceId', 0);
			if ($workspaceId < 1) {
				return $this->error('workspaceId is required.', Http::STATUS_BAD_REQUEST);
			}
			$this->rateLimit->assertAllowed($userId, 'project_period_export', 20, 300);
			$file = $this->exportService->buildProjectPeriodXlsx($workspaceId, $userId);
			$response = new DataDownloadResponse($file['content'], $file['filename'], $file['mimeType']);
			$response->addHeader('X-Content-Type-Options', 'nosniff');
			return $response;
		} catch (\InvalidArgumentException $e) {
			return $this->error($e->getMessage(), Http::STATUS_BAD_REQUEST);
		} catch (WorkspaceTypeMismatchException $e) {
			return $this->error('This operation does not apply to this workspace type.', 422);
		} catch (RateLimitExceededException) {
			return $this->error('Too many export requests. Please wait a moment and try again.', 429);
		} catch (NotAuthenticatedException) {
			return $this->error('Authentication required.', Http::STATUS_UNAUTHORIZED);
		} catch (AccessDeniedException) {
			return $this->error('Access denied.', Http::STATUS_FORBIDDEN);
		} catch (\Throwable) {
			return $this->error('Request could not be completed.', Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	private function error(string $message, int $status): DataDisplayResponse
	{
		return new DataDisplayResponse($message, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
	}
}
