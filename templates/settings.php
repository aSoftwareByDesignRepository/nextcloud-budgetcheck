<?php
/**
 * Workspace settings shell (any workspace member).
 *
 * Split into one sub-page per section (DeskCheck / App settings pattern): the
 * controller validates `settingsSection` against
 * {@see \OCA\BudgetCheck\Service\WorkspaceSettingsSectionCatalog} and this
 * template dispatches through a literal slug → file map, so no request value
 * is ever used to build an include path.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

$workspace = $_['workspace'] ?? null;
$canManage = !empty($_['canManageWorkspace']);
$currencyChangeAllowed = array_key_exists('currencyChangeAllowed', $_)
	? (bool) $_['currencyChangeAllowed']
	: true;
?>
<?php include __DIR__ . '/common/page-start.php'; ?>

<?php if ($workspace === null): ?>
	<section class="bc-card bc-empty">
		<h2><?php p($l->t('Pick a workspace')); ?></h2>
		<p><?php p($l->t('Select a workspace from the sidebar to open workspace settings.')); ?></p>
	</section>
<?php else:
	$bcSettingsSectionFiles = [
		'planning-view' => 'planning-view.php',
		'workspace' => 'workspace.php',
		'tax' => 'tax.php',
		'categories' => 'categories.php',
		'budget-defaults' => 'budget-defaults.php',
		'booking-statuses' => 'booking-statuses.php',
		'members' => 'members.php',
		'recurring' => 'recurring.php',
		'help' => 'help.php',
	];
	$bcRequestedSection = (string) ($_['settingsSection'] ?? '');
	?>
	<div id="bc-workspace-settings-page" class="bc-workspace-settings">
<?php
	include __DIR__ . '/parts/settings-nav.php';
	if (!isset($bcSettingsSectionFiles[$bcRequestedSection])) {
		throw new \RuntimeException('BudgetCheck workspace settings: unknown section reached the template dispatcher.');
	}
	include __DIR__ . '/parts/settings/' . $bcSettingsSectionFiles[$bcRequestedSection];
?>
	</div>
<?php endif; ?>

<?php include __DIR__ . '/common/page-end.php'; ?>
