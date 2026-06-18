<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\AppInfo\Application;
use OCA\BudgetCheck\Service\AccessControlService;
use OCA\BudgetCheck\Service\SummaryViewPreferencesService;
use OCA\BudgetCheck\Service\WorkspaceService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class SummaryViewPreferencesServiceTest extends TestCase
{
	public function testEffectiveUsesWorkspaceDefaultWhenUnset(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn('');
		$access = $this->createMock(AccessControlService::class);

		$service = new SummaryViewPreferencesService($config, $access);
		$this->assertFalse($service->effectiveIncludeSpecials(3, 'alice', false));
		$this->assertTrue($service->effectiveIncludeSpecials(3, 'alice', true));
		$this->assertFalse($service->hasUserOverride(3, 'alice'));
	}

	public function testSavePersistsExplicitUserChoice(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn('0');
		$config->expects($this->once())
			->method('setUserValue')
			->with('bob', Application::APP_ID, 'summary_view_ws_9', '0');
		$access = $this->createMock(AccessControlService::class);

		$service = new SummaryViewPreferencesService($config, $access);
		$result = $service->save(9, 'bob', ['includeSpecialsInTotals' => false], true);

		$this->assertFalse($result['includeSpecialsInTotals']);
		$this->assertTrue($result['hasUserOverride']);
		$this->assertTrue($result['workspaceDefault']);
	}

	public function testEnrichHouseholdWorkspaceSkipsProjects(): void
	{
		$config = $this->createMock(IConfig::class);
		$access = $this->createMock(AccessControlService::class);
		$service = new SummaryViewPreferencesService($config, $access);

		$workspace = ['id' => 2, 'type' => WorkspaceService::TYPE_PROJECT];
		$this->assertSame($workspace, $service->enrichHouseholdWorkspace($workspace, 'alice'));
	}
}
