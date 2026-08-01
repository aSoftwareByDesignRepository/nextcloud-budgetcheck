<?php
/**
 * Workspace settings sub-page: Your planning view (household only).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
?>
<section class="bc-card bc-section" aria-labelledby="bc-summary-view-prefs-title">
	<header class="bc-section__header">
		<div>
			<h2 id="bc-summary-view-prefs-title" class="bc-sr-only"><?php p($l->t('Your planning view')); ?></h2>
			<p class="bc-section__sub"><?php p($l->t('Controls how income and expense totals appear on the dashboard, monthly plan, and yearly overview—for you only.')); ?></p>
		</div>
	</header>
	<div class="bc-specials-toggle-wrap" data-bc-summary-view-prefs></div>
</section>
