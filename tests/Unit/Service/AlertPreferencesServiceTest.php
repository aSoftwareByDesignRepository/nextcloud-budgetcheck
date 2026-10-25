<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\AppInfo\Application;
use OCA\BudgetCheck\Service\AlertPreferencesService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * Per-user alert prefs (spec: alert-notifications §9): master switch gates
 * every type, per-type toggles gate individually, threshold clamps 50–100,
 * quiet hours wrap midnight.
 */
final class AlertPreferencesServiceTest extends TestCase
{
	/** @var array<string,string> */
	private array $store = [];
	private function prefs(): AlertPreferencesService
	{
		$this->store = [];
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			fn (string $uid, string $app, string $key, string $default = '') =>
				$this->store["$uid|$key"] ?? $default
		);
		$config->method('setUserValue')->willReturnCallback(
			function (string $uid, string $app, string $key, string $value): void {
				$this->store["$uid|$key"] = $value;
			}
		);
		return new AlertPreferencesService(
			$config,
			$this->createMock(\OCA\BudgetCheck\Service\AccessControlService::class),
		);
	}

	public function testDefaultsAreAllOn(): void
	{
		$p = $this->prefs();
		self::assertTrue($p->alertsEnabled('u1'));
		foreach (AlertPreferencesService::TYPES as $t) {
			self::assertTrue($p->typeEnabled('u1', $t), "$t should default on");
		}
		self::assertSame(80, $p->thresholdPercent('u1'));
		self::assertFalse($p->emailEnabled('u1'));
		self::assertTrue($p->dashboardWidgetEnabled('u1'));
	}

	public function testMasterSwitchSuppressesEveryType(): void
	{
		$p = $this->prefs();
		$p->save(7, 'u1', ['alertsEnabled' => false]);
		foreach (AlertPreferencesService::TYPES as $t) {
			self::assertFalse($p->canSend('u1', $t), "master off must block $t");
		}
	}

	public function testPerTypeToggleIsIndependent(): void
	{
		$p = $this->prefs();
		$p->save(7, 'u1', ['typeDigest' => false]);
		self::assertFalse($p->canSend('u1', AlertPreferencesService::TYPE_DIGEST));
		self::assertTrue($p->canSend('u1', AlertPreferencesService::TYPE_BUDGET));
	}

	public function testThresholdPercentClampsAndRejectsOutOfRangeOnSave(): void
	{
		$p = $this->prefs();
		$this->expectException(\InvalidArgumentException::class);
		$p->save(7, 'u1', ['thresholdPercent' => 20]);
	}

	public function testThresholdBoundaryValuesAccepted(): void
	{
		$p = $this->prefs();
		$p->save(7, 'u1', ['thresholdPercent' => 50]);
		self::assertSame(50, $p->thresholdPercent('u1'));
		$p->save(7, 'u1', ['thresholdPercent' => 100]);
		self::assertSame(100, $p->thresholdPercent('u1'));
	}

	public function testQuietHoursWrapMidnight(): void
	{
		$p = $this->prefs();
		$p->save(7, 'u1', ['quietStart' => '22:00', 'quietEnd' => '06:00']);
		self::assertTrue($p->inQuietHours('u1', new \DateTimeImmutable('2026-01-10 23:30')));
		self::assertTrue($p->inQuietHours('u1', new \DateTimeImmutable('2026-01-10 05:59')));
		self::assertFalse($p->inQuietHours('u1', new \DateTimeImmutable('2026-01-10 12:00')));
	}

	public function testQuietHoursBlockCanSendButNotIdentity(): void
	{
		$p = $this->prefs();
		$p->save(7, 'u1', ['quietStart' => '22:00', 'quietEnd' => '06:00']);
		self::assertFalse($p->canSend('u1', AlertPreferencesService::TYPE_BUDGET, new \DateTimeImmutable('2026-01-10 23:00')));
		self::assertTrue($p->canSend('u1', AlertPreferencesService::TYPE_BUDGET, new \DateTimeImmutable('2026-01-10 09:00')));
	}

	public function testQuietHoursDisabledWhenUnset(): void
	{
		$p = $this->prefs();
		self::assertFalse($p->inQuietHours('u1', new \DateTimeImmutable('2026-01-10 03:00')));
	}

	public function testInvalidQuietHourRejected(): void
	{
		$p = $this->prefs();
		$this->expectException(\InvalidArgumentException::class);
		$p->save(7, 'u1', ['quietStart' => '25:99']);
	}

	public function testCategoryThresholdOverrideFallsBackToGlobal(): void
	{
		$p = $this->prefs();
		$p->save(7, 'u1', ['categoryThresholds' => ['11' => 90, 'bad' => 200]]);
		self::assertSame(90, $p->thresholdForCategory('u1', 7, 11));
		self::assertSame(80, $p->thresholdForCategory('u1', 7, 22));
		self::assertSame(80, $p->thresholdForCategory('u1', 8, 11), 'overrides are per-workspace');
	}
}
