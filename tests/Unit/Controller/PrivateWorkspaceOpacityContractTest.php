<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * MH-10 / AC-12: non-member admin and stranger must receive identical deny bodies.
 * AC-22: PageController cannot select a private workspace for a non-member.
 */
final class PrivateWorkspaceOpacityContractTest extends TestCase
{
	public function testApiAccessDeniedBodyIsOpaqueAndIdentical(): void
	{
		$api = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/ApiController.php');
		self::assertStringContainsString(
			"return \$this->error('Access denied.', Http::STATUS_FORBIDDEN, 'access_denied');",
			$api
		);
		// Existence cannot be probed via distinct not_found vs private messages.
		self::assertDoesNotMatchRegularExpression(
			'/catch \(AccessDeniedException[^{]*\{[^}]*private/s',
			$api
		);
		self::assertStringContainsString('existence cannot be probed', $api);
	}

	public function testMobileAccessDeniedBodyIsOpaque(): void
	{
		$mobile = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/MobileApiController.php');
		self::assertStringContainsString(
			"return \$this->error('Access denied.', Http::STATUS_FORBIDDEN, 'FORBIDDEN');",
			$mobile
		);
	}

	public function testPageResolveWorkspaceSwallowsPrivateGetFailures(): void
	{
		$page = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/PageController.php');
		self::assertMatchesRegularExpression(
			'/function resolveWorkspace\(\): array\s*\{[\s\S]*?getForUser\([\s\S]*?catch \(\\\\Throwable\)\s*\{\s*\$selected = null;/s',
			$page
		);
		self::assertStringContainsString('favoriteWorkspaceIds', $page);
		self::assertStringContainsString('array_intersect', $page);
	}

	public function testUserDeletedListenerOnlyPurgesAuthState(): void
	{
		$listener = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Listener/UserDeletedListener.php');
		$purge = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/AccessControlService.php');
		self::assertStringContainsString('purgeUser', $listener);
		self::assertMatchesRegularExpression(
			'/function purgeUser\(string \$userId\): void\s*\{[\s\S]*?delete\(\'bc_workspace_members\'/s',
			$purge
		);
		self::assertStringNotContainsString('delete(\'bc_workspaces\')', $purge);
	}
}
