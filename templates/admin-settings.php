<?php

declare(strict_types=1);

/**
 * @var array $_
 * @var \OCP\IL10N $l
 *
 * BudgetCheck admin section. The full policy form lives inside the app where
 * managers, categories, savings, and tax mode are configured per workspace;
 * this section therefore acts as a deep link into the app and shows the
 * effective global defaults read-only so a system admin can verify them
 * without leaving the admin area.
 */

$appAdminUserIds = is_array($_['appAdminUserIds'] ?? null) ? $_['appAdminUserIds'] : [];
$defaultTimezone = (string)($_['defaultTimezone'] ?? 'Europe/Berlin');
$defaultCurrency = (string)($_['defaultCurrency'] ?? 'EUR');
$appUrl = (string)($_['appUrl'] ?? '');
?>
<div class="section bc-admin-settings" lang="<?php p(str_replace('_', '-', $l->getLanguageCode())); ?>">
	<header class="bc-admin-intro">
		<h2><?php p($l->t('BudgetCheck')); ?></h2>
		<p class="bc-admin-intro__lead">
			<?php p($l->t('Workspace settings, members, categories, savings targets and tax mode are managed inside BudgetCheck and scoped per workspace. Open the app to change the global defaults below.')); ?>
		</p>
		<p>
			<a class="button primary" href="<?php p($appUrl); ?>"><?php p($l->t('Open BudgetCheck')); ?></a>
		</p>
	</header>

	<dl class="bc-admin-summary">
		<dt><?php p($l->t('App administrators')); ?></dt>
		<dd><?php
			if ($appAdminUserIds === []) {
				p($l->t('Only Nextcloud system administrators (no app administrators configured).'));
			} else {
				p(implode(', ', $appAdminUserIds));
			}
		?></dd>
		<dt><?php p($l->t('Default timezone')); ?></dt>
		<dd><?php p($defaultTimezone); ?></dd>
		<dt><?php p($l->t('Default currency')); ?></dt>
		<dd><?php p($defaultCurrency); ?></dd>
	</dl>
</div>
