<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

use OCA\BudgetCheck\AppInfo\Application;
use OCA\BudgetCheck\Support\StrictBool;
use OCP\IConfig;

/**
 * Per-user alert preferences (spec: alert-notifications §9).
 *
 * Stored as IConfig user values — sparse scalars, no schema needed. Every
 * alert type is individually switchable; the master switch suppresses all
 * sends (NN-01). Threshold percent validates 50–100.
 */
class AlertPreferencesService
{
	public const TYPE_BUDGET = 'budget';
	public const TYPE_RECURRING = 'recurring';
	public const TYPE_DIGEST = 'digest';
	public const TYPE_SAVINGS = 'savings';
	public const TYPES = [self::TYPE_BUDGET, self::TYPE_RECURRING, self::TYPE_DIGEST, self::TYPE_SAVINGS];

	private const KEY_MASTER = 'alerts_enabled';
	private const KEY_TYPE_PREFIX = 'alerts_type_';
	private const KEY_THRESHOLD = 'alerts_threshold_pct';
	private const KEY_WIDGET = 'dashboard_widget_enabled';
	private const KEY_WIDGET_WS = 'dashboard_widget_workspace';
	private const KEY_EMAIL = 'alerts_email_enabled';
	private const KEY_QUIET_START = 'alerts_quiet_start';
	private const KEY_QUIET_END = 'alerts_quiet_end';
	private const KEY_CAT_THRESHOLDS = 'alerts_cat_thresholds';

	public function __construct(
		private IConfig $config,
		private AccessControlService $access,
		private CategoryService $categories,
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function get(int $workspaceId, string $userId): array
	{
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_VIEWER);
		return $this->snapshot($userId);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function save(int $workspaceId, string $userId, array $payload): array
	{
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_VIEWER);
		if (array_key_exists('alertsEnabled', $payload)) {
			$this->setBool($userId, self::KEY_MASTER, StrictBool::field($payload, 'alertsEnabled'));
		}
		foreach (self::TYPES as $type) {
			$key = 'type' . ucfirst($type);
			if (array_key_exists($key, $payload)) {
				$this->setBool($userId, self::KEY_TYPE_PREFIX . $type, StrictBool::field($payload, $key));
			}
		}
		if (array_key_exists('thresholdPercent', $payload)) {
			$pct = (int)$payload['thresholdPercent'];
			if ($pct < 50 || $pct > 100) {
				throw new \InvalidArgumentException('thresholdPercent must be between 50 and 100.');
			}
			$this->config->setUserValue($userId, Application::APP_ID, self::KEY_THRESHOLD, (string)$pct);
		}
		if (array_key_exists('emailEnabled', $payload)) {
			$this->setBool($userId, self::KEY_EMAIL, StrictBool::field($payload, 'emailEnabled'));
		}
		if (array_key_exists('quietStart', $payload) || array_key_exists('quietEnd', $payload)) {
			$start = $this->validHourMinute($payload['quietStart'] ?? null);
			$end = $this->validHourMinute($payload['quietEnd'] ?? null);
			$this->config->setUserValue($userId, Application::APP_ID, self::KEY_QUIET_START, $start ?? '');
			$this->config->setUserValue($userId, Application::APP_ID, self::KEY_QUIET_END, $end ?? '');
		}
		if (array_key_exists('categoryThresholds', $payload)) {
			$this->saveCategoryThresholds($workspaceId, $userId, $payload['categoryThresholds']);
		}
		if (array_key_exists('dashboardWidgetEnabled', $payload)) {
			$this->setBool($userId, self::KEY_WIDGET, StrictBool::field($payload, 'dashboardWidgetEnabled'));
		}
		if (array_key_exists('dashboardWidgetWorkspaceId', $payload)) {
			$wid = (int)$payload['dashboardWidgetWorkspaceId'];
			if ($wid > 0) {
				// Widget workspace must be one the user can actually open —
				// storing a foreign id would pin the widget to a dead space.
				$this->access->ensureMinimumRole($wid, $userId, AccessControlService::ROLE_VIEWER);
			}
			$this->config->setUserValue($userId, Application::APP_ID, self::KEY_WIDGET_WS, $wid > 0 ? (string)$wid : '');
		}
		return $this->snapshot($userId);
	}

	public function alertsEnabled(string $userId): bool
	{
		return $this->config->getUserValue($userId, Application::APP_ID, self::KEY_MASTER, '1') === '1';
	}

	public function typeEnabled(string $userId, string $type): bool
	{
		if (!in_array($type, self::TYPES, true)) {
			return false;
		}
		return $this->config->getUserValue($userId, Application::APP_ID, self::KEY_TYPE_PREFIX . $type, '1') === '1';
	}

	/** Whether a dispatch of $type to $userId is allowed right now (master + type + quiet hours). */
	public function canSend(string $userId, string $type, ?\DateTimeInterface $now = null): bool
	{
		if (!$this->alertsEnabled($userId) || !$this->typeEnabled($userId, $type)) {
			return false;
		}
		return !$this->inQuietHours($userId, $now ?? new \DateTimeImmutable());
	}

	public function thresholdPercent(string $userId): int
	{
		$pct = (int)$this->config->getUserValue($userId, Application::APP_ID, self::KEY_THRESHOLD, '80');
		return max(50, min(100, $pct));
	}

	public function thresholdForCategory(string $userId, int $workspaceId, int $categoryId): int
	{
		$overrides = $this->categoryThresholdsForWorkspace($workspaceId, $userId);
		$pct = (int)($overrides[$categoryId] ?? $this->thresholdPercent($userId));
		return max(50, min(100, $pct));
	}

	public function emailEnabled(string $userId): bool
	{
		return $this->config->getUserValue($userId, Application::APP_ID, self::KEY_EMAIL, '0') === '1';
	}

	public function dashboardWidgetEnabled(string $userId): bool
	{
		return $this->config->getUserValue($userId, Application::APP_ID, self::KEY_WIDGET, '1') === '1';
	}

	public function dashboardWidgetWorkspaceId(string $userId): ?int
	{
		$raw = (string)$this->config->getUserValue($userId, Application::APP_ID, self::KEY_WIDGET_WS, '');
		return ctype_digit($raw) && (int)$raw > 0 ? (int)$raw : null;
	}

	public function inQuietHours(string $userId, \DateTimeInterface $now): bool
	{
		$start = (string)$this->config->getUserValue($userId, Application::APP_ID, self::KEY_QUIET_START, '');
		$end = (string)$this->config->getUserValue($userId, Application::APP_ID, self::KEY_QUIET_END, '');
		if ($start === '' || $end === '') {
			return false;
		}
		$minutes = (int)$now->format('H') * 60 + (int)$now->format('i');
		[$sh, $sm] = array_map('intval', explode(':', $start));
		[$eh, $em] = array_map('intval', explode(':', $end));
		$s = $sh * 60 + $sm;
		$e = $eh * 60 + $em;
		if ($s === $e) {
			return false;
		}
		return $s < $e ? ($minutes >= $s && $minutes < $e) : ($minutes >= $s || $minutes < $e);
	}

	private function saveCategoryThresholds(int $workspaceId, string $userId, mixed $raw): void
	{
		if (!is_array($raw)) {
			throw new \InvalidArgumentException('categoryThresholds must be an object of categoryId => percent.');
		}
		$clean = [];
		foreach ($raw as $cid => $pct) {
			$cid = (int)$cid;
			$pct = (int)$pct;
			if ($cid > 0 && $pct >= 50 && $pct <= 100) {
				// References without FKs are validated for existence AND
				// ownership: a foreign/deleted category id never reaches prefs.
				if ($this->categories->loadForWorkspace($cid, $workspaceId) !== null) {
					$clean[$cid] = $pct;
				}
			}
		}
		$this->config->setUserValue(
			$userId,
			Application::APP_ID,
			self::KEY_CAT_THRESHOLDS . '_' . $workspaceId,
			json_encode($clean),
		);
	}

	/** Per-workspace category threshold overrides (NH-03). */
	public function categoryThresholdsForWorkspace(int $workspaceId, string $userId): array
	{
		$raw = (string)$this->config->getUserValue($userId, Application::APP_ID, self::KEY_CAT_THRESHOLDS . '_' . $workspaceId, '');
		if ($raw === '') {
			return [];
		}
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * Forget this user's workspace-scoped alert state (workspace deletion):
	 * category thresholds and a widget pin pointing at the deleted space.
	 */
	public function clearForUserWorkspace(string $userId, int $workspaceId): void
	{
		$this->config->deleteUserValue($userId, Application::APP_ID, self::KEY_CAT_THRESHOLDS . '_' . $workspaceId);
		if ($this->dashboardWidgetWorkspaceId($userId) === $workspaceId) {
			$this->config->deleteUserValue($userId, Application::APP_ID, self::KEY_WIDGET_WS);
		}
	}

	private function snapshot(string $userId): array
	{
		$types = [];
		foreach (self::TYPES as $type) {
			$types[$type] = $this->typeEnabled($userId, $type);
		}
		return [
			'alertsEnabled' => $this->alertsEnabled($userId),
			'types' => $types,
			'thresholdPercent' => $this->thresholdPercent($userId),
			'emailEnabled' => $this->emailEnabled($userId),
			'quietStart' => (string)$this->config->getUserValue($userId, Application::APP_ID, self::KEY_QUIET_START, '') ?: null,
			'quietEnd' => (string)$this->config->getUserValue($userId, Application::APP_ID, self::KEY_QUIET_END, '') ?: null,
			'dashboardWidgetEnabled' => $this->dashboardWidgetEnabled($userId),
			'dashboardWidgetWorkspaceId' => $this->dashboardWidgetWorkspaceId($userId),
		];
	}

	private function validHourMinute(mixed $value): ?string
	{
		$v = trim((string)($value ?? ''));
		if ($v === '') {
			return null;
		}
		if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $v, $m)) {
			throw new \InvalidArgumentException('Quiet hours must be in HH:MM format.');
		}
		return sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
	}

	private function setBool(string $userId, string $key, bool $value): void
	{
		$this->config->setUserValue($userId, Application::APP_ID, $key, $value ? '1' : '0');
	}
}
