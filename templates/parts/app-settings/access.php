<?php
/**
 * App settings sub-page: Directory access (default section).
 *
 * Page H1 + lead come from the chrome (AppSettingsSectionCatalog). Section ids
 * are a stable contract for legacy /app-settings#… anchors.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
?>
<section class="bc-card bc-section" aria-labelledby="bc-access-gate-title">
	<form class="bc-form-grid" data-bc-app-policy-form data-bc-app-policy-scope="access">
		<fieldset class="bc-fieldset bc-app-policy-access">
			<legend class="bc-fieldset__legend"><?php p($l->t('Directory access')); ?></legend>
			<div class="bc-callout bc-callout--info" role="note" aria-labelledby="bc-access-gate-title">
				<p id="bc-access-gate-title"><strong><?php p($l->t('This list controls the door, not the data.')); ?></strong></p>
				<p class="bc-callout__hint"><?php p($l->t('Adding a user or group here only lets them open the app. To actually see or edit a ledger, they must be a member of a workspace. Add people or groups to a workspace from that workspace\'s “Members” section.')); ?></p>
			</div>
			<p id="bc-access-restriction-desc" class="bc-field__hint bc-field__hint--block">
				<?php p($l->t('When restriction is on, only the users and groups listed here may open BudgetCheck. Nextcloud server administrators and BudgetCheck app administrators always keep access. Everyone else still needs a workspace to see ledgers (unless they are an app administrator).')); ?>
			</p>
			<label class="bc-field bc-field--full-width bc-field--boolean">
				<span class="bc-field__label"><?php p($l->t('Restrict who may open the app')); ?></span>
				<span class="bc-boolean-control">
					<input type="checkbox" name="accessRestrictionEnabled" value="1" aria-describedby="bc-access-restriction-desc bc-access-restriction-hint">
					<span class="bc-boolean-control__text"><?php p($l->t('Require membership in the allowed users or groups list')); ?></span>
				</span>
			</label>
			<p id="bc-access-restriction-hint" class="bc-field__hint bc-field__hint--block">
				<?php p($l->t('When restriction is enabled, add at least one user or one group before saving.')); ?>
			</p>
			<div class="bc-field bc-field--full-width bc-entity-field">
				<span class="bc-field__label" id="bc-allowed-users-label"><?php p($l->t('Allowed users')); ?></span>
				<p id="bc-allowed-users-hint" class="bc-field__hint bc-field__hint--block"><?php p($l->t('Type at least two characters to search.')); ?></p>
				<ul class="bc-chip-list" role="list" data-bc-allowed-user-list aria-labelledby="bc-allowed-users-label"></ul>
				<div class="bc-entity-picker">
					<label for="bc-policy-users-q" class="bc-sr-only"><?php p($l->t('Search users to add to the allowed list')); ?></label>
					<input id="bc-policy-users-q" type="search" class="bc-input bc-entity-picker__q" autocomplete="off" maxlength="120" aria-describedby="bc-allowed-users-hint" placeholder="<?php p($l->t('Search users to add to the allowed list')); ?>">
					<div id="bc-policy-users-suggest" class="bc-entity-picker__suggest" hidden aria-live="polite"></div>
				</div>
			</div>
			<div class="bc-field bc-field--full-width bc-entity-field">
				<span class="bc-field__label" id="bc-allowed-groups-label"><?php p($l->t('Allowed groups')); ?></span>
				<p id="bc-allowed-groups-hint" class="bc-field__hint bc-field__hint--block"><?php p($l->t('Type at least two characters to search.')); ?></p>
				<ul class="bc-chip-list" role="list" data-bc-allowed-group-list aria-labelledby="bc-allowed-groups-label"></ul>
				<div class="bc-entity-picker">
					<label for="bc-policy-groups-q" class="bc-sr-only"><?php p($l->t('Search groups to add to the allowed list')); ?></label>
					<input id="bc-policy-groups-q" type="search" class="bc-input bc-entity-picker__q" autocomplete="off" maxlength="120" aria-describedby="bc-allowed-groups-hint" placeholder="<?php p($l->t('Search groups to add to the allowed list')); ?>">
					<div id="bc-policy-groups-suggest" class="bc-entity-picker__suggest" hidden aria-live="polite"></div>
				</div>
			</div>
		</fieldset>
		<div class="bc-form-actions">
			<button type="submit" class="button primary"><?php p($l->t('Save app policy')); ?></button>
		</div>
	</form>
</section>
<?php
// Stable id for legacy #bc-app-policy-title bookmarks (H1 is the page title now).
?>
<span id="bc-app-policy-title" class="bc-sr-only"><?php p($l->t('Access control')); ?></span>
