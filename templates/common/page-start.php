<?php
/**
 * Common BudgetCheck page chrome.
 *
 * Renders:
 *  - the sidebar navigation (#app-navigation)
 *  - the app shell (#app-content / #app-content-wrapper)
 *  - skip link, polite + assertive live regions
 *  - workspace switcher and the §6.4 scope strip (workspace name + type badge)
 *  - the page header with optional primary action slot (#bc-page-actions)
 *  - opens <main id="bc-main-content"> for the body
 *
 * Per-page contract (variables read from $_):
 *  - pageId (string, required)
 *  - pageTitle (string, required)  already translated
 *  - pageHelp  (string, optional)
 *  - workspace (?array)             selected workspace, or null when in picker mode
 *  - workspaces (list<array>)       list of all workspaces the user can see
 *  - canManageWorkspace (bool)      true when current user is manager
 *  - canContribute (bool)           true when current user is contributor or higher
 *  - canAdminApp (bool)             true when current user is an app admin
 *  - navigation (list<array>)       items provided by PageController
 *  - urls (array<string,string>)    canonical URLs for each route
 *  - clientHints (array)            {locale, htmlLang, timezone}
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\BudgetCheck\Service\IconCatalog;

$pageId = isset($_['pageId']) ? (string)$_['pageId'] : 'dashboard';
$pageTitle = (string)($_['pageTitle'] ?? '');
$pageHelp = (string)($_['pageHelp'] ?? '');
$workspace = $_['workspace'] ?? null;
$workspaces = $_['workspaces'] ?? [];
$canManage = !empty($_['canManageWorkspace']);
$canContribute = !empty($_['canContribute']);
$canAdminApp = !empty($_['canAdminApp']);
$nav = $_['navigation'] ?? [];
$urls = $_['urls'] ?? [];
$clientHints = $_['clientHints'] ?? ['locale' => 'en', 'htmlLang' => 'en-US', 'timezone' => 'Europe/Berlin'];
$bcHtmlLang = (string)($clientHints['htmlLang'] ?? $clientHints['locale'] ?? 'en');
/** @var \OCA\BudgetCheck\Service\LocaleFormatService|null $localeFormat */
$localeFormat = $_['localeFormat'] ?? null;

$pageIcons = [
	'dashboard' => 'layout-grid',
	'transactions' => 'list',
	'budgets' => 'wallet',
	'monthly' => 'calendar-days',
	'period' => 'calendar-range',
	'yearly' => 'calendar-clock',
	'settings' => 'settings',
	'app-settings' => 'shield-check',
];
$headerIconName = $pageIcons[$pageId] ?? 'layout-grid';

$workspaceJson = htmlspecialchars(json_encode($workspace, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
$workspacesJson = htmlspecialchars(json_encode($workspaces, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
$urlsJson = htmlspecialchars(json_encode($urls, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
?>
<?php include __DIR__ . '/navigation.php'; ?>
<div id="app-content" class="bc-app bc-app--<?php p($pageId); ?>"
	lang="<?php p($bcHtmlLang); ?>"
	data-bc-locale="<?php p((string)($clientHints['locale'] ?? '')); ?>"
	data-bc-html-lang="<?php p($bcHtmlLang); ?>"
	data-bc-timezone="<?php p((string)($clientHints['timezone'] ?? '')); ?>"
	data-bc-page="<?php p($pageId); ?>"
	data-bc-workspace="<?php print_unescaped($workspaceJson); ?>"
	data-bc-workspaces="<?php print_unescaped($workspacesJson); ?>"
	data-bc-urls="<?php print_unescaped($urlsJson); ?>"
	data-bc-can-manage="<?php p($canManage ? '1' : '0'); ?>"
	data-bc-can-contribute="<?php p($canContribute ? '1' : '0'); ?>"
	data-bc-can-admin="<?php p($canAdminApp ? '1' : '0'); ?>">
	<a class="bc-skip-link" href="#bc-main-content"><?php p($l->t('Skip to main content')); ?></a>
	<div id="bc-live-region" class="bc-sr-only" role="status" aria-live="polite" aria-atomic="true"></div>
	<div id="bc-alert-region" class="bc-sr-only" role="alert" aria-live="assertive" aria-atomic="true"></div>
	<div id="app-content-wrapper" class="bc-shell">
		<header class="bc-page-header" aria-labelledby="bc-page-title">
			<nav class="bc-breadcrumb" aria-label="<?php p($l->t('Breadcrumb')); ?>">
				<ol>
					<li><a class="bc-breadcrumb__brand" href="<?php p((string)($urls['dashboard'] ?? '#')); ?>"><?php p($l->t('BudgetCheck')); ?></a></li>
					<?php if ($workspace !== null): ?>
						<li class="bc-breadcrumb__sep" aria-hidden="true">/</li>
						<li class="bc-breadcrumb__workspace"><?php p((string)($workspace['name'] ?? '')); ?></li>
					<?php endif; ?>
					<li class="bc-breadcrumb__sep" aria-hidden="true">/</li>
					<li class="bc-breadcrumb__current" aria-current="page"><?php p($pageTitle); ?></li>
				</ol>
			</nav>
			<div class="bc-page-header__main">
				<div class="bc-page-header__icon" aria-hidden="true">
					<?php print_unescaped(IconCatalog::render($headerIconName, 'bc-page-header__icon-svg')); ?>
				</div>
				<div class="bc-page-header__text">
					<h1 id="bc-page-title"><?php p($pageTitle); ?></h1>
					<?php if ($pageHelp !== ''): ?>
						<p class="bc-page-header__lead"><?php p($pageHelp); ?></p>
					<?php endif; ?>
				</div>
				<div id="bc-page-actions" class="bc-page-header__actions" aria-live="polite"></div>
			</div>
			<?php if ($workspace !== null): ?>
				<div class="bc-scope-strip" aria-label="<?php p($l->t('Active workspace context')); ?>">
					<span class="bc-scope-strip__name"><?php p((string)$workspace['name']); ?></span>
					<span class="bc-scope-strip__badge bc-badge bc-badge--<?php p((string)$workspace['type']); ?>">
						<?php p($workspace['type'] === 'household' ? $l->t('Household') : $l->t('Project')); ?>
					</span>
					<?php if ($workspace['type'] === 'project'): ?>
						<span class="bc-scope-strip__dates">
							<?php
							$ps = $workspace['projectStartDate'] ?? null;
							$pe = $workspace['projectEndDate'] ?? null;
							if ($ps !== null && $ps !== '' && $localeFormat !== null) {
								p($localeFormat->formatDate((string)$ps, 'medium'));
							} else {
								p($ps !== null && $ps !== '' ? (string)$ps : '—');
							}
							?>
							<span aria-hidden="true">–</span>
							<?php
							if ($pe !== null && $pe !== '' && $localeFormat !== null) {
								p($localeFormat->formatDate((string)$pe, 'medium'));
							} else {
								p($pe !== null && $pe !== '' ? (string)$pe : '—');
							}
							?>
						</span>
					<?php elseif ($workspace['type'] === 'household' && !empty($workspace['activeCalendarYearMonth'])): ?>
						<span class="bc-scope-strip__dates" title="<?php p($l->t('Active calendar month in the workspace timezone')); ?>">
							<?php
							$ymStrip = (string)$workspace['activeCalendarYearMonth'];
							p($localeFormat !== null ? $localeFormat->formatYearMonth($ymStrip) : $ymStrip);
							?>
						</span>
					<?php endif; ?>
					<span class="bc-scope-strip__currency"><?php p((string)$workspace['currencyCode']); ?></span>
				</div>
			<?php endif; ?>
		</header>
		<main id="bc-main-content" class="bc-main" tabindex="-1">
