<?php
/**
 * Workspace settings sub-page: Members (managers only).
 *
 * Controller already redirects non-managers; soft denial is defense in depth
 * if that gate regresses.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

$canManage = !empty($_['canManageWorkspace']);
?>
<?php if (!$canManage): ?>
	<section class="bc-card bc-empty" role="status">
		<h2><?php p($l->t('Managers only')); ?></h2>
		<p><?php p($l->t('Only workspace managers can invite members or change roles.')); ?></p>
	</section>
<?php else: ?>
<section class="bc-card bc-section" aria-labelledby="bc-members-title">
	<header class="bc-section__header">
		<div>
			<h2 id="bc-members-title" class="bc-sr-only"><?php p($l->t('Members')); ?></h2>
			<p class="bc-section__sub"><?php p($l->t('Give people access to this workspace by adding them as a user or by adding a whole group. Each member is a manager, contributor, or viewer.')); ?></p>
		</div>
	</header>

	<div class="bc-member-invite" data-bc-member-invite aria-labelledby="bc-member-invite-title">
		<h3 id="bc-member-invite-title" class="bc-member-invite__title"><?php p($l->t('Add a person')); ?></h3>
		<p id="bc-member-invite-hint" class="bc-field__hint bc-field__hint--block"><?php p($l->t('Type at least two characters to search. Add users one by one, choosing a role for the selected user each time.')); ?></p>
		<div class="bc-member-invite__grid">
			<div class="bc-entity-picker bc-member-invite__search">
				<label for="bc-member-invite-q" class="bc-sr-only"><?php p($l->t('Search directory for a user to add')); ?></label>
				<input id="bc-member-invite-q" type="search" class="bc-input bc-entity-picker__q" autocomplete="off" maxlength="120" aria-describedby="bc-member-invite-hint" placeholder="<?php p($l->t('Search directory for a user to add')); ?>">
				<div id="bc-member-invite-suggest" class="bc-entity-picker__suggest" hidden aria-live="polite"></div>
			</div>
			<label class="bc-field bc-member-invite__role">
				<span class="bc-field__label"><?php p($l->t('Role for selected user')); ?></span>
				<select id="bc-member-invite-role" class="bc-input" data-bc-member-invite-role>
					<option value="viewer"><?php p($l->t('Viewer')); ?></option>
					<option value="contributor"><?php p($l->t('Contributor')); ?></option>
					<option value="manager"><?php p($l->t('Manager')); ?></option>
				</select>
			</label>
			<button type="button" class="button primary bc-member-invite__submit" data-bc-action="member-invite-submit">
				<?php p($l->t('Add to workspace')); ?>
			</button>
		</div>
		<div class="bc-member-picked" data-bc-member-selected-wrap hidden role="status" aria-live="polite">
			<p class="bc-member-picked__label"><?php p($l->t('Selected user')); ?></p>
			<div class="bc-member-picked__row">
				<div>
					<p class="bc-member-picked__value" data-bc-member-selected></p>
					<p class="bc-member-picked__meta" data-bc-member-selected-role></p>
				</div>
				<button type="button" class="button bc-member-picked__clear" data-bc-action="member-invite-clear"><?php p($l->t('Clear selection')); ?></button>
			</div>
		</div>
	</div>

	<div class="bc-member-invite" data-bc-group-invite aria-labelledby="bc-group-invite-title" data-bc-group-invite-panel>
		<h3 id="bc-group-invite-title" class="bc-member-invite__title"><?php p($l->t('Add a group')); ?></h3>
		<p id="bc-group-invite-hint" class="bc-field__hint bc-field__hint--block"><?php p($l->t('Everyone in the group gets the chosen role. Groups can be contributors or viewers; assign managers to individual people so responsibility stays clear.')); ?></p>
		<p class="bc-callout bc-callout--warning" data-bc-private-groups-blocked hidden role="status">
			<?php p($l->t('This workspace is private. Only individual people can be members — groups are turned off so access stays under your control.')); ?>
		</p>
		<div class="bc-member-invite__grid">
			<div class="bc-entity-picker bc-member-invite__search">
				<label for="bc-group-invite-q" class="bc-sr-only"><?php p($l->t('Search directory for a group to add')); ?></label>
				<input id="bc-group-invite-q" type="search" class="bc-input bc-entity-picker__q" autocomplete="off" maxlength="120" aria-describedby="bc-group-invite-hint" placeholder="<?php p($l->t('Search directory for a group to add')); ?>">
				<div id="bc-group-invite-suggest" class="bc-entity-picker__suggest" hidden aria-live="polite"></div>
			</div>
			<label class="bc-field bc-member-invite__role">
				<span class="bc-field__label"><?php p($l->t('Role for selected group')); ?></span>
				<select id="bc-group-invite-role" class="bc-input" data-bc-group-invite-role>
					<option value="viewer"><?php p($l->t('Viewer')); ?></option>
					<option value="contributor"><?php p($l->t('Contributor')); ?></option>
				</select>
			</label>
			<button type="button" class="button primary bc-member-invite__submit" data-bc-action="group-invite-submit">
				<?php p($l->t('Add group to workspace')); ?>
			</button>
		</div>
		<div class="bc-member-picked" data-bc-group-selected-wrap hidden role="status" aria-live="polite">
			<p class="bc-member-picked__label"><?php p($l->t('Selected group')); ?></p>
			<div class="bc-member-picked__row">
				<div>
					<p class="bc-member-picked__value" data-bc-group-selected></p>
					<p class="bc-member-picked__meta" data-bc-group-selected-role></p>
				</div>
				<button type="button" class="button bc-member-picked__clear" data-bc-action="group-invite-clear"><?php p($l->t('Clear selection')); ?></button>
			</div>
		</div>
	</div>

	<div class="bc-table-scroll" role="region" aria-label="<?php p($l->t('Members')); ?>" tabindex="0">
		<table class="bc-table bc-members-table">
			<thead>
				<tr>
					<th scope="col"><?php p($l->t('Member')); ?></th>
					<th scope="col"><?php p($l->t('Type')); ?></th>
					<th scope="col"><?php p($l->t('Role')); ?></th>
					<th scope="col"><?php p($l->t('Added')); ?></th>
					<th scope="col" class="bc-sr-only"><?php p($l->t('Actions')); ?></th>
				</tr>
			</thead>
			<tbody data-bc-member-rows>
				<tr>
					<td colspan="5" class="bc-loading"><?php p($l->t('Loading…')); ?></td>
				</tr>
			</tbody>
		</table>
	</div>
</section>
<?php endif; ?>
