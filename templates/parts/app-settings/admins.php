<?php
/**
 * App settings sub-page: App administrators.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
?>
<section class="bc-card bc-section" aria-labelledby="bc-app-admin-legend">
	<form class="bc-form-grid" data-bc-app-policy-form data-bc-app-policy-scope="admins">
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
		<div class="bc-form-actions">
			<button type="submit" class="button primary"><?php p($l->t('Save app policy')); ?></button>
		</div>
	</form>
</section>
