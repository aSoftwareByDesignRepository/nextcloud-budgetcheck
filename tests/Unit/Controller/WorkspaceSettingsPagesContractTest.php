<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Controller;

use OCA\BudgetCheck\Service\WorkspaceSettingsSectionCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Cross-artifact drift protection for the split Workspace settings sub-pages.
 */
final class WorkspaceSettingsPagesContractTest extends TestCase
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

	public function testLegacySettingsRouteIsPreserved(): void
	{
		$route = self::routeByName('page#settings');
		self::assertSame('/settings', $route['url']);
		self::assertSame('GET', $route['verb']);
	}

	public function testAppSettingsRoutesRemainSeparate(): void
	{
		$index = self::routeByName('page#appSettings');
		self::assertSame('/app-settings', $index['url']);
		$section = self::routeByName('page#appSettingsSection');
		self::assertSame('/app-settings/{section}', $section['url']);
	}

	public function testSectionRouteRequirementMatchesCatalog(): void
	{
		$route = self::routeByName('page#settingsSection');
		self::assertSame('/settings/{section}', $route['url']);
		self::assertSame('GET', $route['verb']);
		self::assertSame(
			WorkspaceSettingsSectionCatalog::routeRequirement(),
			$route['requirements']['section'] ?? null,
			'Route allowlist drifted from WorkspaceSettingsSectionCatalog::routeRequirement()',
		);
	}

	public function testDispatcherMapCoversExactlyTheCatalogInOrder(): void
	{
		$dispatcher = self::read('templates/settings.php');
		self::assertSame(
			1,
			preg_match('/\$bcSettingsSectionFiles\s*=\s*\[(.*?)\];/s', $dispatcher, $m),
			'Dispatcher must declare the literal slug → file map',
		);
		preg_match_all("/'([a-z-]+)'\s*=>\s*'([a-z.-]+)'/", $m[1], $pairs, PREG_SET_ORDER);
		$map = [];
		foreach ($pairs as $pair) {
			$map[$pair[1]] = $pair[2];
		}
		self::assertSame(WorkspaceSettingsSectionCatalog::SECTIONS, array_keys($map), 'Dispatcher slugs drifted from the catalog');
		foreach ($map as $slug => $file) {
			self::assertSame($slug . '.php', $file, 'Dispatcher file names must mirror slugs (auditability)');
			self::assertFileExists(
				self::appRoot() . '/templates/parts/settings/' . $file,
				"Partial for section '{$slug}' is missing",
			);
		}
	}

	public function testDispatcherFailsClosedAndNeverBuildsPathsFromInput(): void
	{
		$dispatcher = self::read('templates/settings.php');
		self::assertStringContainsString(
			'if (!isset($bcSettingsSectionFiles[$bcRequestedSection]))',
			$dispatcher,
			'Unknown sections must fail closed before include',
		);
		self::assertStringContainsString(
			'BudgetCheck workspace settings: unknown section reached the template dispatcher.',
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
				['$bcSettingsSectionFiles[$bcRequestedSection]', 'isset($bcSettingsSectionFiles[$bcRequestedSection])'],
				['', ''],
				$dispatcher,
			),
			'The request value must never be concatenated into an include path',
		);
	}

	/**
	 * @return array<string, string>
	 */
	private static function jsAnchorSections(): array
	{
		$js = self::read('js/workspace-settings-legacy-redirect.js');
		self::assertSame(
			1,
			preg_match('/ANCHOR_SECTIONS\s*=\s*Object\.freeze\(\{(.*?)\}\)/s', $js, $m),
			'workspace-settings-legacy-redirect.js must declare a frozen ANCHOR_SECTIONS map',
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
			WorkspaceSettingsSectionCatalog::LEGACY_ANCHORS,
			self::jsAnchorSections(),
			'js/workspace-settings-legacy-redirect.js drifted from WorkspaceSettingsSectionCatalog::LEGACY_ANCHORS',
		);
	}

	public function testSettingsJsConsumesTheLegacyRedirectBeforeWiringSections(): void
	{
		$js = self::read('js/settings.js');
		self::assertStringContainsString('BudgetCheckWorkspaceSettingsLegacyRedirect', $js);
		self::assertStringContainsString('window.location.replace(redirectUrl)', $js);
		self::assertMatchesRegularExpression(
			'/window\.location\.replace\(redirectUrl\);\s*return;/',
			$js,
			'Boot must stop after scheduling the redirect (no wasted requests)',
		);
		self::assertStringContainsString("getAttribute('data-bc-settings-section')", $js);
	}

	public function testPageControllerShipsTheRedirectScriptWithSettingsPages(): void
	{
		$controller = self::read('lib/Controller/PageController.php');
		self::assertMatchesRegularExpression(
			"/if \(\\\$pageScript === 'settings'\) \{\s*Util::addScript\(Application::APP_ID, 'workspace-settings-legacy-redirect'\);/s",
			$controller,
			'PageController must load workspace-settings-legacy-redirect.js on settings pages',
		);
		$legacyPos = strpos($controller, "'workspace-settings-legacy-redirect'");
		$pageScriptPos = strpos($controller, 'Util::addScript(Application::APP_ID, $pageScript)');
		self::assertNotFalse($legacyPos);
		self::assertNotFalse($pageScriptPos);
		self::assertLessThan($pageScriptPos, $legacyPos, 'Legacy redirect must be registered before settings.js');
	}

	public function testControllerRedirectsInvisibleSectionsAndReturnsNotFoundForUnknown(): void
	{
		$controller = self::read('lib/Controller/PageController.php');
		self::assertStringContainsString('function settingsSection(string $section)', $controller);
		self::assertStringContainsString('isVisible($section, $workspaceType, $canManage)', $controller);
		self::assertStringContainsString('NotFoundResponse', $controller);
		self::assertStringContainsString('defaultSection($workspaceType)', $controller);
		self::assertStringContainsString("'settingsSections'", $controller);
	}

	public function testControllerEmitsOnlyVisibleSettingsSectionUrls(): void
	{
		$controller = self::read('lib/Controller/PageController.php');
		self::assertStringContainsString(
			'Only emit URLs the current role/type may open',
			$controller,
			'data-bc-urls.settingsSections must be filtered by isVisible',
		);
		self::assertMatchesRegularExpression(
			'/foreach \(WorkspaceSettingsSectionCatalog::SECTIONS as \$sectionId\) \{\s*'
			. 'if \(!\$this->workspaceSettingsSections->isVisible\(\$sectionId, \$workspaceTypeForUrls, \$canManage\)\) \{\s*'
			. 'continue;/s',
			$controller,
		);
	}

	public function testManagerOnlyPartialsShipSoftDenialCards(): void
	{
		foreach (['members', 'recurring', 'budget-defaults'] as $section) {
			$partial = self::read('templates/parts/settings/' . $section . '.php');
			self::assertStringContainsString(
				"\$canManage = !empty(\$_['canManageWorkspace']);",
				$partial,
				"{$section} must check canManageWorkspace before rendering manager UI",
			);
			self::assertStringContainsString(
				'Managers only',
				$partial,
				"{$section} must include a soft denial card for defense in depth",
			);
		}
	}

	public function testEveryLegacyAnchorTargetStillExistsInItsOwningPartial(): void
	{
		foreach (WorkspaceSettingsSectionCatalog::LEGACY_ANCHORS as $anchor => $section) {
			$haystack = self::read('templates/parts/settings/' . $section . '.php');
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
		self::assertStringContainsString("\$urls['settingsSections']", $nav, 'Sub-nav URLs must come from the controller (catalog)');
		self::assertStringContainsString("\$_['settingsSectionLabels']", $nav);
		self::assertStringContainsString("(\$item['id'] ?? '') === 'settings'", $nav);
		self::assertStringContainsString('bc-nav__sublist', $nav);
		self::assertStringContainsString('bc-nav__sublink', $nav);
	}

	public function testInPageSettingsNavIsIncludedBeforeSectionDispatch(): void
	{
		$dispatcher = self::read('templates/settings.php');
		$navInclude = strpos($dispatcher, "include __DIR__ . '/parts/settings-nav.php'");
		$sectionInclude = strpos($dispatcher, "include __DIR__ . '/parts/settings/'");
		self::assertNotFalse($navInclude, 'settings.php must include the in-page chip bar');
		self::assertNotFalse($sectionInclude);
		self::assertGreaterThan($navInclude, $sectionInclude, 'Chip bar must render above the section body');

		$nav = self::read('templates/parts/settings-nav.php');
		self::assertStringContainsString('bc-settings-nav', $nav);
		self::assertStringContainsString('bc-settings-nav__link', $nav);
		self::assertStringContainsString('id="bc-settings-pages"', $nav);
		self::assertStringContainsString("\$_['settingsSectionLabels']", $nav);
		self::assertStringContainsString("\$_['urls']['settingsSections']", $nav);
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
			'$this->workspaceSettingsSections->navLabel($this->l10n, $sectionId)',
			$controller,
			'Sidebar/chip labels must use navLabel() (short names)',
		);
		self::assertStringContainsString(
			'$this->workspaceSettingsSections->label($this->l10n, $section)',
			$controller,
			'Page H1 must keep the longer label()',
		);
		self::assertStringContainsString('settingsSection', $controller);
		self::assertStringContainsString('workspaceId', $controller);
	}

	public function testPageChromeExposesTheCurrentSectionToClientScripts(): void
	{
		$pageStart = self::read('templates/common/page-start.php');
		self::assertStringContainsString('data-bc-settings-section', $pageStart);
		self::assertStringContainsString("\$pageId === 'settings'", $pageStart);
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

	public function testSavingsRadiosCarryDisabledForNonManagers(): void
	{
		$html = self::read('templates/parts/settings/workspace.php');
		self::assertSame(
			4,
			preg_match_all(
				'/name="defaultSavingsTargetMode"[^>]*<\?php p\(\$canManage \? \'\' : \'disabled\'\); \?>/',
				$html,
			),
			'All four savings-target radios must gate disabled on !canManage',
		);
	}

	public function testComponentsDeepLinkPrefersCategoriesSectionUrl(): void
	{
		$js = self::read('js/common/components.js');
		self::assertStringContainsString('settingsSections.categories', $js);
		self::assertStringContainsString('#bc-categories-title', $js);
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
	}
}
