<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Controller;

use OCA\BudgetCheck\Service\AppSettingsSectionCatalog;
use OCA\BudgetCheck\Support\SupportUsLinks;
use PHPUnit\Framework\TestCase;

/**
 * Renders every App settings sub-page partial through real PHP includes (no
 * Nextcloud kernel) and asserts each fragment is self-contained.
 */
final class AppSettingsTemplateRenderTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		require_once dirname(__DIR__, 2) . '/Unit/Support/template_stubs.php';
	}

	private function l10n(): object
	{
		return new class {
			public function getLanguageCode(): string
			{
				return 'en';
			}

			/** @param array<int|string, mixed> $parameters */
			public function t(string $text, array $parameters = []): string
			{
				return $parameters === [] ? $text : vsprintf($text, $parameters);
			}
		};
	}

	/**
	 * @param array<string, mixed> $vars template payload ($_)
	 */
	private function renderPartial(string $section, array $vars = [], ?object $l10n = null): string
	{
		$file = dirname(__DIR__, 3) . '/templates/parts/app-settings/' . $section . '.php';
		self::assertFileExists($file, "Partial for '{$section}' must exist");
		$_ = $vars;
		$l = $l10n ?? $this->l10n();
		ob_start();
		try {
			include $file;
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	public function testEverySectionPartialRendersAsAccessibleFragment(): void
	{
		foreach (AppSettingsSectionCatalog::SECTIONS as $section) {
			$vars = match ($section) {
				'support' => [],
				default => [],
			};
			$html = $this->renderPartial($section, $vars);
			self::assertStringContainsString('<section', $html, "'{$section}' must render at least one section landmark");
			self::assertDoesNotMatchRegularExpression('/<h1[\s>]/', $html, "'{$section}' must not render an h1 (duplicate page title)");
			self::assertSame(
				substr_count($html, '<section'),
				substr_count($html, '</section>'),
				"'{$section}' has unbalanced <section> tags",
			);
			self::assertStringNotContainsString('<?php', $html, "'{$section}' leaked PHP open tags");
		}
	}

	public function testAccessPartialHasDirectoryAccessControls(): void
	{
		$html = $this->renderPartial('access');
		self::assertStringContainsString('data-bc-app-policy-scope="access"', $html);
		self::assertStringContainsString('bc-access-gate-title', $html);
		self::assertStringContainsString('bc-policy-users-q', $html);
		self::assertStringContainsString('bc-policy-groups-q', $html);
		self::assertStringNotContainsString('bc-policy-admins-q', $html);
		self::assertStringNotContainsString('bc-policy-timezone', $html);
	}

	public function testAdminsPartialHasAppAdminPicker(): void
	{
		$html = $this->renderPartial('admins');
		self::assertStringContainsString('data-bc-app-policy-scope="admins"', $html);
		self::assertStringContainsString('id="bc-policy-admins-q"', $html);
		self::assertStringContainsString('data-bc-app-admin-list', $html);
		self::assertStringContainsString('bc-entity-picker', $html);
		self::assertStringNotContainsString('bc-policy-users-q', $html);
	}

	public function testDefaultsPartialHasTimezoneAndCurrencyPickers(): void
	{
		$html = $this->renderPartial('defaults');
		self::assertStringContainsString('data-bc-app-policy-scope="defaults"', $html);
		self::assertStringContainsString('bc-policy-timezone-label', $html);
		self::assertStringContainsString('bc-policy-currency-label', $html);
		self::assertStringNotContainsString('bc-policy-admins-q', $html);
	}

	public function testSupportPartialRendersSupportUsSection(): void
	{
		$html = $this->renderPartial('support');
		self::assertStringContainsString('bc-support-us', $html);
		self::assertStringContainsString('id="bc-support-us-title"', $html);
		self::assertStringContainsString('Support &amp; us', $html);
		self::assertStringContainsString('data-support-us="1"', $html);
		self::assertStringNotContainsString('data-bc-app-policy-form', $html);
	}

	public function testTranslatorOutputIsEscapedInAccessPartial(): void
	{
		$evil = new class {
			public function getLanguageCode(): string
			{
				return 'en';
			}

			/** @param array<int|string, mixed> $parameters */
			public function t(string $text, array $parameters = []): string
			{
				return '<script>alert(1)</script>';
			}
		};
		$html = $this->renderPartial('access', [], $evil);
		self::assertStringNotContainsString('<script>alert(1)</script>', $html);
		self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
	}

	public function testSupportPartialUsesSupportUsLinksClass(): void
	{
		$src = (string) file_get_contents(dirname(__DIR__, 3) . '/templates/parts/app-settings/support.php');
		self::assertStringContainsString(SupportUsLinks::class, $src);
		self::assertStringContainsString('support-us-section.php', $src);
	}
}
