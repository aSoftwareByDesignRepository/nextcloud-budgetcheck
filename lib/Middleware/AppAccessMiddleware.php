<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Middleware;

use OCA\BudgetCheck\AppInfo\Application;
use OCA\BudgetCheck\Exception\AppAccessDeniedException;
use OCA\BudgetCheck\Service\AccessControlService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Refuses to enter any BudgetCheck controller when {@see AccessControlService::canUseApp}
 * is false (directory restriction gate).
 * Workspace membership checks run in service-layer role gates for workspace-scoped routes.
 */
class AppAccessMiddleware extends Middleware
{
	public function __construct(
		private IUserSession $userSession,
		private AccessControlService $accessControl,
		private IRequest $request,
		private LoggerInterface $logger,
	) {
	}

	public function beforeController($controller, $methodName): void
	{
		$class = is_object($controller) ? get_class($controller) : '';
		if (!str_starts_with($class, 'OCA\\BudgetCheck\\Controller\\')) {
			return;
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			// Nextcloud's own session middleware already redirects anonymous
			// users to login; we should not over-step it here.
			return;
		}
		$uid = $user->getUID();
		if ($this->accessControl->canUseApp($uid)) {
			return;
		}

		$reason = $this->accessControl->denialReasonWhenCannotUseApp($uid);
		$this->logger->warning('budgetcheck app access denied', [
			'userId' => $uid,
			'path' => $this->request->getPathInfo(),
			'reason' => $reason,
		]);
		throw new AppAccessDeniedException('app_access_denied', 0, null, $reason);
	}

	public function afterException($controller, $methodName, \Exception $exception)
	{
		if (!$exception instanceof AppAccessDeniedException) {
			throw $exception;
		}

		$path = (string) ($this->request->getPathInfo() ?? '');
		$isApi = str_contains($path, '/api/')
			|| str_contains($path, '/ocs/')
			|| $this->request->getMethod() !== 'GET';
		$accept = strtolower((string) $this->request->getHeader('Accept'));
		$contentType = strtolower((string) $this->request->getHeader('Content-Type'));
		$xRequestedWith = strtolower((string) $this->request->getHeader('X-Requested-With'));
		$wantsJson = str_contains($accept, 'application/json')
			|| str_contains($contentType, 'application/json')
			|| $xRequestedWith === 'xmlhttprequest';

		// Restricted mode is indistinguishable from absent: a missing app
		// answers 404, so any 403/'access denied' shape would leak
		// "installed but you are excluded" to unlisted users (uniform-not-found
		// rule, restricted_door_status_oracle class).
		if ($isApi || $wantsJson) {
			return new JSONResponse([
				'ok' => false,
				'message' => 'Not found.',
				'error' => ['code' => 'NOT_FOUND', 'message' => 'Not found.'],
			], Http::STATUS_NOT_FOUND);
		}

		$response = new TemplateResponse('core', '404', [], 'guest');
		$response->setStatus(Http::STATUS_NOT_FOUND);
		return $response;
	}
}
