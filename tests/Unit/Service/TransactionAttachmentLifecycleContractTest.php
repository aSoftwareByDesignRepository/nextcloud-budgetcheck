<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Source contracts for attachment privacy + concurrency hardenings.
 */
final class TransactionAttachmentLifecycleContractTest extends TestCase
{
	public function testSoftDeletePurgesAttachments(): void
	{
		$tx = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/TransactionService.php');
		self::assertStringContainsString('purgeForTransaction($transactionId)', $tx);
	}

	public function testAttachmentServiceExposesPurgeAndWorkspaceDelete(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/TransactionAttachmentService.php');
		self::assertStringContainsString('function purgeForTransaction(int $transactionId)', $src);
		self::assertStringContainsString('function deleteInWorkspace(int $attachmentId, string $userId, ?int $workspaceId)', $src);
		self::assertStringContainsString('Delete DB row first', $src);
		self::assertStringContainsString('self::MAX_FILE_SIZE', $src);
		self::assertMatchesRegularExpression(
			'/validateEInvoiceXmlContent[\s\S]*MAX_FILE_SIZE/',
			$src,
			'XML entity scan must cover the full upload cap',
		);
	}

	public function testUploadRollsBackPersistedFileOnFailure(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/TransactionAttachmentService.php');
		self::assertStringContainsString('$persistedName = $storedName', $src);
		self::assertStringContainsString('if ($persistedName !== null)', $src);
	}

	public function testUninstallKeepsAttachmentAppData(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Repair/UninstallDropTables.php');
		// 1.1.7+: explicit removal drops DB metadata but keeps receipt binaries on disk.
		self::assertStringNotContainsString('purgeTransactionAttachmentFiles', $src);
		self::assertStringNotContainsString('/tx-attachments', $src);
		self::assertStringContainsString('purgeUpgradeBackupSnapshots', $src);
	}

	public function testMobileDeleteBindsWorkspace(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/MobileApiController.php');
		self::assertStringContainsString('deleteInWorkspace($attachmentId, $userId, $workspaceId)', $src);
	}

	public function testMobileDownloadBindsWorkspace(): void
	{
		$svc = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/TransactionAttachmentService.php');
		$ctrl = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/MobileApiController.php');
		self::assertStringContainsString('function resolveForDeliveryInWorkspace(', $svc);
		self::assertStringContainsString('resolveForDeliveryInWorkspace(', $ctrl);
		self::assertStringContainsString('function downloadTransactionAttachment(', $ctrl);
		self::assertStringContainsString('X-Content-Type-Options', $ctrl);
	}
}
