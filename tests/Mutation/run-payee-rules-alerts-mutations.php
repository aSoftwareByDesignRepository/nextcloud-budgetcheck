<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for the payee/rules/analytics/alerts/widget feature set:
 * each assertion names a security or correctness invariant a careless
 * refactor could silently break. Run:
 *   php tests/Mutation/run-payee-rules-alerts-mutations.php
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

$payees = (string) file_get_contents($root . '/lib/Service/PayeeService.php');
$rules = (string) file_get_contents($root . '/lib/Service/CategorizationRuleService.php');
$analytics = (string) file_get_contents($root . '/lib/Service/AnalyticsService.php');
$prefs = (string) file_get_contents($root . '/lib/Service/AlertPreferencesService.php');
$dispatch = (string) file_get_contents($root . '/lib/Service/AlertDispatchService.php');
$evaluate = (string) file_get_contents($root . '/lib/Service/AlertEvaluationService.php');
$widget = (string) file_get_contents($root . '/lib/Dashboard/BudgetStatusWidget.php');
$notifier = (string) file_get_contents($root . '/lib/Notification/Notifier.php');
$job = (string) file_get_contents($root . '/lib/BackgroundJob/AlertEvaluationJob.php');
$m1025 = (string) file_get_contents($root . '/lib/Migration/Version1025Date20261026090000.php');
$m1026 = (string) file_get_contents($root . '/lib/Migration/Version1026Date20261026100000.php');
$tx = (string) file_get_contents($root . '/lib/Service/TransactionService.php');
$import = (string) file_get_contents($root . '/lib/Service/TransactionImportService.php');
$api = (string) file_get_contents($root . '/lib/Controller/ApiController.php');
$mobile = (string) file_get_contents($root . '/lib/Controller/MobileApiController.php');
$export = (string) file_get_contents($root . '/lib/Controller/ExportController.php');
$routes = (string) file_get_contents($root . '/appinfo/routes.php');
$app = (string) file_get_contents($root . '/lib/AppInfo/Application.php');
$info = (string) file_get_contents($root . '/appinfo/info.xml');
$catalog = (string) file_get_contents($root . '/lib/Migration/BudgetCheckTableCatalog.php');
$uninstall = (string) file_get_contents($root . '/lib/Repair/UninstallDropTables.php');
$schema = (string) file_get_contents($root . '/lib/Repair/SchemaTableCatalog.php');
$backup = (string) file_get_contents($root . '/lib/Service/UpgradeBackupCatalog.php');

// ---- Payees -----------------------------------------------------------
$assert(str_contains($m1025, "createTable('bc_payees')"), 'm1025_creates_payees');
$assert(str_contains($m1025, "addUniqueIndex(['workspace_id', 'name_norm']"), 'payees_unique_norm_per_workspace');
$assert(str_contains($m1025, "'payee_id'"), 'm1025_tx_payee_column');
$assert(str_contains($payees, 'normalizeName'), 'payee_normalize_exists');
$assert(str_contains($payees, 'mb_strtolower'), 'payee_norm_lowercases');
$assert(str_contains($payees, 'Normalizer::FORM_C'), 'payee_norm_nfc');
$assert(str_contains($payees, 'findByNorm') && str_contains($payees, 'isUniqueConstraintViolation'), 'payee_create_idempotent');
$assert(str_contains($payees, 'beginTransaction'), 'payee_merge_transactional');
$assert(str_contains($payees, 'rollBack'), 'payee_merge_rollback');
$assert(str_contains($payees, 'AccessControlService::ROLE_MANAGER'), 'payee_write_manager_gated');
$assert(str_contains($payees, 'ensureMembership'), 'payee_read_membership_gated');
$assert(str_contains($payees, "throw new AccessDeniedException()"), 'payee_foreign_id_denies');
$assert(str_contains($payees, 'WorkspaceRowLock::acquire'), 'payee_merge_holds_workspace_lock');

// ---- Rules ------------------------------------------------------------
$assert(str_contains($m1025, "createTable('bc_category_rules')"), 'm1025_creates_rules');
$assert(str_contains($rules, "orderBy('priority', 'ASC')"), 'rules_sorted_by_priority');
$assert(str_contains($rules, 'str_contains($hay, $needle)'), 'rules_contains_literal');
$assert(str_contains($rules, 'str_starts_with($hay, $needle)'), 'rules_starts_with_literal');
$assert(str_contains($rules, 'pcre.backtrack_limit'), 'rules_regex_redos_guard');
$assert(str_contains($rules, 'REGEX_MAX_PATTERN'), 'rules_regex_length_capped');
$assert(str_contains($rules, 'use_regex'), 'rules_regex_opt_in');
$assert(str_contains($rules, 'version'), 'rules_cas_version');
$assert(str_contains($rules, 'version_conflict'), 'rules_cas_conflict');
$assert(str_contains($rules, 'MAX_RULES_PER_WORKSPACE'), 'rules_cap_1000');
$assert(str_contains($rules, 'AccessControlService::ROLE_MANAGER'), 'rules_write_manager_gated');
$assert(str_contains($import, 'ruleMatchForRow'), 'import_applies_rules');
$assert(str_contains($import, 'recordHits'), 'import_commit_records_hits');
$assert(str_contains($import, "'__ruleId'"), 'import_rule_hit_tracking');

// ---- Analytics ---------------------------------------------------------
$assert(str_contains($analytics, "self::MIN_MONTHS"), 'trend_min_bound');
$assert(str_contains($analytics, 'self::MAX_MONTHS'), 'trend_max_bound');
$assert(str_contains($analytics, "'linear_mtd_plus_scheduled'"), 'forecast_method_named');
$assert(str_contains($analytics, 'dataSufficient'), 'forecast_data_sufficient_flag');
$assert(str_contains($analytics, 'WorkspaceTypeMismatchException'), 'forecast_household_only');
$assert(str_contains($analytics, 'groupBy'), 'toppayees_grouped_serverside');
$assert(str_contains($analytics, "'(No payee)'"), 'toppayees_null_bucket_labelled');
$assert(str_contains($analytics, 'MAX_PAYEE_LIMIT'), 'toppayees_limit_capped');
$assert(str_contains($analytics, 'isNull'), 'analytics_excludes_deleted');
$assert(str_contains($analytics, 'is_planned'), 'analytics_excludes_planned');

// ---- Alerts ------------------------------------------------------------
$assert(str_contains($m1026, "createTable('bc_alert_log')"), 'm1026_creates_alert_log');
$assert(str_contains($m1026, "addUniqueIndex("), 'alert_dedupe_unique_index');
$assert(str_contains($m1026, "'dedupe_key'"), 'alert_dedupe_key_column');
$assert(str_contains($dispatch, 'isUniqueConstraintViolation'), 'alert_dedupe_insert_then_send');
$assert(str_contains($dispatch, 'canSend'), 'alert_dispatch_checks_prefs');
$assert(str_contains($dispatch, 'try {'), 'alert_send_wrapped');
$assert(str_contains($prefs, "'alerts_enabled'"), 'alert_master_switch');
$assert(str_contains($prefs, 'self::TYPES'), 'alert_per_type_toggles');
$assert(str_contains($prefs, 'inQuietHours'), 'alert_quiet_hours');
$assert(str_contains($prefs, 'thresholdPercent must be between 50 and 100'), 'alert_threshold_validated');
$assert(str_contains($evaluate, 'evaluateBudgetThresholds'), 'alert_budget_evaluator');
$assert(str_contains($evaluate, 'evaluateMonthlyDigest'), 'alert_digest_evaluator');
$assert(str_contains($evaluate, 'evaluateSavings'), 'alert_savings_evaluator');
$assert(str_contains($evaluate, 'notifyRecurringResult'), 'alert_recurring_evaluator');
$assert(str_contains($job, 'TimedJob'), 'alert_job_timed');
$assert(str_contains($job, 'prune'), 'alert_job_prunes_log');
$assert(str_contains($job, "'household'"), 'alert_job_household_only');
$assert(str_contains($notifier, 'UnknownNotificationException'), 'notifier_rejects_other_apps');
$assert(str_contains($notifier, 'setParsedSubject'), 'notifier_renders_subject');
$assert(str_contains($info, 'AlertEvaluationJob'), 'info_xml_registers_job');

// ---- Dashboard widget ---------------------------------------------------
$assert(str_contains($widget, 'IAPIWidgetV2'), 'widget_implements_api_v2');
$assert(str_contains($widget, 'IButtonWidget'), 'widget_has_button');
$assert(str_contains($widget, 'IReloadableWidget'), 'widget_reloadable');
$assert(str_contains($widget, 'dashboardWidgetEnabled'), 'widget_kill_switch');
$assert(str_contains($widget, 'catch (\\Throwable'), 'widget_never_breaks_dashboard');
$assert(str_contains($widget, '$this->cache'), 'widget_per_request_cache');
$assert(str_contains($widget, 'TYPE_HOUSEHOLD'), 'widget_household_only');
$assert(str_contains($app, 'registerDashboardWidget'), 'app_registers_widget');
$assert(str_contains($app, 'registerNotifierService'), 'app_registers_notifier');

// ---- Catalogs / wiring ---------------------------------------------------
foreach (['bc_payees', 'bc_category_rules', 'bc_alert_log'] as $t) {
	$assert(str_contains($catalog, "'$t'"), "catalog_lists_$t");
	$assert(str_contains($uninstall, "'$t'"), "uninstall_drops_$t");
	$assert(str_contains($schema, "'$t'"), "schema_catalog_lists_$t");
	$assert(str_contains($backup, "'$t'"), "backup_includes_$t");
}
$assert(str_contains($routes, 'api/payees'), 'route_payees');
$assert(str_contains($routes, 'api/categorization-rules'), 'route_rules');
$assert(str_contains($routes, 'api/month-forecast'), 'route_forecast');
$assert(str_contains($routes, 'alert-preferences'), 'route_alert_prefs');
$assert(str_contains($routes, 'mobile/v1/workspaces/{workspaceId}/payees'), 'route_mobile_payees');
$assert(str_contains($routes, 'payees'), 'settings_nav_has_payees');
$assert(str_contains($routes, 'categorization-rules'), 'settings_nav_has_rules');

// ---- Transaction payee + strict years ------------------------------------
$assert(str_contains($tx, "'payee_id'"), 'tx_writes_payee_id');
$assert(str_contains($tx, "'payeeName'"), 'tx_hydrates_payee_name');
$assert(str_contains($tx, "bc_payees"), 'tx_joins_payee');
$assert(str_contains($tx, 'resolvePayeeIdForWrite'), 'tx_resolves_payee_on_write');
$assert(substr_count($tx, 'CODE_PAYEE_GONE') === 2, 'tx_payee_recheck_under_lock');
$assert(str_contains($rules, 'closedYearMonths'), 'backfill_skips_closed_months');
$assert(str_contains($rules, 'PARAM_INT_ARRAY'), 'backfill_still_uncat_guard');
$assert(str_contains($mobile, 'strictYearParam'), 'mobile_strict_years');
$assert(str_contains($mobile, "/^\\d{4}$/"), 'mobile_year_regex');
$assert(str_contains($export, 'year and compareYear must differ'), 'export_rejects_self_compare');
$assert(str_contains($mobile, "'payeeId'"), 'mobile_write_accepts_payee_id');
$assert(str_contains($mobile, "'yearlyCategoryCompare' => true"), 'mobile_capability_compare');
$assert(str_contains($mobile, "'payees' => true"), 'mobile_capability_payees');


// ---- NH additions: email channel, rules export, second widget, trend overlay --
$widget2 = (string) file_get_contents($root . '/lib/Dashboard/UpcomingRecurringWidget.php');
$assert(str_contains($dispatch, 'sendEmail'), 'alert_email_channel');
$assert(str_contains($dispatch, 'IMailer'), 'alert_email_mailer_dep');
$assert(str_contains($dispatch, 'emailEnabled'), 'alert_email_opt_in');
$assert(str_contains($dispatch, 'validateMailAddress'), 'alert_email_validates_address');
$assert(str_contains($dispatch, 'setAutoSubmitted'), 'alert_email_auto_submitted');
$assert(str_contains($rules, 'exportCsv'), 'rules_export_exists');
$assert(str_contains($rules, 'guardCsvCell'), 'rules_export_formula_guard');
$assert(str_contains($rules, '"\xEF\xBB\xBF"'), 'rules_export_utf8_bom');
$assert(str_contains($rules, 'ROLE_MANAGER'), 'rules_export_manager_gated');
$assert(str_contains($routes, 'categorization-rules/export'), 'route_rules_export');
$assert(str_contains($widget2, 'UpcomingRecurringWidget'), 'widget2_exists');
$assert(str_contains($widget2, 'IAPIWidgetV2'), 'widget2_api_v2');
$assert(str_contains($widget2, 'dashboardWidgetEnabled'), 'widget2_kill_switch');
$assert(str_contains($widget2, 'catch (\\Throwable'), 'widget2_fail_silent');
$assert(str_contains($app, 'UpcomingRecurringWidget::class'), 'app_registers_widget2');
$dash = (string) file_get_contents($root . '/js/dashboard.js');
$assert(str_contains($dash, 'bc-insight-compare'), 'trend_compare_select');
$assert(str_contains($dash, 'Promise.all'), 'trend_compare_parallel_fetch');
$assert(str_contains($dash, 'bc-trend-bars__bar--compare'), 'trend_compare_bar_class');
$assert(str_contains($dash, "role: 'listitem'"), 'trend_bars_keep_list_semantics');

fwrite(STDOUT, $failed === 0 ? "\nAll mutations killed.\n" : "\n{$failed} mutant(s) survived.\n");
exit($failed === 0 ? 0 : 1);
