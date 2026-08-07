<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Suite legacy isolation (CHECK-SUITE L1): BudgetCheck must work without
 * InvoiceCheck / CustomerCheck / InventoryCheck / MaintenanceCheck installed.
 * Billing facades remain available for IC when present — ownership stays in BC.
 *
 * @see planning/check-productivity-suite/LEGACY-SAFETY.md
 */
final class SuiteLegacyIsolationContractTest extends TestCase
{
	private const FORBIDDEN_HARD_DEPS = [
		'customercheck',
		'invoicecheck',
		'inventorycheck',
		'maintenancecheck',
	];

	private string $infoXml;

	protected function setUp(): void
	{
		parent::setUp();
		$path = dirname(__DIR__, 2) . '/appinfo/info.xml';
		$this->assertFileExists($path);
		$this->infoXml = (string)file_get_contents($path);
		$this->assertNotSame('', trim($this->infoXml));
	}

	public function testInfoXmlDeclaresBudgetcheckId(): void
	{
		$this->assertMatchesRegularExpression('/<id>\s*budgetcheck\s*<\/id>/', $this->infoXml);
	}

	public function testHardDependenciesDoNotRequireSuiteSpineApps(): void
	{
		$hardBlock = $this->dependenciesInnerXml('dependencies');
		foreach (self::FORBIDDEN_HARD_DEPS as $appId) {
			$this->assertDoesNotMatchRegularExpression(
				'/<app\b[^>]*>\s*' . preg_quote($appId, '/') . '\s*<\/app>/i',
				$hardBlock,
				"Hard <dependencies> must not require {$appId} (suite L1)"
			);
		}
	}

	public function testBillingFacadesExistForOptionalInvoiceCheckConsumers(): void
	{
		$root = dirname(__DIR__, 2);
		$this->assertFileExists($root . '/lib/Public/BillingReadFacade.php');
		$this->assertFileExists($root . '/lib/Public/BillingWriteFacade.php');
	}

	public function testPhpSourcesDoNotStaticallyUseForbiddenSuiteNamespaces(): void
	{
		$root = dirname(__DIR__, 2) . '/lib';
		$hits = $this->scanPhpForForbiddenUse($root, [
			'OCA\\CustomerCheck\\',
			'OCA\\InvoiceCheck\\',
			'OCA\\InventoryCheck\\',
			'OCA\\MaintenanceCheck\\',
		]);
		$this->assertSame([], $hits, implode("\n", $hits));
	}

	/**
	 * @param list<string> $forbidden
	 * @return list<string>
	 */
	private function scanPhpForForbiddenUse(string $root, array $forbidden): array
	{
		$hits = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
		);
		/** @var \SplFileInfo $file */
		foreach ($iterator as $file) {
			if (!$file->isFile() || $file->getExtension() !== 'php') {
				continue;
			}
			$contents = (string)file_get_contents($file->getPathname());
			foreach ($forbidden as $ns) {
				if (str_contains($contents, 'use ' . $ns) || str_contains($contents, 'new ' . $ns)) {
					$hits[] = $file->getPathname() . ' → ' . $ns;
				}
			}
		}
		return $hits;
	}

	private function dependenciesInnerXml(string $tag): string
	{
		if (!preg_match(
			'/' . preg_quote('<' . $tag . '>', '/') . '(.*?)' . preg_quote('</' . $tag . '>', '/') . '/is',
			$this->infoXml,
			$m
		)) {
			return '';
		}
		return (string)$m[1];
	}
}
