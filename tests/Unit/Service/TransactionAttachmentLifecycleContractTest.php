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

	public function testUninstallPurgesAttachmentAppData(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Repair/UninstallDropTables.php');
		self::assertStringContainsString('purgeTransactionAttachmentFiles', $src);
		self::assertStringContainsString('/tx-attachments', $src);
	}

	public function testMobileDeleteBindsWorkspace(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/MobileApiController.php');
		self::assertStringContainsString('deleteInWorkspace($attachmentId, $userId, $workspaceId)', $src);
	}
}
