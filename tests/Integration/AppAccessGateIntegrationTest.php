<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Integration;

use OCA\BudgetCheck\AppInfo\Application;
use OCA\BudgetCheck\Controller\ApiController;
use OCA\BudgetCheck\Exception\AppAccessDeniedException;
use OCA\BudgetCheck\Middleware\AppAccessMiddleware;
use OCA\BudgetCheck\Service\AccessControlService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use Test\TestCase;

/**
 * Verifies the app-entry middleware against live app config and user sessions.
 */
final class AppAccessGateIntegrationTest extends TestCase
{
	private const ALLOWED = 'bc_gate_allowed';
	private const DENIED = 'bc_gate_denied';
	private const PASSWORD = 'bc-test-pass-9xK!';

	private ?string $prevRestriction = null;
	private ?string $prevAllowedUsers = null;
	private ?string $prevAllowedGroups = null;

	protected function setUp(): void
	{
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud is not bootstrapped (run inside Docker with NEXTCLOUD_ROOT).');
		}
		/** @var IConfig $config */
		$config = \OC::$server->get(IConfig::class);
		$this->prevRestriction = $config->getAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, '0');
		$this->prevAllowedUsers = $config->getAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS, '[]');
		$this->prevAllowedGroups = $config->getAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS, '[]');

		/** @var IUserManager $userManager */
		$userManager = \OC::$server->get(IUserManager::class);
		foreach ([self::ALLOWED, self::DENIED] as $uid) {
			if ($userManager->userExists($uid)) {
				$userManager->get($uid)?->delete();
			}
		}
	}

	protected function tearDown(): void
	{
		if (!isset(\OC::$server)) {
			return;
		}
		/** @var IConfig $config */
		$config = \OC::$server->get(IConfig::class);
		if ($this->prevRestriction !== null) {
			$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, $this->prevRestriction);
		}
		if ($this->prevAllowedUsers !== null) {
			$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS, $this->prevAllowedUsers);
		}
		if ($this->prevAllowedGroups !== null) {
			$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS, $this->prevAllowedGroups);
		}
		/** @var IUserManager $userManager */
		$userManager = \OC::$server->get(IUserManager::class);
		foreach ([self::ALLOWED, self::DENIED] as $uid) {
			try {
				if ($userManager->userExists($uid)) {
					$userManager->get($uid)?->delete();
				}
			} catch (\Throwable) {
				// Sibling app user-deleted listeners must not fail tearDown.
			}
		}
		/** @var IUserSession $session */
		$session = \OC::$server->get(IUserSession::class);
		$session->setUser(null);
	}

	public function testDeniedUserBlockedByMiddlewareAndReceivesJson403(): void
	{
		/** @var IUserManager $userManager */
		$userManager = \OC::$server->get(IUserManager::class);
		$userManager->createUser(self::ALLOWED, self::PASSWORD);
		$userManager->createUser(self::DENIED, self::PASSWORD);
		$denied = $userManager->get(self::DENIED);
		self::assertNotNull($denied);

		/** @var IConfig $config */
		$config = \OC::$server->get(IConfig::class);
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, '1');
		$config->setAppValue(
			Application::APP_ID,
			AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS,
			json_encode([self::ALLOWED], JSON_THROW_ON_ERROR),
		);
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS, '[]');

		/** @var ApiController $controller */
		$controller = \OC::$server->get(ApiController::class);
		$middleware = $this->middlewareWithUser($denied);

		try {
			$middleware->beforeController($controller, 'listWorkspaces');
			$this->fail('Expected AppAccessDeniedException for gated user');
		} catch (AppAccessDeniedException $exception) {
			$this->assertSame(AccessControlService::DENIAL_RESTRICTION, $exception->getDenialReason());
		}

		$response = $middleware->afterException(
			$controller,
			'listWorkspaces',
			new AppAccessDeniedException('app_access_denied', 0, null, AccessControlService::DENIAL_RESTRICTION),
		);
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$data = $response->getData();
		$this->assertIsArray($data);
		$this->assertSame('access_denied', $data['error']['code'] ?? null);
	}

	public function testAllowedUserWithoutWorkspacePassesMiddlewareGate(): void
	{
		/** @var IUserManager $userManager */
		$userManager = \OC::$server->get(IUserManager::class);
		$userManager->createUser(self::ALLOWED, self::PASSWORD);
		$allowed = $userManager->get(self::ALLOWED);
		self::assertNotNull($allowed);

		/** @var IConfig $config */
		$config = \OC::$server->get(IConfig::class);
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, '1');
		$config->setAppValue(
			Application::APP_ID,
			AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS,
			json_encode([self::ALLOWED], JSON_THROW_ON_ERROR),
		);

		/** @var ApiController $controller */
		$controller = \OC::$server->get(ApiController::class);
		$this->middlewareWithUser($allowed)->beforeController($controller, 'listWorkspaces');
		$this->addToAssertionCount(1);
	}

	private function middlewareWithUser(\OCP\IUser $user): AppAccessMiddleware
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn('/apps/budgetcheck/api/workspaces');
		$request->method('getMethod')->willReturn('GET');
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => match (strtolower($name)) {
				'accept' => 'application/json',
				default => '',
			},
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new AppAccessMiddleware(
			$session,
			\OC::$server->get(AccessControlService::class),
			$request,
			\OC::$server->get(\OCP\IURLGenerator::class),
			\OC::$server->get(\OCP\L10N\IFactory::class),
			\OC::$server->get(\Psr\Log\LoggerInterface::class),
		);
	}
}
