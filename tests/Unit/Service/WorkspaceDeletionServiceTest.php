<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Exception\AccessDeniedException;
use OCA\BudgetCheck\Exception\ValidationException;
use OCA\BudgetCheck\Service\AccessControlService;
use OCA\BudgetCheck\Service\AuditLogService;
use OCA\BudgetCheck\Service\ImportPreferencesService;
use OCA\BudgetCheck\Service\SummaryViewPreferencesService;
use OCA\BudgetCheck\Service\TransactionAttachmentService;
use OCA\BudgetCheck\Service\WorkspaceDeletionService;
use OCA\BudgetCheck\Service\WorkspaceService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Behavioral unit proofs for delete authz + confirmName (no live DB).
 */
final class WorkspaceDeletionServiceTest extends TestCase
{
	public function testPreviewDeniesNonMembersOpaquely(): void
	{
		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->expects(self::once())
			->method('getForUser')
			->with(9, 'eve')
			->willThrowException(new AccessDeniedException());

		$svc = $this->makeService(workspaces: $workspaces);
		$this->expectException(AccessDeniedException::class);
		$svc->previewImpact(9, 'eve');
	}

	public function testDeleteRejectsMismatchedConfirmNameWithoutTouchingDb(): void
	{
		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->method('getForUser')->willReturn([
			'id' => 3,
			'name' => 'Alpha Project',
			'type' => 'project',
			'privacyMode' => 'standard',
			'role' => AccessControlService::ROLE_MANAGER,
		]);

		$access = $this->createMock(AccessControlService::class);
		$access->expects(self::once())
			->method('ensureMinimumRole')
			->with(3, 'alice', AccessControlService::ROLE_MANAGER)
			->willReturn(AccessControlService::ROLE_MANAGER);

		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::never())->method('beginTransaction');

		$svc = $this->makeService(db: $db, access: $access, workspaces: $workspaces);

		try {
			$svc->deleteWorkspace(3, 'alice', 'Wrong Name');
			self::fail('Expected ValidationException');
		} catch (ValidationException $e) {
			self::assertArrayHasKey('confirmName', $e->getFields());
		}
	}

	public function testDeleteRejectsWhitespaceOnlyConfirmName(): void
	{
		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->method('getForUser')->willReturn([
			'id' => 3,
			'name' => 'Alpha',
			'type' => 'household',
			'privacyMode' => 'standard',
			'role' => AccessControlService::ROLE_MANAGER,
		]);
		$access = $this->createMock(AccessControlService::class);
		$access->method('ensureMinimumRole')->willReturn(AccessControlService::ROLE_MANAGER);
		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::never())->method('beginTransaction');

		$svc = $this->makeService(db: $db, access: $access, workspaces: $workspaces);
		$this->expectException(ValidationException::class);
		$svc->deleteWorkspace(3, 'alice', "  \t  ");
	}

	public function testContributorCannotPreviewImpact(): void
	{
		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->method('getForUser')->willReturn([
			'id' => 4,
			'name' => 'Shared',
			'type' => 'household',
			'privacyMode' => 'standard',
			'role' => AccessControlService::ROLE_CONTRIBUTOR,
		]);
		$access = $this->createMock(AccessControlService::class);
		$access->expects(self::once())
			->method('ensureMinimumRole')
			->willThrowException(new AccessDeniedException());

		$svc = $this->makeService(access: $access, workspaces: $workspaces);
		$this->expectException(AccessDeniedException::class);
		$svc->previewImpact(4, 'bob');
	}

	private function makeService(
		?IDBConnection $db = null,
		?AccessControlService $access = null,
		?WorkspaceService $workspaces = null,
	): WorkspaceDeletionService {
		return new WorkspaceDeletionService(
			$db ?? $this->createMock(IDBConnection::class),
			$access ?? $this->createMock(AccessControlService::class),
			$workspaces ?? $this->createMock(WorkspaceService::class),
			$this->createMock(TransactionAttachmentService::class),
			$this->createMock(AuditLogService::class),
			$this->createMock(ImportPreferencesService::class),
			$this->createMock(SummaryViewPreferencesService::class),
		);
	}
}
