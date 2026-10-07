<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Integration;

use OCA\BudgetCheck\AppInfo\Application;
use OCA\BudgetCheck\Exception\ValidationException;
use OCA\BudgetCheck\Service\AccessControlService;
use OCA\BudgetCheck\Service\CategoryService;
use OCA\BudgetCheck\Service\TransactionService;
use OCA\BudgetCheck\Service\WorkspaceDeletionService;
use OCA\BudgetCheck\Service\WorkspaceService;
use OCP\IConfig;
use OCP\IUserManager;
use Test\TestCase;

/**
 * Live-container proof for the optional billing period (Abrechnungszeitraum).
 *
 * Legacy-safety contract: a project workspace without billing bounds must
 * validate bookingDate against the project window exactly as before; once a
 * billing bound is set, that side relaxes/extends; clearing it restores the
 * project-window behaviour.
 */
final class BillingPeriodIntegrationTest extends TestCase
{
	private const OWNER = 'bc_billing_owner';
	private const PASSWORD = 'bc-billing-pass-7fQ!';

	/** @var array<int, string> workspace id => name (for confirmName deletes) */
	private array $workspaceNames = [];

	private ?string $prevAppAdmins = null;

	protected function setUp(): void
	{
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud is not bootstrapped (run inside Docker).');
		}
		/** @var IConfig $config */
		$config = \OC::$server->get(IConfig::class);
		$this->prevAppAdmins = $config->getAppValue(Application::APP_ID, AccessControlService::KEY_APP_ADMINS, '[]');
		// Standard workspaces require app-admin to create.
		$admins = json_decode($this->prevAppAdmins, true);
		$admins = is_array($admins) ? $admins : [];
		if (!in_array(self::OWNER, $admins, true)) {
			$admins[] = self::OWNER;
		}
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_APP_ADMINS, json_encode($admins, JSON_THROW_ON_ERROR));

		/** @var IUserManager $users */
		$users = \OC::$server->get(IUserManager::class);
		if ($users->userExists(self::OWNER)) {
			$users->get(self::OWNER)?->delete();
		}
		$users->createUser(self::OWNER, self::PASSWORD);
	}

	protected function tearDown(): void
	{
		if (isset(\OC::$server)) {
			/** @var WorkspaceDeletionService $deletion */
			$deletion = \OC::$server->get(WorkspaceDeletionService::class);
			foreach ($this->workspaceNames as $id => $name) {
				try {
					$deletion->deleteWorkspace($id, self::OWNER, $name);
				} catch (\Throwable) {
					// best-effort cleanup
				}
			}
			\OC::$server->get(IUserManager::class)->get(self::OWNER)?->delete();
			if ($this->prevAppAdmins !== null) {
				\OC::$server->get(IConfig::class)->setAppValue(
					Application::APP_ID,
					AccessControlService::KEY_APP_ADMINS,
					$this->prevAppAdmins,
				);
			}
		}
		parent::tearDown();
	}

	private function createProjectWorkspace(string $name, array $extra = []): array
	{
		/** @var WorkspaceService $workspaces */
		$workspaces = \OC::$server->get(WorkspaceService::class);
		$ws = $workspaces->createWorkspace(self::OWNER, array_merge([
			'name' => $name,
			'type' => 'project',
			'currencyCode' => 'EUR',
			'projectStartDate' => '2026-01-01',
			'projectEndDate' => '2026-09-30',
		], $extra));
		$this->workspaceNames[(int)$ws['id']] = $name;
		return $ws;
	}

	private function expenseCategory(int $workspaceId): array
	{
		/** @var CategoryService $categories */
		$categories = \OC::$server->get(CategoryService::class);
		return $categories->create($workspaceId, self::OWNER, [
			'name' => 'Materials',
			'type' => 'expense',
		]);
	}

	private function tryCreate(int $workspaceId, array $workspace, array $category, string $bookingDate): array
	{
		/** @var TransactionService $transactions */
		$transactions = \OC::$server->get(TransactionService::class);
		return $transactions->create($workspaceId, self::OWNER, [
			'direction' => 'expense',
			'amountMinor' => 100,
			'bookingDate' => $bookingDate,
			'categoryId' => (int)$category['id'],
		], $workspace, $category);
	}

	public function testNoBillingBoundsKeepsProjectWindowBehaviour(): void
	{
		$ws = $this->createProjectWorkspace('Billing IT legacy');
		$category = $this->expenseCategory((int)$ws['id']);

		// Booking one month after project end — the reporter's case — must
		// still be rejected when no billing bounds are configured.
		$this->expectException(ValidationException::class);
		$this->tryCreate((int)$ws['id'], $ws, $category, '2026-10-15');
	}

	public function testBillingEndAdmitsPostProjectInvoiceThenClearingRestores(): void
	{
		$ws = $this->createProjectWorkspace('Billing IT extend');
		$category = $this->expenseCategory((int)$ws['id']);
		/** @var WorkspaceService $workspaces */
		$workspaces = \OC::$server->get(WorkspaceService::class);

		try {
			$this->tryCreate((int)$ws['id'], $ws, $category, '2026-10-15');
			$this->fail('expected project-window rejection');
		} catch (ValidationException $e) {
			self::assertArrayHasKey('bookingDate', $e->getFields());
			self::assertStringContainsString('project', $e->getFields()['bookingDate']);
		}

		$ws = $workspaces->updateWorkspace((int)$ws['id'], self::OWNER, [
			'billingEndDate' => '2026-10-31',
		]);
		self::assertSame('2026-10-31', $ws['billingEndDate']);
		self::assertNull($ws['billingStartDate']);

		// Inside the billing window now — accepted.
		$tx = $this->tryCreate((int)$ws['id'], $ws, $category, '2026-10-15');
		self::assertGreaterThan(0, (int)$tx['id']);
		self::assertArrayHasKey('version', $tx);

		// Still bounded: beyond the billing end is rejected with billing wording.
		try {
			$this->tryCreate((int)$ws['id'], $ws, $category, '2026-11-02');
			$this->fail('expected billing-window rejection');
		} catch (ValidationException $e) {
			self::assertStringContainsString('billing period', $e->getFields()['bookingDate']);
		}

		// Clearing the override while a booking sits outside the project window
		// must be refused — same no-orphans invariant as narrowing project dates.
		try {
			$workspaces->updateWorkspace((int)$ws['id'], self::OWNER, [
				'billingEndDate' => null,
			]);
			$this->fail('expected orphan-guard rejection when clearing billing end');
		} catch (\InvalidArgumentException $e) {
			self::assertStringContainsString('orphan', $e->getMessage());
		}

		// Once the offending booking is gone, clearing restores the project window.
		/** @var TransactionService $transactions */
		$transactions = \OC::$server->get(TransactionService::class);
		$transactions->delete((int)$tx['id'], self::OWNER, $ws, (int)$tx['version']);
		$ws = $workspaces->updateWorkspace((int)$ws['id'], self::OWNER, [
			'billingEndDate' => null,
		]);
		self::assertNull($ws['billingEndDate']);
		try {
			$this->tryCreate((int)$ws['id'], $ws, $category, '2026-10-20');
			$this->fail('expected project-window rejection after clearing billing bounds');
		} catch (ValidationException) {
		}
	}

	public function testBillingStartCanPrecedeProjectStart(): void
	{
		$ws = $this->createProjectWorkspace('Billing IT early', ['billingStartDate' => '2025-11-01']);
		$category = $this->expenseCategory((int)$ws['id']);

		// Pre-kickoff invoice inside the billing period — accepted.
		$tx = $this->tryCreate((int)$ws['id'], $ws, $category, '2025-11-15');
		self::assertGreaterThan(0, (int)$tx['id']);
		// The unset side still falls back to the project end.
		try {
			$this->tryCreate((int)$ws['id'], $ws, $category, '2026-10-01');
			$this->fail('expected rejection beyond effective end');
		} catch (ValidationException) {
		}
	}

	public function testBillingEndBeforeEffectiveStartRejected(): void
	{
		$ws = $this->createProjectWorkspace('Billing IT invalid');
		/** @var WorkspaceService $workspaces */
		$workspaces = \OC::$server->get(WorkspaceService::class);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('billingEndDate');
		$workspaces->updateWorkspace((int)$ws['id'], self::OWNER, [
			'billingEndDate' => '2025-06-01',
		]);
	}

	public function testHouseholdRejectsBillingFields(): void
	{
		/** @var WorkspaceService $workspaces */
		$workspaces = \OC::$server->get(WorkspaceService::class);
		$ws = $workspaces->createWorkspace(self::OWNER, [
			'name' => 'Billing IT HH',
			'type' => 'household',
			'currencyCode' => 'EUR',
		]);
		$this->workspaceNames[(int)$ws['id']] = 'Billing IT HH';

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Household workspaces do not accept project fields.');
		$workspaces->updateWorkspace((int)$ws['id'], self::OWNER, [
			'billingEndDate' => '2026-12-31',
		]);
	}
}
