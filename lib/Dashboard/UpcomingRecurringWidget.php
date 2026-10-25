<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Dashboard;

use OCA\BudgetCheck\AppInfo\Application;
use OCA\BudgetCheck\Service\AccessControlService;
use OCA\BudgetCheck\Service\AlertPreferencesService;
use OCA\BudgetCheck\Service\RecurringRuleService;
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
 * "Upcoming recurring" dashboard tile (NH-03, spec: dashboard-widget).
 *
 * Lists the next due recurring bookings for the resolved household workspace.
 * Shares BudgetStatusWidget's safety contract: per-user kill-switch first,
 * whole data path wrapped, per-request user cache — a widget must never
 * break the shared Nextcloud dashboard.
 */
class UpcomingRecurringWidget implements IAPIWidgetV2, IButtonWidget, IIconWidget, IReloadableWidget
{
	private const LOOKAHEAD_DAYS = 14;
	private array $cache = [];

	public function __construct(
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urlGenerator,
		private readonly WorkspaceService $workspaces,
		private readonly RecurringRuleService $recurring,
		private readonly AccessControlService $access,
		private readonly AlertPreferencesService $prefs,
		private readonly LoggerInterface $logger,
	) {
	}

	public function getId(): string
	{
		return Application::APP_ID . '-upcoming-recurring';
	}

	public function getTitle(): string
	{
		return $this->l10n->t('Upcoming recurring');
	}

	public function getOrder(): int
	{
		return 31;
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
		return $this->appUrl();
	}

	public function load(): void
	{
	}

	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems
	{
		$data = $this->widgetData($userId);
		$url = $this->appUrl();
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
		if (($data['error'] ?? false) === true) {
			return new WidgetItems([], '');
		}
		$upcoming = $data['upcoming'] ?? [];
		if ($upcoming === []) {
			$items[] = new WidgetItem(
				$this->l10n->t('Nothing due soon'),
				$this->l10n->t('No recurring booking is due in the next %d days.', [self::LOOKAHEAD_DAYS]),
				$url, $icon, $ts . '-empty'
			);
			return new WidgetItems($items, '');
		}
		foreach (array_slice($upcoming, 0, max(1, $limit)) as $i => $entry) {
			$items[] = new WidgetItem(
				(string)$entry['title'],
				$entry['subtitle'],
				$url, $icon, $ts . '-r' . $i
			);
		}
		return new WidgetItems($items, '');
	}

	public function getWidgetButtons(string $userId): array
	{
		return [
			new WidgetButton(
				WidgetButton::TYPE_MORE,
				$this->appUrl(),
				$this->l10n->t('Open BudgetCheck')
			),
		];
	}

	public function getReloadInterval(): int
	{
		return 900;
	}

	private function appUrl(): string
	{
		return $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->linkToRoute('budgetcheck.page.settingsSection', ['section' => 'recurring'])
		);
	}

	private function widgetData(string $userId): array
	{
		if (isset($this->cache[$userId])) {
			return $this->cache[$userId];
		}
		if (!$this->prefs->dashboardWidgetEnabled($userId)) {
			return $this->cache[$userId] = ['enabled' => false, 'upcoming' => []];
		}
		try {
			$workspaceId = $this->resolveWorkspaceId($userId);
			if ($workspaceId === null) {
				return $this->cache[$userId] = ['enabled' => true, 'upcoming' => []];
			}
			$workspace = $this->workspaces->getForUser($workspaceId, $userId);
			$currency = (string)($workspace['currencyCode'] ?? 'EUR');
			$decimals = (int)($workspace['currencyDecimals'] ?? 2);
			$today = (string)($this->recurring->workspaceTodayIso($workspace));
			$cutoff = (new \DateTimeImmutable($today))->modify('+' . self::LOOKAHEAD_DAYS . ' days')->format('Y-m-d');
			$upcoming = [];
			foreach ($this->recurring->listForWorkspace($workspaceId, $userId, $currency) as $rule) {
				if (empty($rule['isActive'] ?? $rule['is_active'] ?? true)) {
					continue;
				}
				$due = (string)($rule['nextDueDate'] ?? $rule['next_due_date'] ?? '');
				if ($due === '' || $due > $cutoff) {
					continue;
				}
				$minor = (int)($rule['amountMinor'] ?? $rule['amount_minor'] ?? 0);
				$amount = number_format($minor / (10 ** $decimals), $decimals) . ' ' . $currency;
				$label = $due <= $today
					? $this->l10n->t('Due today')
					: (new \DateTimeImmutable($due))->format('d.m.');
				$upcoming[] = [
					'title' => (string)($rule['title'] ?? ''),
					'subtitle' => $label . ' · ' . $amount,
					'due' => $due,
				];
			}
			usort($upcoming, fn ($a, $b) => strcmp($a['due'], $b['due']));
			return $this->cache[$userId] = ['enabled' => true, 'upcoming' => $upcoming];
		} catch (\Throwable $e) {
			$this->logger->info('BudgetCheck recurring widget data failed', [
				'widget' => $this->getId(),
				'exception' => $e,
			]);
			return $this->cache[$userId] = ['enabled' => true, 'upcoming' => [], 'error' => true];
		}
	}

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
