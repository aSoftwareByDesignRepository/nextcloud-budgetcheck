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
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * Refuses to enter any BudgetCheck controller when {@see AccessControlService::canUseApp}
 * is false (directory restriction, missing workspace membership, or both).
 * Nextcloud and delegated app administrators pass without a workspace so they
 * can manage policy. Per-route role checks run inside services after this gate.
 */
class AppAccessMiddleware extends Middleware
{
	public function __construct(
		private IUserSession $userSession,
		private AccessControlService $accessControl,
		private IRequest $request,
		private IURLGenerator $urlGenerator,
		private IFactory $l10nFactory,
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

		if ($isApi || $wantsJson) {
			return new JSONResponse([
				'ok' => false,
				'error' => ['code' => 'access_denied'],
				'message' => 'access_denied',
			], Http::STATUS_FORBIDDEN);
		}

		$l = $this->l10nFactory->get(Application::APP_ID);
		$reason = $exception->getDenialReason();
		$message = $reason === AccessControlService::DENIAL_RESTRICTION
			? $l->t('You do not have access to BudgetCheck. Your account is not among the users or groups allowed to use this app. Ask a server or app administrator if you need access.')
			: $l->t('You do not have access to BudgetCheck. Ask an app administrator to add you to a workspace.');
		$response = new TemplateResponse(Application::APP_ID, 'access-denied', [
			'message' => $message,
			'homeUrl' => $this->urlGenerator->linkToDefaultPageUrl(),
		]);
		$response->setStatus(Http::STATUS_FORBIDDEN);
		$response->renderAs(TemplateResponse::RENDER_AS_USER);
		return $response;
	}
}
