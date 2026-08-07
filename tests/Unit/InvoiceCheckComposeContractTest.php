<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * WP-ECO-BC: soft InvoiceCheck deep links on project workspace dashboard.
 * BudgetCheck stays standalone — links default null / hidden when IC disabled.
 */
final class InvoiceCheckComposeContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 2);
	}

	public function testPageControllerSoftGatesInvoiceCheckUrls(): void
	{
		$src = (string) file_get_contents($this->root . '/lib/Controller/PageController.php');
		$this->assertStringContainsString("'invoicingCheckCreateUrl' => null", $src);
		$this->assertStringContainsString("'invoicingCheckReceivablesUrl' => null", $src);
		$this->assertStringContainsString("isEnabledForUser('invoicecheck')", $src);
		$this->assertStringContainsString("=== 'project'", $src);
		$this->assertStringContainsString('invoicecheck.page.createForm', $src);
		$this->assertStringContainsString('invoicecheck.page.receivables', $src);
		$this->assertStringContainsString("'workspaceId' => (int) \$selected['id']", $src);
		$this->assertStringContainsString('catch (\\Throwable)', $src);
	}

	public function testDashboardTemplateRendersComposeStripWhenUrlsPresent(): void
	{
		$tpl = (string) file_get_contents($this->root . '/templates/dashboard.php');
		$this->assertStringContainsString('bc-ic-compose', $tpl);
		$this->assertStringContainsString('invoicingCheckCreateUrl', $tpl);
		$this->assertStringContainsString('Create invoice (InvoiceCheck)', $tpl);
		$this->assertStringContainsString('Open receivables (InvoiceCheck)', $tpl);
		$this->assertStringContainsString("\$workspace['type'] === 'project'", $tpl);
	}

	public function testNoHardDependencyOnInvoiceCheckInInfoXml(): void
	{
		$xml = (string) file_get_contents($this->root . '/appinfo/info.xml');
		$this->assertSame(1, preg_match('/<dependencies>(.*?)<\/dependencies>/is', $xml, $m));
		$hard = (string) ($m[1] ?? '');
		$this->assertDoesNotMatchRegularExpression(
			'/<app\b[^>]*>\s*invoicecheck\s*<\/app>/i',
			$hard,
			'BudgetCheck must not hard-require InvoiceCheck'
		);
	}
}
