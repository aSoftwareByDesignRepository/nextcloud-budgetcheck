<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Controller;

use OCA\BudgetCheck\Capabilities;
use OCA\BudgetCheck\Controller\AttachmentController;
use OCA\BudgetCheck\Controller\ExportController;
use OCA\BudgetCheck\Listener\GroupDeletedListener;
use OCA\BudgetCheck\Listener\UserDeletedListener;
use OCA\BudgetCheck\Settings\AdminSettings;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCA\BudgetCheck\Service\AccessControlService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Characterization for shipping entrypoints that lack dedicated behavior suites:
 * admin settings, export/attachment controllers, capabilities, user/group delete listeners.
 */
final class EntrypointCoverageContractTest extends TestCase
{
	public function testCapabilitiesAdvertisesFreeCompanion(): void
	{
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn('1.2.1');
		$caps = (new Capabilities($apps))->getCapabilities();
		self::assertSame('1.2.1', $caps['budgetcheck']['version']);
		self::assertSame(Capabilities::COMPANION_API, $caps['budgetcheck']['companion.api']);
		self::assertTrue($caps['budgetcheck']['companion']['free']);
		self::assertFalse($caps['budgetcheck']['companion']['licensed']);
	}

	public function testAdminSettingsSectionAndPriority(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('getAppPolicy')->willReturn([
			'appAdminUserIds' => ['bcadmin'],
			'defaultTimezone' => 'Europe/Berlin',
			'defaultCurrency' => 'EUR',
		]);
		$l10n = $this->createMock(IL10N::class);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRoute')->willReturn('/apps/budgetcheck/x');
		$config = $this->createMock(IConfig::class);

		$settings = new AdminSettings($config, $factory, $url, $access);
		self::assertSame('additional', $settings->getSection());
		self::assertSame(60, $settings->getPriority());
		$form = $settings->getForm();
		self::assertInstanceOf(TemplateResponse::class, $form);
	}

	public function testExportAndAttachmentControllersExposeRouteMethods(): void
	{
		foreach ([
			[ExportController::class, ['householdYearly', 'projectPeriod']],
			[AttachmentController::class, ['download']],
		] as [$class, $methods]) {
			$ref = new ReflectionClass($class);
			foreach ($methods as $name) {
				self::assertTrue($ref->hasMethod($name), "{$class}::{$name}");
				$method = $ref->getMethod($name);
				self::assertTrue($method->isPublic());
			}
		}
	}

	public function testDeleteListenersHandleEventsViaAccessControl(): void
	{
		foreach ([UserDeletedListener::class, GroupDeletedListener::class] as $class) {
			$ref = new ReflectionClass($class);
			self::assertTrue($ref->hasMethod('handle'));
			$handle = $ref->getMethod('handle');
			self::assertTrue($handle->isPublic());
			$ctor = $ref->getConstructor();
			self::assertNotNull($ctor);
			$params = $ctor->getParameters();
			self::assertNotEmpty($params);
			$type = $params[0]->getType();
			self::assertNotNull($type);
			self::assertSame(AccessControlService::class, $type->getName());
		}

		// Source contract: listeners must not wipe ledger history (privacy opacity).
		$userSrc = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Listener/UserDeletedListener.php');
		self::assertStringContainsString('handle', $userSrc);
		self::assertStringNotContainsString('DELETE FROM', $userSrc);
	}

	public function testRoutesMapExportAndAttachmentActions(): void
	{
		$routes = include dirname(__DIR__, 3) . '/appinfo/routes.php';
		$names = array_column($routes['routes'], 'name');
		self::assertContains('export#householdYearly', $names);
		self::assertContains('export#projectPeriod', $names);
		self::assertContains('attachment#download', $names);
	}
}
