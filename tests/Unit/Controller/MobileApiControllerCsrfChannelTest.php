<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Controller;

use OCA\BudgetCheck\Controller\MobileApiController;
use OCA\BudgetCheck\Exception\AccessDeniedException;
use OCA\BudgetCheck\Service\AccessControlService;
use OCA\BudgetCheck\Service\BookingStatusService;
use OCA\BudgetCheck\Service\CategoryService;
use OCA\BudgetCheck\Service\MobileIdempotencyService;
use OCA\BudgetCheck\Service\MobilePushService;
use OCA\BudgetCheck\Service\RateLimitService;
use OCA\BudgetCheck\Service\RecurringRuleService;
use OCA\BudgetCheck\Service\SummaryService;
use OCA\BudgetCheck\Service\TransactionAttachmentService;
use OCA\BudgetCheck\Service\TransactionService;
use OCA\BudgetCheck\Service\WorkspaceService;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Authentication\Token\IProvider;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Official companion sends Basic loginName:appPassword. A cookie session plus
 * `Authorization: Bearer garbage` must not skip CSRF.
 */
final class MobileApiControllerCsrfChannelTest extends TestCase
{
	private function invokeChannel(IRequest $request, IUserSession $session, IUserManager $users): void
	{
		$l10n = $this->createMock(IL10N::class);
		$controller = new MobileApiController(
			$request,
			$session,
			$users,
			$this->createMock(IProvider::class),
			$this->createMock(AccessControlService::class),
			$this->createMock(WorkspaceService::class),
			$this->createMock(\OCA\BudgetCheck\Service\WorkspaceDeletionService::class),
			$this->createMock(CategoryService::class),
			$this->createMock(TransactionService::class),
			$this->createMock(BookingStatusService::class),
			$this->createMock(SummaryService::class),
			$this->createMock(RecurringRuleService::class),
			$this->createMock(MobileIdempotencyService::class),
			$this->createMock(MobilePushService::class),
			$this->createMock(RateLimitService::class),
			$this->createMock(TransactionAttachmentService::class),
			$this->createMock(IAppManager::class),
			$l10n,
			$this->createMock(LoggerInterface::class),
		);
		$method = new ReflectionMethod(MobileApiController::class, 'assertSafeMutationChannel');
		$method->setAccessible(true);
		$method->invoke($controller);
	}

	public function testBearerGarbageDoesNotSkipCsrf(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('passesCSRFCheck')->willReturn(false);
		$request->method('getHeader')->willReturn('Bearer definitely-not-a-token');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));

		$this->expectException(AccessDeniedException::class);
		$this->invokeChannel($request, $session, $this->createMock(IUserManager::class));
	}

	public function testForgedBasicDoesNotSkipCsrf(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('passesCSRFCheck')->willReturn(false);
		$request->method('getHeader')->willReturn('Basic ' . base64_encode('alice:wrong-password'));
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$users = $this->createMock(IUserManager::class);
		$users->method('checkPassword')->willReturn(false);

		$this->expectException(AccessDeniedException::class);
		$this->invokeChannel($request, $session, $users);
	}

	public function testValidBasicMatchingSessionSkipsCsrf(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('passesCSRFCheck')->willReturn(false);
		$request->method('getHeader')->willReturn('Basic ' . base64_encode('alice:app-password'));
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$authed = $this->createMock(IUser::class);
		$authed->method('getUID')->willReturn('alice');
		$users = $this->createMock(IUserManager::class);
		$users->expects($this->once())->method('checkPassword')->with('alice', 'app-password')->willReturn($authed);

		$this->invokeChannel($request, $session, $users);
		$this->addToAssertionCount(1);
	}

	public function testValidCsrfPassesWithoutAuthorization(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('passesCSRFCheck')->willReturn(true);
		$request->expects($this->never())->method('getHeader');
		$session = $this->createMock(IUserSession::class);
		$users = $this->createMock(IUserManager::class);
		$users->expects($this->never())->method('checkPassword');

		$this->invokeChannel($request, $session, $users);
		$this->addToAssertionCount(1);
	}

	public function testCookieOnlyWithoutCsrfIsRejected(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('passesCSRFCheck')->willReturn(false);
		$request->method('getHeader')->willReturn('');
		$session = $this->createMock(IUserSession::class);

		$this->expectException(AccessDeniedException::class);
		$this->invokeChannel($request, $session, $this->createMock(IUserManager::class));
	}
}
