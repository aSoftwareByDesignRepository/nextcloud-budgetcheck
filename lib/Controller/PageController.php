<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Controller;

use OCA\BudgetCheck\AppInfo\Application;
use OCA\BudgetCheck\Service\AccessControlService;
use OCA\BudgetCheck\Service\AppSettingsSectionCatalog;
use OCA\BudgetCheck\Service\LocaleFormatService;
use OCA\BudgetCheck\Service\WorkspaceService;
use OCA\BudgetCheck\Service\WorkspaceSettingsSectionCatalog;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;

/**
 * BudgetCheck page controller.
 *
 * Page handlers are HTML GET routes; mutation lives in {@see ApiController} and
 * keeps CSRF protection on. Each page resolves the active workspace from the
 * `?workspaceId=` query parameter (§6.0) and falls back to "last used" /
 * "single membership" / "picker mode" in that order so deep links stay stable
 * but newcomers are not forced to type IDs into the URL bar.
 *
 * Type-only pages (`/monthly`, `/yearly`, `/period`) redirect when the
 * resolved workspace is the wrong type so bookmarks stay valid (§12.1).
 */
class PageController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private IURLGenerator $urlGenerator,
		private AccessControlService $access,
		private WorkspaceService $workspaces,
		private LocaleFormatService $localeFormat,
		private IL10N $l10n,
		private AppSettingsSectionCatalog $appSettingsSections,
		private WorkspaceSettingsSectionCatalog $workspaceSettingsSections,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): RedirectResponse
	{
		return new RedirectResponse($this->urlGenerator->linkToRoute('budgetcheck.page.dashboard'));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function dashboard(): TemplateResponse
	{
		$ctx = $this->resolveWorkspace();
		return $this->page('dashboard', $this->l10n->t('Dashboard'),
			$ctx['workspace'] === null
				? $this->l10n->t('Pick a workspace to see income, expenses, and warnings.')
				: ($ctx['workspace']['type'] === WorkspaceService::TYPE_HOUSEHOLD
					? $this->l10n->t('See income, expenses, savings, and what needs your attention.')
					: $this->l10n->t('See spend versus cap, period progress, and warnings for this project.')),
			$ctx,
			'dashboard'
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function transactions(): TemplateResponse
	{
		$ctx = $this->resolveWorkspace();
		return $this->page('transactions', $this->l10n->t('Transactions'),
			$ctx['workspace'] === null
				? $this->l10n->t('Pick a workspace to log income and expenses.')
				: $this->l10n->t('Log income and expenses; the server enforces direction, dates, and tax rules.'),
			$ctx,
			'transactions'
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function import(): TemplateResponse
	{
		$ctx = $this->resolveWorkspace();
		return $this->page('import', $this->l10n->t('Import transactions'),
			$ctx['workspace'] === null
				? $this->l10n->t('Pick a workspace before importing transactions.')
				: $this->l10n->t('Upload a CSV file, validate every row, then import safely in one atomic step.'),
			$ctx,
			'import'
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function budgets(): TemplateResponse
	{
		$ctx = $this->resolveWorkspace();
		return $this->page('budgets', $this->l10n->t('Budgets'),
			$ctx['workspace'] === null
				? $this->l10n->t('Pick a workspace to plan monthly budgets.')
				: ($ctx['workspace']['type'] === WorkspaceService::TYPE_HOUSEHOLD
					? $this->l10n->t('Plan a category budget for each month and set a savings target.')
					: $this->l10n->t('Plan category budgets month by month inside the project window.')),
			$ctx,
			'budgets'
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function monthly(): TemplateResponse|RedirectResponse
	{
		$ctx = $this->resolveWorkspace();
		if ($ctx['workspace'] !== null && $ctx['workspace']['type'] === WorkspaceService::TYPE_PROJECT) {
			return new RedirectResponse($this->urlGenerator->linkToRoute('budgetcheck.page.period', [
				'workspaceId' => $ctx['workspace']['id'],
			]));
		}
		return $this->page('monthly', $this->l10n->t('Monthly plan'),
			$ctx['workspace'] === null
				? $this->l10n->t('Pick a household workspace to review and close a month.')
				: $this->l10n->t('Review the month, fix warnings, then close to lock the snapshot.'),
			$ctx,
			'monthly'
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function period(): TemplateResponse|RedirectResponse
	{
		$ctx = $this->resolveWorkspace();
		if ($ctx['workspace'] !== null && $ctx['workspace']['type'] === WorkspaceService::TYPE_HOUSEHOLD) {
			return new RedirectResponse($this->urlGenerator->linkToRoute('budgetcheck.page.monthly', [
				'workspaceId' => $ctx['workspace']['id'],
			]));
		}
		return $this->page('period', $this->l10n->t('Period overview'),
			$ctx['workspace'] === null
				? $this->l10n->t('Pick a project workspace to see period totals and cap warnings.')
				: $this->l10n->t('Track total project spend versus the cap across the configured period.'),
			$ctx,
			'period'
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function yearly(): TemplateResponse|RedirectResponse
	{
		$ctx = $this->resolveWorkspace();
		if ($ctx['workspace'] !== null && $ctx['workspace']['type'] === WorkspaceService::TYPE_PROJECT) {
			return new RedirectResponse($this->urlGenerator->linkToRoute('budgetcheck.page.period', [
				'workspaceId' => $ctx['workspace']['id'],
			]));
		}
		return $this->page('yearly', $this->l10n->t('Yearly overview'),
			$ctx['workspace'] === null
				? $this->l10n->t('Pick a household workspace to see the yearly story.')
				: $this->l10n->t('Compare months, totals, and savings across the year.'),
			$ctx,
			'yearly'
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function workspaceOverview(): TemplateResponse
	{
		$ctx = $this->resolveWorkspace();
		return $this->page(
			'workspace-overview',
			$this->l10n->t('Workspace overview'),
			$this->l10n->t('Browse all workspaces, filter quickly, and choose quick access entries for the sidebar.'),
			$ctx,
			'workspace-overview'
		);
	}

	/**
	 * Workspace settings index — redirects to the type-aware default sub-page
	 * while preserving `?workspaceId=`. Any workspace member may open settings.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settings(): RedirectResponse
	{
		$ctx = $this->resolveWorkspace();
		$selected = $ctx['workspace'];
		if ($selected === null) {
			return new RedirectResponse($this->urlGenerator->linkToRoute('budgetcheck.page.dashboard'));
		}
		$section = $this->workspaceSettingsSections->defaultSection((string)($selected['type'] ?? ''));
		return new RedirectResponse($this->urlGenerator->linkToRoute(
			'budgetcheck.page.settingsSection',
			[
				'section' => $section,
				'workspaceId' => (int)$selected['id'],
			],
		));
	}

	/**
	 * One Workspace settings sub-page per former section.
	 *
	 * The route requirement already restricts {section} to the allowlist; the
	 * catalog check below is defense in depth. Sections that fail the
	 * type/role visibility matrix redirect to the type-aware default — never
	 * render manager-only partials to viewers.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settingsSection(string $section): TemplateResponse|RedirectResponse|NotFoundResponse
	{
		$ctx = $this->resolveWorkspace();
		$selected = $ctx['workspace'];
		if ($selected === null) {
			return new RedirectResponse($this->urlGenerator->linkToRoute('budgetcheck.page.dashboard'));
		}

		$section = strtolower(trim($section));
		if (!$this->workspaceSettingsSections->isSection($section)) {
			return new NotFoundResponse();
		}

		$workspaceType = (string)($selected['type'] ?? '');
		$canManage = in_array(($selected['role'] ?? null), [AccessControlService::ROLE_MANAGER], true);
		if (!$this->workspaceSettingsSections->isVisible($section, $workspaceType, $canManage)) {
			return new RedirectResponse($this->urlGenerator->linkToRoute(
				'budgetcheck.page.settingsSection',
				[
					'section' => $this->workspaceSettingsSections->defaultSection($workspaceType),
					'workspaceId' => (int)$selected['id'],
				],
			));
		}

		$userId = $this->access->currentUserId();
		$ctx['canAdminApp'] = $this->access->isAppAdmin($userId);
		$ctx['currencyChangeAllowed'] = $this->workspaces->currencyChangeAllowed((int)$selected['id']);
		$ctx['settingsSection'] = $section;

		$settingsSectionLabels = [];
		foreach (WorkspaceSettingsSectionCatalog::SECTIONS as $sectionId) {
			if (!$this->workspaceSettingsSections->isVisible($sectionId, $workspaceType, $canManage)) {
				continue;
			}
			$settingsSectionLabels[$sectionId] = $this->workspaceSettingsSections->navLabel($this->l10n, $sectionId);
		}
		$ctx['settingsSectionLabels'] = $settingsSectionLabels;

		$ctx['breadcrumbParent'] = [
			'label' => $this->l10n->t('Workspace settings'),
			'url' => $this->urlGenerator->linkToRoute('budgetcheck.page.settings', [
				'workspaceId' => (int)$selected['id'],
			]),
		];

		return $this->page(
			'settings',
			$this->workspaceSettingsSections->label($this->l10n, $section),
			$this->workspaceSettingsSections->help($this->l10n, $section),
			$ctx,
			'settings',
		);
	}

	/**
	 * App settings index — redirects to the default sub-page while preserving
	 * `?workspaceId=` when a workspace is resolved.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function appSettings(): RedirectResponse
	{
		$userId = $this->access->currentUserId();
		if (!$this->access->isAppAdmin($userId)) {
			return new RedirectResponse($this->urlGenerator->linkToRoute('budgetcheck.page.dashboard'));
		}
		$ctx = $this->resolveWorkspace();
		$params = ['section' => AppSettingsSectionCatalog::DEFAULT_SECTION];
		if ($ctx['workspace'] !== null) {
			$params['workspaceId'] = (int)$ctx['workspace']['id'];
		}
		return new RedirectResponse($this->urlGenerator->linkToRoute(
			'budgetcheck.page.appSettingsSection',
			$params,
		));
	}

	/**
	 * One App settings sub-page per former section (DeskCheck / DutyCheck pattern).
	 *
	 * The route requirement already restricts {section} to the allowlist; the
	 * catalog check below is defense in depth so a route change can never open
	 * an unvalidated template dispatch.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function appSettingsSection(string $section): TemplateResponse|RedirectResponse|NotFoundResponse
	{
		$userId = $this->access->currentUserId();
		if (!$this->access->isAppAdmin($userId)) {
			return new RedirectResponse($this->urlGenerator->linkToRoute('budgetcheck.page.dashboard'));
		}
		$section = strtolower(trim($section));
		if (!$this->appSettingsSections->isSection($section)) {
			return new NotFoundResponse();
		}

		$ctx = $this->resolveWorkspace();
		$ctx['canAdminApp'] = true;
		$ctx['appPolicy'] = $this->access->getAppPolicy();
		$ctx['settingsSection'] = $section;

		$appSettingsSectionLabels = [];
		foreach (AppSettingsSectionCatalog::SECTIONS as $sectionId) {
			$appSettingsSectionLabels[$sectionId] = $this->appSettingsSections->navLabel($this->l10n, $sectionId);
		}
		$ctx['appSettingsSectionLabels'] = $appSettingsSectionLabels;

		$wsQuery = $ctx['workspace'] !== null ? ['workspaceId' => (int)$ctx['workspace']['id']] : [];
		$ctx['breadcrumbParent'] = [
			'label' => $this->l10n->t('App settings'),
			'url' => $this->urlGenerator->linkToRoute('budgetcheck.page.appSettings', $wsQuery),
		];

		return $this->page(
			'app-settings',
			$this->appSettingsSections->label($this->l10n, $section),
			$this->appSettingsSections->help($this->l10n, $section),
			$ctx,
			'app-settings',
		);
	}

	/**
	 * Resolve a workspace context for the active request.
	 *
	 * Returns:
	 *   workspace: hydrated workspace row (with role) when one is selected;
	 *              null when the user is in "pick a workspace" mode.
	 *   workspaces: list of all the user's workspaces, used by the switcher.
	 *
	 * @return array{workspace: ?array<string,mixed>, workspaces: list<array<string,mixed>>}
	 */
	private function resolveWorkspace(): array
	{
		$userId = $this->access->currentUserId();
		$workspaces = $this->workspaces->listForUser($userId);
		// Prune stale favorite IDs in-memory only — never persist on GET (writes go through PUT /workspace-favorites).
		$favoriteWorkspaceIds = array_values(array_map('intval', array_intersect(
			$this->access->favoriteWorkspaceIds($userId),
			array_map(static fn (array $w): int => (int)$w['id'], $workspaces)
		)));
		$selected = null;

		$rawId = $this->request->getParam('workspaceId');
		$wid = is_numeric($rawId) ? (int)$rawId : 0;
		if ($wid > 0) {
			try {
				$selected = $this->workspaces->getForUser($wid, $userId);
				if (!empty($selected['isActive'])) {
					$this->access->rememberLastUsedWorkspace($userId, (int)$selected['id']);
				} else {
					$selected = null;
				}
			} catch (\Throwable) {
				$selected = null;
			}
		}
		if ($selected === null) {
			$lastUsed = $this->access->lastUsedWorkspace($userId);
			if ($lastUsed !== null) {
				try {
					$selected = $this->workspaces->getForUser($lastUsed, $userId);
					if (empty($selected['isActive'])) {
						$this->access->forgetLastUsedWorkspace($userId, $lastUsed);
						$selected = null;
					}
				} catch (\Throwable) {
					$this->access->forgetLastUsedWorkspace($userId, $lastUsed);
				}
			}
		}
		if ($selected === null && count($workspaces) === 1) {
			$selected = $workspaces[0];
			$this->access->rememberLastUsedWorkspace($userId, (int)$selected['id']);
		}
		return ['workspace' => $selected, 'workspaces' => $workspaces, 'favoriteWorkspaceIds' => $favoriteWorkspaceIds];
	}

	/**
	 * Render a page with shared chrome.
	 *
	 * @param array{workspace: ?array<string,mixed>, workspaces: list<array<string,mixed>>} $ctx
	 */
	private function page(string $template, string $title, string $help, array $ctx, string $script): TemplateResponse
	{
		$userId = $this->access->currentUserId();
		$selected = $ctx['workspace'];
		$canManage = $selected !== null && in_array(($selected['role'] ?? null), [AccessControlService::ROLE_MANAGER], true);
		$canContribute = $selected !== null && in_array(($selected['role'] ?? null), [AccessControlService::ROLE_MANAGER, AccessControlService::ROLE_CONTRIBUTOR], true);
		$canAdminApp = $this->access->isAppAdmin($userId);
		$navigation = $this->buildNavigation($template, $selected, $canAdminApp, $canManage);

		$wsQuery = $selected !== null ? ['workspaceId' => (int)$selected['id']] : [];
		// Only emit app-settings section URLs to app admins — viewers must not
		// see privileged paths in data-bc-urls.
		$appSettingsSectionUrls = [];
		if ($canAdminApp) {
			foreach (AppSettingsSectionCatalog::SECTIONS as $sectionId) {
				$appSettingsSectionUrls[$sectionId] = $this->urlGenerator->linkToRoute(
					'budgetcheck.page.appSettingsSection',
					array_merge(['section' => $sectionId], $wsQuery),
				);
			}
		}
		$appSettingsSectionLabels = $ctx['appSettingsSectionLabels'] ?? [];
		if ($appSettingsSectionLabels === [] && $canAdminApp) {
			foreach (AppSettingsSectionCatalog::SECTIONS as $sectionId) {
				$appSettingsSectionLabels[$sectionId] = $this->appSettingsSections->navLabel($this->l10n, $sectionId);
			}
		}

		// Only emit URLs the current role/type may open — avoids advertising
		// manager-only section paths in data-bc-urls. Legacy hash forward fails
		// closed when a target is absent (viewer keeps the current page).
		$settingsSectionUrls = [];
		if ($selected !== null) {
			$workspaceTypeForUrls = (string)($selected['type'] ?? '');
			foreach (WorkspaceSettingsSectionCatalog::SECTIONS as $sectionId) {
				if (!$this->workspaceSettingsSections->isVisible($sectionId, $workspaceTypeForUrls, $canManage)) {
					continue;
				}
				$settingsSectionUrls[$sectionId] = $this->urlGenerator->linkToRoute(
					'budgetcheck.page.settingsSection',
					array_merge(['section' => $sectionId], $wsQuery),
				);
			}
		}
		$settingsSectionLabels = $ctx['settingsSectionLabels'] ?? [];
		if ($settingsSectionLabels === [] && $selected !== null && $template === 'settings') {
			$workspaceType = (string)($selected['type'] ?? '');
			foreach (WorkspaceSettingsSectionCatalog::SECTIONS as $sectionId) {
				if (!$this->workspaceSettingsSections->isVisible($sectionId, $workspaceType, $canManage)) {
					continue;
				}
				$settingsSectionLabels[$sectionId] = $this->workspaceSettingsSections->navLabel($this->l10n, $sectionId);
			}
		}

		$params = [
			'pageId' => $template,
			'pageTitle' => $title,
			'pageHelp' => $help,
			'workspace' => $selected,
			'workspaces' => $ctx['workspaces'],
			'favoriteWorkspaceIds' => $ctx['favoriteWorkspaceIds'] ?? [],
			'canManageWorkspace' => $canManage,
			'canContribute' => $canContribute,
			'canAdminApp' => $canAdminApp,
			'appPolicy' => $ctx['appPolicy'] ?? null,
			'settingsSection' => (string)($ctx['settingsSection'] ?? ''),
			'settingsSectionLabels' => $settingsSectionLabels,
			'appSettingsSectionLabels' => $appSettingsSectionLabels,
			'breadcrumbParent' => is_array($ctx['breadcrumbParent'] ?? null) ? $ctx['breadcrumbParent'] : null,
			'navigation' => $navigation,
			'localeFormat' => $this->localeFormat,
			'clientHints' => $this->localeFormat->clientHints(),
			'urls' => [
				'dashboard'    => $this->urlGenerator->linkToRoute('budgetcheck.page.dashboard'),
				'transactions' => $this->urlGenerator->linkToRoute('budgetcheck.page.transactions'),
				'import'       => $this->urlGenerator->linkToRoute('budgetcheck.page.import'),
				'budgets'      => $this->urlGenerator->linkToRoute('budgetcheck.page.budgets'),
				'monthly'      => $this->urlGenerator->linkToRoute('budgetcheck.page.monthly'),
				'period'       => $this->urlGenerator->linkToRoute('budgetcheck.page.period'),
				'yearly'       => $this->urlGenerator->linkToRoute('budgetcheck.page.yearly'),
				'workspaceOverview' => $this->urlGenerator->linkToRoute('budgetcheck.page.workspaceOverview', $wsQuery),
				'settings'     => $this->urlGenerator->linkToRoute('budgetcheck.page.settings', $wsQuery),
				'settingsSections' => $settingsSectionUrls,
				'appSettings'  => $this->urlGenerator->linkToRoute('budgetcheck.page.appSettings', $wsQuery),
				'appSettingsSections' => $appSettingsSectionUrls,
				'home'         => $this->urlGenerator->linkToDefaultPageUrl(),
			],
			'currentUserId' => $userId,
			'invoicingCheckCreateUrl' => null,
			'invoicingCheckReceivablesUrl' => null,
		];
		// WP-ECO-BC: soft deep link for project workspaces when InvoiceCheck is enabled.
		if ($selected !== null && ($selected['type'] ?? '') === 'project') {
			try {
				$appManager = \OCP\Server::get(\OCP\App\IAppManager::class);
				if ($appManager->isEnabledForUser('invoicecheck')) {
					$params['invoicingCheckCreateUrl'] = $this->urlGenerator->linkToRoute('invoicecheck.page.createForm', [
						'workspaceId' => (int) $selected['id'],
					]);
					$params['invoicingCheckReceivablesUrl'] = $this->urlGenerator->linkToRoute('invoicecheck.page.receivables', [
						'workspaceId' => (int) $selected['id'],
					]);
				}
			} catch (\Throwable) {
				$params['invoicingCheckCreateUrl'] = null;
				$params['invoicingCheckReceivablesUrl'] = null;
			}
		}
		if (array_key_exists('currencyChangeAllowed', $ctx)) {
			$params['currencyChangeAllowed'] = (bool)$ctx['currencyChangeAllowed'];
		}
		$response = new TemplateResponse($this->appName, $template, $params);
		$this->registerFrontEndAssets($script);
		return $response;
	}

	/**
	 * Register app stylesheet and shared JS globals before the page module.
	 *
	 * Page scripts expect `window.BudgetCheckApi`, `window.BudgetCheckConstants`,
	 * `window.BudgetCheckWorkspace`, and
	 * related globals. `common/dates` is registered **before** `common/components` so
	 * `BudgetCheckDates.applyLocaleToTemporalInputs` exists when modals mount.
	 * Registering from the page controller (not from
	 * {@see Application::boot()}) avoids brittle request-path detection and fixes load order.
	 */
	private function registerFrontEndAssets(string $pageScript): void {
		Util::addStyle(Application::APP_ID, 'common/tokens');
		Util::addStyle(Application::APP_ID, 'app');
		if ($pageScript === 'transactions') {
			Util::addStyle(Application::APP_ID, 'transactions');
		}
		if ($pageScript === 'import') {
			Util::addStyle(Application::APP_ID, 'import');
		}
		// Bootstrap first: page modules resolve deps via BudgetCheck.require/onReady
		// and must never snapshot window.BudgetCheck* at IIFE evaluation time.
		Util::addScript(Application::APP_ID, 'common/bootstrap');
		Util::addScript(Application::APP_ID, 'common/api');
		Util::addScript(Application::APP_ID, 'common/constants');
		Util::addScript(Application::APP_ID, 'common/dates');
		Util::addScript(Application::APP_ID, 'common/components');
		Util::addScript(Application::APP_ID, 'common/icons');
		Util::addScript(Application::APP_ID, 'common/household-period-controls');
		Util::addScript(Application::APP_ID, 'common/messaging');
		// Soft keyboard: keep focused notes/inputs above the IME on phones.
		Util::addScript(Application::APP_ID, 'common/keep-focused-visible');
		Util::addScript(Application::APP_ID, 'common/money');
		Util::addScript(Application::APP_ID, 'common/workspace');
		if (in_array($pageScript, ['dashboard', 'transactions'], true)) {
			Util::addScript(Application::APP_ID, 'common/attachment-gallery');
			Util::addScript(Application::APP_ID, 'common/transaction-attachments');
			Util::addScript(Application::APP_ID, 'common/transaction-editor');
		}
		if ($pageScript === 'dashboard') {
			Util::addScript(Application::APP_ID, 'common/transaction-list');
		}
		if (in_array($pageScript, ['dashboard', 'monthly', 'yearly', 'settings'], true)) {
			Util::addScript(Application::APP_ID, 'common/specials-view');
		}
		Util::addScript(Application::APP_ID, 'common/catalog-pickers');
		if ($pageScript === 'settings' || $pageScript === 'app-settings') {
			Util::addScript(Application::APP_ID, 'common/entity-picker');
		}
		// Legacy /app-settings#anchor → split sub-page forwarding (must load before app-settings.js).
		if ($pageScript === 'app-settings') {
			Util::addScript(Application::APP_ID, 'app-settings-legacy-redirect');
		}
		// Legacy /settings#anchor → split sub-page forwarding (must load before settings.js).
		if ($pageScript === 'settings') {
			Util::addScript(Application::APP_ID, 'workspace-settings-legacy-redirect');
		}
		Util::addScript(Application::APP_ID, $pageScript);
	}

	/**
	 * Build navigation entries with type-aware filtering. Items that do not
	 * apply to the active workspace type are omitted (not disabled), which
	 * keeps "no teaser UI" satisfied per §2.14.
	 *
	 * @param array<string,mixed>|null $workspace
	 * @return list<array{id:string,label:string,url:string,icon:string,active:bool,hint:string}>
	 */
	private function buildNavigation(string $current, ?array $workspace, bool $canAdminApp, bool $canManageWorkspace): array
	{
		$workspaceType = $workspace['type'] ?? null;
		$workspaceId = $workspace !== null ? (int)$workspace['id'] : null;
		$paramsWithWs = $workspaceId !== null ? ['workspaceId' => $workspaceId] : [];
		$canContribute = $workspace !== null && in_array(($workspace['role'] ?? null), [AccessControlService::ROLE_MANAGER, AccessControlService::ROLE_CONTRIBUTOR], true);

		$items = [
			['id' => 'dashboard',    'label' => $this->l10n->t('Dashboard'),      'icon' => 'layout-grid',    'route' => 'budgetcheck.page.dashboard',    'show' => true,                                 'hint' => $this->l10n->t('Quick overview')],
			['id' => 'transactions', 'label' => $this->l10n->t('Transactions'),   'icon' => 'list',           'route' => 'budgetcheck.page.transactions', 'show' => $workspace !== null,                  'hint' => $this->l10n->t('Income and expenses')],
			['id' => 'import',       'label' => $this->l10n->t('Import'),         'icon' => 'upload',         'route' => 'budgetcheck.page.import',       'show' => $workspace !== null && $canContribute, 'hint' => $this->l10n->t('Guided CSV import with validation')],
			['id' => 'budgets',      'label' => $this->l10n->t('Budgets'),        'icon' => 'wallet',         'route' => 'budgetcheck.page.budgets',      'show' => $workspace !== null,                  'hint' => $this->l10n->t('Category budgets and savings targets')],
			['id' => 'monthly',      'label' => $this->l10n->t('Monthly plan'),   'icon' => 'calendar-days',  'route' => 'budgetcheck.page.monthly',      'show' => $workspaceType === WorkspaceService::TYPE_HOUSEHOLD, 'hint' => $this->l10n->t('Close and review months')],
			['id' => 'period',       'label' => $this->l10n->t('Period overview'),'icon' => 'calendar-range', 'route' => 'budgetcheck.page.period',       'show' => $workspaceType === WorkspaceService::TYPE_PROJECT, 'hint' => $this->l10n->t('Project totals and cap')],
			['id' => 'yearly',       'label' => $this->l10n->t('Yearly overview'),'icon' => 'calendar-clock', 'route' => 'budgetcheck.page.yearly',       'show' => $workspaceType === WorkspaceService::TYPE_HOUSEHOLD, 'hint' => $this->l10n->t('Year-at-a-glance')],
			['id' => 'settings',     'label' => $this->l10n->t('Workspace settings'), 'icon' => 'settings',       'route' => 'budgetcheck.page.settings',     'show' => $workspace !== null, 'hint' => $canManageWorkspace ? $this->l10n->t('Members, categories, tax, and workspace details.') : $this->l10n->t('Your planning view and read-only workspace details.')],
			['id' => 'app-settings', 'label' => $this->l10n->t('App settings'),   'icon' => 'shield-check',   'route' => 'budgetcheck.page.appSettings',  'show' => $canAdminApp, 'hint' => $this->l10n->t('Directory access, app administrators, and defaults for new workspaces.')],
		];

		$out = [];
		foreach ($items as $item) {
			if (!$item['show']) {
				continue;
			}
			$url = $this->urlGenerator->linkToRoute($item['route'], $paramsWithWs);
			$out[] = [
				'id' => $item['id'],
				'label' => $item['label'],
				'url' => $url,
				'icon' => $item['icon'],
				'active' => $item['id'] === $current,
				'hint' => $item['hint'],
			];
		}
		return $out;
	}
}
