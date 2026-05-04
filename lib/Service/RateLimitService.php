<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

use OCA\BudgetCheck\AppInfo\Application;
use OCA\BudgetCheck\Exception\AccessDeniedException;
use OCA\BudgetCheck\Exception\RateLimitExceededException;
use OCP\IConfig;

/**
 * Sliding-window rate limiter. Counters live in `oc_appconfig` so we never
 * depend on Redis or other optional infrastructure to enforce abuse limits.
 *
 * The bucket is keyed per (action, user). The window is configurable per call so
 * we can be generous on dashboard reads and strict on workspace mutations.
 */
class RateLimitService
{
	public function __construct(
		private IConfig $config,
		private ?AuditLogService $audit = null,
	) {
	}

	public function assertAllowed(string $userId, string $action, int $max, int $windowSeconds): void
	{
		if ($userId === '') {
			throw new AccessDeniedException();
		}
		$max = max(1, $max);
		$windowSeconds = max(1, $windowSeconds);
		$key = 'rate_limit:' . $action . ':' . $userId;
		$now = time();

		$raw = $this->config->getAppValue(Application::APP_ID, $key, '[]');
		try {
			$entries = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			$entries = [];
		}
		if (!is_array($entries)) {
			$entries = [];
		}
		$cutoff = $now - $windowSeconds;
		$entries = array_values(array_filter($entries, static fn ($ts): bool => is_int($ts) && $ts >= $cutoff));
		if (count($entries) >= $max) {
			$this->audit?->record($userId, 'rate_limited', 'api', $action, [
				'max' => $max,
				'window' => $windowSeconds,
				'attempts' => count($entries),
			]);
			throw new RateLimitExceededException();
		}
		$entries[] = $now;
		$this->config->setAppValue(Application::APP_ID, $key, json_encode($entries, JSON_THROW_ON_ERROR));
	}
}
