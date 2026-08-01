<?php
/**
 * In-page Workspace settings sub-navigation (DeskCheck / App settings parity).
 *
 * Complements the sidebar sub-list: Nextcloud collapses #app-navigation below
 * ~1024px, so without this chip bar members cannot reach sibling settings pages
 * on phones/tablets. Labels and URLs come from the controller
 * (WorkspaceSettingsSectionCatalog) — never hardcoded here. Only VISIBLE
 * sections are present in settingsSectionLabels.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @var string $bcRequestedSection
 */

$bcNavLabels = (array) ($_['settingsSectionLabels'] ?? []);
$bcNavUrls = (array) (($_['urls']['settingsSections'] ?? []) ?: []);
if ($bcNavLabels === []) {
	return;
}
?>
<nav class="bc-settings-nav" id="bc-settings-pages" aria-label="<?php p($l->t('Settings pages')); ?>">
	<?php foreach ($bcNavLabels as $sectionId => $sectionLabel):
		$sectionId = (string) $sectionId;
		$href = (string) ($bcNavUrls[$sectionId] ?? '');
		if ($href === '' || $href === '#') {
			continue;
		}
		$active = $bcRequestedSection === $sectionId;
		?>
		<a class="bc-settings-nav__link<?php p($active ? ' is-active' : ''); ?>"
			href="<?php p($href); ?>"
			<?php if ($active): ?>aria-current="page"<?php endif; ?>>
			<?php p((string) $sectionLabel); ?>
		</a>
	<?php endforeach; ?>
</nav>
