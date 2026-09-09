<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Controller;

use OCA\BudgetCheck\Controller\AttachmentController;
use OCA\BudgetCheck\Controller\ExportController;
use OCA\BudgetCheck\Listener\GroupDeletedListener;
use OCA\BudgetCheck\Listener\UserDeletedListener;
use OCA\BudgetCheck\Service\AccessControlService;
use OCA\BudgetCheck\Service\HouseholdYearlyExportService;
use OCA\BudgetCheck\Service\RateLimitService;
use OCA\BudgetCheck\Service\TransactionAttachmentService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\IGroup;
use OCP\IRequest;
use OCP\IUser;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\TestCase;

/**
 * Invoking (not reflection-only) proofs for export/attachment controllers and delete listeners.
 */
final class EntrypointInvokeCoverageTest extends TestCase
{
	public function testExportHouseholdYearlyInvokesService(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $k, $d = null) => match ($k) {
				'workspaceId' => 7,
				'year' => 2026,
				default => $d,
			}
		);
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('alice');
		$rate = $this->createMock(RateLimitService::class);
		$export = $this->createMock(HouseholdYearlyExportService::class);
		$export->expects($this->once())->method('buildXlsx')->with(7, 'alice', 2026)->willReturn([
			'content' => 'xlsx-bytes',
			'filename' => 'household-2026.xlsx',
			'mimeType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		]);

		$controller = new ExportController('budgetcheck', $request, $access, $export, $rate);
		$res = $controller->householdYearly();
		self::assertInstanceOf(DataDownloadResponse::class, $res);
		self::assertSame(Http::STATUS_OK, $res->getStatus());
	}

	public function testExportProjectPeriodInvokesService(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $k, $d = null) => $k === 'workspaceId' ? 7 : $d
		);
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('alice');
		$rate = $this->createMock(RateLimitService::class);
		$export = $this->createMock(HouseholdYearlyExportService::class);
		$export->expects($this->once())->method('buildProjectPeriodXlsx')->with(7, 'alice')->willReturn([
			'content' => 'xlsx-bytes',
			'filename' => 'project.xlsx',
			'mimeType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		]);

		$controller = new ExportController('budgetcheck', $request, $access, $export, $rate);
		$res = $controller->projectPeriod();
		self::assertInstanceOf(DataDownloadResponse::class, $res);
	}

	public function testExportHouseholdYearlyAuthzDenied(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn(7);
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('alice');
		$rate = $this->createMock(RateLimitService::class);
		$export = $this->createMock(HouseholdYearlyExportService::class);
		$export->method('buildXlsx')->willThrowException(new \OCA\BudgetCheck\Exception\AccessDeniedException());

		$controller = new ExportController('budgetcheck', $request, $access, $export, $rate);
		$res = $controller->householdYearly();
		self::assertSame(Http::STATUS_FORBIDDEN, $res->getStatus());
	}

	public function testAttachmentDownloadInvokesResolveForDelivery(): void
	{
		$tmp = tempnam(sys_get_temp_dir(), 'bcatt');
		self::assertNotFalse($tmp);
		file_put_contents($tmp, 'pdf-bytes');

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn(null);
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('alice');
		$rate = $this->createMock(RateLimitService::class);
		$attachments = $this->createMock(TransactionAttachmentService::class);
		$attachments->expects($this->once())->method('resolveForDelivery')->with(11, 'alice', false)->willReturn([
			'row' => [
				'original_name' => 'receipt.pdf',
				'mime_type' => 'application/pdf',
				'file_size' => 9,
			],
			'filePath' => $tmp,
			'disposition' => 'attachment',
		]);
		$attachments->method('sanitizeContentDispositionFilename')->willReturn('receipt.pdf');

		$controller = new AttachmentController('budgetcheck', $request, $access, $attachments, $rate);
		$res = $controller->download(11);
		self::assertInstanceOf(StreamResponse::class, $res);
		@unlink($tmp);
	}

	public function testAttachmentDownloadAuthzDenied(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn(null);
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('alice');
		$rate = $this->createMock(RateLimitService::class);
		$rate->method('assertAllowed')->willReturnCallback(static function (): void {});
		$attachments = $this->createMock(TransactionAttachmentService::class);
		$attachments->expects($this->once())
			->method('resolveForDelivery')
			->with(11, 'alice', false)
			->willThrowException(new \OCA\BudgetCheck\Exception\AccessDeniedException());

		$controller = new AttachmentController('budgetcheck', $request, $access, $attachments, $rate);
		$res = $controller->download(11);
		self::assertSame(Http::STATUS_FORBIDDEN, $res->getStatus());
		self::assertSame('Access denied.', $res->getData());
	}

	public function testUserDeletedListenerInvokesPurgeUser(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->expects($this->once())->method('purgeUser')->with('gone');
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('gone');
		$event = $this->createMock(UserDeletedEvent::class);
		$event->method('getUser')->willReturn($user);

		(new UserDeletedListener($access))->handle($event);
	}

	public function testGroupDeletedListenerInvokesPurgeGroup(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->expects($this->once())->method('purgeGroup')->with('ops');
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn('ops');
		$event = $this->createMock(GroupDeletedEvent::class);
		$event->method('getGroup')->willReturn($group);

		(new GroupDeletedListener($access))->handle($event);
	}
}
