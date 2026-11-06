<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Family rule: assignment pickers never offer deactivated Nextcloud
 * accounts — the server filters fail-closed regardless of client flags.
 */
final class DisabledAccountGuardContractTest extends TestCase
{
	public function testUserSearchSkipsDeactivatedAccounts(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/ApiController.php');
		$this->assertMatchesRegularExpression(
			'/searchDisplayName\(\$q, 50, 0\)[\s\S]{0,1500}if \(!\$user->isEnabled\(\)\)/',
			$src,
			'ApiController user search must skip deactivated accounts server-side'
		);
	}
}
