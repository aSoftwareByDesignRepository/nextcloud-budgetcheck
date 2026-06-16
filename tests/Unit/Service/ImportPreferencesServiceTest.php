<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\AppInfo\Application;
use OCA\BudgetCheck\Service\AccessControlService;
use OCA\BudgetCheck\Service\ImportPreferencesService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class ImportPreferencesServiceTest extends TestCase
{
	public function testGetReturnsDefaultsWhenUnset(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn('');
		$access = $this->createMock(AccessControlService::class);

		$service = new ImportPreferencesService($config, $access);
		$prefs = $service->get(5, 'user1');

		$this->assertNull($prefs['expenseCategoryId']);
		$this->assertNull($prefs['incomeCategoryId']);
		$this->assertSame('auto', $prefs['directionMode']);
		$this->assertFalse($prefs['skipDuplicates']);
		$this->assertFalse($prefs['skipFingerprintDuplicates']);
	}

	public function testSaveSanitizesAndPersists(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->expects($this->once())
			->method('setUserValue')
			->with(
				'user1',
				Application::APP_ID,
				'import_prefs_ws_7',
				$this->callback(static function (string $json): bool {
					$data = json_decode($json, true);
					return is_array($data)
						&& $data['expenseCategoryId'] === 12
						&& $data['incomeCategoryId'] === 34
						&& $data['directionMode'] === 'expense'
						&& $data['skipDuplicates'] === true
						&& $data['skipFingerprintDuplicates'] === true;
				}),
			);
		$access = $this->createMock(AccessControlService::class);

		$service = new ImportPreferencesService($config, $access);
		$result = $service->save(7, 'user1', [
			'expenseCategoryId' => 12,
			'incomeCategoryId' => 34,
			'directionMode' => 'expense',
			'skipDuplicates' => true,
			'skipFingerprintDuplicates' => true,
		]);

		$this->assertSame(12, $result['expenseCategoryId']);
		$this->assertSame(34, $result['incomeCategoryId']);
		$this->assertTrue($result['skipFingerprintDuplicates']);
	}

	public function testFingerprintRequiresSkipDuplicates(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->expects($this->once())
			->method('setUserValue')
			->with(
				'user1',
				Application::APP_ID,
				'import_prefs_ws_1',
				$this->callback(static function (string $json): bool {
					$data = json_decode($json, true);
					return is_array($data)
						&& $data['skipDuplicates'] === false
						&& $data['skipFingerprintDuplicates'] === false;
				}),
			);
		$access = $this->createMock(AccessControlService::class);

		$service = new ImportPreferencesService($config, $access);
		$result = $service->save(1, 'user1', [
			'skipDuplicates' => false,
			'skipFingerprintDuplicates' => true,
		]);

		$this->assertFalse($result['skipFingerprintDuplicates']);
	}
}
