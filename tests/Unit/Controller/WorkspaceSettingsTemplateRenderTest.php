<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Controller;

use OCA\BudgetCheck\Service\WorkspaceSettingsSectionCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Renders every Workspace settings sub-page partial through real PHP includes
 * (no Nextcloud kernel) and asserts each fragment is self-contained.
 */
final class WorkspaceSettingsTemplateRenderTest extends TestCase
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
		$file = dirname(__DIR__, 3) . '/templates/parts/settings/' . $section . '.php';
		self::assertFileExists($file, "Partial for '{$section}' must exist");
		$_ = $vars;
		$l = $l10n ?? $this->l10n();
		$bcHtmlLang = 'en';
		ob_start();
		try {
			include $file;
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function householdWorkspace(bool $canManage = true): array
	{
		return [
			'workspace' => [
				'id' => 1,
				'type' => 'household',
				'name' => 'Home',
				'currencyCode' => 'EUR',
				'timezone' => 'Europe/Berlin',
			],
			'canManageWorkspace' => $canManage,
			'currencyChangeAllowed' => true,
			'clientHints' => ['htmlLang' => 'en', 'locale' => 'en'],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function projectWorkspace(bool $canManage = true): array
	{
		return [
			'workspace' => [
				'id' => 2,
				'type' => 'project',
				'name' => 'Build',
				'currencyCode' => 'EUR',
				'timezone' => 'Europe/Berlin',
			],
			'canManageWorkspace' => $canManage,
			'currencyChangeAllowed' => true,
			'clientHints' => ['htmlLang' => 'en', 'locale' => 'en'],
		];
	}

	public function testEverySectionPartialRendersAsAccessibleFragment(): void
	{
		foreach (WorkspaceSettingsSectionCatalog::SECTIONS as $section) {
			$vars = match ($section) {
				'planning-view', 'budget-defaults' => $this->householdWorkspace(),
				'booking-statuses' => $this->projectWorkspace(),
				'workspace', 'tax', 'categories', 'members', 'recurring', 'help' => $this->householdWorkspace(),
				default => $this->householdWorkspace(),
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

	public function testWorkspacePartialRendersHouseholdAndProjectBranches(): void
	{
		$household = $this->renderPartial('workspace', $this->householdWorkspace());
		self::assertStringContainsString('primaryPlanningYear', $household);
		self::assertStringContainsString('defaultSavingsTargetMode', $household);
		self::assertStringNotContainsString('projectStartDate', $household);

		$project = $this->renderPartial('workspace', $this->projectWorkspace());
		self::assertStringContainsString('projectStartDate', $project);
		self::assertStringContainsString('projectTotalCapMinor', $project);
		self::assertStringNotContainsString('primaryPlanningYear', $project);
	}

	public function testWorkspacePartialDisablesSavingsRadiosForNonManagers(): void
	{
		$html = $this->renderPartial('workspace', $this->householdWorkspace(false));
		self::assertSame(
			4,
			preg_match_all('/name="defaultSavingsTargetMode"[^>]*\sdisabled(?:\s|>)/', $html),
			'Non-managers must see disabled savings radios',
		);
		self::assertStringNotContainsString('Save workspace', $html);
	}

	public function testWorkspacePartialKeepsSavingsRadiosEnabledForManagers(): void
	{
		$html = $this->renderPartial('workspace', $this->householdWorkspace(true));
		self::assertSame(
			0,
			preg_match_all('/name="defaultSavingsTargetMode"[^>]*\sdisabled(?:\s|>)/', $html),
			'Managers must not have disabled savings radios',
		);
		self::assertStringContainsString('Save workspace', $html);
	}

	public function testWorkspacePartialOmitsPlanningViewLinkWhenUrlMissing(): void
	{
		$vars = $this->householdWorkspace(true);
		unset($vars['urls']);
		$html = $this->renderPartial('workspace', $vars);
		self::assertStringNotContainsString('href="#"', $html, 'Missing planning-view URL must never emit href="#"');
		self::assertDoesNotMatchRegularExpression('/<a[^>]*class="[^"]*bc-inline-link[^"]*"[^>]*>Your planning view<\/a>/', $html);
	}

	public function testPanelTitleHeadingsAreVisuallyHiddenWhenTheyDuplicateChromeH1(): void
	{
		$html = $this->renderPartial('categories', $this->householdWorkspace());
		self::assertMatchesRegularExpression(
			'/<h2[^>]*class="[^"]*bc-sr-only[^"]*"[^>]*>/',
			$html,
			'Categories panel H2 must be visually hidden (chrome already shows the page H1)',
		);
	}

	public function testCategoriesPartialShowsCreateForManagersOnly(): void
	{
		$manager = $this->renderPartial('categories', $this->householdWorkspace(true));
		self::assertStringContainsString('data-bc-action="open-create-category"', $manager);

		$viewer = $this->renderPartial('categories', $this->householdWorkspace(false));
		self::assertStringNotContainsString('data-bc-action="open-create-category"', $viewer);
		self::assertStringContainsString('bc-categories-title', $viewer);
	}

	public function testBookingStatusesPartialRespectsCanManage(): void
	{
		$manager = $this->renderPartial('booking-statuses', $this->projectWorkspace(true));
		self::assertStringContainsString('data-bc-action="open-create-booking-status"', $manager);
		self::assertStringContainsString('colspan="4"', $manager);

		$viewer = $this->renderPartial('booking-statuses', $this->projectWorkspace(false));
		self::assertStringNotContainsString('data-bc-action="open-create-booking-status"', $viewer);
		self::assertStringContainsString('colspan="3"', $viewer);
	}

	public function testMembersAndRecurringPartialsRenderInviteAndTableLandmarks(): void
	{
		$members = $this->renderPartial('members', $this->householdWorkspace());
		self::assertStringContainsString('bc-members-title', $members);
		self::assertStringContainsString('bc-member-invite-q', $members);
		self::assertStringContainsString('bc-group-invite-q', $members);

		$recurring = $this->renderPartial('recurring', $this->householdWorkspace());
		self::assertStringContainsString('bc-recurring-title', $recurring);
		self::assertStringContainsString('data-bc-action="open-create-recurring"', $recurring);
	}

	public function testManagerOnlyPartialsShowSoftDenialForViewers(): void
	{
		foreach (['members', 'recurring', 'budget-defaults'] as $section) {
			$html = $this->renderPartial($section, $this->householdWorkspace(false));
			self::assertStringContainsString('Managers only', $html, "'{$section}' must soft-deny viewers");
			self::assertStringNotContainsString('data-bc-action="member-invite-submit"', $html);
			self::assertStringNotContainsString('data-bc-action="open-create-recurring"', $html);
			self::assertStringNotContainsString('data-bc-action="save-budget-defaults"', $html);
		}
	}

	public function testHelpPartialRendersGlossaryAndBridge(): void
	{
		$html = $this->renderPartial('help');
		self::assertStringContainsString('id="bc-help-panels"', $html);
		self::assertStringContainsString('id="bc-glossary"', $html);
		self::assertStringContainsString('id="bc-spreadsheet-bridge"', $html);
	}

	public function testTranslatorOutputIsEscapedInTaxPartial(): void
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
		$html = $this->renderPartial('tax', $this->householdWorkspace(), $evil);
		self::assertStringNotContainsString('<script>alert(1)</script>', $html);
		self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
	}
}
