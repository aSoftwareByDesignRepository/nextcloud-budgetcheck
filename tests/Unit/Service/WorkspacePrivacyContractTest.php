<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Exception\ConflictException;
use OCA\BudgetCheck\Service\AccessControlService;
use OCA\BudgetCheck\Service\WorkspaceService;
use PHPUnit\Framework\TestCase;

/**
 * Source-level security contracts for private workspaces (mutation-friendly).
 * These assert the choke-point behaviours the auditor will try to break.
 */
final class WorkspacePrivacyContractTest extends TestCase
{
	public function testAccessControlSkipsAdminBypassForPrivate(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/AccessControlService.php');
		self::assertStringContainsString('PRIVACY_PRIVATE', $src);
		self::assertMatchesRegularExpression(
			'/function role\(int \$workspaceId, string \$userId\): \?string\s*\{.*?PRIVACY_PRIVATE.*?individualRole/s',
			$src
		);
		self::assertStringContainsString('workspaceIdsVisibleToAppAdmin', $src);
		self::assertStringContainsString('neq(\'w.privacy_mode\'', $src);
		self::assertStringContainsString('canCreateWorkspace', $src);
		self::assertStringContainsString('countPrivateWorkspaces', $src);
	}

	public function testWorkspaceServiceEnforcesPrivateGuards(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/WorkspaceService.php');
		self::assertStringContainsString('privacy_mode', $src);
		self::assertStringContainsString('assertPrivacyTransitionAllowed', $src);
		self::assertStringContainsString('CODE_WORKSPACE_HAS_GROUP_MEMBERS', $src);
		self::assertStringContainsString('CODE_PRIVATE_WORKSPACE_DUAL_MANAGER', $src);
		self::assertStringContainsString('CODE_PRIVATE_WORKSPACE_GROUPS_FORBIDDEN', $src);
		self::assertStringContainsString('privacy_mode_changed', $src);
		self::assertStringContainsString('WorkspaceRowLock::acquire', $src);
		self::assertStringContainsString('individualMemberRole', $src);
		self::assertTrue(class_exists(WorkspaceService::class));
		self::assertTrue(class_exists(AccessControlService::class));
	}

	public function testConflictCodesAreDistinct(): void
	{
		$v = new ConflictException();
		self::assertSame(ConflictException::CODE_VERSION_CONFLICT, $v->getErrorCode());
		$g = new ConflictException(ConflictException::CODE_PRIVATE_WORKSPACE_GROUPS_FORBIDDEN, 'no groups');
		self::assertSame(ConflictException::CODE_PRIVATE_WORKSPACE_GROUPS_FORBIDDEN, $g->getErrorCode());
		self::assertSame('no groups', $g->getMessage());
		self::assertNotSame($v->getErrorCode(), $g->getErrorCode());
	}

	public function testMigrationAddsPrivacyMode(): void
	{
		$src = (string)file_get_contents(
			dirname(__DIR__, 3) . '/lib/Migration/Version1021Date20260811120000.php'
		);
		self::assertStringContainsString('privacy_mode', $src);
		self::assertStringContainsString("'default' => 'standard'", $src);
		self::assertStringContainsString('bc_ws_privacy_idx', $src);
		self::assertStringContainsString('hasColumn', $src);
	}

	public function testUiAndApiSurfacePrivacyMode(): void
	{
		$api = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/ApiController.php');
		$mobile = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/MobileApiController.php');
		$tpl = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/parts/settings/workspace.php');
		$createJs = (string)file_get_contents(dirname(__DIR__, 3) . '/js/common/workspace.js');
		self::assertStringContainsString('canCreatePrivateWorkspace', $api);
		self::assertStringContainsString('canCreateStandardWorkspace', $api);
		self::assertStringContainsString('canCreatePrivateWorkspace', $mobile);
		self::assertStringContainsString('privacyMode', $mobile);
		self::assertStringContainsString('bc-privacy-fieldset', $tpl);
		self::assertStringContainsString('not end-to-end encryption', $tpl);
		self::assertStringContainsString('privacyMode', $createJs);
		self::assertStringNotContainsString('end-to-end encrypted', strtolower($tpl));
		self::assertStringNotContainsString('zero-knowledge', strtolower($tpl));
	}

	public function testBannedMarketingStringsAbsentFromSettingsJs(): void
	{
		$js = strtolower((string)file_get_contents(dirname(__DIR__, 3) . '/js/settings.js'));
		self::assertStringNotContainsString('end-to-end encrypt', $js);
		self::assertStringNotContainsString('zero-knowledge', $js);
		self::assertStringContainsString('privacymode', $js);
	}

	public function testMemberMutationsTakeWorkspaceRowLockAndCreateGatesPrivacy(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/WorkspaceService.php');
		self::assertMatchesRegularExpression(
			'/function updateMember\([\s\S]*?WorkspaceRowLock::acquire\([\s\S]*?ensureNotLastManager/s',
			$src
		);
		self::assertMatchesRegularExpression(
			'/function removeMember\([\s\S]*?WorkspaceRowLock::acquire\([\s\S]*?ensureNotLastManager/s',
			$src
		);
		self::assertStringContainsString('canCreateWorkspace($userId, $privacyMode)', $src);
	}

	public function testBannedMarketingStringsAbsentAcrossPrivacySurfaces(): void
	{
		$paths = [
			dirname(__DIR__, 3) . '/templates/parts/settings/workspace.php',
			dirname(__DIR__, 3) . '/js/settings.js',
			dirname(__DIR__, 3) . '/js/common/workspace.js',
			dirname(__DIR__, 3) . '/l10n/en.json',
			dirname(__DIR__, 3) . '/l10n/de.json',
		];
		$banned = [
			'zero-knowledge',
			'zero knowledge',
			'impossible for admins to access',
			'not even the server can read',
			'end-to-end encrypted',
		];
		foreach ($paths as $path) {
			$hay = strtolower((string)file_get_contents($path));
			foreach ($banned as $phrase) {
				self::assertStringNotContainsString(
					$phrase,
					$hay,
					$path . ' must not claim cryptographic secrecy (' . $phrase . ')'
				);
			}
		}
	}
}
