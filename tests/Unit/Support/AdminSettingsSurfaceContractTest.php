<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * Static contract: the admin settings surface (rendered inside Nextcloud's
 * /settings/admin/additional, outside the app shell) must load its own scoped
 * stylesheet, and that sheet must neutralise the core definition-list pin
 * (dt { width:130px; white-space:nowrap; text-align:end }) that otherwise
 * clips admin-summary labels into their neighbouring values.
 */
final class AdminSettingsSurfaceContractTest extends TestCase {
	private function root(): string {
		return dirname(__DIR__, 3);
	}

	public function testGetFormWiresScopedStyles(): void {
		$src = file_get_contents($this->root() . '/lib/Settings/AdminSettings.php');
		self::assertNotFalse($src);
		self::assertMatchesRegularExpression(
			"/Util::addStyle\(Application::APP_ID, 'common\/tokens'\)/",
			$src,
			'Admin settings surface needs the token block for --bc-* vars',
		);
		self::assertMatchesRegularExpression(
			"/Util::addStyle\(Application::APP_ID, 'admin-settings'\)/",
			$src,
			'Admin settings surface needs its scoped stylesheet — app.css is not loaded there',
		);
	}

	public function testScopedSheetNeutralisesCoreDtPin(): void {
		$path = $this->root() . '/css/admin-settings.css';
		self::assertFileExists($path);
		$css = (string)file_get_contents($path);
		foreach (['width: auto', 'white-space: normal', 'text-align: start', 'display: block'] as $needle) {
			self::assertMatchesRegularExpression(
				'/\.bc-admin-settings \.bc-admin-summary (?:dt|dd)[^}]*' . preg_quote($needle, '/') . '/s',
				$css,
				'admin-summary dt/dd must defeat the core ' . $needle . ' pin',
			);
		}
	}

	public function testScopedSheetCarriesNoBareElementSelectors(): void {
		$css = (string)file_get_contents($this->root() . '/css/admin-settings.css');
		self::assertNotSame('', $css);
		// Strip comments, then require every rule selector to start with a
		// class/id scope or an at-rule — bare elements would leak onto core
		// settings chrome.
		$css = preg_replace('!/\*.*?\*/!s', '', $css);
		preg_match_all('/(^|\})\s*([^{}]+)\s*\{/m', (string)$css, $m);
		foreach ($m[2] as $selector) {
			$selector = trim($selector);
			if ($selector === '') {
				continue;
			}
			foreach (explode(',', $selector) as $part) {
				$part = trim($part);
				self::assertMatchesRegularExpression(
					'/^[.#:@\[]/',
					$part,
					'Unscoped selector leaks onto core settings chrome: ' . $part,
				);
			}
		}
	}

	public function testTemplateUsesScopedSummaryList(): void {
		$src = file_get_contents($this->root() . '/templates/admin-settings.php');
		self::assertNotFalse($src);
		self::assertStringContainsString('bc-admin-settings', $src);
		self::assertStringContainsString('bc-admin-summary', $src);
		self::assertStringContainsString('<dl', $src);
	}
}
