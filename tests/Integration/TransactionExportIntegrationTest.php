<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Integration;

use OCA\BudgetCheck\AppInfo\Application;
use OCA\BudgetCheck\Exception\AccessDeniedException;
use OCA\BudgetCheck\Service\AccessControlService;
use OCA\BudgetCheck\Service\CategoryService;
use OCA\BudgetCheck\Service\TransactionExportService;
use OCA\BudgetCheck\Service\TransactionService;
use OCA\BudgetCheck\Service\WorkspaceDeletionService;
use OCA\BudgetCheck\Service\WorkspaceService;
use OCP\IConfig;
use OCP\IUserManager;
use Test\TestCase;

/**
 * Live-container proof for the all-transactions CSV/ODS export (issue #21).
 *
 * Contract under test: every active booking in the authorized workspace is
 * exported with its full detail; soft-deleted and foreign-workspace rows can
 * never leak; filters narrow the export exactly like the ledger list.
 */
final class TransactionExportIntegrationTest extends TestCase
{
	private const OWNER = 'bc_export_owner';
	private const OUTSIDER = 'bc_export_out';
	private const PASSWORD = 'bc-export-pass-7fQ!';

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
		$admins = json_decode($this->prevAppAdmins, true);
		$admins = is_array($admins) ? $admins : [];
		if (!in_array(self::OWNER, $admins, true)) {
			$admins[] = self::OWNER;
		}
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_APP_ADMINS, json_encode($admins, JSON_THROW_ON_ERROR));

		/** @var IUserManager $users */
		$users = \OC::$server->get(IUserManager::class);
		foreach ([self::OWNER, self::OUTSIDER] as $uid) {
			if ($users->userExists($uid)) {
				$users->get($uid)?->delete();
			}
			$users->createUser($uid, self::PASSWORD);
		}
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
			/** @var IUserManager $users */
			$users = \OC::$server->get(IUserManager::class);
			$users->get(self::OWNER)?->delete();
			$users->get(self::OUTSIDER)?->delete();
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

	private function exporter(): TransactionExportService
	{
		return \OC::$server->get(TransactionExportService::class);
	}

	private function createWorkspace(string $name, string $type, array $extra = []): array
	{
		/** @var WorkspaceService $workspaces */
		$workspaces = \OC::$server->get(WorkspaceService::class);
		$ws = $workspaces->createWorkspace(self::OWNER, array_merge([
			'name' => $name,
			'type' => $type,
			'currencyCode' => 'EUR',
		], $type === 'project' ? [
			'projectStartDate' => '2026-01-01',
			'projectEndDate' => '2026-12-31',
		] : [], $extra));
		$this->workspaceNames[(int)$ws['id']] = $name;
		return $ws;
	}

	private function category(int $workspaceId, string $name = 'Materials'): array
	{
		/** @var CategoryService $categories */
		$categories = \OC::$server->get(CategoryService::class);
		return $categories->create($workspaceId, self::OWNER, [
			'name' => $name,
			'type' => 'expense',
		]);
	}

	private function book(int $workspaceId, array $workspace, array $category, array $payload): array
	{
		/** @var TransactionService $transactions */
		$transactions = \OC::$server->get(TransactionService::class);
		return $transactions->create($workspaceId, self::OWNER, array_merge([
			'direction' => 'expense',
			'amountMinor' => 100,
			'bookingDate' => '2026-03-05',
			'categoryId' => (int)$category['id'],
		], $payload), $workspace, $category);
	}

	/** @return list<list<string>> */
	private function csvDataRows(string $content): array
	{
		$fh = fopen('php://memory', 'r+');
		fwrite($fh, substr($content, 3)); // strip BOM
		rewind($fh);
		$rows = [];
		while (($row = fgetcsv($fh)) !== false) {
			$rows[] = $row;
		}
		fclose($fh);
		$header = array_shift($rows);
		return array_map(fn ($r) => array_combine($header, $r), $rows);
	}

	public function testHouseholdCsvExportsEveryActiveBookingChronologically(): void
	{
		$ws = $this->createWorkspace('Export household', 'household');
		/** @var WorkspaceService $workspaces */
		$workspaces = \OC::$server->get(WorkspaceService::class);
		$ws = $workspaces->updateTaxMode((int)$ws['id'], self::OWNER, ['taxModeEnabled' => true]);
		$cat = $this->category((int)$ws['id']);
		$this->book((int)$ws['id'], $ws, $cat, ['bookingDate' => '2026-03-10', 'title' => 'Later']);
		$this->book((int)$ws['id'], $ws, $cat, ['bookingDate' => '2026-03-01', 'title' => 'Earlier']);
		$this->book((int)$ws['id'], $ws, $cat, [
			'bookingDate' => '2026-03-05',
			'title' => 'Taxed',
			'entryAmountBasis' => 'net',
			'vatRateBp' => 1900,
		]);

		$file = $this->exporter()->build((int)$ws['id'], self::OWNER, 'csv', []);
		$rows = $this->csvDataRows($file['content']);

		$this->assertCount(3, $rows);
		$this->assertSame(['Earlier', 'Taxed', 'Later'], array_column($rows, 'title'));
		$taxed = $rows[1];
		$this->assertSame('0.19', $taxed['vat'], '1.00 EUR net at 19% VAT → 0.19');
		$this->assertSame('19', $taxed['vatRatePercent']);
		$this->assertNotContains('bookingStatus', array_keys($taxed));
	}

	public function testSoftDeletedRowsNeverExport(): void
	{
		$ws = $this->createWorkspace('Export deleted', 'household');
		$cat = $this->category((int)$ws['id']);
		$keep = $this->book((int)$ws['id'], $ws, $cat, ['title' => 'Stays']);
		$gone = $this->book((int)$ws['id'], $ws, $cat, ['title' => 'Deleted']);

		/** @var TransactionService $transactions */
		$transactions = \OC::$server->get(TransactionService::class);
		$transactions->delete((int)$gone['id'], self::OWNER, $ws, (int)$gone['version']);

		$rows = $this->csvDataRows($this->exporter()->build((int)$ws['id'], self::OWNER, 'csv', [])['content']);
		$this->assertCount(1, $rows);
		$this->assertSame('Stays', $rows[0]['title']);

		// Even an explicit includeDeleted hint must not smuggle tombstones out.
		$rows = $this->csvDataRows($this->exporter()->build((int)$ws['id'], self::OWNER, 'csv', ['includeDeleted' => true])['content']);
		$this->assertCount(1, $rows);
		$this->assertSame((string)$keep['id'], $rows[0]['id']);
	}

	public function testFiltersNarrowExportLikeTheLedger(): void
	{
		$ws = $this->createWorkspace('Export filtered', 'household');
		$catA = $this->category((int)$ws['id'], 'Cat A');
		$catB = $this->category((int)$ws['id'], 'Cat B');
		$this->book((int)$ws['id'], $ws, $catA, ['title' => 'Alpha']);
		$this->book((int)$ws['id'], $ws, $catB, ['title' => 'Beta']);

		$rows = $this->csvDataRows($this->exporter()->build((int)$ws['id'], self::OWNER, 'csv', [
			'categoryId' => (int)$catA['id'],
		])['content']);
		$this->assertCount(1, $rows);
		$this->assertSame('Alpha', $rows[0]['title']);

		$rows = $this->csvDataRows($this->exporter()->build((int)$ws['id'], self::OWNER, 'csv', [
			'q' => 'bet',
		])['content']);
		$this->assertCount(1, $rows);
		$this->assertSame('Beta', $rows[0]['title']);
	}

	public function testProjectExportCarriesStatusAndBillingColumns(): void
	{
		$ws = $this->createWorkspace('Export project', 'project');
		$cat = $this->category((int)$ws['id']);
		$this->book((int)$ws['id'], $ws, $cat, ['title' => 'Phase 1']);

		$file = $this->exporter()->build((int)$ws['id'], self::OWNER, 'csv', []);
		$rows = $this->csvDataRows($file['content']);
		$this->assertCount(1, $rows);
		$this->assertArrayHasKey('bookingStatus', $rows[0]);
		$this->assertArrayHasKey('isBillable', $rows[0]);
		$this->assertArrayHasKey('billingStatus', $rows[0]);
	}

	public function testForeignWorkspaceRowsNeverLeak(): void
	{
		$wsA = $this->createWorkspace('Export leak A', 'household');
		$wsB = $this->createWorkspace('Export leak B', 'household');
		$catA = $this->category((int)$wsA['id']);
		$catB = $this->category((int)$wsB['id']);
		$this->book((int)$wsA['id'], $wsA, $catA, ['title' => 'OnlyInA']);
		$this->book((int)$wsB['id'], $wsB, $catB, ['title' => 'OnlyInB']);

		$rows = $this->csvDataRows($this->exporter()->build((int)$wsA['id'], self::OWNER, 'csv', [])['content']);
		$this->assertSame(['OnlyInA'], array_column($rows, 'title'));
	}

	public function testNonMemberIsDenied(): void
	{
		$ws = $this->createWorkspace('Export denied', 'household');
		$this->expectException(AccessDeniedException::class);
		$this->exporter()->build((int)$ws['id'], self::OUTSIDER, 'csv', []);
	}

	public function testOdsContainsEveryRowAndColumn(): void
	{
		$ws = $this->createWorkspace('Export ods', 'household');
		$cat = $this->category((int)$ws['id']);
		$this->book((int)$ws['id'], $ws, $cat, ['title' => 'ODS row', 'bookingDate' => '2026-04-01']);

		$file = $this->exporter()->build((int)$ws['id'], self::OWNER, 'ods', []);
		$this->assertSame('application/vnd.oasis.opendocument.spreadsheet', $file['mimeType']);
		$this->assertStringEndsWith('.ods', $file['filename']);

		$tmp = tempnam(sys_get_temp_dir(), 'ods-it-');
		file_put_contents($tmp, $file['content']);
		$zip = new \ZipArchive();
		$this->assertTrue($zip->open($tmp));
		$this->assertSame('mimetype', $zip->statIndex(0)['name']);
		$content = $zip->getFromName('content.xml');
		$zip->close();
		unlink($tmp);

		$this->assertNotFalse(simplexml_load_string($content));
		$this->assertStringContainsString('ODS row', $content);
		$this->assertStringContainsString('office:date-value="2026-04-01"', $content);
	}
}
