<?php
/**
 * Rendered by AppAccessMiddleware when the current user is not allowed to use
 * the app. Deliberately renders without the sidebar so the user is not stared
 * down by a list of links they cannot click.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

\OCP\Util::addStyle('budgetcheck', 'app');

use OCA\BudgetCheck\Service\IconCatalog;
?>
<div id="app-content" class="bc-app bc-app--access-denied">
	<a class="bc-skip-link" href="#bc-denied-main"><?php p($l->t('Skip to main content')); ?></a>
	<div class="bc-denied">
		<section id="bc-denied-main" class="bc-card" role="alert" aria-labelledby="bc-denied-title" tabindex="-1">
			<div class="bc-page-header__icon" aria-hidden="true">
				<?php print_unescaped(IconCatalog::render('alert-triangle', 'bc-page-header__icon-svg')); ?>
			</div>
			<h1 id="bc-denied-title"><?php p($l->t('Access denied')); ?></h1>
			<p><?php p($_['message'] ?? $l->t('You are not allowed to use BudgetCheck right now.')); ?></p>
			<a class="button primary" href="<?php p((string)($_['homeUrl'] ?? '/')); ?>"><?php p($l->t('Back to Nextcloud')); ?></a>
		</section>
	</div>
</div>
