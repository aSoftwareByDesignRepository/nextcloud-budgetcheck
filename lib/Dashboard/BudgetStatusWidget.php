<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Dashboard;

use OCA\BudgetCheck\AppInfo\Application;
use OCA\BudgetCheck\Service\AccessControlService;
use OCA\BudgetCheck\Service\AlertPreferencesService;
use OCA\BudgetCheck\Service\SummaryService;
use OCA\BudgetCheck\Service\WorkspaceService;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IReloadableWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * "Budget status" dashboard tile (spec: dashboard-widget).
 *
 * Resolution order for the household workspace: explicit per-user widget
 * preference → favorite household workspace → single household workspace →
 * honest empty state. The per-user kill-switch is checked before any figure
 * is computed (NN-01) and the whole data path is wrapped so a failure can
 * never break the shared NC dashboard (US-303 AC-3).
 */
class BudgetStatusWidget implements IAPIWidgetV2, IButtonWidget, IIconWidget, IReloadableWidget
{
	/** In-request cache keyed by uid — NC may batch widget calls per request (NN-05). */
	private array $cache = [];

	public function __construct(
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urlGenerator,
		private readonly WorkspaceService $workspaces,
		private readonly SummaryService $summaries,
		private readonly AccessControlService $access,
		private readonly AlertPreferencesService $prefs,
		private readonly LoggerInterface $logger,
	) {
	}

	public function getId(): string
	{
		return Application::APP_ID . '-budget-status';
	}

	public function getTitle(): string
	{
		return $this->l10n->t('Budget status');
	}

	public function getOrder(): int
	{
		return 30;
	}

	public function getIconClass(): string
	{
		return 'icon-budgetcheck';
	}

	public function getIconUrl(): string
	{
		return $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath(Application::APP_ID, 'app.svg')
		);
	}

	public function getUrl(): ?string
	{
		return $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->linkToRoute('budgetcheck.page.dashboard')
		);
	}

	public function load(): void
	{
	}

	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems
	{
		$data = $this->widgetData($userId);
		$url = $this->appUrl($data['workspaceId'] ?? null);
		$icon = $this->getIconUrl();
		$ts = (string)time();
		$items = [];

		if (!$data['enabled']) {
			$items[] = new WidgetItem(
				$this->l10n->t('BudgetCheck data hidden'),
				$this->l10n->t('Enable in BudgetCheck settings'),
				$url, $icon, $ts . '-off'
			);
			return new WidgetItems($items, '');
		}
		if ($data['workspaceId'] === null) {
			$items[] = new WidgetItem(
				$this->l10n->t('No household workspace yet'),
				$this->l10n->t('Create or pick a household workspace in BudgetCheck'),
				$url, $icon, $ts . '-empty'
			);
			return new WidgetItems($items, '');
		}
		if (($data['error'] ?? false) === true) {
			return new WidgetItems([], '');
		}

		$items[] = new WidgetItem(
			$this->l10n->t('Net result (%s)', [$data['monthLabel']]),
			(string)$data['netResult'],
			$url, $icon, $ts . '-net'
		);
		$items[] = new WidgetItem(
			$this->l10n->t('Budget consumed'),
			$data['budgetRatio'] !== null
				? $this->l10n->t('%s%% used', [(string)round($data['budgetRatio'] * 100)])
				: $this->l10n->t('No budget planned'),
			$url, $icon, $ts . '-budget'
		);
		if ($data['savingsLabel'] !== null) {
			$items[] = new WidgetItem(
				$this->l10n->t('Savings'),
				(string)$data['savingsLabel'],
				$url, $icon, $ts . '-savings'
			);
		}
		return new WidgetItems($items, '');
	}

	public function getWidgetButtons(string $userId): array
	{
		$data = $this->widgetData($userId);
		return [
			new WidgetButton(
				WidgetButton::TYPE_MORE,
				$this->appUrl($data['workspaceId'] ?? null),
				$this->l10n->t('Open BudgetCheck')
			),
		];
	}

	public function getReloadInterval(): int
	{
		return 300;
	}

	private function appUrl(?int $workspaceId): string
	{
		$params = $workspaceId !== null ? ['workspaceId' => $workspaceId] : [];
		return $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->linkToRoute('budgetcheck.page.dashboard', $params)
		);
	}

	/**
	 * @return array{enabled:bool, workspaceId:?int, error?:bool, monthLabel?:string, netResult?:string, budgetRatio?:?float, savingsLabel?:?string}
	 */
	private function widgetData(string $userId): array
	{
		if (isset($this->cache[$userId])) {
			return $this->cache[$userId];
		}
		if (!$this->prefs->dashboardWidgetEnabled($userId)) {
			return $this->cache[$userId] = ['enabled' => false, 'workspaceId' => null];
		}
		try {
			$workspaceId = $this->resolveWorkspaceId($userId);
			if ($workspaceId === null) {
				return $this->cache[$userId] = ['enabled' => true, 'workspaceId' => null];
			}
			$summary = $this->summaries->household($workspaceId, $userId, (new \DateTimeImmutable())->format('Y-m'));
			$totals = (array)($summary['totals'] ?? []);
			$planned = (int)(($totals['budgetPlanned']['minor'] ?? 0));
			$actual = (int)(($totals['budgetActual']['minor'] ?? 0));
			$net = (int)(($totals['netResult']['minor'] ?? 0));
			$currency = (string)($summary['workspace']['currencyCode'] ?? 'EUR');
			$decimals = (int)($summary['workspace']['currencyDecimals'] ?? 2);
			$fmt = fn (int $minor): string => number_format($minor / (10 ** $decimals), $decimals) . ' ' . $currency;
			$savingsLabel = null;
			$target = (int)(($totals['savingsTarget']['minor'] ?? 0));
			if ($target > 0) {
				$achieved = (int)(($totals['savingsAchieved']['minor'] ?? 0));
				$savingsLabel = $fmt($achieved) . ' / ' . $fmt($target);
			}
			return $this->cache[$userId] = [
				'enabled' => true,
				'workspaceId' => $workspaceId,
				'monthLabel' => (new \DateTimeImmutable())->format('F Y'),
				'netResult' => $fmt($net),
				'budgetRatio' => $planned > 0 ? $actual / $planned : null,
				'savingsLabel' => $savingsLabel,
			];
		} catch (\Throwable $e) {
			$this->logger->info('BudgetCheck dashboard widget data failed', [
				'widget' => $this->getId(),
				'exception' => $e,
			]);
			return $this->cache[$userId] = ['enabled' => true, 'workspaceId' => null, 'error' => true];
		}
	}

	/**
	 * Explicit widget preference → favorite household → single household.
	 * Never an arbitrary "first by id" workspace.
	 */
	private function resolveWorkspaceId(string $userId): ?int
	{
		$householdIds = [];
		foreach ($this->workspaces->listForUser($userId) as $w) {
			if (($w['type'] ?? '') === WorkspaceService::TYPE_HOUSEHOLD && !empty($w['isActive'])) {
				$householdIds[(int)$w['id']] = true;
			}
		}
		if ($householdIds === []) {
			return null;
		}
		$preferred = $this->prefs->dashboardWidgetWorkspaceId($userId);
		if ($preferred !== null && isset($householdIds[$preferred])) {
			return $preferred;
		}
		foreach ($this->access->favoriteWorkspaceIds($userId) as $fid) {
			if (isset($householdIds[$fid])) {
				return $fid;
			}
		}
		return count($householdIds) === 1 ? array_key_first($householdIds) : null;
	}
}
