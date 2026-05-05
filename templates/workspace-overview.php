<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
?>
<?php include __DIR__ . '/common/page-start.php'; ?>

<section class="bc-card bc-section" aria-labelledby="bc-workspaces-overview-title" data-bc-workspace-overview>
	<header class="bc-section__header">
		<div>
			<h2 id="bc-workspaces-overview-title"><?php p($l->t('All workspaces')); ?></h2>
			<p class="bc-section__sub"><?php p($l->t('Find workspaces fast, include archived ones when needed, and choose sidebar quick access.')); ?></p>
		</div>
	</header>

	<form class="bc-filter-grid" data-bc-workspace-filters>
		<label class="bc-field">
			<span class="bc-field__label"><?php p($l->t('Search')); ?></span>
			<input type="search" class="bc-input" data-bc-filter-search placeholder="<?php p($l->t('Search by name')); ?>" />
		</label>
		<label class="bc-field">
			<span class="bc-field__label"><?php p($l->t('Type')); ?></span>
			<select class="bc-input" data-bc-filter-type>
				<option value="all"><?php p($l->t('All types')); ?></option>
				<option value="household"><?php p($l->t('Household')); ?></option>
				<option value="project"><?php p($l->t('Project')); ?></option>
			</select>
		</label>
		<label class="bc-field">
			<span class="bc-field__label"><?php p($l->t('Role')); ?></span>
			<select class="bc-input" data-bc-filter-role>
				<option value="all"><?php p($l->t('All roles')); ?></option>
				<option value="manager"><?php p($l->t('Manager')); ?></option>
				<option value="contributor"><?php p($l->t('Contributor')); ?></option>
				<option value="viewer"><?php p($l->t('Viewer')); ?></option>
			</select>
		</label>
		<div class="bc-filter-bool-row">
			<label class="bc-field">
				<span class="bc-boolean-control bc-boolean-control--filter-row">
					<input type="checkbox" data-bc-filter-show-archived />
					<span class="bc-boolean-control__text"><?php p($l->t('Show archived workspaces')); ?></span>
				</span>
			</label>
			<label class="bc-field">
				<span class="bc-boolean-control bc-boolean-control--filter-row">
					<input type="checkbox" data-bc-filter-only-favorites />
					<span class="bc-boolean-control__text"><?php p($l->t('Only quick access workspaces')); ?></span>
				</span>
			</label>
		</div>
		<div class="bc-filter-actions">
			<button type="button" class="button" data-bc-action="workspace-filters-reset"><?php p($l->t('Reset')); ?></button>
		</div>
	</form>

	<div class="bc-workspace-overview__stats" data-bc-workspace-stats aria-live="polite"></div>
	<div class="bc-workspace-overview__grid" data-bc-workspace-grid aria-busy="true"></div>
</section>

<?php include __DIR__ . '/common/page-end.php'; ?>
