<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */

$canAdminApp = !empty($_['canAdminApp']);
?>
<?php include __DIR__ . '/common/page-start.php'; ?>

<?php if (!$canAdminApp): ?>
	<section class="bc-card bc-empty">
		<h2><?php p($l->t('Access denied')); ?></h2>
		<p><?php p($l->t('You do not have permission to open app settings.')); ?></p>
	</section>
<?php else: ?>
	<section class="bc-card bc-section" aria-labelledby="bc-app-policy-title">
		<header class="bc-section__header">
			<div>
				<h2 id="bc-app-policy-title"><?php p($l->t('App policy (admins only)')); ?></h2>
				<p class="bc-section__sub"><?php p($l->t('Who may open the app, delegated administrators, and defaults for new workspaces.')); ?></p>
			</div>
		</header>
		<form class="bc-form-grid" data-bc-app-policy-form>
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
			<fieldset class="bc-fieldset bc-app-policy-admins">
				<legend id="bc-app-admin-legend" class="bc-fieldset__legend"><?php p($l->t('App administrators')); ?></legend>
				<p id="bc-app-admin-hint" class="bc-field__hint bc-field__hint--block"><?php p($l->t('Only real Nextcloud user accounts can be selected. Unknown logins are rejected when you save.')); ?></p>
				<p id="bc-app-admin-search-hint" class="bc-field__hint bc-field__hint--block"><?php p($l->t('Type at least two characters to search.')); ?></p>
				<ul class="bc-chip-list" role="list" data-bc-app-admin-list aria-labelledby="bc-app-admin-legend"></ul>
				<div class="bc-entity-picker">
					<label for="bc-policy-admins-q" class="bc-sr-only"><?php p($l->t('Search users to add as app administrators')); ?></label>
					<input id="bc-policy-admins-q" type="search" class="bc-input bc-entity-picker__q" autocomplete="off" maxlength="120" aria-describedby="bc-app-admin-hint bc-app-admin-search-hint" placeholder="<?php p($l->t('Search users to add as app administrators')); ?>">
					<div id="bc-policy-admins-suggest" class="bc-entity-picker__suggest" hidden aria-live="polite"></div>
				</div>
			</fieldset>
			<fieldset class="bc-fieldset bc-fieldset--defaults">
				<legend class="bc-fieldset__legend"><?php p($l->t('Defaults for new workspaces')); ?></legend>
				<p class="bc-field__hint bc-field__hint--block"><?php p($l->t('These values pre-fill the form when an app administrator creates a workspace. They do not change existing workspaces.')); ?></p>
			<div class="bc-field bc-field--catalog">
				<span class="bc-field__label" id="bc-policy-timezone-label"><?php p($l->t('Default timezone')); ?></span>
				<?php
				$pickerId = 'bc-policy-timezone';
				$pickerName = 'defaultTimezone';
				$pickerDefault = 'Europe/Berlin';
				$pickerDisabled = false;
				$pickerDescribedBy = 'bc-policy-timezone-hint';
				include __DIR__ . '/common/bc-timezone-picker.php';
				?>
				<p id="bc-policy-timezone-hint" class="bc-field__hint"><?php p($l->t('Pre-selected when someone creates a new workspace.')); ?></p>
			</div>
			<div class="bc-field bc-field--catalog">
				<span class="bc-field__label" id="bc-policy-currency-label"><?php p($l->t('Default currency')); ?></span>
				<?php
				$pickerId = 'bc-policy-currency';
				$pickerName = 'defaultCurrency';
				$pickerDefault = 'EUR';
				$pickerDisabled = false;
				$pickerDescribedBy = 'bc-policy-currency-hint';
				include __DIR__ . '/common/bc-currency-picker.php';
				?>
				<p id="bc-policy-currency-hint" class="bc-field__hint"><?php p($l->t('Pre-selected when someone creates a new workspace.')); ?></p>
			</div>
			</fieldset>
			<div class="bc-form-actions">
				<button type="submit" class="button primary"><?php p($l->t('Save app policy')); ?></button>
			</div>
		</form>
	</section>
<?php endif; ?>

<?php include __DIR__ . '/common/page-end.php'; ?>
