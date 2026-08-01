<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

use OCP\IL10N;

/**
 * Single source of truth for the split Workspace settings sub-pages.
 *
 * Every artifact that knows about workspace-settings sections derives from this class:
 *  - appinfo/routes.php pins its `{section}` requirement to {@see routeRequirement()},
 *  - PageController validates, titles, and gates visibility through it,
 *  - templates/settings.php dispatches to templates/parts/settings/<section>.php,
 *  - js/workspace-settings-legacy-redirect.js mirrors {@see LEGACY_ANCHORS} for old
 *    `/settings#anchor` links.
 *
 * Contract tests in tests/Unit assert all four artifacts stay in sync, so a
 * drifting copy fails CI instead of shipping a dead link.
 *
 * Distinct from {@see AppSettingsSectionCatalog} (`/app-settings/{section}`).
 */
final class WorkspaceSettingsSectionCatalog
{
	public const DEFAULT_SECTION = 'workspace';

	/**
	 * Ordered section slugs — order drives the sidebar sub-navigation.
	 *
	 * @var list<string>
	 */
	public const SECTIONS = [
		'planning-view',
		'workspace',
		'tax',
		'categories',
		'budget-defaults',
		'booking-statuses',
		'members',
		'recurring',
		'help',
	];

	/**
	 * Legacy single-page anchors → owning section slug.
	 *
	 * The old Workspace settings page was one long document with jump anchors. URL
	 * fragments never reach the server, so js/workspace-settings-legacy-redirect.js
	 * uses this map to forward stale bookmarks client-side.
	 *
	 * @var array<string, string>
	 */
	public const LEGACY_ANCHORS = [
		'bc-summary-view-prefs-title' => 'planning-view',
		'bc-ws-meta-title' => 'workspace',
		'bc-ws-currency-label' => 'workspace',
		'bc-ws-timezone-label' => 'workspace',
		'bc-primary-year-hint' => 'workspace',
		'bc-summary-default-hint' => 'workspace',
		'bc-tax-title' => 'tax',
		'bc-categories-title' => 'categories',
		'bc-budget-defaults-title' => 'budget-defaults',
		'bc-booking-statuses-title' => 'booking-statuses',
		'bc-members-title' => 'members',
		'bc-member-invite-title' => 'members',
		'bc-member-invite-hint' => 'members',
		'bc-member-invite-q' => 'members',
		'bc-member-invite-suggest' => 'members',
		'bc-member-invite-role' => 'members',
		'bc-group-invite-title' => 'members',
		'bc-group-invite-hint' => 'members',
		'bc-group-invite-q' => 'members',
		'bc-group-invite-suggest' => 'members',
		'bc-group-invite-role' => 'members',
		'bc-recurring-title' => 'recurring',
		'bc-help-panels' => 'help',
		'bc-glossary' => 'help',
		'bc-spreadsheet-bridge' => 'help',
	];

	public function isSection(string $section): bool
	{
		return in_array($section, self::SECTIONS, true);
	}

	/**
	 * Value for the `{section}` route placeholder requirement.
	 */
	public static function routeRequirement(): string
	{
		return implode('|', self::SECTIONS);
	}

	/**
	 * Type-aware default section when opening `/settings`.
	 *
	 * Household members land on personal planning view; projects land on workspace details.
	 */
	public function defaultSection(string $workspaceType): string
	{
		return $workspaceType === WorkspaceService::TYPE_HOUSEHOLD
			? 'planning-view'
			: self::DEFAULT_SECTION;
	}

	/**
	 * Whether a section is available for this workspace type and role.
	 *
	 * Visibility gates both navigation chips/sidebar children and direct URL access
	 * (unauthorized sections redirect to {@see defaultSection()}).
	 */
	public function isVisible(string $section, string $workspaceType, bool $canManage): bool
	{
		if (!$this->isSection($section)) {
			return false;
		}
		$isHousehold = $workspaceType === WorkspaceService::TYPE_HOUSEHOLD;
		$isProject = $workspaceType === WorkspaceService::TYPE_PROJECT;

		return match ($section) {
			'planning-view' => $isHousehold,
			'workspace', 'tax', 'categories', 'help' => true,
			'budget-defaults' => $isHousehold && $canManage,
			'booking-statuses' => $isProject,
			'members', 'recurring' => $canManage,
			default => false,
		};
	}

	/**
	 * Human page title (H1 / breadcrumb current). Longer, descriptive copy.
	 */
	public function label(IL10N $l, string $section): string
	{
		return match ($section) {
			'planning-view' => $l->t('Your planning view'),
			'workspace' => $l->t('Workspace details'),
			'tax' => $l->t('Tax mode'),
			'categories' => $l->t('Categories'),
			'budget-defaults' => $l->t('Default category budgets'),
			'booking-statuses' => $l->t('Booking statuses'),
			'members' => $l->t('Members'),
			'recurring' => $l->t('Recurring rules'),
			'help' => $l->t('Help and glossary'),
			default => $l->t('Workspace settings'),
		};
	}

	/**
	 * Short sidebar / in-page chip label.
	 */
	public function navLabel(IL10N $l, string $section): string
	{
		return match ($section) {
			'planning-view' => $l->t('Planning view'),
			'workspace' => $l->t('Workspace'),
			'tax' => $l->t('Tax'),
			'categories' => $l->t('Categories'),
			'budget-defaults' => $l->t('Budget defaults'),
			'booking-statuses' => $l->t('Booking statuses'),
			'members' => $l->t('Members'),
			'recurring' => $l->t('Recurring'),
			'help' => $l->t('Help'),
			default => $l->t('Workspace settings'),
		};
	}

	/**
	 * One-line page lead under the H1. Reuses existing section sub-copy where possible.
	 *
	 * Help intentionally returns '' — its panels ship self-contained intros.
	 */
	public function help(IL10N $l, string $section): string
	{
		return match ($section) {
			'planning-view' => $l->t('Controls how income and expense totals appear on the dashboard, monthly plan, and yearly overview—for you only.'),
			'workspace' => $l->t('Each workspace has its own currency and timezone. They apply to all months and transactions in this workspace.'),
			'tax' => $l->t('Optional: net/gross/VAT entry. Disabled by default.'),
			'categories' => $l->t('Name categories for your budget. Group is for a few custom buckets in reports and filters—not bank hierarchy. Notes on transactions work well for vendor names.'),
			'budget-defaults' => $l->t('Used as baseline for months. You can still override single months in planning.'),
			'booking-statuses' => $l->t('Project-only workflow states for bookings (for example Open, In progress, Paid).'),
			'members' => $l->t('Give people access to this workspace by adding them as a user or by adding a whole group. Each member is a manager, contributor, or viewer.'),
			'recurring' => $l->t('Repeating income or expenses — on a fixed interval or on specific dates you list. Generate creates planned ledger entries; a matching import removes the plan automatically.'),
			default => '',
		};
	}
}
