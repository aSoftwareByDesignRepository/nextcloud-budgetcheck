<?php
/**
 * App settings sub-page: AI access (MCP) — scoped agent tokens.
 *
 * Thin dispatcher wrapper: the synced agent-auth pack ships its canonical
 * partial at templates/parts/settings/agent.php (the pack's fixed target
 * for every consumer). BudgetCheck splits app-level settings under
 * templates/parts/app-settings/, so this file bridges the two — it renders
 * the synced partial unchanged. The partial self-guards on
 * $agentAccess['visible'].
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
$agentAccess = is_array($agentAccess ?? null) ? $agentAccess : ($_['agentAccess'] ?? null);
include __DIR__ . '/../settings/agent.php';
