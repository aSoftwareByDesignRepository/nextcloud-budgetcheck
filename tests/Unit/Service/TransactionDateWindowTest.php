<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\TransactionService;
use OCA\BudgetCheck\Service\WorkspaceService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class TransactionDateWindowTest extends TestCase
{
	public function testHouseholdWithoutBoundsReturnsUnboundedWindow(): void
	{
		$service = $this->service();
		$method = new ReflectionMethod(TransactionService::class, 'resolveDateWindow');
		$method->setAccessible(true);

		/** @var array{0:?string,1:?string} $window */
		$window = $method->invoke($service, [
			'type' => WorkspaceService::TYPE_HOUSEHOLD,
			'timezone' => 'Europe/Copenhagen',
		], []);

		$this->assertNull($window[0]);
		$this->assertNull($window[1]);
	}

	public function testHouseholdHonoursExplicitBounds(): void
	{
		$service = $this->service();
		$method = new ReflectionMethod(TransactionService::class, 'resolveDateWindow');
		$method->setAccessible(true);

		/** @var array{0:?string,1:?string} $window */
		$window = $method->invoke($service, [
			'type' => WorkspaceService::TYPE_HOUSEHOLD,
		], [
			'from' => '2025-05-01',
			'to' => '2026-06-30',
		]);

		$this->assertSame('2025-05-01', $window[0]);
		$this->assertSame('2026-06-30', $window[1]);
	}

	public function testProjectClampsToWorkspaceWindowWhenBoundsOmitted(): void
	{
		$service = $this->service();
		$method = new ReflectionMethod(TransactionService::class, 'resolveDateWindow');
		$method->setAccessible(true);

		/** @var array{0:?string,1:?string} $window */
		$window = $method->invoke($service, [
			'type' => WorkspaceService::TYPE_PROJECT,
			'projectStartDate' => '2024-03-01',
			'projectEndDate' => '2025-12-31',
		], []);

		$this->assertSame('2024-03-01', $window[0]);
		$this->assertSame('2025-12-31', $window[1]);
	}

	public function testNoBillingBoundsKeepsProjectWindow(): void
	{
		$method = new ReflectionMethod(TransactionService::class, 'bookingDateInsideProjectWindow');
		$method->setAccessible(true);
		$ws = [
			'type' => WorkspaceService::TYPE_PROJECT,
			'projectStartDate' => '2026-01-01',
			'projectEndDate' => '2026-09-30',
			'billingStartDate' => null,
			'billingEndDate' => null,
		];

		$this->assertTrue($method->invoke($this->service(), $ws, new \DateTimeImmutable('2026-06-15')));
		$this->assertFalse($method->invoke($this->service(), $ws, new \DateTimeImmutable('2026-10-15')));
	}

	public function testMissingBillingKeysFallBackToProjectWindow(): void
	{
		// Rows hydrated by older code or test fixtures may not carry the keys at all.
		$method = new ReflectionMethod(TransactionService::class, 'bookingDateInsideProjectWindow');
		$method->setAccessible(true);
		$ws = [
			'type' => WorkspaceService::TYPE_PROJECT,
			'projectStartDate' => '2026-01-01',
			'projectEndDate' => '2026-09-30',
		];

		$this->assertFalse($method->invoke($this->service(), $ws, new \DateTimeImmutable('2026-10-15')));
	}

	public function testBillingEndRelaxesOnlyTheEndSide(): void
	{
		$method = new ReflectionMethod(TransactionService::class, 'bookingDateInsideProjectWindow');
		$method->setAccessible(true);
		$ws = [
			'type' => WorkspaceService::TYPE_PROJECT,
			'projectStartDate' => '2026-01-01',
			'projectEndDate' => '2026-09-30',
			'billingStartDate' => null,
			'billingEndDate' => '2026-10-31',
		];

		$this->assertTrue($method->invoke($this->service(), $ws, new \DateTimeImmutable('2026-10-15')));
		$this->assertFalse($method->invoke($this->service(), $ws, new \DateTimeImmutable('2026-11-01')));
		// Start side still falls back to the project bound.
		$this->assertFalse($method->invoke($this->service(), $ws, new \DateTimeImmutable('2025-12-31')));
	}

	public function testBillingStartRelaxesOnlyTheStartSide(): void
	{
		$method = new ReflectionMethod(TransactionService::class, 'bookingDateInsideProjectWindow');
		$method->setAccessible(true);
		$ws = [
			'type' => WorkspaceService::TYPE_PROJECT,
			'projectStartDate' => '2026-01-01',
			'projectEndDate' => '2026-09-30',
			'billingStartDate' => '2025-11-01',
			'billingEndDate' => null,
		];

		$this->assertTrue($method->invoke($this->service(), $ws, new \DateTimeImmutable('2025-11-15')));
		$this->assertFalse($method->invoke($this->service(), $ws, new \DateTimeImmutable('2025-10-31')));
		$this->assertFalse($method->invoke($this->service(), $ws, new \DateTimeImmutable('2026-10-01')));
	}

	public function testBillingWindowExceptionUsesBillingWording(): void
	{
		$method = new ReflectionMethod(TransactionService::class, 'projectWindowException');
		$method->setAccessible(true);

		/** @var \OCA\BudgetCheck\Exception\ValidationException $e */
		$e = $method->invoke($this->service(), [
			'billingEndDate' => '2026-10-31',
		]);
		$this->assertSame('bookingDate must lie inside the billing period.', $e->getMessage());
		$this->assertArrayHasKey('bookingDate', $e->getFields());
		$this->assertStringContainsString('billing period', $e->getFields()['bookingDate']);

		/** @var \OCA\BudgetCheck\Exception\ValidationException $legacy */
		$legacy = $method->invoke($this->service(), [
			'billingStartDate' => null,
			'billingEndDate' => null,
		]);
		$this->assertSame('bookingDate must lie inside the project date window.', $legacy->getMessage());
	}

	public function testListWindowClampsToEffectiveWindowNotJustProject(): void
	{
		$method = new ReflectionMethod(TransactionService::class, 'resolveDateWindow');
		$method->setAccessible(true);
		$ws = [
			'type' => WorkspaceService::TYPE_PROJECT,
			'projectStartDate' => '2026-01-01',
			'projectEndDate' => '2026-09-30',
			'billingStartDate' => '2025-11-01',
			'billingEndDate' => '2026-10-31',
		];

		// Default bounds must cover the billing extension, else post-project
		// bookings are invisible in the ledger.
		$window = $method->invoke($this->service(), $ws, []);
		$this->assertSame('2025-11-01', $window[0]);
		$this->assertSame('2026-10-31', $window[1]);

		// Explicit bounds inside the billing extension must not be clamped
		// back to the project window.
		$window = $method->invoke($this->service(), $ws, [
			'from' => '2026-10-01',
			'to' => '2026-10-31',
		]);
		$this->assertSame('2026-10-01', $window[0]);
		$this->assertSame('2026-10-31', $window[1]);
	}

	public function testListWindowWithoutBillingBoundsClampsToProject(): void
	{
		$method = new ReflectionMethod(TransactionService::class, 'resolveDateWindow');
		$method->setAccessible(true);
		$window = $method->invoke($this->service(), [
			'type' => WorkspaceService::TYPE_PROJECT,
			'projectStartDate' => '2026-01-01',
			'projectEndDate' => '2026-09-30',
			'billingStartDate' => null,
			'billingEndDate' => null,
		], []);
		$this->assertSame('2026-01-01', $window[0]);
		$this->assertSame('2026-09-30', $window[1]);
	}

	public function testParseIsoDateRejectsImpossibleCalendarDates(): void
	{
		$method = new ReflectionMethod(TransactionService::class, 'parseIsoDate');
		$method->setAccessible(true);
		$service = $this->service();

		foreach (['2026-02-30', '2026-02-29', '2026-04-31', '2026-00-10'] as $bad) {
			try {
				$method->invoke($service, $bad, 'bookingDate');
				$this->fail("expected rejection for {$bad}");
			} catch (\InvalidArgumentException) {
			}
		}

		$ok = $method->invoke($service, '2024-02-29', 'bookingDate');
		$this->assertSame('2024-02-29', $ok->format('Y-m-d'));
	}

	private function service(): TransactionService
	{
		return new TransactionService(
			$this->createMock(\OCP\IDBConnection::class),
			$this->createMock(\OCA\BudgetCheck\Service\AccessControlService::class),
			$this->createMock(\OCA\BudgetCheck\Service\MoneyService::class),
			$this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class),
			$this->createMock(\OCA\BudgetCheck\Service\AuditLogService::class),
			$this->createMock(\OCA\BudgetCheck\Service\CategoryService::class),
			$this->createMock(\OCA\BudgetCheck\Service\BookingStatusService::class),
		);
	}
}
