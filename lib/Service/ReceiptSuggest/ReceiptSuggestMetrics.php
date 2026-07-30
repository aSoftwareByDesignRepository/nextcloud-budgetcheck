<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service\ReceiptSuggest;

use OCA\BudgetCheck\AppInfo\Application;
use OCP\IConfig;

/**
 * Telemetry-free outcome counters (no receipt text, no user ids, no amounts).
 * Stored as app config integers for operator diagnostics via occ config:list.
 */
final class ReceiptSuggestMetrics implements ReceiptSuggestMetricsInterface
{
	public const STARTED = 'rs_metric_started';
	public const READY = 'rs_metric_ready';
	public const LOW_QUALITY = 'rs_metric_low_quality';
	public const FAILED = 'rs_metric_failed';
	public const ACCEPTED = 'rs_metric_accepted';
	public const CANCELLED = 'rs_metric_cancelled';
	public const ACCEPT_REJECTED = 'rs_metric_accept_rejected';
	public const ACCEPT_BUSY = 'rs_metric_accept_busy';
	public const ACCEPT_FAILED = 'rs_metric_accept_failed';

	public function __construct(
		private readonly IConfig $config,
	) {
	}

	public function increment(string $key): void
	{
		if (!$this->isKnown($key)) {
			return;
		}
		$current = $this->config->getAppValue(Application::APP_ID, $key, '0');
		$n = ctype_digit($current) ? (int)$current : 0;
		if ($n < 0) {
			$n = 0;
		}
		// Cap to keep config values bounded (ops still see relative volume).
		if ($n >= PHP_INT_MAX - 1) {
			return;
		}
		$this->config->setAppValue(Application::APP_ID, $key, (string)($n + 1));
	}

	/**
	 * @return array<string, int>
	 */
	public function snapshot(): array
	{
		$out = [];
		foreach ($this->keys() as $key) {
			$raw = $this->config->getAppValue(Application::APP_ID, $key, '0');
			$out[$key] = ctype_digit($raw) ? (int)$raw : 0;
		}
		return $out;
	}

	/**
	 * @return list<string>
	 */
	public function keys(): array
	{
		return [
			self::STARTED,
			self::READY,
			self::LOW_QUALITY,
			self::FAILED,
			self::ACCEPTED,
			self::CANCELLED,
			self::ACCEPT_REJECTED,
			self::ACCEPT_BUSY,
			self::ACCEPT_FAILED,
		];
	}

	private function isKnown(string $key): bool
	{
		return in_array($key, $this->keys(), true);
	}
}
