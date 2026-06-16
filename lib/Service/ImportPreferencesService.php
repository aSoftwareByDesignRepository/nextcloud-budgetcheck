<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

use OCA\BudgetCheck\AppInfo\Application;
use OCP\IConfig;

/**
 * Per-user, per-workspace CSV import UI preferences (defaults, direction mode, duplicate skip).
 *
 * Stored in oc_preferences so choices follow the account across browsers and devices.
 */
class ImportPreferencesService
{
	private const KEY_PREFIX = 'import_prefs_ws_';
	private const DIRECTION_MODES = ['auto', 'expense', 'income'];

	public function __construct(
		private IConfig $config,
		private AccessControlService $access,
	) {
	}

	/**
	 * @return array{
	 *   expenseCategoryId:?int,
	 *   incomeCategoryId:?int,
	 *   directionMode:string,
	 *   skipDuplicates:bool,
	 *   skipFingerprintDuplicates:bool
	 * }
	 */
	public function get(int $workspaceId, string $userId): array
	{
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_CONTRIBUTOR);
		$raw = (string)$this->config->getUserValue($userId, Application::APP_ID, $this->storageKey($workspaceId), '');
		if ($raw === '') {
			return $this->defaults();
		}
		try {
			$decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return $this->defaults();
		}
		if (!is_array($decoded)) {
			return $this->defaults();
		}
		return $this->sanitize($decoded);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{
	 *   expenseCategoryId:?int,
	 *   incomeCategoryId:?int,
	 *   directionMode:string,
	 *   skipDuplicates:bool,
	 *   skipFingerprintDuplicates:bool
	 * }
	 */
	public function save(int $workspaceId, string $userId, array $payload): array
	{
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_CONTRIBUTOR);
		$clean = $this->sanitize($payload);
		$this->config->setUserValue(
			$userId,
			Application::APP_ID,
			$this->storageKey($workspaceId),
			json_encode($clean, JSON_THROW_ON_ERROR),
		);
		return $clean;
	}

	/**
	 * @return array{
	 *   expenseCategoryId:?int,
	 *   incomeCategoryId:?int,
	 *   directionMode:string,
	 *   skipDuplicates:bool,
	 *   skipFingerprintDuplicates:bool
	 * }
	 */
	private function defaults(): array
	{
		return [
			'expenseCategoryId' => null,
			'incomeCategoryId' => null,
			'directionMode' => 'auto',
			'skipDuplicates' => false,
			'skipFingerprintDuplicates' => false,
		];
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{
	 *   expenseCategoryId:?int,
	 *   incomeCategoryId:?int,
	 *   directionMode:string,
	 *   skipDuplicates:bool,
	 *   skipFingerprintDuplicates:bool
	 * }
	 */
	private function sanitize(array $payload): array
	{
		$expense = isset($payload['expenseCategoryId']) ? (int)$payload['expenseCategoryId'] : 0;
		$income = isset($payload['incomeCategoryId']) ? (int)$payload['incomeCategoryId'] : 0;
		$mode = (string)($payload['directionMode'] ?? 'auto');
		if (!in_array($mode, self::DIRECTION_MODES, true)) {
			$mode = 'auto';
		}
		$skipDuplicates = !empty($payload['skipDuplicates']);
		$skipFingerprint = $skipDuplicates && !empty($payload['skipFingerprintDuplicates']);

		return [
			'expenseCategoryId' => $expense > 0 ? $expense : null,
			'incomeCategoryId' => $income > 0 ? $income : null,
			'directionMode' => $mode,
			'skipDuplicates' => $skipDuplicates,
			'skipFingerprintDuplicates' => $skipFingerprint,
		];
	}

	private function storageKey(int $workspaceId): string
	{
		return self::KEY_PREFIX . $workspaceId;
	}
}
