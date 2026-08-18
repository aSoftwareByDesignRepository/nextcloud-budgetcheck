<?php
/**
 * Sidebar navigation. Items pre-filtered by PageController::buildNavigation().
 *
 * Includes a workspace switcher above the nav menu so the active scope is
 * always one tab away. The switcher shows two grouped sections (household and
 * project) per §6.2 to make type membership visible at a glance.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\BudgetCheck\Service\IconCatalog;

$nav = $_['navigation'] ?? [];
$workspace = $_['workspace'] ?? null;
$workspaces = $_['workspaces'] ?? [];
$favoriteWorkspaceIds = array_map('intval', (array)($_['favoriteWorkspaceIds'] ?? []));
$urls = $_['urls'] ?? [];
$canAdminApp = !empty($_['canAdminApp']);
$canCreateWorkspace = !empty($_['canCreateWorkspace']);
$overviewUrl = (string)($urls['workspaceOverview'] ?? '#');
$pageIdNav = (string)($_['pageId'] ?? '');
$overviewActive = $pageIdNav === 'workspace-overview';

$favorites = [];
foreach ($workspaces as $w) {
	if (in_array((int)($w['id'] ?? 0), $favoriteWorkspaceIds, true)) {
		$favorites[] = $w;
	}
}
$activeId = $workspace !== null ? (int)$workspace['id'] : 0;
?>
<div id="app-navigation" class="bc-nav" role="navigation" aria-label="<?php p($l->t('BudgetCheck navigation')); ?>">
	<div class="app-brand bc-brand">
		<span class="app-icon bc-brand__icon" aria-hidden="true">
			<?php print_unescaped(IconCatalog::render('wallet', 'bc-brand__icon-svg')); ?>
		</span>
		<div class="app-info bc-brand__text">
			<h3 class="bc-brand__title"><?php p($l->t('BudgetCheck')); ?></h3>
			<p class="bc-brand__subtitle"><?php p($l->t('Household and project finance')); ?></p>
		</div>
	</div>

	<section class="bc-switcher" aria-labelledby="bc-switcher-title">
		<header class="bc-switcher__header">
			<h4 id="bc-switcher-title" class="bc-switcher__title"><?php p($l->t('Workspaces')); ?></h4>
			<?php if ($canCreateWorkspace): ?>
				<button type="button" class="bc-switcher__add" data-bc-action="open-create-workspace">
					<?php print_unescaped(IconCatalog::render('plus', 'bc-icon--inline')); ?>
					<span><?php p($l->t('New')); ?></span>
				</button>
			<?php endif; ?>
		</header>
		<div class="bc-switcher__group bc-switcher__group--overview">
			<ul class="bc-nav__list bc-switcher__overview-nav" aria-label="<?php p($l->t('Workspace tools')); ?>">
				<li class="bc-nav__item <?php p($overviewActive ? 'is-active active' : ''); ?>">
					<a class="bc-nav__link" href="<?php p($overviewUrl); ?>" <?php if ($overviewActive): ?>aria-current="page"<?php endif; ?>>
						<span class="bc-nav__icon" aria-hidden="true">
							<?php print_unescaped(IconCatalog::render('users')); ?>
						</span>
						<span class="bc-nav__label">
							<span class="bc-nav__name"><?php p($l->t('Workspace overview')); ?></span>
							<span class="bc-nav__hint"><?php p($l->t('Find and pin workspaces quickly')); ?></span>
						</span>
					</a>
				</li>
			</ul>
		</div>
		<div data-bc-quickaccess-slot>
			<?php if (!empty($favorites)): ?>
				<div class="bc-switcher__group">
					<p class="bc-switcher__group-title" id="bc-switcher-favorites"><?php p($l->t('Quick access')); ?></p>
					<ul class="bc-switcher__list" aria-labelledby="bc-switcher-favorites">
						<?php foreach ($favorites as $w):
							$id = (int)$w['id'];
							$active = $id === $activeId;
							$url = (string)($urls['dashboard'] ?? '#') . '?workspaceId=' . $id;
							$type = (string)($w['type'] ?? 'household');
							$isPrivate = (($w['privacyMode'] ?? 'standard') === 'private');
							?>
							<li>
								<a class="bc-switcher__link <?php p($active ? 'is-active' : ''); ?>"
									href="<?php p($url); ?>"
									data-bc-workspace-id="<?php p((string)$id); ?>"
									data-bc-workspace-type="<?php p($type); ?>"
									<?php if ($isPrivate): ?>data-bc-workspace-private="1"<?php endif; ?>
									<?php if ($active): ?>aria-current="true"<?php endif; ?>>
									<span class="bc-switcher__icon" aria-hidden="true">
										<?php print_unescaped(IconCatalog::render($type === 'household' ? 'home' : 'briefcase')); ?>
									</span>
									<span class="bc-switcher__label">
										<span class="bc-switcher__name"><?php p((string)$w['name']); ?></span>
										<span class="bc-switcher__meta">
											<?php p((string)$w['currencyCode']); ?>
											<?php if ($isPrivate): ?>
												· <?php p($l->t('Private')); ?>
											<?php endif; ?>
											<?php if (!empty($w['role'])): ?>
												· <?php p((string)$w['role']); ?>
											<?php endif; ?>
										</span>
									</span>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
			<?php if (!empty($workspaces) && empty($favorites)): ?>
				<p class="bc-switcher__empty">
					<?php p($l->t('No quick access workspaces yet.')); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php if (empty($workspaces)): ?>
			<p class="bc-switcher__empty"><?php p($l->t('You are not a member of any workspace yet.')); ?></p>
			<?php if ($canCreateWorkspace): ?>
				<button type="button" class="button primary bc-switcher__cta" data-bc-action="open-create-workspace"><?php p($l->t('Create your first workspace')); ?></button>
			<?php endif; ?>
		<?php endif; ?>
	</section>

	<ul class="nav-menu bc-nav__list" aria-label="<?php p($l->t('Main sections')); ?>">
		<?php
		// App settings / Workspace settings sub-pages: expanded sub-list while on any section.
		$settingsSection = (string) ($_['settingsSection'] ?? '');
		$appSettingsSectionUrls = (array) ($urls['appSettingsSections'] ?? []);
		$appSettingsSectionLabels = (array) ($_['appSettingsSectionLabels'] ?? []);
		$workspaceSettingsSectionUrls = (array) ($urls['settingsSections'] ?? []);
		$workspaceSettingsSectionLabels = (array) ($_['settingsSectionLabels'] ?? []);
		$appSettingsChildren = [];
		if ($canAdminApp && $pageIdNav === 'app-settings') {
			foreach ($appSettingsSectionLabels as $sectionId => $sectionLabel) {
				$childHref = (string) ($appSettingsSectionUrls[$sectionId] ?? '');
				if ($childHref === '' || $childHref === '#') {
					continue;
				}
				$appSettingsChildren[] = [
					'id' => (string) $sectionId,
					'label' => (string) $sectionLabel,
					'url' => $childHref,
					'active' => $settingsSection === (string) $sectionId,
				];
			}
		}
		$workspaceSettingsChildren = [];
		if ($pageIdNav === 'settings') {
			foreach ($workspaceSettingsSectionLabels as $sectionId => $sectionLabel) {
				$childHref = (string) ($workspaceSettingsSectionUrls[$sectionId] ?? '');
				if ($childHref === '' || $childHref === '#') {
					continue;
				}
				$workspaceSettingsChildren[] = [
					'id' => (string) $sectionId,
					'label' => (string) $sectionLabel,
					'url' => $childHref,
					'active' => $settingsSection === (string) $sectionId,
				];
			}
		}
		?>
		<?php foreach ($nav as $item):
			$children = [];
			if (($item['id'] ?? '') === 'app-settings' && $appSettingsChildren !== []) {
				$children = $appSettingsChildren;
			} elseif (($item['id'] ?? '') === 'settings' && $workspaceSettingsChildren !== []) {
				$children = $workspaceSettingsChildren;
			}
			$active = !empty($item['active']);
			// With an expanded sub-list, aria-current belongs to the active
			// child link only; the parent keeps the visual active state.
			$parentAriaCurrent = $active && $children === [];
			?>
			<li class="bc-nav__item <?php p($active ? 'is-active active' : ''); ?>">
				<a class="bc-nav__link" href="<?php p($item['url']); ?>" <?php if ($parentAriaCurrent): ?>aria-current="page"<?php endif; ?>>
					<span class="bc-nav__icon" aria-hidden="true">
						<?php print_unescaped(IconCatalog::render($item['icon'] ?? 'layout-grid')); ?>
					</span>
					<span class="bc-nav__label">
						<span class="bc-nav__name"><?php p($item['label']); ?></span>
						<?php if (!empty($item['hint'])): ?>
							<span class="bc-nav__hint"><?php p($item['hint']); ?></span>
						<?php endif; ?>
					</span>
				</a>
				<?php if ($children !== []): ?>
					<ul class="bc-nav__sublist">
						<?php foreach ($children as $child):
							$childHref = (string) ($child['url'] ?? '');
							if ($childHref === '' || $childHref === '#') {
								continue;
							}
							$childActive = !empty($child['active']);
							?>
							<li class="bc-nav__subitem <?php p($childActive ? 'is-active active' : ''); ?>">
								<a class="bc-nav__sublink" href="<?php p($childHref); ?>"
									<?php if ($childActive): ?>aria-current="page"<?php endif; ?>>
									<?php p((string) $child['label']); ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php include __DIR__ . '/../parts/feedback-nav-footer.php'; ?>
</div>
