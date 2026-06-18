<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

use OCA\BudgetCheck\AppInfo\Application;
use OCP\IConfig;

/**
 * Per-user summary view preference (include special transactions in planning totals)
 * with household workspace default stored on bc_workspaces.include_specials_default.
 */
class SummaryViewPreferencesService
{
	private const KEY_PREFIX = 'summary_view_ws_';

	public function __construct(
		private IConfig $config,
		private AccessControlService $access,
	) {
	}

	/**
	 * @return array{
	 *   includeSpecialsInTotals:bool,
	 *   hasUserOverride:bool,
	 *   workspaceDefault:bool
	 * }
	 */
	public function get(int $workspaceId, string $userId, bool $workspaceDefault): array
	{
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_VIEWER);
		return [
			'includeSpecialsInTotals' => $this->effectiveIncludeSpecials($workspaceId, $userId, $workspaceDefault),
			'hasUserOverride' => $this->hasUserOverride($workspaceId, $userId),
			'workspaceDefault' => $workspaceDefault,
		];
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{
	 *   includeSpecialsInTotals:bool,
	 *   hasUserOverride:bool,
	 *   workspaceDefault:bool
	 * }
	 */
	public function save(int $workspaceId, string $userId, array $payload, bool $workspaceDefault): array
	{
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_VIEWER);
		if (!array_key_exists('includeSpecialsInTotals', $payload)) {
			throw new \InvalidArgumentException('includeSpecialsInTotals is required.');
		}
		$include = (bool)$payload['includeSpecialsInTotals'];
		$this->config->setUserValue(
			$userId,
			Application::APP_ID,
			$this->storageKey($workspaceId),
			$include ? '1' : '0',
		);
		return $this->get($workspaceId, $userId, $workspaceDefault);
	}

	public function effectiveIncludeSpecials(int $workspaceId, string $userId, bool $workspaceDefault): bool
	{
		$stored = $this->rawUserValue($workspaceId, $userId);
		if ($stored === '1') {
			return true;
		}
		if ($stored === '0') {
			return false;
		}
		return $workspaceDefault;
	}

	public function hasUserOverride(int $workspaceId, string $userId): bool
	{
		$stored = $this->rawUserValue($workspaceId, $userId);
		return $stored === '0' || $stored === '1';
	}

	/**
	 * @param array<string,mixed> $workspace
	 * @return array<string,mixed>
	 */
	public function enrichHouseholdWorkspace(array $workspace, string $userId): array
	{
		if (($workspace['type'] ?? '') !== WorkspaceService::TYPE_HOUSEHOLD) {
			return $workspace;
		}
		$workspaceId = (int)$workspace['id'];
		$default = (bool)($workspace['includeSpecialsInTotalsDefault'] ?? false);
		$workspace['includeSpecialsInTotals'] = $this->effectiveIncludeSpecials($workspaceId, $userId, $default);
		$workspace['hasIncludeSpecialsUserOverride'] = $this->hasUserOverride($workspaceId, $userId);
		return $workspace;
	}

	private function rawUserValue(int $workspaceId, string $userId): string
	{
		return (string)$this->config->getUserValue(
			$userId,
			Application::APP_ID,
			$this->storageKey($workspaceId),
			'',
		);
	}

	private function storageKey(int $workspaceId): string
	{
		return self::KEY_PREFIX . $workspaceId;
	}
}
