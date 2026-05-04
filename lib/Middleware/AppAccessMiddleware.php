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
 * Refuses to enter any BudgetCheck controller for users that have no membership
 * in any workspace and are not app admins. Per-route role checks (manager,
 * contributor, viewer) and per-workspace membership are performed inside the
 * services, after this middleware has confirmed app-level access.
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
		if ($this->accessControl->canUseApp($user->getUID())) {
			return;
		}

		$this->logger->warning('budgetcheck app access denied', [
			'userId' => $user->getUID(),
			'path' => $this->request->getPathInfo(),
		]);
		throw new AppAccessDeniedException('app_access_denied');
	}

	public function afterException($controller, $methodName, \Exception $exception)
	{
		if (!$exception instanceof AppAccessDeniedException) {
			throw $exception;
		}

		$path = (string) $this->request->getPathInfo();
		$isApi = str_contains($path, '/api/') || $this->request->getMethod() !== 'GET';
		$wantsJson = str_contains((string) $this->request->getHeader('Accept'), 'application/json')
			|| str_starts_with((string) $this->request->getHeader('Content-Type'), 'application/json');

		if ($isApi || $wantsJson) {
			return new JSONResponse([
				'ok' => false,
				'error' => ['code' => 'access_denied'],
				'message' => 'access_denied',
			], Http::STATUS_FORBIDDEN);
		}

		$l = $this->l10nFactory->get(Application::APP_ID);
		$response = new TemplateResponse(Application::APP_ID, 'access-denied', [
			'message' => $l->t('You do not have access to BudgetCheck. Ask an app administrator to add you to a workspace.'),
			'homeUrl' => $this->urlGenerator->linkToDefaultPageUrl(),
		]);
		$response->setStatus(Http::STATUS_FORBIDDEN);
		$response->renderAs(TemplateResponse::RENDER_AS_USER);
		return $response;
	}
}
