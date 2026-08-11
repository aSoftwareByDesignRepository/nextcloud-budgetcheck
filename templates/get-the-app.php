<?php
/**
 * Get the App — official BudgetCheck Mobile companion.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\BudgetCheck\Service\IconCatalog;
use OCA\BudgetCheck\Support\MobileAppLinks;

$urls = is_array($_['urls'] ?? null) ? $_['urls'] : [];
$playStore = (string)($urls['playStore'] ?? MobileAppLinks::PLAY_STORE_URL);
if (!str_starts_with($playStore, 'https://play.google.com/')) {
	$playStore = MobileAppLinks::PLAY_STORE_URL;
}
$productPage = (string)($urls['mobileProductPage'] ?? '');
$privacyPage = (string)($urls['mobilePrivacyPage'] ?? '');
if ($productPage !== '' && !str_starts_with($productPage, 'https://')) {
	$productPage = '';
}
if ($privacyPage !== '' && !str_starts_with($privacyPage, 'https://')) {
	$privacyPage = '';
}

$features = [
	[
		'icon' => 'layout-grid',
		'title' => $l->t('Household and project workspaces'),
		'hint' => $l->t('Open the same workspaces you use in the browser — household budgets or project caps.'),
	],
	[
		'icon' => 'list',
		'title' => $l->t('Log income and expenses on the go'),
		'hint' => $l->t('Add bookings from your phone. Direction, dates, and tax rules stay enforced on the server.'),
	],
	[
		'icon' => 'wallet',
		'title' => $l->t('Budgets and savings targets'),
		'hint' => $l->t('See category budgets and savings targets without opening a laptop.'),
	],
	[
		'icon' => 'calendar-days',
		'title' => $l->t('Monthly close and period overview'),
		'hint' => $l->t('Review months for households, or period progress for projects, wherever you are.'),
	],
	[
		'icon' => 'lock',
		'title' => $l->t('Sign in safely'),
		'hint' => $l->t('Uses Nextcloud Login Flow — your main password is never stored in the app.'),
	],
];
?>
<?php include __DIR__ . '/common/page-start.php'; ?>

<div class="bc-get-app-page bc-page-stack">
	<section class="bc-get-app__hero" aria-labelledby="bc-get-app-intro-title">
		<p class="bc-get-app__eyebrow"><?php p($l->t('Official Android companion')); ?></p>
		<h2 id="bc-get-app-intro-title" class="bc-get-app__title"><?php p($l->t('BudgetCheck Mobile')); ?></h2>
		<p class="bc-get-app__lead">
			<?php p($l->t('The official Android app connects to this Nextcloud. Keep household and project budgets with you — progress stays on your server.')); ?>
		</p>
		<div class="bc-get-app__cta">
			<a class="bc-get-app__play" href="<?php p($playStore); ?>" target="_blank" rel="noopener noreferrer">
				<span class="bc-get-app__play-icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('smartphone')); ?></span>
				<span class="bc-get-app__play-label"><?php p($l->t('Get it on Google Play')); ?></span>
			</a>
			<p class="bc-get-app__price-hint">
				<?php p($l->t('Not yet publicly listed on Google Play. Until then the web app covers the feature set. When available: one-time purchase — price varies by country. No organisation seat licence.')); ?>
			</p>
		</div>
	</section>

	<section class="bc-get-app__features-block" aria-labelledby="bc-get-app-features-title">
		<h2 id="bc-get-app-features-title" class="bc-get-app__section-title"><?php p($l->t('What you can do')); ?></h2>
		<ul class="bc-get-app__features">
			<?php foreach ($features as $feature): ?>
				<li class="bc-get-app__feature">
					<span class="bc-get-app__icon-well bc-get-app__icon-well--feature" aria-hidden="true">
						<?php print_unescaped(IconCatalog::render((string)$feature['icon'])); ?>
					</span>
					<div class="bc-get-app__feature-copy">
						<span class="bc-get-app__feature-title"><?php p((string)$feature['title']); ?></span>
						<span class="bc-get-app__feature-hint"><?php p((string)$feature['hint']); ?></span>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>

	<?php
	$actionRows = [];
	if ($productPage !== '') {
		$actionRows[] = [
			'href' => $productPage,
			'label' => $l->t('Product page'),
		];
	}
	if ($privacyPage !== '') {
		$actionRows[] = [
			'href' => $privacyPage,
			'label' => $l->t('Privacy policy for the mobile app'),
		];
	}
	?>
	<?php if ($actionRows !== []): ?>
		<nav class="bc-get-app__actions" aria-label="<?php p($l->t('More information')); ?>">
			<?php foreach ($actionRows as $row): ?>
				<a class="bc-get-app__action" href="<?php p((string)$row['href']); ?>" target="_blank" rel="noopener noreferrer">
					<span class="bc-get-app__action-label"><?php p((string)$row['label']); ?></span>
					<span class="bc-get-app__action-external" aria-hidden="true">↗</span>
				</a>
			<?php endforeach; ?>
		</nav>
	<?php endif; ?>
</div>

<?php include __DIR__ . '/common/page-end.php'; ?>
