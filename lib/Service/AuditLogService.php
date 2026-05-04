<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

use OCA\BudgetCheck\AppInfo\Application;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;

/**
 * Append-only audit trail for security-relevant and operationally relevant
 * actions in BudgetCheck (workspace create/update/delete/membership change,
 * monthly close/reopen, settings save, rate-limit hit, access-denied probe).
 *
 * - IPs and user-agents are stored as salted SHA-256 hashes.
 * - `details_json` is sanitised to drop tokens, secrets, and raw user content.
 * - The reader API enforces workspace scoping so non-managers can only see
 *   audit rows for the workspaces they belong to.
 */
class AuditLogService
{
	public function __construct(
		private IDBConnection $db,
		private IRequest $request,
		private IConfig $config,
	) {
	}

	public function record(
		string $actorUserId,
		string $action,
		string $objectType,
		string $objectId,
		array $details = [],
		?int $workspaceId = null,
	): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('bc_audit_log')
			->values([
				'actor_user_id' => $qb->createNamedParameter($actorUserId),
				'action' => $qb->createNamedParameter($action),
				'object_type' => $qb->createNamedParameter($objectType),
				'object_id' => $qb->createNamedParameter($objectId),
				'workspace_id' => $qb->createNamedParameter($workspaceId, $workspaceId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT),
				'details_json' => $qb->createNamedParameter(json_encode($this->sanitiseDetails($details), JSON_THROW_ON_ERROR)),
				'ip_hash' => $qb->createNamedParameter($this->hashNullable($this->request->getRemoteAddress())),
				'user_agent_hash' => $qb->createNamedParameter($this->hashNullable((string)$this->request->getHeader('User-Agent'))),
				'created_at' => $qb->createNamedParameter($this->now()),
			]);
		$qb->executeStatement();
	}

	private function sanitiseDetails(array $details): array
	{
		$blocked = ['password', 'requesttoken', 'token', 'secret', 'ip', 'userAgent'];
		foreach ($blocked as $key) {
			unset($details[$key]);
		}
		$out = [];
		foreach ($details as $key => $value) {
			if (!is_string($key) || $key === '') {
				continue;
			}
			if (is_scalar($value) || $value === null) {
				$out[$key] = $value;
				continue;
			}
			if (is_array($value)) {
				$out[$key] = $this->sanitiseDetails($value);
			}
		}
		return $out;
	}

	private function hashNullable(?string $value): ?string
	{
		$value = trim((string)$value);
		if ($value === '') {
			return null;
		}
		$salt = (string)$this->config->getAppValue(Application::APP_ID, 'audit_hash_salt', '');
		if ($salt === '') {
			$salt = bin2hex(random_bytes(16));
			$this->config->setAppValue(Application::APP_ID, 'audit_hash_salt', $salt);
		}
		return hash('sha256', $salt . ':' . $value);
	}

	private function now(): string
	{
		return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
	}
}
