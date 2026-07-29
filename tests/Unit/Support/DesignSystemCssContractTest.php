<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * Pins the BudgetCheck design-system theming contract against regressions that
 * break dark mode / high-contrast / WCAG (hardcoded overlays, transparent tint
 * mixes, shell max-width traps, missing token file).
 */
final class DesignSystemCssContractTest extends TestCase {
	private string $appCss;
	private string $tokensCss;
	private string $importCss;
	private string $transactionsCss;

	protected function setUp(): void {
		parent::setUp();
		$root = dirname(__DIR__, 3);
		$this->appCss = (string) file_get_contents($root . '/css/app.css');
		$this->tokensCss = (string) file_get_contents($root . '/css/common/tokens.css');
		$this->importCss = (string) file_get_contents($root . '/css/import.css');
		$this->transactionsCss = (string) file_get_contents($root . '/css/transactions.css');
		self::assertNotSame('', $this->appCss);
		self::assertNotSame('', $this->tokensCss);
	}

	public function testAppImportsCanonicalTokens(): void {
		self::assertStringContainsString("@import url('common/tokens.css');", $this->appCss);
	}

	public function testMutedTokenPrefersNextcloudMaxContrast(): void {
		self::assertMatchesRegularExpression(
			'/--bc-muted:\s*var\(\s*--color-text-maxcontrast/s',
			$this->tokensCss,
			'--bc-muted must prefer Nextcloud --color-text-maxcontrast',
		);
	}

	public function testTintsMixIntoMainBackgroundNotTransparent(): void {
		self::assertMatchesRegularExpression(
			'/--bc-tint-info:\s*color-mix\([^;]*var\(--color-main-background\)/s',
			$this->tokensCss,
		);
		self::assertMatchesRegularExpression(
			'/--bc-tint-warning:\s*color-mix\([^;]*var\(--color-main-background\)/s',
			$this->tokensCss,
		);
		self::assertMatchesRegularExpression(
			'/--bc-tint-danger:\s*color-mix\([^;]*var\(--color-main-background\)/s',
			$this->tokensCss,
		);
		self::assertDoesNotMatchRegularExpression(
			'/--bc-tint-(?:info|warning|critical|household|project):\s*color-mix\([^;]*,\s*transparent\)/s',
			$this->tokensCss,
			'Tints must not mix into transparent (washes out on custom themes)',
		);
	}

	public function testThemeTokensLiveOnBodyAndAppSurfaces(): void {
		self::assertMatchesRegularExpression(
			'/body\s*,\s*#app-content\.bc-app/s',
			$this->tokensCss,
		);
		self::assertStringContainsString('.bc-modal', $this->tokensCss);
		self::assertStringContainsString('.bc-toasts', $this->tokensCss);
	}

	public function testNoHardcodedBlackOverlaysOrShadows(): void {
		$bundle = $this->appCss . "\n" . $this->tokensCss . "\n" . $this->importCss . "\n" . $this->transactionsCss;
		self::assertStringNotContainsString('rgba(0, 0, 0', $bundle);
		self::assertStringNotContainsString('color-mix(in srgb, #000', $bundle);
		self::assertStringNotContainsString('rgba(70, 186, 97', $bundle);
	}

	public function testShellHasNoFixedTwelveHundredCap(): void {
		self::assertDoesNotMatchRegularExpression(
			'/\.bc-shell\s*\{[^}]*max-width:\s*1200px/s',
			$this->appCss,
			'Shell must fill #app-content; reading measure belongs on prose only',
		);
		self::assertStringContainsString('max-width: none', $this->appCss);
	}

	public function testPageActionsKeepFortyFourPxHitTarget(): void {
		self::assertDoesNotMatchRegularExpression(
			'/\.bc-page-header__actions\s*\{[^}]*min-height:\s*36px/s',
			$this->appCss,
		);
		self::assertMatchesRegularExpression(
			'/\.bc-page-header__actions\s*\{[^}]*min-height:\s*44px/s',
			$this->appCss,
		);
	}

	public function testBadgeUsesLeadingDotCue(): void {
		self::assertStringContainsString('.bc-badge::before', $this->appCss);
	}

	public function testSafeAreaAndContrastGuardsExist(): void {
		self::assertStringContainsString('env(safe-area-inset-top', $this->appCss);
		self::assertStringContainsString('prefers-contrast: more', $this->appCss);
		self::assertStringContainsString('forced-colors: active', $this->appCss);
		self::assertStringContainsString('prefers-reduced-motion: reduce', $this->appCss);
	}

	public function testCanonicalBreakpointsPresentInTokens(): void {
		self::assertStringContainsString('--bc-breakpoint-sm: 480px', $this->tokensCss);
		self::assertStringContainsString('--bc-breakpoint-md: 768px', $this->tokensCss);
		self::assertStringContainsString('--bc-breakpoint-lg: 1024px', $this->tokensCss);
	}

	public function testBooleanControlsResetNextcloudMinHeightAndStayThemeSafe(): void {
		// NC core: input { min-height: var(--default-clickable-area) } stretches
		// bare checkboxes/radios under dark / high-contrast themes.
		self::assertMatchesRegularExpression(
			'/\.bc-boolean-control input\[type="checkbox"\][\s\S]*?min-height:\s*1\.25rem/s',
			$this->appCss,
			'.bc-boolean-control must reset min-height against NC core clickable-area',
		);
		self::assertMatchesRegularExpression(
			'/\.bc-boolean-control input\[type="checkbox"\][\s\S]*?background-color:\s*var\(--bc-bg-card/s',
			$this->appCss,
			'Checkbox fill must use theme surface tokens',
		);
		self::assertMatchesRegularExpression(
			'/\.bc-field--radio input\[type="radio"\][\s\S]*?min-height:\s*1\.25rem/s',
			$this->appCss,
			'Radio controls must reset min-height against NC core',
		);
		self::assertMatchesRegularExpression(
			'/\.bc-field--checkbox input\[type="checkbox"\][\s\S]*?min-height:\s*1\.25rem/s',
			$this->importCss,
			'Import checkbox fields must stay theme-safe',
		);
	}

	public function testGlossaryNeutralisesNextcloudCoreDtChrome(): void {
		self::assertMatchesRegularExpression(
			'/\.bc-glossary(?:__item)?\s+dt[^{]*\{[^}]*text-align:\s*start/s',
			$this->appCss,
			'Glossary DLs must force text-align: start against core dt end-align',
		);
		self::assertMatchesRegularExpression(
			'/\.bc-glossary(?:__item)?\s+dt[^{]*\{[^}]*width:\s*auto/s',
			$this->appCss,
			'Glossary DLs must clear core dt width: 130px',
		);
	}
}
