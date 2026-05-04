<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\LocaleFormatService;
use PHPUnit\Framework\TestCase;

class LocaleFormatServiceHtmlLangTest extends TestCase
{
	public function testBareGermanBecomesDeDe(): void
	{
		self::assertSame('de-DE', LocaleFormatService::canonicalHtmlLangFromLocaleString('de'));
	}

	public function testUnderscoreLocaleNormalized(): void
	{
		self::assertSame('de-DE', LocaleFormatService::canonicalHtmlLangFromLocaleString('de_DE'));
		self::assertSame('en-US', LocaleFormatService::canonicalHtmlLangFromLocaleString('en_US'));
	}

	public function testBareEnglishBecomesEnGb(): void
	{
		self::assertSame('en-GB', LocaleFormatService::canonicalHtmlLangFromLocaleString('en'));
	}
}
