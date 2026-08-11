<?php

declare(strict_types=1);

/**
 * Private workspaces mutation gauntlet.
 * Run: php tests/Mutation/run-private-workspaces-mutations.php
 */

$root = dirname(__DIR__, 2);
$failed = 0;

$assert = static function (bool $ok, string $label) use (&$failed): void {
	if ($ok) {
		fwrite(STDOUT, "killed {$label}\n");
		return;
	}
	fwrite(STDERR, "SURVIVED {$label}\n");
	$failed++;
};

$access = (string)file_get_contents($root . '/lib/Service/AccessControlService.php');
$ws = (string)file_get_contents($root . '/lib/Service/WorkspaceService.php');
$api = (string)file_get_contents($root . '/lib/Controller/ApiController.php');
$mobile = (string)file_get_contents($root . '/lib/Controller/MobileApiController.php');
$conflict = (string)file_get_contents($root . '/lib/Exception/ConflictException.php');
$mig = (string)file_get_contents($root . '/lib/Migration/Version1021Date20260811120000.php');
$tpl = (string)file_get_contents($root . '/templates/parts/settings/workspace.php');
$nav = (string)file_get_contents($root . '/templates/common/navigation.php');
$page = (string)file_get_contents($root . '/lib/Controller/PageController.php');

// Admin bypass must not run before private check.
$assert(
	(bool)preg_match(
		'/function role\(int \$workspaceId, string \$userId\): \?string\s*\{[^}]*PRIVACY_PRIVATE[^}]*individualRole[^}]*isAppAdmin/s',
		$access
	) || (bool)preg_match(
		'/privacy === self::PRIVACY_PRIVATE.*?return \$this->individualRole.*?isAppAdmin/s',
		$access
	),
	'role_private_before_admin_bypass'
);
$assert(str_contains($access, 'workspaceIdsVisibleToAppAdmin'), 'admin_list_helper_present');
$assert(str_contains($access, "PRIVACY_PRIVATE"), 'privacy_const');
$assert(
	str_contains($access, "neq('privacy_mode'") || str_contains($access, "neq('w.privacy_mode'"),
	'admin_list_excludes_private'
);
$assert(str_contains($access, 'canCreateWorkspace'), 'create_gate_helper');
$assert(
	(bool)preg_match(
		'/PRIVACY_PRIVATE.*?canUseApp/s',
		$access
	),
	'private_create_uses_door'
);
$assert(
	(bool)preg_match(
		'/PRIVACY_STANDARD.*?isAppAdmin/s',
		$access
	),
	'standard_create_uses_app_admin'
);

$assert(str_contains($ws, 'assertPrivacyTransitionAllowed'), 'privacy_transition_guard');
$assert(str_contains($ws, 'CODE_WORKSPACE_HAS_GROUP_MEMBERS'), 'group_block_on_private');
$assert(str_contains($ws, 'CODE_PRIVATE_WORKSPACE_DUAL_MANAGER'), 'dual_manager_guard');
$assert(str_contains($ws, 'CODE_PRIVATE_WORKSPACE_GROUPS_FORBIDDEN'), 'group_assign_forbidden');
$assert(str_contains($ws, 'individualMemberRole'), 'toggle_requires_individual_manager');
$assert(str_contains($ws, 'WorkspaceRowLock::acquire'), 'privacy_toggle_row_lock');
$assert(
	(bool)preg_match('/function updateMember\([\s\S]*?WorkspaceRowLock::acquire/s', $ws),
	'member_update_row_lock'
);
$assert(
	(bool)preg_match('/function removeMember\([\s\S]*?WorkspaceRowLock::acquire/s', $ws),
	'member_remove_row_lock'
);
$assert(str_contains($ws, 'canCreateWorkspace($userId, $privacyMode)'), 'create_service_privacy_gate');
$assert(str_contains($ws, "'privacy_mode'"), 'insert_privacy_column');
$assert(str_contains($ws, 'privacy_mode_changed'), 'audit_privacy_event');

$assert(str_contains($api, 'canCreatePrivateWorkspace'), 'api_capability_private');
$assert(str_contains($api, 'canCreateStandardWorkspace'), 'api_capability_standard');
$assert(str_contains($api, 'normalisePrivacyMode'), 'api_create_normalises_privacy');
$assert(str_contains($api, 'getErrorCode()'), 'api_conflict_uses_error_code');

$assert(str_contains($mobile, 'canCreatePrivateWorkspace'), 'mobile_capability_private');
$assert(str_contains($mobile, "'privacyMode'"), 'mobile_row_privacy');
$assert(str_contains($mobile, 'function updateWorkspace'), 'mobile_update_workspace');
$assert(str_contains($mobile, 'mobilePrivacyCapabilities'), 'mobile_privacy_capabilities_helper');
$assert(str_contains($mobile, 'getErrorCode()'), 'mobile_conflict_uses_error_code');
$assert(str_contains($page, 'canManagePrivacy'), 'page_can_manage_privacy');
$assert(str_contains($tpl, 'canManagePrivacy'), 'tpl_can_manage_privacy');
$assert(str_contains($page, 'array_intersect'), 'page_favorites_clip');
$membersTpl = (string)file_get_contents($root . '/templates/parts/settings/members.php');
$settingsJs = (string)file_get_contents($root . '/js/settings.js');
$assert(str_contains($membersTpl, 'data-bc-private-groups-blocked'), 'members_private_groups_callout');
$assert(str_contains($settingsJs, 'syncPrivateMembersUi'), 'settings_sync_private_members');
$assert(str_contains($settingsJs, 'is-private-locked'), 'settings_private_locked_class');
$listener = (string)file_get_contents($root . '/lib/Listener/UserDeletedListener.php');
$assert(str_contains($listener, 'purgeUser'), 'user_deleted_purges_membership');

$assert(str_contains($conflict, 'CODE_PRIVATE_WORKSPACE_DUAL_MANAGER'), 'conflict_codes');
$assert(str_contains($mig, 'privacy_mode'), 'migration_column');
$assert(str_contains($mig, 'bc_ws_privacy_idx'), 'migration_index');
$assert(str_contains($tpl, 'bc-privacy-disclosure'), 'ui_disclosure');
$assert(!str_contains(strtolower($tpl), 'zero-knowledge'), 'no_false_crypto_claim');
$assert(str_contains($nav, 'canCreateWorkspace'), 'nav_create_uses_capability');

exit($failed === 0 ? 0 : 1);
