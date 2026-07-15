<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Controller;

use OCA\BudgetCheck\Exception\AccessDeniedException;
use OCA\BudgetCheck\Exception\NotAuthenticatedException;
use OCA\BudgetCheck\Exception\RateLimitExceededException;
use OCA\BudgetCheck\Service\AccessControlService;
use OCA\BudgetCheck\Service\RateLimitService;
use OCA\BudgetCheck\Service\TransactionAttachmentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IRequest;

/**
 * Streams transaction attachment bytes with workspace access checks.
 */
class AttachmentController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private AccessControlService $access,
		private TransactionAttachmentService $attachments,
		private RateLimitService $rateLimit,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function download(int $id): StreamResponse|DataDisplayResponse
	{
		try {
			$userId = $this->access->currentUserId();
			$this->rateLimit->assertAllowed($userId, 'transaction_attachment_read', 600, 300);
			$requestInline = $this->request->getParam('inline') === '1'
				|| $this->request->getParam('inline') === 'true';
			$resolved = $this->attachments->resolveForDelivery($id, $userId, $requestInline);
			$row = $resolved['row'];
			$safeName = $this->attachments->sanitizeContentDispositionFilename((string)$row['original_name']);

			$response = new StreamResponse($resolved['filePath']);
			$response->addHeader('Content-Type', (string)$row['mime_type']);
			$response->addHeader('Content-Disposition', $resolved['disposition'] . '; filename="' . $safeName . '"');
			$response->addHeader('Content-Length', (string)((int)$row['file_size']));
			$response->addHeader('X-Content-Type-Options', 'nosniff');
			$response->addHeader('Cache-Control', 'private, no-store, must-revalidate');
			$response->addHeader('Pragma', 'no-cache');
			if ($resolved['disposition'] === 'inline') {
				// StreamResponse defaults to frame-ancestors 'none', which blocks gallery iframes.
				$response->setContentSecurityPolicy(new ContentSecurityPolicy());
			}
			return $response;
		} catch (\InvalidArgumentException $e) {
			return $this->error($e->getMessage(), Http::STATUS_BAD_REQUEST);
		} catch (RateLimitExceededException) {
			return $this->error('Too many requests. Please wait a moment and try again.', Http::STATUS_TOO_MANY_REQUESTS);
		} catch (NotAuthenticatedException) {
			return $this->error('Authentication required.', Http::STATUS_UNAUTHORIZED);
		} catch (AccessDeniedException) {
			return $this->error('Access denied.', Http::STATUS_FORBIDDEN);
		} catch (\Throwable) {
			return $this->error('Attachment could not be loaded.', Http::STATUS_NOT_FOUND);
		}
	}

	private function error(string $message, int $status): DataDisplayResponse
	{
		$response = new DataDisplayResponse($message, $status);
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		return $response;
	}
}
