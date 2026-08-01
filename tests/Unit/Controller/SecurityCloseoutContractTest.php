<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Security contracts: CSRF on mutations, IDOR-fail-closed helpers, delete version CAS.
 */
final class SecurityCloseoutContractTest extends TestCase
{
	public function testMutationMethodsDoNotBypassCsrf(): void
	{
		$src = (string) file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/ApiController.php');
		$methods = [
			'createWorkspace',
			'updateWorkspace',
			'updateTaxMode',
			'createTransaction',
			'updateTransaction',
			'deleteTransaction',
			'uploadTransactionAttachment',
			'deleteTransactionAttachment',
			'replaceTransactionAttachment',
			'createCategory',
			'updateCategory',
			'saveSavingsTarget',
			'saveAppPolicy',
			'createRecurringRule',
			'updateRecurringRule',
			'deleteRecurringRule',
			'saveSummaryViewPreferences',
			'saveImportPreferences',
			'createBookingStatus',
			'updateBookingStatus',
			'updateMember',
			'updateGroupMember',
		];
		foreach ($methods as $method) {
			self::assertMatchesRegularExpression(
				'/#\[NoAdminRequired\]\s*\n\s*public function ' . preg_quote($method, '/') . '\(/',
				$src,
				$method . ' must not carry NoCSRFRequired (CSRF required for mutations)',
			);
			self::assertDoesNotMatchRegularExpression(
				'/#\[NoCSRFRequired\]\s*\n\s*(?:#\[[^\]]+\]\s*\n\s*)*public function ' . preg_quote($method, '/') . '\(/',
				$src,
				$method . ' must not be annotated NoCSRFRequired',
			);
		}
	}

	public function testResolveCategoryFailsClosedOnForeignIds(): void
	{
		$src = (string) file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/ApiController.php');
		self::assertStringContainsString('loadForWorkspace($categoryId, $workspaceId)', $src);
		self::assertStringContainsString('throw new AccessDeniedException()', $src);
		self::assertStringContainsString('cross-workspace IDs', $src);
	}

	public function testOwnerWorkspaceLookupIsOpaque(): void
	{
		$src = (string) file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/ApiController.php');
		self::assertStringContainsString('ownerWorkspaceId($transactionId)', $src);
		self::assertStringContainsString('existence cannot be probed', $src);
	}

	public function testDeleteUsesVersionCas(): void
	{
		$src = (string) file_get_contents(dirname(__DIR__, 3) . '/lib/Service/TransactionService.php');
		self::assertStringContainsString('function delete(int $transactionId, string $userId, array $workspace, ?int $expectedVersion', $src);
		self::assertMatchesRegularExpression(
			'/andWhere\(\$qb->expr\(\)->eq\(\'version\'/',
			$src,
		);
		self::assertStringContainsString('isNull(\'deleted_at\')', $src);
		$api = (string) file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/ApiController.php');
		self::assertStringContainsString('expectedVersion', $api);
		$js = (string) file_get_contents(dirname(__DIR__, 3) . '/js/transactions.js');
		self::assertStringContainsString('version: tx.version', $js);
	}

	public function testFrontendSendsCsrfOnMutations(): void
	{
		$src = (string) file_get_contents(dirname(__DIR__, 3) . '/js/common/api.js');
		self::assertStringContainsString("headers.requesttoken = token", $src);
		self::assertStringContainsString("MUTATION_METHODS = new Set(['POST', 'PUT', 'PATCH', 'DELETE'])", $src);
		self::assertStringContainsString('Missing CSRF request token', $src);
	}

	public function testNotFoundMapsToHttp404BeforeGenericDomainCatch(): void
	{
		$src = (string) file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/ApiController.php');
		self::assertMatchesRegularExpression(
			'/catch \(NotFoundException \$e\) \{\s*\n\s*return \$this->error\(/',
			$src,
			'NotFoundException must map to a dedicated error response',
		);
		$notFoundPos = strpos($src, 'catch (NotFoundException $e)');
		$budgetPos = strpos($src, 'catch (BudgetCheckException $e)');
		self::assertNotFalse($notFoundPos);
		self::assertNotFalse($budgetPos);
		self::assertLessThan(
			$budgetPos,
			$notFoundPos,
			'NotFoundException must be caught before BudgetCheckException (otherwise 500)',
		);
		self::assertStringContainsString('Http::STATUS_NOT_FOUND', $src);
		self::assertStringContainsString("'NOT_FOUND'", $src);
	}
}
