<?php

declare(strict_types=1);

/**
 * @var array $_
 * @var \OCP\IL10N $l
 * @var array<string,mixed> $policy
 *
 * BudgetCheck admin section. The editable policy form lives inside the app for
 * delegated app administrators; this page is a read-only digest for Nextcloud
 * server administrators plus a deep link into the app.
 */

$policy = is_array($_['policy'] ?? null) ? $_['policy'] : [];
$appAdminUserIds = is_array($policy['appAdminUserIds'] ?? null) ? $policy['appAdminUserIds'] : [];
$appAdminsPreview = is_array($policy['appAdminsPreview'] ?? null) ? $policy['appAdminsPreview'] : [];
$defaultTimezone = (string)($policy['defaultTimezone'] ?? 'Europe/Berlin');
$defaultCurrency = (string)($policy['defaultCurrency'] ?? 'EUR');
$restrictionOn = !empty($policy['accessRestrictionEnabled']);
$allowedUserIds = is_array($policy['allowedUserIds'] ?? null) ? $policy['allowedUserIds'] : [];
$allowedGroupIds = is_array($policy['allowedGroupIds'] ?? null) ? $policy['allowedGroupIds'] : [];
$appUrl = (string)($_['appUrl'] ?? '');
?>
<div class="section bc-admin-settings" lang="<?php p(str_replace('_', '-', $l->getLanguageCode())); ?>">
	<header class="bc-admin-intro">
		<h2><?php p($l->t('BudgetCheck')); ?></h2>
		<p class="bc-admin-intro__lead">
			<?php p($l->t('Workspace details, members, categories, savings targets and tax mode are managed in BudgetCheck under Workspace settings (workspace managers). Global directory access and app administrators are edited under App settings in the app.')); ?>
		</p>
		<p>
			<a class="button primary" href="<?php p($appUrl); ?>"><?php p($l->t('Open BudgetCheck')); ?></a>
		</p>
	</header>

	<dl class="bc-admin-summary">
		<dt><?php p($l->t('Directory access restriction')); ?></dt>
		<dd><?php p($restrictionOn ? $l->t('Enabled — only listed users or group members may open the app (server and app administrators excluded).') : $l->t('Disabled — any account with workspace membership may open the app.')); ?></dd>
		<dt><?php p($l->t('Allowed users (when restriction is on)')); ?></dt>
		<dd><?php p($restrictionOn ? (string)count($allowedUserIds) : '-'); ?></dd>
		<dt><?php p($l->t('Allowed groups (when restriction is on)')); ?></dt>
		<dd><?php p($restrictionOn ? (string)count($allowedGroupIds) : '-'); ?></dd>
		<dt><?php p($l->t('App administrators')); ?></dt>
		<dd><?php
			if ($appAdminUserIds === []) {
				p($l->t('Only Nextcloud system administrators (no app administrators configured).'));
			} elseif ($appAdminsPreview !== []) {
				$labels = [];
				foreach ($appAdminsPreview as $row) {
					if (!is_array($row)) {
						continue;
					}
					$id = isset($row['id']) ? (string)$row['id'] : '';
					$dn = isset($row['displayName']) ? (string)$row['displayName'] : $id;
					if ($id === '') {
						continue;
					}
					$labels[] = $dn !== '' && $dn !== $id ? $dn . ' (' . $id . ')' : $id;
				}
				p(implode(', ', $labels !== [] ? $labels : $appAdminUserIds));
			} else {
				p(implode(', ', $appAdminUserIds));
			}
		?></dd>
		<dt><?php p($l->t('Default timezone')); ?></dt>
		<dd><?php p($defaultTimezone); ?></dd>
		<dt><?php p($l->t('Default currency')); ?></dt>
		<dd><?php p($defaultCurrency); ?></dd>
		<dt><?php p($l->t('Private workspaces')); ?></dt>
		<dd><?php
			$count = isset($policy['privateWorkspaceCount']) ? (int)$policy['privateWorkspaceCount'] : 0;
			p($l->n('%n private workspace (names hidden from administrators who are not members)', '%n private workspaces (names hidden from administrators who are not members)', $count));
		?></dd>
	</dl>
</div>
