<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Portfolio §2.1 — BudgetCheck is the reference App Admin OR model.
 */
final class DedicatedAppAdminContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 2);
	}

	public function testIsAppAdminUsesSystemAdminOrDedicatedList(): void
	{
		$src = (string) file_get_contents($this->root . '/lib/Service/AccessControlService.php');
		$start = strpos($src, 'public function isAppAdmin(string $userId): bool');
		$this->assertNotFalse($start);
		$end = strpos($src, 'public function canUseApp', $start);
		$this->assertNotFalse($end);
		$body = substr($src, $start, $end - $start);
		$this->assertStringContainsString('isSystemAdmin($userId)', $body);
		$this->assertStringContainsString('getAppAdminIds()', $body);
		$this->assertMatchesRegularExpression('/\|\|/', $body);
		$this->assertStringNotContainsString('if (!$this->isSystemAdmin($userId))', $body);
	}

	public function testAccessSettingsUseDirectoryPickers(): void
	{
		$html = (string) file_get_contents($this->root . '/templates/parts/app-settings/admins.php');
		$access = (string) file_get_contents($this->root . '/templates/parts/app-settings/access.php');
		$js = (string) file_get_contents($this->root . '/js/app-settings.js');
		$this->assertStringContainsString('bc-app-admin', $html);
		$this->assertStringContainsString('data-bc-app-admin-list', $html);
		$this->assertStringContainsString('id="bc-policy-admins-q"', $html);
		$this->assertStringContainsString('bc-entity-picker', $html . "\n" . $access . "\n" . $js);
		$this->assertStringNotContainsString('comma-separated user', strtolower($html . "\n" . $access . "\n" . $js));
		$this->assertStringNotContainsString('one per line', strtolower($html . "\n" . $access . "\n" . $js));
	}
}
