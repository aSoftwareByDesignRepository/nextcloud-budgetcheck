<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\WorkspaceService;
use OCA\BudgetCheck\Service\WorkspaceSettingsSectionCatalog;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for the Workspace settings sub-page catalog.
 */
final class WorkspaceSettingsSectionCatalogTest extends TestCase
{
	private WorkspaceSettingsSectionCatalog $catalog;

	protected function setUp(): void
	{
		parent::setUp();
		$this->catalog = new WorkspaceSettingsSectionCatalog();
	}

	private function l10n(): IL10N
	{
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(
			static fn (string $text, $parameters = []): string => 'T:' . $text,
		);
		return $l;
	}

	public function testDefaultSectionConstantIsWorkspaceAndListed(): void
	{
		self::assertSame('workspace', WorkspaceSettingsSectionCatalog::DEFAULT_SECTION);
		self::assertContains(WorkspaceSettingsSectionCatalog::DEFAULT_SECTION, WorkspaceSettingsSectionCatalog::SECTIONS);
	}

	public function testDefaultSectionIsTypeAware(): void
	{
		self::assertSame('planning-view', $this->catalog->defaultSection(WorkspaceService::TYPE_HOUSEHOLD));
		self::assertSame('workspace', $this->catalog->defaultSection(WorkspaceService::TYPE_PROJECT));
		self::assertSame('workspace', $this->catalog->defaultSection('unknown'));
	}

	public function testSectionsAreUniqueLowercaseSlugs(): void
	{
		$sections = WorkspaceSettingsSectionCatalog::SECTIONS;
		self::assertSame($sections, array_values(array_unique($sections)), 'Section slugs must be unique');
		self::assertCount(9, $sections);
		foreach ($sections as $section) {
			self::assertMatchesRegularExpression(
				'/^[a-z]+(-[a-z]+)*$/',
				$section,
				"Slug '{$section}' must be lowercase kebab-case (URL- and regex-safe)",
			);
		}
	}

	public function testIsSectionAcceptsEveryCatalogSlug(): void
	{
		foreach (WorkspaceSettingsSectionCatalog::SECTIONS as $section) {
			self::assertTrue($this->catalog->isSection($section), "isSection('{$section}') must be true");
		}
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function rejectedSectionProvider(): array
	{
		return [
			'empty string' => [''],
			'unknown slug' => ['nonsense'],
			'case variant' => ['Workspace'],
			'trailing whitespace' => ['workspace '],
			'leading whitespace' => [' workspace'],
			'path traversal' => ['../workspace'],
			'alternation injection' => ['workspace|tax'],
			'null byte' => ["workspace\0"],
			'legacy anchor id' => ['bc-categories-title'],
			'app-settings slug' => ['access'],
		];
	}

	/**
	 * @dataProvider rejectedSectionProvider
	 */
	public function testIsSectionRejectsInvalidInput(string $candidate): void
	{
		self::assertFalse($this->catalog->isSection($candidate));
	}

	public function testRouteRequirementIsPipeJoinedAllowlist(): void
	{
		$requirement = WorkspaceSettingsSectionCatalog::routeRequirement();
		self::assertSame(implode('|', WorkspaceSettingsSectionCatalog::SECTIONS), $requirement);
		self::assertMatchesRegularExpression('/^[a-z-]+(\|[a-z-]+)*$/', $requirement);
		foreach (WorkspaceSettingsSectionCatalog::SECTIONS as $section) {
			self::assertSame(
				1,
				preg_match('/^(?:' . $requirement . ')$/', $section),
				"Requirement regex must accept '{$section}'",
			);
		}
		self::assertSame(0, preg_match('/^(?:' . $requirement . ')$/', 'not-a-section'));
		self::assertSame(0, preg_match('/^(?:' . $requirement . ')$/', 'access'));
	}

	/**
	 * Exhaustive visibility matrix: every catalog section × household/project × manage/viewer.
	 *
	 * @return array<string, array{string, string, bool, bool}>
	 */
	public static function visibilityProvider(): array
	{
		$cases = [];
		$expected = [
			'planning-view' => ['household' => [true, true], 'project' => [false, false]],
			'workspace' => ['household' => [true, true], 'project' => [true, true]],
			'tax' => ['household' => [true, true], 'project' => [true, true]],
			'categories' => ['household' => [true, true], 'project' => [true, true]],
			'budget-defaults' => ['household' => [true, false], 'project' => [false, false]],
			'booking-statuses' => ['household' => [false, false], 'project' => [true, true]],
			'members' => ['household' => [true, false], 'project' => [true, false]],
			'recurring' => ['household' => [true, false], 'project' => [true, false]],
			'help' => ['household' => [true, true], 'project' => [true, true]],
		];
		foreach ($expected as $section => $byType) {
			foreach ($byType as $type => $manageViewer) {
				$cases["{$section}/{$type}/manager"] = [$section, $type, true, $manageViewer[0]];
				$cases["{$section}/{$type}/viewer"] = [$section, $type, false, $manageViewer[1]];
			}
		}
		return $cases;
	}

	/**
	 * @dataProvider visibilityProvider
	 */
	public function testIsVisibleMatchesMatrix(string $section, string $type, bool $canManage, bool $expected): void
	{
		self::assertSame(
			$expected,
			$this->catalog->isVisible($section, $type, $canManage),
			"isVisible('{$section}', '{$type}', " . ($canManage ? 'true' : 'false') . ')',
		);
	}

	public function testIsVisibleRejectsUnknownSection(): void
	{
		self::assertFalse($this->catalog->isVisible('nonsense', WorkspaceService::TYPE_HOUSEHOLD, true));
		self::assertFalse($this->catalog->isVisible('', WorkspaceService::TYPE_PROJECT, true));
	}

	public function testEveryLegacyAnchorMapsToAKnownSection(): void
	{
		self::assertNotSame([], WorkspaceSettingsSectionCatalog::LEGACY_ANCHORS);
		foreach (WorkspaceSettingsSectionCatalog::LEGACY_ANCHORS as $anchor => $section) {
			self::assertTrue(
				$this->catalog->isSection($section),
				"Legacy anchor '{$anchor}' targets unknown section '{$section}'",
			);
		}
	}

	public function testEverySectionIsReachableFromALegacyAnchor(): void
	{
		$targets = array_values(array_unique(array_values(WorkspaceSettingsSectionCatalog::LEGACY_ANCHORS)));
		sort($targets);
		$sections = WorkspaceSettingsSectionCatalog::SECTIONS;
		sort($sections);
		self::assertSame($sections, $targets, 'Every section owns at least one legacy anchor');
	}

	public function testLabelsArePinnedAndTranslated(): void
	{
		$l = $this->l10n();
		$expected = [
			'planning-view' => 'T:Your planning view',
			'workspace' => 'T:Workspace details',
			'tax' => 'T:Tax mode',
			'categories' => 'T:Categories',
			'budget-defaults' => 'T:Default category budgets',
			'booking-statuses' => 'T:Booking statuses',
			'members' => 'T:Members',
			'recurring' => 'T:Recurring rules',
			'help' => 'T:Help and glossary',
		];
		self::assertSame(array_keys($expected), WorkspaceSettingsSectionCatalog::SECTIONS);
		foreach ($expected as $section => $label) {
			self::assertSame($label, $this->catalog->label($l, $section));
		}
	}

	public function testNavLabelsAreShortPinnedAndTranslated(): void
	{
		$l = $this->l10n();
		$expected = [
			'planning-view' => 'T:Planning view',
			'workspace' => 'T:Workspace',
			'tax' => 'T:Tax',
			'categories' => 'T:Categories',
			'budget-defaults' => 'T:Budget defaults',
			'booking-statuses' => 'T:Booking statuses',
			'members' => 'T:Members',
			'recurring' => 'T:Recurring',
			'help' => 'T:Help',
		];
		self::assertSame(array_keys($expected), WorkspaceSettingsSectionCatalog::SECTIONS);
		foreach ($expected as $section => $label) {
			$nav = $this->catalog->navLabel($l, $section);
			self::assertSame($label, $nav);
			$page = $this->catalog->label($l, $section);
			self::assertLessThanOrEqual(
				strlen($page),
				strlen($nav),
				"navLabel('{$section}') must not be longer than label()",
			);
		}
		self::assertSame('T:Workspace settings', $this->catalog->navLabel($l, 'nonsense'));
	}

	public function testLabelFallsBackToWorkspaceSettingsForUnknownSection(): void
	{
		self::assertSame('T:Workspace settings', $this->catalog->label($this->l10n(), 'nonsense'));
		self::assertSame('T:Workspace settings', $this->catalog->label($this->l10n(), ''));
	}

	public function testHelpTextsAreDistinctTranslatedAndSectionSpecific(): void
	{
		$l = $this->l10n();
		$fingerprints = [
			'planning-view' => 'for you only',
			'workspace' => 'currency and timezone',
			'tax' => 'net/gross/VAT',
			'categories' => 'not bank hierarchy',
			'budget-defaults' => 'baseline for months',
			'booking-statuses' => 'Project-only workflow',
			'members' => 'manager, contributor, or viewer',
			'recurring' => 'fixed interval or on specific dates',
		];
		$seen = [];
		foreach ($fingerprints as $section => $fingerprint) {
			$help = $this->catalog->help($l, $section);
			self::assertStringStartsWith('T:', $help, "help('{$section}') must be translated");
			self::assertStringContainsString($fingerprint, $help, "help('{$section}') lost its section-specific copy");
			self::assertNotContains($help, $seen, "help('{$section}') duplicates another section's copy");
			$seen[] = $help;
		}
	}

	public function testHelpIsEmptyForSelfDescribingPanelsAndUnknown(): void
	{
		$l = $this->l10n();
		self::assertSame('', $this->catalog->help($l, 'help'), 'Help panel ships its own intro');
		self::assertSame('', $this->catalog->help($l, 'nonsense'));
	}
}
