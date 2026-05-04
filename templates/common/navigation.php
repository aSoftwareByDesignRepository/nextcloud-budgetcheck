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
$urls = $_['urls'] ?? [];
$canAdminApp = !empty($_['canAdminApp']);

$grouped = ['household' => [], 'project' => []];
foreach ($workspaces as $w) {
	$type = (string)($w['type'] ?? 'household');
	if (!isset($grouped[$type])) {
		$grouped[$type] = [];
	}
	$grouped[$type][] = $w;
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
			<?php if ($canAdminApp): ?>
				<button type="button" class="bc-switcher__add" data-bc-action="open-create-workspace">
					<?php print_unescaped(IconCatalog::render('plus', 'bc-icon--inline')); ?>
					<span><?php p($l->t('New')); ?></span>
				</button>
			<?php endif; ?>
		</header>
		<?php foreach (['household' => $l->t('Household'), 'project' => $l->t('Project')] as $type => $label): ?>
			<?php if (empty($grouped[$type])): continue; endif; ?>
			<div class="bc-switcher__group">
				<p class="bc-switcher__group-title" id="bc-switcher-<?php p($type); ?>"><?php p($label); ?></p>
				<ul class="bc-switcher__list" aria-labelledby="bc-switcher-<?php p($type); ?>">
					<?php foreach ($grouped[$type] as $w):
						$id = (int)$w['id'];
						$active = $id === $activeId;
						$url = (string)($urls['dashboard'] ?? '#') . '?workspaceId=' . $id;
						?>
						<li>
							<a class="bc-switcher__link <?php p($active ? 'is-active' : ''); ?>" href="<?php p($url); ?>" <?php if ($active): ?>aria-current="true"<?php endif; ?>>
								<span class="bc-switcher__icon" aria-hidden="true">
									<?php print_unescaped(IconCatalog::render($type === 'household' ? 'home' : 'briefcase')); ?>
								</span>
								<span class="bc-switcher__label">
									<span class="bc-switcher__name"><?php p((string)$w['name']); ?></span>
									<span class="bc-switcher__meta">
										<?php p((string)$w['currencyCode']); ?>
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
		<?php endforeach; ?>
		<?php if (empty($workspaces)): ?>
			<p class="bc-switcher__empty"><?php p($l->t('You are not a member of any workspace yet.')); ?></p>
			<?php if ($canAdminApp): ?>
				<button type="button" class="button primary bc-switcher__cta" data-bc-action="open-create-workspace"><?php p($l->t('Create your first workspace')); ?></button>
			<?php endif; ?>
		<?php endif; ?>
	</section>

	<ul class="nav-menu bc-nav__list" aria-label="<?php p($l->t('Main sections')); ?>">
		<?php foreach ($nav as $item): ?>
			<?php $active = !empty($item['active']); ?>
			<li class="bc-nav__item <?php p($active ? 'is-active active' : ''); ?>">
				<a class="bc-nav__link" href="<?php p($item['url']); ?>" <?php if ($active): ?>aria-current="page"<?php endif; ?>>
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
			</li>
		<?php endforeach; ?>
	</ul>
</div>
