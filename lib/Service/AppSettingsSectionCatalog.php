<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

use OCP\IL10N;

/**
 * Single source of truth for the split App settings sub-pages.
 *
 * Every artifact that knows about app-settings sections derives from this class:
 *  - appinfo/routes.php pins its `{section}` requirement to {@see routeRequirement()},
 *  - PageController validates and titles pages through it,
 *  - templates/app-settings.php dispatches to templates/parts/app-settings/<section>.php,
 *  - js/app-settings-legacy-redirect.js mirrors {@see LEGACY_ANCHORS} for old
 *    `/app-settings#anchor` links.
 *
 * Contract tests in tests/Unit assert all four artifacts stay in sync, so a
 * drifting copy fails CI instead of shipping a dead link.
 *
 * Distinct from {@see WorkspaceSettingsSectionCatalog} (`/settings/{section}`).
 */
final class AppSettingsSectionCatalog
{
	public const DEFAULT_SECTION = 'access';

	/**
	 * Ordered section slugs — order drives the sidebar sub-navigation.
	 *
	 * @var list<string>
	 */
	public const SECTIONS = [
		'access',
		'admins',
		'defaults',
		'support',
	];

	/**
	 * Legacy single-page anchors → owning section slug.
	 *
	 * The old App settings page was one long document with jump anchors. URL
	 * fragments never reach the server, so js/app-settings-legacy-redirect.js
	 * uses this map to forward stale bookmarks client-side.
	 *
	 * @var array<string, string>
	 */
	public const LEGACY_ANCHORS = [
		'bc-app-policy-title' => 'access',
		'bc-access-gate-title' => 'access',
		'bc-access-restriction-desc' => 'access',
		'bc-allowed-users-label' => 'access',
		'bc-allowed-groups-label' => 'access',
		'bc-app-admin-legend' => 'admins',
		'bc-policy-admins-q' => 'admins',
		'bc-policy-timezone-label' => 'defaults',
		'bc-policy-currency-label' => 'defaults',
		'bc-support-us' => 'support',
		'bc-support-us-title' => 'support',
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
	 * Human page title (H1 / breadcrumb current). Longer, descriptive copy.
	 */
	public function label(IL10N $l, string $section): string
	{
		return match ($section) {
			'access' => $l->t('Access control'),
			'admins' => $l->t('App administrators'),
			'defaults' => $l->t('Defaults for new workspaces'),
			'support' => $l->t('Support & us'),
			default => $l->t('App settings'),
		};
	}

	/**
	 * Short sidebar / in-page chip label (DeskCheck parity).
	 */
	public function navLabel(IL10N $l, string $section): string
	{
		return match ($section) {
			'access' => $l->t('Access'),
			'admins' => $l->t('App admins'),
			'defaults' => $l->t('Defaults'),
			'support' => $l->t('Support us'),
			default => $l->t('App settings'),
		};
	}

	/**
	 * One-line page lead under the H1. Reuses existing copy where possible.
	 *
	 * Support intentionally returns '' — its panel ships a self-contained intro.
	 */
	public function help(IL10N $l, string $section): string
	{
		return match ($section) {
			'access' => $l->t('This list controls the door, not the data.'),
			'admins' => $l->t('Only real Nextcloud user accounts can be selected. Unknown logins are rejected when you save.'),
			'defaults' => $l->t('These values pre-fill the form when an app administrator creates a workspace. They do not change existing workspaces.'),
			default => '',
		};
	}
}
