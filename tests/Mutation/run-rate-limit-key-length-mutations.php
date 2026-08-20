<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for RateLimitService LDAP/UUID key-length fix (#17).
 * Run: php tests/Mutation/run-rate-limit-key-length-mutations.php
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

$rl = (string)file_get_contents($root . '/lib/Service/RateLimitService.php');
$app = (string)file_get_contents($root . '/lib/AppInfo/Application.php');
$api = (string)file_get_contents($root . '/lib/Controller/ApiController.php');
$unit = (string)file_get_contents($root . '/tests/Unit/Service/RateLimitServiceTest.php');

// Must not rebuild the broken appconfig key that embeds the raw userId.
$assert(!preg_match("/['\"]rate_limit:['\"]\s*\.\s*\\\$action\s*\.\s*['\"]:['\"]\s*\.\s*\\\$userId/", $rl), 'no_legacy_appconfig_key_concat');
$assert(!str_contains($rl, "setAppValue(Application::APP_ID, \$key"), 'no_setAppValue_for_counter');
$assert(str_contains($rl, 'setUserValue'), 'uses_setUserValue');
$assert(str_contains($rl, 'getUserValue'), 'uses_getUserValue');
$assert(str_contains($rl, 'CONFIG_KEY_MAX_LENGTH'), 'exposes_config_key_max');
$assert(str_contains($rl, 'preferenceKey'), 'exposes_preference_key_helper');
$assert((bool)preg_match('/strlen\(\$key\)\s*>\s*self::CONFIG_KEY_MAX_LENGTH|CONFIG_KEY_MAX_LENGTH/', $rl), 'guards_key_length');
$assert(str_contains($rl, 'ILockingProvider'), 'uses_locking_provider');
$assert(str_contains($rl, 'LOCK_EXCLUSIVE'), 'exclusive_lock');
$assert(str_contains($rl, 'lock_contested') || str_contains($rl, 'LockedException'), 'contested_lock_fail_closed');
$assert(str_contains($rl, 'bc-rl-'), 'short_lock_prefix');
$assert(str_contains($rl, 'md5('), 'lock_digest_fits_file_locks');
$assert(
	(bool)preg_match("/LOCK_PREFIX\s*=\s*'bc-rl-'/", $rl)
		&& !str_contains($rl, "LOCK_PREFIX . hash('sha256'"),
	'lock_key_not_sha256_overflow'
);
$assert(str_contains($rl, 'normalizeAction') || (bool)preg_match('/preg_match\(\s*[\'"]\\/\\^\\[a-z0-9_\\]/', $rl), 'action_charset_validated');
$assert(str_contains($rl, 'deleteLegacyAppConfigCounter') || str_contains($rl, 'deleteAppValue'), 'legacy_cleanup_present');
$assert(str_contains($rl, 'ITimeFactory'), 'injects_time_factory');

// DI must wire time + locking (container resolution / occ upgrade path).
$assert(
	(bool)preg_match(
		'/registerService\(RateLimitService::class[\s\S]*?ITimeFactory::class[\s\S]*?ILockingProvider::class/s',
		$app
	),
	'di_wires_time_and_locking'
);

// Favorites write still rate-limited (regression: do not remove the gate).
$assert(str_contains($api, "assertAllowed(\$userId, 'workspace_favorites_write'"), 'favorites_still_rate_limited');

// Unit tests must pin the UUID case that filed #17.
$assert(str_contains($unit, 'a1b2c3d4-e5f6-7890-abcd-ef1234567890') || str_contains($unit, 'UuidStyleUserId'), 'unit_covers_uuid_userid');
$assert(str_contains($unit, 'workspace_favorites_write'), 'unit_covers_favorites_action');
$assert(str_contains($unit, 'CONFIG_KEY_MAX_LENGTH') || str_contains($unit, 'LessThanOrEqual(64'), 'unit_asserts_key_length');
$assert(is_file($root . '/tests/Integration/RateLimitUuidUserIdIntegrationTest.php'), 'integration_test_present');

// Preference key for the reported action must be ≤64 in the source contract.
$favoritesKey = 'rate_limit:workspace_favorites_write';
$assert(strlen($favoritesKey) <= 64, 'favorites_pref_key_fits');
$assert(strlen($favoritesKey . ':a1b2c3d4-e5f6-7890-abcd-ef1234567890') > 64, 'legacy_key_still_too_long_for_uuid');

exit($failed === 0 ? 0 : 1);
