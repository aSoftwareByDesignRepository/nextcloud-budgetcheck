<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Controller;

use OCA\BudgetCheck\Controller\ApiController;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Mutation routes must not carry NoCSRFRequired (T5.1 / §11.2 / §21.3).
 * GET/read helpers may; this pins the write surface.
 */
final class ApiControllerCsrfAttributeTest extends TestCase
{
	/** @var list<string> */
	private const MUTATION_METHODS = [
		'createWorkspace',
		'updateWorkspace',
		'updateTaxMode',
		'saveWorkspaceFavorites',
		'createBookingStatus',
		'updateBookingStatus',
		'deactivateBookingStatus',
		'createCategory',
		'updateCategory',
		'deactivateCategory',
		'createTransaction',
		'updateTransaction',
		'deleteTransaction',
		'previewTransactionImport',
		'commitTransactionImport',
		'saveImportPreferences',
		'saveSummaryViewPreferences',
		'uploadTransactionAttachment',
		'deleteTransactionAttachment',
		'replaceTransactionAttachment',
		'monthlyClose',
		'monthlyReopen',
		'bulkUpsertBudgets',
		'bulkUpsertBudgetDefaults',
		'generatePlannedFromBudgets',
		'saveSavingsTarget',
		'createRecurringRule',
		'updateRecurringRule',
		'deleteRecurringRule',
		'generateFromRecurringRule',
		'addMember',
		'updateMember',
		'removeMember',
		'addGroupMember',
		'updateGroupMember',
		'removeGroupMember',
		'saveAppPolicy',
	];

	public function testMutationMethodsRequireCsrf(): void
	{
		$ref = new ReflectionClass(ApiController::class);
		$missing = [];
		foreach (self::MUTATION_METHODS as $name) {
			if (!$ref->hasMethod($name)) {
				// Method renamed — fail loud so the gate stays honest.
				$missing[] = $name . ' (missing method)';
				continue;
			}
			$method = $ref->getMethod($name);
			if ($this->hasNoCsrf($method)) {
				$missing[] = $name;
			}
		}
		self::assertSame([], $missing, 'These mutation methods must NOT use NoCSRFRequired');
	}

	public function testReadHelpersMaySkipCsrf(): void
	{
		$ref = new ReflectionClass(ApiController::class);
		foreach (['listWorkspaces', 'monthlySummary', 'projectPeriodSummary', 'yearlySummary'] as $name) {
			self::assertTrue($ref->hasMethod($name), $name);
			self::assertTrue(
				$this->hasNoCsrf($ref->getMethod($name)),
				$name . ' should remain a GET-safe NoCSRFRequired read'
			);
		}
	}

	private function hasNoCsrf(ReflectionMethod $method): bool
	{
		$attrs = $method->getAttributes(NoCSRFRequired::class);
		return $attrs !== [];
	}
}
