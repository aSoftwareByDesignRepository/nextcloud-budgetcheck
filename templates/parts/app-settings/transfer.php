<?php
/**
 * App settings sub-page: Transfer configuration (test → production).
 *
 * Thin dispatcher wrapper: the synced config-transfer pack ships its
 * canonical partial at templates/parts/settings/transfer.php (the pack's
 * fixed target for every consumer). BudgetCheck splits app-level settings
 * under templates/parts/app-settings/, so this file bridges the two —
 * it renders the synced partial unchanged.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/../settings/transfer.php';
