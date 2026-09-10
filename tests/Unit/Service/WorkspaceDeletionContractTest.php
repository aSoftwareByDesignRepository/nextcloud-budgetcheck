<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\AccessControlService;
use OCA\BudgetCheck\Service\WorkspaceDeletionService;
use PHPUnit\Framework\TestCase;

/**
 * Security & cascade contracts for hard-delete workspace (issue #19).
 */
final class WorkspaceDeletionContractTest extends TestCase
{
	public function testServiceEnforcesManagerGateConfirmNameAndLock(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/WorkspaceDeletionService.php');
		self::assertStringContainsString('ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_MANAGER)', $src);
		self::assertStringContainsString('getForUser($workspaceId, $userId)', $src);
		self::assertStringContainsString('confirmNameMatches', $src);
		self::assertStringContainsString('hash_equals', $src);
		self::assertStringContainsString('WorkspaceRowLock::acquire', $src);
		self::assertStringContainsString('workspace_deleted', $src);
		self::assertStringContainsString('collectAndDeleteRowsForWorkspace', $src);
		self::assertStringContainsString('purgeCollectedFiles', $src);
		self::assertStringContainsString('removeFavoriteWorkspaceId', $src);
		self::assertStringContainsString('forgetLastUsedWorkspace', $src);
		self::assertTrue(class_exists(WorkspaceDeletionService::class));
		self::assertTrue(class_exists(AccessControlService::class));
	}

	public function testCascadeCoversCanonicalChildTables(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/WorkspaceDeletionService.php');
		foreach ([
			'bc_transactions',
			'bc_recurring_rules',
			'bc_budgets',
			'bc_budget_defaults',
			'bc_savings_targets',
			'bc_monthly_snapshots',
			'bc_categories',
			'bc_booking_statuses',
			'bc_idempotency',
			'bc_workspace_members',
			'bc_workspace_groups',
			'bc_workspaces',
		] as $table) {
			self::assertStringContainsString($table, $src, "Cascade must touch {$table}");
		}
		// Audit trail is retained (orphan workspace_id) — do not wipe evidence.
		self::assertStringNotContainsString("'bc_audit_log'", $src);
	}

	public function testApiSurfaceRequiresCsrfRateLimitAndConfirmBody(): void
	{
		$api = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/ApiController.php');
		$routes = (string)file_get_contents(dirname(__DIR__, 3) . '/appinfo/routes.php');
		self::assertStringContainsString('function deleteWorkspace(int $id)', $api);
		self::assertStringContainsString('function previewWorkspaceDelete(int $id)', $api);
		self::assertStringContainsString("assertAllowed(\$userId, 'workspace_delete', 5, 3600)", $api);
		self::assertStringContainsString("assertAllowed(\$userId, 'workspace_delete_preview', 30, 300)", $api);
		self::assertStringContainsString("confirmName", $api);
		// DELETE must NOT be NoCSRFRequired.
		self::assertDoesNotMatchRegularExpression(
			'/\#\[NoCSRFRequired\]\s*\n\s*public function deleteWorkspace/m',
			$api,
		);
		self::assertStringContainsString("api#deleteWorkspace", $routes);
		self::assertStringContainsString("api#previewWorkspaceDelete", $routes);
		self::assertStringContainsString("'/api/workspaces/{id}/delete-impact'", $routes);
		self::assertMatchesRegularExpression(
			"/api#deleteWorkspace'.*?'verb' => 'DELETE'/s",
			$routes,
		);
	}

	public function testUiRequiresTypedNameAndDangerZoneForManagers(): void
	{
		$tpl = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/parts/settings/workspace.php');
		$js = (string)file_get_contents(dirname(__DIR__, 3) . '/js/settings.js');
		self::assertStringContainsString('data-bc-workspace-delete-zone', $tpl);
		self::assertStringContainsString('data-bc-workspace-delete', $tpl);
		self::assertStringContainsString('InvoiceCheck', $tpl);
		self::assertStringContainsString('wireWorkspaceDelete', $js);
		self::assertStringContainsString('confirmName', $js);
		self::assertStringContainsString(
			"await Api.del('/apps/budgetcheck/api/workspaces/' + workspaceId, { confirmName });",
			$js,
		);
		self::assertStringContainsString('delete-impact', $js);
		self::assertStringContainsString('/apps/budgetcheck/workspaces', $js);
		self::assertStringContainsString('input.value.trim() !== workspaceName', $js);
	}

	public function testMobileDeleteSurfaceExists(): void
	{
		$routes = (string)file_get_contents(dirname(__DIR__, 3) . '/appinfo/routes.php');
		$mobile = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/MobileApiController.php');
		$caps = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Capabilities.php');
		self::assertStringContainsString("mobile_api#deleteWorkspace", $routes);
		self::assertMatchesRegularExpression(
			"/mobile_api#deleteWorkspace'.*?'verb' => 'DELETE'/s",
			$routes,
		);
		self::assertStringContainsString('function deleteWorkspace(int $workspaceId)', $mobile);
		self::assertStringContainsString("assertAllowed(\$userId, 'workspace_delete', 5, 3600)", $mobile);
		self::assertStringContainsString('COMPANION_API = 7', $caps);
	}

	public function testAttachmentPurgeApisExist(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/TransactionAttachmentService.php');
		self::assertStringContainsString('function collectAndDeleteRowsForWorkspace(int $workspaceId)', $src);
		self::assertStringContainsString('function purgeCollectedFiles(array $files)', $src);
	}
}
