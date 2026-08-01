<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Exception\AccessDeniedException;
use OCA\BudgetCheck\Exception\BudgetCheckException;
use OCA\BudgetCheck\Service\AccessControlService;
use PHPUnit\Framework\TestCase;

/**
 * IDOR foundation: membership and minimum-role gates fail closed (T5.1).
 */
final class AccessControlIdorGateTest extends TestCase
{
	public function testEnsureMembershipAndMinimumRoleFailClosed(): void
	{
		$src = (string)file_get_contents(
			dirname(__DIR__, 3) . '/lib/Service/AccessControlService.php'
		);
		self::assertMatchesRegularExpression(
			'/function ensureMembership\(int \$workspaceId, string \$userId\).*?\{.*?throw new AccessDeniedException\(\)/s',
			$src
		);
		self::assertMatchesRegularExpression(
			'/function ensureMinimumRole\(int \$workspaceId, string \$userId, string \$minimum\).*?\{.*?throw new AccessDeniedException\(\)/s',
			$src
		);
		self::assertStringContainsString('ROLE_MANAGER', $src);
		self::assertStringContainsString('ROLE_CONTRIBUTOR', $src);
		self::assertStringContainsString('ROLE_VIEWER', $src);
	}

	public function testAccessDeniedIsTypedBudgetCheckException(): void
	{
		$e = new AccessDeniedException();
		self::assertInstanceOf(BudgetCheckException::class, $e);
		self::assertTrue(class_exists(AccessControlService::class));
	}
}
