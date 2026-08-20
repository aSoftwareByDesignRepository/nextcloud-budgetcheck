<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\AppInfo\Application;
use OCA\BudgetCheck\Exception\AccessDeniedException;
use OCA\BudgetCheck\Exception\RateLimitExceededException;
use OCA\BudgetCheck\Service\AuditLogService;
use OCA\BudgetCheck\Service\RateLimitService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Rate-limit counters must survive LDAP/AD UUID-style userIds (#17): preferences
 * store the UID in a dedicated column; the configkey is only rate_limit:{action}.
 */
final class RateLimitServiceTest extends TestCase
{
	/** @var IConfig&MockObject */
	private IConfig $config;
	/** @var ITimeFactory&MockObject */
	private ITimeFactory $time;
	/** @var ILockingProvider&MockObject */
	private ILockingProvider $locking;
	/** @var array<string, string> */
	private array $prefs = [];
	/** @var list<string> */
	private array $deletedAppKeys = [];

	protected function setUp(): void
	{
		parent::setUp();
		$this->prefs = [];
		$this->deletedAppKeys = [];
		$this->config = $this->createMock(IConfig::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->locking = $this->createMock(ILockingProvider::class);

		$this->config->method('getUserValue')->willReturnCallback(
			function (string $userId, string $app, string $key, $default = '') {
				self::assertSame(Application::APP_ID, $app);
				self::assertLessThanOrEqual(RateLimitService::CONFIG_KEY_MAX_LENGTH, strlen($key));
				$storeKey = $userId . "\0" . $key;
				return $this->prefs[$storeKey] ?? (string)$default;
			}
		);
		$this->config->method('setUserValue')->willReturnCallback(
			function (string $userId, string $app, string $key, $value) {
				self::assertSame(Application::APP_ID, $app);
				self::assertLessThanOrEqual(RateLimitService::CONFIG_KEY_MAX_LENGTH, strlen($key));
				self::assertLessThanOrEqual(64, strlen($userId));
				$this->prefs[$userId . "\0" . $key] = (string)$value;
			}
		);
		$this->config->method('deleteAppValue')->willReturnCallback(
			function (string $app, string $key): void {
				self::assertSame(Application::APP_ID, $app);
				$this->deletedAppKeys[] = $key;
			}
		);
		$this->locking->method('acquireLock');
		$this->locking->method('releaseLock');
	}

	private function service(?AuditLogService $audit = null): RateLimitService
	{
		return new RateLimitService($this->config, $this->time, $this->locking, $audit);
	}

	public function testUuidStyleUserIdFavoritesKeyFitsUnder64(): void
	{
		$uuid = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
		self::assertSame(36, strlen($uuid));

		$svc = $this->service();
		$key = $svc->preferenceKey('workspace_favorites_write');
		self::assertSame('rate_limit:workspace_favorites_write', $key);
		self::assertLessThanOrEqual(64, strlen($key));
		// The broken pre-#17 key would be 74 chars:
		$legacy = 'rate_limit:workspace_favorites_write:' . $uuid;
		self::assertGreaterThan(64, strlen($legacy));

		$this->time->method('getTime')->willReturn(1_700_000_000);
		$svc->assertAllowed($uuid, 'workspace_favorites_write', 90, 300);

		$storeKey = $uuid . "\0" . $key;
		self::assertArrayHasKey($storeKey, $this->prefs);
		$entries = json_decode($this->prefs[$storeKey], true, 512, JSON_THROW_ON_ERROR);
		self::assertSame([1_700_000_000], $entries);
		// UUID legacy key never fitted in appconfig — cleanup is a no-op.
		self::assertSame([], $this->deletedAppKeys);
	}

	public function testShortUserIdCleansLegacyAppConfigKey(): void
	{
		$this->time->method('getTime')->willReturn(1_700_000_100);
		$this->service()->assertAllowed('admin', 'category_write', 60, 300);
		self::assertSame(['rate_limit:category_write:admin'], $this->deletedAppKeys);
	}

	public function testAllowsUnderMaxAndRecordsHit(): void
	{
		$now = 1_700_000_200;
		$this->time->method('getTime')->willReturn($now);
		$this->prefs['alice' . "\0" . 'rate_limit:transaction_write'] = json_encode([$now - 10], JSON_THROW_ON_ERROR);

		$this->locking->expects(self::once())
			->method('acquireLock')
			->with(self::callback(static function (string $key): bool {
				return str_starts_with($key, 'bc-rl-')
					&& strlen($key) <= 64
					&& strlen($key) === strlen('bc-rl-') + 32;
			}), ILockingProvider::LOCK_EXCLUSIVE);
		$this->locking->expects(self::once())
			->method('releaseLock')
			->with(self::callback(static function (string $key): bool {
				return str_starts_with($key, 'bc-rl-') && strlen($key) <= 64;
			}), ILockingProvider::LOCK_EXCLUSIVE);

		$this->service()->assertAllowed('alice', 'transaction_write', 5, 60);

		$entries = json_decode(
			$this->prefs['alice' . "\0" . 'rate_limit:transaction_write'],
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		self::assertCount(2, $entries);
		self::assertSame($now, end($entries));
	}

	public function testThrowsWhenWindowFull(): void
	{
		$now = 1_700_000_300;
		$this->time->method('getTime')->willReturn($now);
		$this->prefs['bob' . "\0" . 'rate_limit:member_write'] = json_encode(
			[$now, $now - 1, $now - 2],
			JSON_THROW_ON_ERROR
		);

		$this->expectException(RateLimitExceededException::class);
		$this->service()->assertAllowed('bob', 'member_write', 3, 60);
	}

	public function testDoesNotWriteWhenLimited(): void
	{
		$now = 1_700_000_400;
		$this->time->method('getTime')->willReturn($now);
		$key = 'carol' . "\0" . 'rate_limit:workspace_create';
		$this->prefs[$key] = json_encode([$now, $now - 1], JSON_THROW_ON_ERROR);
		$before = $this->prefs[$key];

		try {
			$this->service()->assertAllowed('carol', 'workspace_create', 2, 600);
			self::fail('expected RateLimitExceededException');
		} catch (RateLimitExceededException) {
			self::assertSame($before, $this->prefs[$key]);
		}
	}

	public function testPrunesStaleEntriesOutsideWindow(): void
	{
		$now = 1_700_000_500;
		$this->time->method('getTime')->willReturn($now);
		$this->prefs['dave' . "\0" . 'rate_limit:budget_write'] = json_encode(
			[$now - 1000, $now - 10],
			JSON_THROW_ON_ERROR
		);

		$this->service()->assertAllowed('dave', 'budget_write', 5, 60);

		$entries = json_decode(
			$this->prefs['dave' . "\0" . 'rate_limit:budget_write'],
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		self::assertSame([$now - 10, $now], $entries);
	}

	public function testCorruptJsonStartsFreshWindow(): void
	{
		$now = 1_700_000_600;
		$this->time->method('getTime')->willReturn($now);
		$this->prefs['erin' . "\0" . 'rate_limit:user_search'] = 'not-json{';

		$this->service()->assertAllowed('erin', 'user_search', 60, 60);
		$entries = json_decode(
			$this->prefs['erin' . "\0" . 'rate_limit:user_search'],
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		self::assertSame([$now], $entries);
	}

	public function testNonArrayJsonStartsFreshWindow(): void
	{
		$now = 1_700_000_700;
		$this->time->method('getTime')->willReturn($now);
		$this->prefs['frank' . "\0" . 'rate_limit:group_search'] = '"oops"';

		$this->service()->assertAllowed('frank', 'group_search', 60, 60);
		self::assertSame(
			[$now],
			json_decode($this->prefs['frank' . "\0" . 'rate_limit:group_search'], true, 512, JSON_THROW_ON_ERROR)
		);
	}

	public function testEmptyUserIdDenied(): void
	{
		$this->expectException(AccessDeniedException::class);
		$this->service()->assertAllowed('', 'workspace_favorites_write', 90, 300);
	}

	public function testOversizedUserIdDenied(): void
	{
		$this->expectException(AccessDeniedException::class);
		$this->service()->assertAllowed(str_repeat('x', 65), 'workspace_favorites_write', 90, 300);
	}

	public function testInvalidActionDenied(): void
	{
		$this->expectException(AccessDeniedException::class);
		$this->service()->assertAllowed('admin', 'evil:action/../x', 10, 60);
	}

	public function testEmptyActionDenied(): void
	{
		$this->expectException(AccessDeniedException::class);
		$this->service()->assertAllowed('admin', '   ', 10, 60);
	}

	public function testClampsMaxAndWindowToAtLeastOne(): void
	{
		$now = 1_700_000_800;
		$this->time->method('getTime')->willReturn($now);
		// max=0 and window=0 must clamp to 1 — first hit allowed, second blocked.
		$this->service()->assertAllowed('gina', 'monthly_close', 0, 0);
		$this->expectException(RateLimitExceededException::class);
		$this->service()->assertAllowed('gina', 'monthly_close', 0, 0);
	}

	public function testContestedLockFailsClosedAsRateLimited(): void
	{
		$this->locking = $this->createMock(ILockingProvider::class);
		$this->locking->expects(self::exactly(2))
			->method('acquireLock')
			->willThrowException(new LockedException('busy'));
		$this->locking->expects(self::never())->method('releaseLock');

		$audit = $this->createMock(AuditLogService::class);
		$audit->expects(self::once())->method('record')->with(
			'admin',
			'rate_limited',
			'api',
			'workspace_favorites_write',
			self::callback(static fn (array $ctx): bool => ($ctx['reason'] ?? '') === 'lock_contested')
		);

		$this->expectException(RateLimitExceededException::class);
		$this->service($audit)->assertAllowed('admin', 'workspace_favorites_write', 90, 300);
	}

	public function testReleasesLockEvenWhenLimited(): void
	{
		$now = 1_700_000_900;
		$this->time->method('getTime')->willReturn($now);
		$this->prefs['hank' . "\0" . 'rate_limit:savings_write'] = json_encode([$now, $now], JSON_THROW_ON_ERROR);

		$this->locking->expects(self::once())->method('acquireLock');
		$this->locking->expects(self::once())->method('releaseLock');

		try {
			$this->service()->assertAllowed('hank', 'savings_write', 2, 300);
			self::fail('expected RateLimitExceededException');
		} catch (RateLimitExceededException) {
			// expected
		}
	}

	public function testAuditsWhenWindowFull(): void
	{
		$now = 1_700_001_000;
		$this->time->method('getTime')->willReturn($now);
		$this->prefs['ivy' . "\0" . 'rate_limit:app_policy_save'] = json_encode([$now], JSON_THROW_ON_ERROR);

		$audit = $this->createMock(AuditLogService::class);
		$audit->expects(self::once())->method('record')->with(
			'ivy',
			'rate_limited',
			'api',
			'app_policy_save',
			self::callback(static function (array $ctx): bool {
				return ($ctx['max'] ?? null) === 1
					&& ($ctx['window'] ?? null) === 300
					&& ($ctx['attempts'] ?? null) === 1;
			})
		);

		$this->expectException(RateLimitExceededException::class);
		$this->service($audit)->assertAllowed('ivy', 'app_policy_save', 1, 300);
	}

	public function testLongestKnownActionKeysStayUnderLimit(): void
	{
		$svc = $this->service();
		$actions = [
			'workspace_favorites_write',
			'mobile_transaction_attachment_write',
			'mobile_transaction_attachment_read',
			'transaction_attachment_write',
			'household_yearly_export',
		];
		foreach ($actions as $action) {
			$key = $svc->preferenceKey($action);
			self::assertLessThanOrEqual(
				RateLimitService::CONFIG_KEY_MAX_LENGTH,
				strlen($key),
				$action . ' preference key too long: ' . $key
			);
			self::assertStringStartsWith('rate_limit:', $key);
		}
	}

	public function testLockPathFitsFileLocksKeyColumn(): void
	{
		$uuid = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
		$lockKey = 'bc-rl-' . md5($uuid . "\0" . 'workspace_favorites_write');
		self::assertSame(38, strlen($lockKey));
		self::assertLessThanOrEqual(64, strlen($lockKey));
		// Pre-fix sha256 form would overflow oc_file_locks.key:
		$broken = 'bc-rl-' . hash('sha256', $uuid . "\0" . 'workspace_favorites_write');
		self::assertGreaterThan(64, strlen($broken));
	}

	public function testUsersAreIsolatedInPreferenceStore(): void
	{
		$now = 1_700_001_100;
		$this->time->method('getTime')->willReturn($now);
		$uuidA = '11111111-2222-3333-4444-555555555555';
		$uuidB = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

		$this->service()->assertAllowed($uuidA, 'workspace_favorites_write', 1, 300);
		// Same action, different user — still allowed (own empty bucket).
		$this->service()->assertAllowed($uuidB, 'workspace_favorites_write', 1, 300);

		self::assertArrayHasKey($uuidA . "\0rate_limit:workspace_favorites_write", $this->prefs);
		self::assertArrayHasKey($uuidB . "\0rate_limit:workspace_favorites_write", $this->prefs);
	}

	public function testFiltersNonIntegerTimestamps(): void
	{
		$now = 1_700_001_200;
		$this->time->method('getTime')->willReturn($now);
		$this->prefs['jill' . "\0" . 'rate_limit:recurring_write'] = json_encode(
			['nope', 12.5, null, $now - 5],
			JSON_THROW_ON_ERROR
		);

		$this->service()->assertAllowed('jill', 'recurring_write', 5, 60);
		self::assertSame(
			[$now - 5, $now],
			json_decode($this->prefs['jill' . "\0" . 'rate_limit:recurring_write'], true, 512, JSON_THROW_ON_ERROR)
		);
	}

	public function testRetryAcquireSucceedsAfterFirstLockFailure(): void
	{
		$now = 1_700_001_300;
		$this->time->method('getTime')->willReturn($now);

		$this->locking = $this->createMock(ILockingProvider::class);
		$this->locking->expects(self::exactly(2))
			->method('acquireLock')
			->willReturnOnConsecutiveCalls(
				self::throwException(new LockedException('busy')),
				null
			);
		$this->locking->expects(self::once())->method('releaseLock');

		$this->service()->assertAllowed('kate', 'import_preferences_write', 60, 300);
		self::assertArrayHasKey('kate' . "\0rate_limit:import_preferences_write", $this->prefs);
	}
}
