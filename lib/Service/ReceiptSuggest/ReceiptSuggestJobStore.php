<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service\ReceiptSuggest;

use OCA\BudgetCheck\AppInfo\Application;
use OCP\IConfig;

/**
 * Persists short-lived suggestion job metadata in user config (survives PHP requests).
 *
 * @phpstan-type JobMeta array{
 *   jobId:int,
 *   workspaceId:int,
 *   userId:string,
 *   source:string,
 *   phase:string,
 *   taskId:int,
 *   fileId:int,
 *   fileName:string,
 *   mimeType:string,
 *   categoryIds:list<int>,
 *   currencyCode:string,
 *   createdAt:int,
 *   customId:string
 * }
 */
class ReceiptSuggestJobStore
{
	private const KEY_PREFIX = 'rs_job_';
	private const ACTIVE_PREFIX = 'rs_active_';

	public function __construct(
		private readonly IConfig $config,
	) {
	}

	/**
	 * @param JobMeta $meta
	 */
	public function save(array $meta): void
	{
		$userId = $meta['userId'];
		$jobId = (int)$meta['jobId'];
		$this->config->setUserValue(
			$userId,
			Application::APP_ID,
			self::KEY_PREFIX . $jobId,
			json_encode($meta, JSON_THROW_ON_ERROR),
		);
		$this->config->setUserValue(
			$userId,
			Application::APP_ID,
			self::ACTIVE_PREFIX . (int)$meta['workspaceId'],
			(string)$jobId,
		);
	}

	/**
	 * @return JobMeta|null
	 */
	public function get(string $userId, int $jobId): ?array
	{
		$raw = $this->config->getUserValue($userId, Application::APP_ID, self::KEY_PREFIX . $jobId, '');
		if ($raw === '') {
			return null;
		}
		try {
			$decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}
		if (!is_array($decoded) || (int)($decoded['jobId'] ?? 0) !== $jobId) {
			return null;
		}
		if (($decoded['userId'] ?? '') !== $userId) {
			return null;
		}
		/** @var JobMeta $decoded */
		return $decoded;
	}

	public function delete(string $userId, int $jobId, int $workspaceId): void
	{
		$this->config->deleteUserValue($userId, Application::APP_ID, self::KEY_PREFIX . $jobId);
		$active = $this->config->getUserValue($userId, Application::APP_ID, self::ACTIVE_PREFIX . $workspaceId, '');
		if ($active === (string)$jobId) {
			$this->config->deleteUserValue($userId, Application::APP_ID, self::ACTIVE_PREFIX . $workspaceId);
		}
	}

	public function activeJobId(string $userId, int $workspaceId): ?int
	{
		$raw = $this->config->getUserValue($userId, Application::APP_ID, self::ACTIVE_PREFIX . $workspaceId, '');
		if ($raw === '' || !ctype_digit($raw)) {
			return null;
		}
		return (int)$raw;
	}
}
