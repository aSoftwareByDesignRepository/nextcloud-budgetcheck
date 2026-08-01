<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Controller;

use OCA\BudgetCheck\Service\AppSettingsSectionCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Cross-artifact drift protection for the split App settings sub-pages.
 */
final class AppSettingsPagesContractTest extends TestCase
{
	private static function appRoot(): string
	{
		return dirname(__DIR__, 3);
	}

	private static function read(string $relative): string
	{
		$path = self::appRoot() . '/' . $relative;
		self::assertFileExists($path);
		return (string) file_get_contents($path);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function routes(): array
	{
		$config = require self::appRoot() . '/appinfo/routes.php';
		self::assertIsArray($config['routes'] ?? null);
		return $config['routes'];
	}

	private static function routeByName(string $name): array
	{
		foreach (self::routes() as $route) {
			if (($route['name'] ?? '') === $name) {
				return $route;
			}
		}
		self::fail("Route '{$name}' is not registered");
	}

	public function testLegacyAppSettingsRouteIsPreserved(): void
	{
		$route = self::routeByName('page#appSettings');
		self::assertSame('/app-settings', $route['url']);
		self::assertSame('GET', $route['verb']);
	}

	public function testWorkspaceSettingsRouteUntouched(): void
	{
		$route = self::routeByName('page#settings');
		self::assertSame('/settings', $route['url']);
		self::assertSame('GET', $route['verb']);
	}

	public function testSectionRouteRequirementMatchesCatalog(): void
	{
		$route = self::routeByName('page#appSettingsSection');
		self::assertSame('/app-settings/{section}', $route['url']);
		self::assertSame('GET', $route['verb']);
		self::assertSame(
			AppSettingsSectionCatalog::routeRequirement(),
			$route['requirements']['section'] ?? null,
			'Route allowlist drifted from AppSettingsSectionCatalog::routeRequirement()',
		);
	}

	public function testDispatcherMapCoversExactlyTheCatalogInOrder(): void
	{
		$dispatcher = self::read('templates/app-settings.php');
		self::assertSame(
			1,
			preg_match('/\$bcAppSettingsSectionFiles\s*=\s*\[(.*?)\];/s', $dispatcher, $m),
			'Dispatcher must declare the literal slug → file map',
		);
		preg_match_all("/'([a-z-]+)'\s*=>\s*'([a-z.-]+)'/", $m[1], $pairs, PREG_SET_ORDER);
		$map = [];
		foreach ($pairs as $pair) {
			$map[$pair[1]] = $pair[2];
		}
		self::assertSame(AppSettingsSectionCatalog::SECTIONS, array_keys($map), 'Dispatcher slugs drifted from the catalog');
		foreach ($map as $slug => $file) {
			self::assertSame($slug . '.php', $file, 'Dispatcher file names must mirror slugs (auditability)');
			self::assertFileExists(
				self::appRoot() . '/templates/parts/app-settings/' . $file,
				"Partial for section '{$slug}' is missing",
			);
		}
	}

	public function testDispatcherFailsClosedAndNeverBuildsPathsFromInput(): void
	{
		$dispatcher = self::read('templates/app-settings.php');
		self::assertStringContainsString(
			'if (!isset($bcAppSettingsSectionFiles[$bcRequestedSection]))',
			$dispatcher,
			'Unknown sections must fail closed before include',
		);
		self::assertStringContainsString(
			'BudgetCheck app settings: unknown section reached the template dispatcher.',
			$dispatcher,
			'Unknown sections must throw rather than soft-fall to another page',
		);
		self::assertStringNotContainsString(
			'$bcRequestedSection . ',
			$dispatcher,
			'The request value must never be concatenated into an include path',
		);
		self::assertStringNotContainsString(
			' . $bcRequestedSection',
			str_replace(
				['$bcAppSettingsSectionFiles[$bcRequestedSection]', 'isset($bcAppSettingsSectionFiles[$bcRequestedSection])'],
				['', ''],
				$dispatcher,
			),
			'The request value must never be concatenated into an include path',
		);
	}

	public function testDispatcherKeepsSoftDenialCard(): void
	{
		$dispatcher = self::read('templates/app-settings.php');
		self::assertStringContainsString('if (!$canAdminApp)', $dispatcher);
		self::assertStringContainsString('You do not have permission to open app settings.', $dispatcher);
	}

	/**
	 * @return array<string, string>
	 */
	private static function jsAnchorSections(): array
	{
		$js = self::read('js/app-settings-legacy-redirect.js');
		self::assertSame(
			1,
			preg_match('/ANCHOR_SECTIONS\s*=\s*Object\.freeze\(\{(.*?)\}\)/s', $js, $m),
			'app-settings-legacy-redirect.js must declare a frozen ANCHOR_SECTIONS map',
		);
		preg_match_all("/'([a-z0-9-]+)'\s*:\s*'([a-z-]+)'/", $m[1], $pairs, PREG_SET_ORDER);
		$map = [];
		foreach ($pairs as $pair) {
			self::assertArrayNotHasKey($pair[1], $map, "Duplicate anchor '{$pair[1]}' in JS map");
			$map[$pair[1]] = $pair[2];
		}
		return $map;
	}

	public function testJsAnchorMapMirrorsCatalogLegacyAnchorsExactly(): void
	{
		self::assertSame(
			AppSettingsSectionCatalog::LEGACY_ANCHORS,
			self::jsAnchorSections(),
			'js/app-settings-legacy-redirect.js drifted from AppSettingsSectionCatalog::LEGACY_ANCHORS',
		);
	}

	public function testAppSettingsJsConsumesTheLegacyRedirectBeforeWiringSections(): void
	{
		$js = self::read('js/app-settings.js');
		self::assertStringContainsString('BudgetCheckAppSettingsLegacyRedirect', $js);
		self::assertStringContainsString('window.location.replace(redirectUrl)', $js);
		self::assertMatchesRegularExpression(
			'/window\.location\.replace\(redirectUrl\);\s*return;/',
			$js,
			'Boot must stop after scheduling the redirect (no wasted requests)',
		);
	}

	public function testPageControllerShipsTheRedirectScriptWithAppSettingsPages(): void
	{
		$controller = self::read('lib/Controller/PageController.php');
		self::assertMatchesRegularExpression(
			"/if \(\\\$pageScript === 'app-settings'\) \{\s*Util::addScript\(Application::APP_ID, 'app-settings-legacy-redirect'\);/s",
			$controller,
			'PageController must load app-settings-legacy-redirect.js on app-settings pages',
		);
		$legacyPos = strpos($controller, "'app-settings-legacy-redirect'");
		$pageScriptPos = strpos($controller, 'Util::addScript(Application::APP_ID, $pageScript)');
		self::assertNotFalse($legacyPos);
		self::assertNotFalse($pageScriptPos);
		self::assertLessThan($pageScriptPos, $legacyPos, 'Legacy redirect must be registered before app-settings.js');
	}

	public function testEveryLegacyAnchorTargetStillExistsInItsOwningPartial(): void
	{
		$sharedPartialsBySection = [
			'support' => 'templates/parts/support-us-section.php',
		];
		foreach (AppSettingsSectionCatalog::LEGACY_ANCHORS as $anchor => $section) {
			$haystack = self::read('templates/parts/app-settings/' . $section . '.php');
			if (isset($sharedPartialsBySection[$section])) {
				$haystack .= self::read($sharedPartialsBySection[$section]);
			}
			if ($anchor === 'bc-support-us' || $anchor === 'bc-support-us-title') {
				self::assertStringContainsString(
					"'-support-us'",
					$haystack,
					"Anchor '{$anchor}' generator disappeared",
				);
				continue;
			}
			if ($anchor === 'bc-policy-admins-q') {
				self::assertMatchesRegularExpression(
					'/\sid="' . preg_quote($anchor, '/') . '"/',
					$haystack,
					"Anchor #{$anchor} must exist on the '{$section}' sub-page",
				);
				continue;
			}
			self::assertMatchesRegularExpression(
				'/\sid="' . preg_quote($anchor, '/') . '"/',
				$haystack,
				"Anchor #{$anchor} must exist on the '{$section}' sub-page so the forwarded fragment still scrolls",
			);
		}
	}

	public function testNavigationBuildsSubListFromControllerData(): void
	{
		$nav = self::read('templates/common/navigation.php');
		self::assertStringContainsString("\$urls['appSettingsSections']", $nav, 'Sub-nav URLs must come from the controller (catalog)');
		self::assertStringContainsString('bc-nav__sublist', $nav);
		self::assertStringContainsString('bc-nav__sublink', $nav);
		self::assertStringContainsString('$parentAriaCurrent = $active && $children === [];', $nav);
		self::assertMatchesRegularExpression(
			'/if \(\$childActive\): \?>aria-current="page"/',
			$nav,
			'Active app-settings sub-page must carry aria-current="page"',
		);
	}

	public function testInPageSettingsNavIsIncludedBeforeSectionDispatch(): void
	{
		$dispatcher = self::read('templates/app-settings.php');
		$navInclude = strpos($dispatcher, "include __DIR__ . '/parts/app-settings-nav.php'");
		$sectionInclude = strpos($dispatcher, "include __DIR__ . '/parts/app-settings/'");
		self::assertNotFalse($navInclude, 'app-settings.php must include the in-page chip bar');
		self::assertNotFalse($sectionInclude);
		self::assertGreaterThan($navInclude, $sectionInclude, 'Chip bar must render above the section body');

		$nav = self::read('templates/parts/app-settings-nav.php');
		self::assertStringContainsString('bc-settings-nav', $nav);
		self::assertStringContainsString('bc-settings-nav__link', $nav);
		self::assertStringContainsString('id="bc-app-settings-pages"', $nav);
		self::assertStringContainsString("\$_['appSettingsSectionLabels']", $nav);
		self::assertStringContainsString("\$_['urls']['appSettingsSections']", $nav);
		self::assertStringContainsString("if (\$href === '' || \$href === '#')", $nav, 'Chip bar must never emit href="#"');
		self::assertMatchesRegularExpression(
			'/if \(\$active\): \?>aria-current="page"/',
			$nav,
			'Active chip must carry aria-current="page"',
		);
	}

	public function testPageControllerFeedsNavLabelsNotPageTitlesIntoTheSidebar(): void
	{
		$controller = self::read('lib/Controller/PageController.php');
		self::assertStringContainsString(
			'$this->appSettingsSections->navLabel($this->l10n, $sectionId)',
			$controller,
			'Sidebar/chip labels must use navLabel() (short DeskCheck-style names)',
		);
		self::assertStringContainsString(
			'$this->appSettingsSections->label($this->l10n, $section)',
			$controller,
			'Page H1 must keep the longer label()',
		);
		self::assertStringContainsString('RedirectResponse', $controller);
		self::assertStringContainsString('appSettingsSection', $controller);
		self::assertStringContainsString('workspaceId', $controller);
		self::assertStringContainsString("'appSettingsSections'", $controller);
		self::assertStringContainsString(
			'// Only emit app-settings section URLs to app admins',
			$controller,
			'App settings section URLs must only be emitted for app admins',
		);
	}

	public function testPageChromeExposesTheCurrentSectionToClientScripts(): void
	{
		$pageStart = self::read('templates/common/page-start.php');
		self::assertStringContainsString('data-bc-app-settings-section', $pageStart);
		self::assertStringContainsString("\$pageId === 'app-settings'", $pageStart);
		self::assertStringContainsString("\$_['settingsSection'] ?? ''", $pageStart);
	}

	public function testPageChromeRendersTheParentBreadcrumbForSubPages(): void
	{
		$pageStart = self::read('templates/common/page-start.php');
		self::assertStringContainsString('bc-breadcrumb__parent', $pageStart);
		self::assertStringContainsString("\$_['breadcrumbParent']", $pageStart);
	}

	public function testPolicyFormsDeclareMergeScopeAttributes(): void
	{
		foreach (['access' => 'access', 'admins' => 'admins', 'defaults' => 'defaults'] as $section => $scope) {
			$html = self::read('templates/parts/app-settings/' . $section . '.php');
			self::assertStringContainsString('data-bc-app-policy-form', $html);
			self::assertStringContainsString('data-bc-app-policy-scope="' . $scope . '"', $html);
		}
		$support = self::read('templates/parts/app-settings/support.php');
		self::assertStringNotContainsString('data-bc-app-policy-form', $support);
	}

	public function testMergeHelperIsExportedForTests(): void
	{
		$js = self::read('js/app-settings.js');
		self::assertStringContainsString('function buildAppPolicySavePayload', $js);
		self::assertStringContainsString('BudgetCheckAppSettingsPolicyMerge', $js);
		self::assertStringContainsString("Api.get('/apps/budgetcheck/api/admin/policy')", $js);
	}

	public function testActiveChipUsesMainTextInk(): void
	{
		$css = self::read('css/app.css');
		self::assertStringContainsString('.bc-settings-nav', $css);
		self::assertStringContainsString('.bc-nav__sublist', $css);
		self::assertMatchesRegularExpression(
			'/\.bc-app \.bc-settings-nav__link\[aria-current="page"\]\s*\{[^}]*color:\s*var\(--color-main-text/s',
			$css,
		);
		self::assertMatchesRegularExpression(
			'/\.bc-app \.bc-settings-nav__link\s*\{[^}]*min-height:\s*44px/s',
			$css,
		);
		self::assertMatchesRegularExpression(
			'/\.bc-app \.bc-settings-nav__link:focus-visible\s*\{[^}]*outline/s',
			$css,
		);
	}
}
