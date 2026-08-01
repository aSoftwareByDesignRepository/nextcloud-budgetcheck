<?php
/**
 * App settings shell (governance, app-admins only).
 *
 * Split into one sub-page per section (DeskCheck pattern): the controller
 * validates `settingsSection` against {@see \OCA\BudgetCheck\Service\AppSettingsSectionCatalog}
 * and this template dispatches through a literal slug → file map, so no
 * request value is ever used to build an include path.
 *
 * Soft denial card kept for defense in depth (controller already redirects).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

$canAdminApp = !empty($_['canAdminApp']);
?>
<?php include __DIR__ . '/common/page-start.php'; ?>

<?php if (!$canAdminApp): ?>
	<section class="bc-card bc-empty">
		<h2><?php p($l->t('Access denied')); ?></h2>
		<p><?php p($l->t('You do not have permission to open app settings.')); ?></p>
	</section>
<?php else:
	$bcAppSettingsSectionFiles = [
		'access' => 'access.php',
		'admins' => 'admins.php',
		'defaults' => 'defaults.php',
		'support' => 'support.php',
	];
	$bcRequestedSection = (string) ($_['settingsSection'] ?? '');
	?>
	<div id="bc-app-settings-page" class="bc-app-settings">
<?php
	include __DIR__ . '/parts/app-settings-nav.php';
	if (!isset($bcAppSettingsSectionFiles[$bcRequestedSection])) {
		throw new \RuntimeException('BudgetCheck app settings: unknown section reached the template dispatcher.');
	}
	include __DIR__ . '/parts/app-settings/' . $bcAppSettingsSectionFiles[$bcRequestedSection];
?>
	</div>
<?php endif; ?>

<?php include __DIR__ . '/common/page-end.php'; ?>
