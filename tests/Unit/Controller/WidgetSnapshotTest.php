<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Controller;

use OCA\BudgetCheck\Controller\MobileApiController;
use OCA\BudgetCheck\Exception\NotAuthenticatedException;
use OCA\BudgetCheck\Exception\NotFoundException;
use OCA\BudgetCheck\Service\AccessControlService;
use OCA\BudgetCheck\Service\AnalyticsService;
use OCA\BudgetCheck\Service\BookingStatusService;
use OCA\BudgetCheck\Service\CategoryService;
use OCA\BudgetCheck\Service\MobileIdempotencyService;
use OCA\BudgetCheck\Service\PayeeService;
use OCA\BudgetCheck\Service\RateLimitService;
use OCA\BudgetCheck\Service\RecurringRuleService;
use OCA\BudgetCheck\Service\SummaryService;
use OCA\BudgetCheck\Service\TransactionAttachmentService;
use OCA\BudgetCheck\Service\TransactionExportService;
use OCA\BudgetCheck\Service\TransactionService;
use OCA\BudgetCheck\Service\WorkspaceDeletionService;
use OCA\BudgetCheck\Service\WorkspaceService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Authentication\Token\IProvider;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Widget snapshot endpoint (SPEC NH-P2): single cached document per sync.
 *
 * Legs: cache-hit short-circuits the home assembly, miss wraps {status,
 * serverNow, redactionLocked} at 60 s TTL, home errors pass through
 * uncached at their real status, unauthenticated maps to the 401 envelope.
 */
final class WidgetSnapshotTest extends TestCase
{
	/** @var IRequest&MockObject */
	private IRequest $request;
	/** @var AccessControlService&MockObject */
	private AccessControlService $access;
	/** @var WorkspaceService&MockObject */
	private WorkspaceService $workspaces;
	/** @var SummaryService&MockObject */
	private SummaryService $summaries;
	/** @var ICacheFactory&MockObject */
	private ICacheFactory $cacheFactory;
	/** @var IAppConfig&MockObject */
	private IAppConfig $appConfig;
	/** @var ICache&MockObject */
	private ICache $cache;

	private MobileApiController $controller;
	private bool $redactionLocked = false;

	protected function setUp(): void
	{
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->access = $this->createMock(AccessControlService::class);
		$this->workspaces = $this->createMock(WorkspaceService::class);
		$this->summaries = $this->createMock(SummaryService::class);
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->cache = $this->createMock(ICache::class);
		$this->cacheFactory->method('createDistributed')
			->with('budgetcheck_wsnap')
			->willReturn($this->cache);
		$this->appConfig->method('getValueBool')
			->willReturnCallback(fn (): bool => $this->redactionLocked);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$this->controller = new MobileApiController(
			$this->request,
			$this->createMock(IUserSession::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IProvider::class),
			$this->access,
			$this->workspaces,
			$this->createMock(WorkspaceDeletionService::class),
			$this->createMock(CategoryService::class),
			$this->createMock(TransactionService::class),
			$this->createMock(BookingStatusService::class),
			$this->summaries,
			$this->createMock(RecurringRuleService::class),
			$this->createMock(MobileIdempotencyService::class),
			$this->createMock(RateLimitService::class),
			$this->createMock(TransactionAttachmentService::class),
			$this->createMock(TransactionExportService::class),
			$l10n,
			$this->createMock(LoggerInterface::class),
			$this->createMock(PayeeService::class),
			$this->createMock(AnalyticsService::class),
			$this->cacheFactory,
			$this->appConfig,
		);
	}

	private function stubHouseholdHome(): void
	{
		$this->access->method('currentUserId')->willReturn('alice');
		$this->access->method('rememberLastUsedWorkspace');
		$this->workspaces->method('getForUser')->willReturn([
			'id' => 1,
			'name' => 'Home',
			'type' => 'household',
			'role' => 'manager',
			'currencyCode' => 'EUR',
			'currencyDecimals' => 2,
			'timezone' => 'UTC',
			'taxModeEnabled' => false,
			'activeCalendarYearMonth' => '2026-07',
		]);
		$this->summaries->method('household')->willReturn([
			'yearMonth' => '2026-07',
			'isClosed' => false,
			'totals' => [
				'income' => ['minor' => 10000],
				'expense' => ['minor' => 4000],
				'netResult' => ['minor' => 6000],
				'availableAfterSavings' => ['minor' => 5000],
			],
			'budget' => ['byCategory' => []],
			'warnings' => [],
		]);
	}

	public function testCacheHitSkipsHomeAssembly(): void
	{
		$this->access->method('currentUserId')->willReturn('alice');
		$cached = [
			'ok' => true,
			'status' => ['scope' => ['workspaceId' => 1]],
			'serverNow' => '2026-11-08T08:00:00+00:00',
		];
		$this->cache->method('get')->with('alice:1:home')->willReturn($cached);
		$this->workspaces->expects(self::never())->method('getForUser');

		$response = $this->controller->widgetSnapshot(1);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($cached, $response->getData());
	}

	public function testMissBuildsWrapsAndCaches(): void
	{
		$this->stubHouseholdHome();
		$this->cache->method('get')->with('alice:1:home')->willReturn(null);
		$this->cache->expects(self::once())
			->method('set')
			->with(
				'alice:1:home',
				self::callback(static fn ($payload): bool => is_array($payload)
					&& ($payload['ok'] ?? false) === true
					&& isset($payload['status'], $payload['serverNow'])
					&& ($payload['status']['ok'] ?? false) === true
					&& ($payload['status']['redactionLocked'] ?? null) === false),
				60
			);

		$response = $this->controller->widgetSnapshot(1);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertTrue($data['ok']);
		self::assertSame(1, $data['status']['scope']['workspaceId']);
		self::assertFalse($data['status']['redactionLocked']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $data['serverNow']);
	}

	public function testRedactionLockPropagatesIntoStatus(): void
	{
		$this->redactionLocked = true;
		$this->stubHouseholdHome();
		$this->cache->method('get')->willReturn(null);

		$response = $this->controller->widgetSnapshot(1);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertTrue($response->getData()['status']['redactionLocked']);
	}

	public function testHomeErrorPassesThroughUncached(): void
	{
		$this->access->method('currentUserId')->willReturn('alice');
		$this->cache->method('get')->willReturn(null);
		$this->workspaces->method('getForUser')
			->willThrowException(new NotFoundException('workspace'));
		$this->cache->expects(self::never())->method('set');

		$response = $this->controller->widgetSnapshot(999);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['ok']);
		self::assertSame('NOT_FOUND', $data['error']['code']);
	}

	public function testUnauthenticatedMapsTo401Envelope(): void
	{
		$this->access->method('currentUserId')
			->willThrowException(new NotAuthenticatedException());
		$this->cache->expects(self::never())->method('get');

		$response = $this->controller->widgetSnapshot(1);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['ok']);
		self::assertSame('not_authenticated', $data['error']['code']);
	}
}
