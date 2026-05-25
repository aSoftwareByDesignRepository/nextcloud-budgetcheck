<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

use OCA\BudgetCheck\Migration\BudgetCheckTableCatalog;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Per-month savings target row.
 *
 * Modes:
 *  - `percentage` — `target_percent_bp` is required; `target_minor` must be null.
 *  - `absolute`   — `target_minor` is required; `target_percent_bp` must be null.
 *  - `hybrid`     — both must be present; computation picks max(% of income, absolute).
 *
 * Savings targets are a household-only concept in v1; the calling controller
 * returns 422 for project workspaces (§12.3 of the spec).
 */
class SavingsTargetService
{
	public const MODE_PERCENTAGE = 'percentage';
	public const MODE_ABSOLUTE = 'absolute';
	public const MODE_HYBRID = 'hybrid';
	private const MODES = [self::MODE_PERCENTAGE, self::MODE_ABSOLUTE, self::MODE_HYBRID];

	public function __construct(
		private IDBConnection $db,
		private AccessControlService $access,
		private MoneyService $money,
		private ITimeFactory $timeFactory,
		private AuditLogService $audit,
	) {
	}

	public function load(int $workspaceId, string $userId, string $yearMonth, string $currencyCode): ?array
	{
		$this->access->ensureMembership($workspaceId, $userId);
		$ym = $this->validateYearMonth($yearMonth);
		$row = $this->loadRow($workspaceId, $ym);
		if ($row === null) {
			return $this->loadWorkspaceDefault($workspaceId, $ym, $currencyCode);
		}
		return $this->hydrate($row, $currencyCode);
	}

	public function save(int $workspaceId, string $userId, array $payload, string $currencyCode): array
	{
		$this->access->ensureMinimumRole($workspaceId, $userId, AccessControlService::ROLE_MANAGER);
		$ym = $this->validateYearMonth((string)($payload['yearMonth'] ?? ''));
		$mode = $this->validateMode((string)($payload['targetMode'] ?? ''));
		$percent = $this->normalisePercent($payload['targetPercentBp'] ?? null);
		$absolute = $this->normaliseAbsolute($payload['targetMinor'] ?? null, $currencyCode);

		if ($mode === self::MODE_PERCENTAGE) {
			if ($percent === null) {
				throw new \InvalidArgumentException('targetPercentBp is required for percentage mode.');
			}
			$absolute = null;
		} elseif ($mode === self::MODE_ABSOLUTE) {
			if ($absolute === null) {
				throw new \InvalidArgumentException('targetMinor is required for absolute mode.');
			}
			$percent = null;
		} else {
			if ($percent === null || $absolute === null) {
				throw new \InvalidArgumentException('hybrid mode requires both targetPercentBp and targetMinor.');
			}
		}

		$now = $this->utcNow();
		$existing = $this->loadRow($workspaceId, $ym);
		if ($existing === null) {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('bc_savings_targets')
				->values([
					'workspace_id' => $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT),
					'year_month' => $qb->createNamedParameter($ym),
					'target_mode' => $qb->createNamedParameter($mode),
					'target_percent_bp' => $qb->createNamedParameter($percent, $percent === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT),
					'target_minor' => $qb->createNamedParameter($absolute, $absolute === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT),
					'updated_by' => $qb->createNamedParameter($userId),
					'updated_at' => $qb->createNamedParameter($now),
				]);
			$qb->executeStatement();
		} else {
			$qb = $this->db->getQueryBuilder();
			$qb->update('bc_savings_targets')
				->set('target_mode', $qb->createNamedParameter($mode))
				->set('target_percent_bp', $qb->createNamedParameter($percent, $percent === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT))
				->set('target_minor', $qb->createNamedParameter($absolute, $absolute === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT))
				->set('updated_by', $qb->createNamedParameter($userId))
				->set('updated_at', $qb->createNamedParameter($now))
				->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$existing['id'], \PDO::PARAM_INT)));
			$qb->executeStatement();
		}
		$this->audit->record($userId, 'savings_target_saved', 'savings_target', $ym, [
			'mode' => $mode,
			'percentBp' => $percent,
			'minor' => $absolute,
		], $workspaceId);
		$row = $this->loadRow($workspaceId, $ym);
		return $this->hydrate($row, $currencyCode);
	}

	/**
	 * Compute the savings target value for a month given the income total.
	 * Hybrid mode picks max(% * income, absolute) so the user is reminded by
	 * whichever floor is larger.
	 */
	public function computeTargetValue(?array $target, int $totalIncomeMinor): int
	{
		if ($target === null) {
			return 0;
		}
		$mode = (string)($target['targetMode'] ?? '');
		$percentBp = $target['targetPercentBp'] ?? null;
		$absoluteMinor = $target['targetMinor'] ?? null;
		if (is_array($absoluteMinor) && isset($absoluteMinor['minor'])) {
			$absoluteMinor = (int)$absoluteMinor['minor'];
		}
		$percentValue = ($percentBp !== null && $totalIncomeMinor > 0)
			? (int)round(($totalIncomeMinor * (int)$percentBp) / 10000, 0, PHP_ROUND_HALF_EVEN)
			: 0;
		$absoluteValue = $absoluteMinor !== null ? (int)$absoluteMinor : 0;
		return match ($mode) {
			self::MODE_PERCENTAGE => $percentValue,
			self::MODE_ABSOLUTE => $absoluteValue,
			self::MODE_HYBRID => max($percentValue, $absoluteValue),
			default => 0,
		};
	}

	private function loadRow(int $workspaceId, string $yearMonth): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('bc_savings_targets')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('year_month', $qb->createNamedParameter($yearMonth)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? null : $row;
	}

	private function hydrate(array $row, string $currencyCode): array
	{
		$absolute = $row['target_minor'] === null ? null : (int)$row['target_minor'];
		return [
			'id' => (int)$row['id'],
			'workspaceId' => (int)$row['workspace_id'],
			'yearMonth' => (string)$row['year_month'],
			'targetMode' => (string)$row['target_mode'],
			'targetPercentBp' => $row['target_percent_bp'] === null ? null : (int)$row['target_percent_bp'],
			'targetMinor' => $absolute,
			'target' => $absolute === null ? null : $this->money->envelope($absolute, $currencyCode),
			'updatedBy' => (string)$row['updated_by'],
			'updatedAt' => (string)$row['updated_at'],
		];
	}

	private function loadWorkspaceDefault(int $workspaceId, string $yearMonth, string $currencyCode): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select(
			'default_savings_target_mode',
			BudgetCheckTableCatalog::COL_DEF_SAV_TGT_PCT_BP,
			'default_savings_target_minor',
		)
			->from('bc_workspaces')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($workspaceId, \PDO::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if ($row === false) {
			return null;
		}
		$mode = $row['default_savings_target_mode'] ?? null;
		if ($mode === null || $mode === '') {
			return null;
		}
		$minor = $row['default_savings_target_minor'] === null ? null : (int)$row['default_savings_target_minor'];
		return [
			'id' => null,
			'workspaceId' => $workspaceId,
			'yearMonth' => $yearMonth,
			'targetMode' => (string)$mode,
			'targetPercentBp' => $this->optionalWorkspacePercentBp($row),
			'targetMinor' => $minor,
			'target' => $minor === null ? null : $this->money->envelope($minor, $currencyCode),
			'updatedBy' => null,
			'updatedAt' => null,
			'inheritedFromWorkspaceDefault' => true,
		];
	}

	private function validateMode(string $mode): string
	{
		$mode = strtolower(trim($mode));
		if (!in_array($mode, self::MODES, true)) {
			throw new \InvalidArgumentException('targetMode must be one of: ' . implode(', ', self::MODES) . '.');
		}
		return $mode;
	}

	private function normalisePercent(mixed $value): ?int
	{
		if ($value === null || $value === '') {
			return null;
		}
		if (!is_int($value) && !ctype_digit((string)$value)) {
			throw new \InvalidArgumentException('targetPercentBp must be an integer.');
		}
		$bp = (int)$value;
		// 0% to 100% — values >100% would imply saving more than you earn, which we reject.
		if ($bp < 0 || $bp > 10000) {
			throw new \InvalidArgumentException('targetPercentBp must be between 0 and 10000 (0% to 100%).');
		}
		return $bp;
	}

	private function normaliseAbsolute(mixed $value, string $currencyCode): ?int
	{
		if ($value === null || $value === '') {
			return null;
		}
		if (is_int($value)) {
			$minor = $value;
		} else {
			$decimals = $this->money->decimalsFor($currencyCode);
			$minor = $this->money->parseHumanAmount($value, $decimals);
		}
		if ($minor < 0) {
			throw new \InvalidArgumentException('targetMinor must be zero or positive.');
		}
		return $minor;
	}

	private function validateYearMonth(string $value): string
	{
		$value = trim($value);
		if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
			throw new \InvalidArgumentException('yearMonth must be in YYYY-MM format with a valid month.');
		}
		return $value;
	}

	private function utcNow(): string
	{
		return $this->timeFactory->getDateTime('now', new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function optionalWorkspacePercentBp(array $row): ?int
	{
		foreach ([
			BudgetCheckTableCatalog::COL_DEF_SAV_TGT_PCT_BP,
			BudgetCheckTableCatalog::COL_DEF_SAV_TGT_PCT_BP_LEGACY,
		] as $key) {
			if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
				return (int)$row[$key];
			}
		}
		return null;
	}
}
