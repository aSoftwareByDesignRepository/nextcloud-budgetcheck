<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Controller;

use OCA\BudgetCheck\Controller\MobileApiController;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Mobile mutations use NoCSRFRequired (Basic app-password) — web ApiController must not.
 * Contract: free companion — no license / BDC2 / 402 strings in controller.
 */
final class MobileApiControllerContractTest extends TestCase
{
	/** @var list<string> */
	private const MOBILE_MUTATIONS = [
		'createTransaction',
		'updateTransaction',
		'deleteTransaction',
		'applyRecurringSuggestion',
		'registerPushToken',
		'unregisterPushToken',
		'uploadTransactionAttachment',
		'deleteTransactionAttachment',
		'createWorkspace',
		'updateWorkspace',
	];

	/** @var list<string> */
	private const MOBILE_READS = [
		'bootstrap',
		'listWorkspaces',
		'home',
		'monthlySummary',
		'yearlySummary',
		'periodSummary',
		'listCategories',
		'listTransactions',
		'getTransaction',
		'listBookingStatuses',
		'listRecurringSuggestions',
		'listTransactionAttachments',
		'downloadTransactionAttachment',
	];

	public function testMobileMutationsAllowNoCsrfForBasicAuth(): void
	{
		$ref = new ReflectionClass(MobileApiController::class);
		foreach (self::MOBILE_MUTATIONS as $name) {
			self::assertTrue($ref->hasMethod($name), $name);
			self::assertTrue(
				$this->hasNoCsrf($ref->getMethod($name)),
				$name . ' must be NoCSRFRequired for app-password clients'
			);
		}
	}

	public function testMobileReadsAreNoCsrf(): void
	{
		$ref = new ReflectionClass(MobileApiController::class);
		foreach (self::MOBILE_READS as $name) {
			self::assertTrue($ref->hasMethod($name), $name);
			self::assertTrue($this->hasNoCsrf($ref->getMethod($name)), $name);
		}
	}

	public function testNoLicenseOrBdc2InMobileController(): void
	{
		$src = (string)file_get_contents(
			dirname(__DIR__, 3) . '/lib/Controller/MobileApiController.php'
		);
		self::assertStringNotContainsString('BDC2', $src);
		self::assertStringNotContainsString('LICENSE_REQUIRED', $src);
		self::assertStringNotContainsString('NO_MOBILE_SEAT', $src);
		self::assertStringNotContainsString('STATUS_PAYMENT_REQUIRED', $src);
		self::assertStringNotContainsString('Http::STATUS_PAYMENT_REQUIRED', $src);
		self::assertStringContainsString('assertSafeMutationChannel', $src);
		self::assertStringContainsString('MobileMutationChannel::isSafe', $src);
		self::assertStringContainsString('MobileErrorCodes::fromInvalidArgument', $src);
		self::assertStringContainsString('getErrorCode()', $src);
		self::assertStringContainsString('strtoupper($e->getErrorCode())', $src);
		self::assertStringContainsString('NOT_FOUND', $src);
		self::assertStringContainsString('NotFoundException', $src);
		self::assertStringContainsString('Idempotency-Key', $src);
		self::assertStringContainsString("\$list['items']", $src);
		self::assertStringContainsString("'transactions' => \$enriched", $src);
		self::assertStringContainsString("'hasBudget' => \$hasBudget", $src);
		self::assertStringContainsString("'direction' => \$direction", $src);
		self::assertStringContainsString("if (!\$hasBudget && \$actual <= 0)", $src);
		$errorCodes = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MobileErrorCodes.php');
		self::assertStringContainsString('MONTH_CLOSED', $errorCodes);
		self::assertStringContainsString('TAX_DISABLED', $errorCodes);
		$channel = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MobileMutationChannel.php');
		self::assertStringContainsString('Basic|Bearer', $channel);
		self::assertStringContainsString('bool $csrfPassed', $channel);
		self::assertStringNotContainsString('requestTokenHeader', $channel);
		self::assertStringContainsString('passesCSRFCheck()', $src);
	}

	public function testMutationChannelAndValidateIdAreInsideSafe(): void
	{
		$src = (string)file_get_contents(
			dirname(__DIR__, 3) . '/lib/Controller/MobileApiController.php'
		);
		foreach (self::MOBILE_MUTATIONS as $name) {
			self::assertMatchesRegularExpression(
				'/public function ' . preg_quote($name, '/') . '\([^)]*\): JSONResponse\s*\{\s*return \$this->safe\(/s',
				$src,
				$name . ' must open with return $this->safe(...) so channel failures become JSON'
			);
		}
		self::assertDoesNotMatchRegularExpression(
			'/public function (?:create|update|delete)Transaction\([^)]*\): JSONResponse\s*\{\s*(?:\$\w+ = \$this->validateId|\$this->assertSafeMutationChannel)/s',
			$src,
			'validateId/assertSafeMutationChannel must not run before safe()'
		);
	}

	public function testCapabilitiesAdvertiseCompanionWithoutLicense(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Capabilities.php');
		self::assertStringContainsString('companion.min', $src);
		self::assertStringContainsString("'free' => true", $src);
		self::assertStringContainsString("'licensed' => false", $src);
		self::assertStringNotContainsString('BDC2', $src);
	}

	private function hasNoCsrf(ReflectionMethod $method): bool
	{
		return $method->getAttributes(NoCSRFRequired::class) !== [];
	}
}
