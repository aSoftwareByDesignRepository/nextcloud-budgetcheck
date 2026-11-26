<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Agent;

use OCA\BudgetCheck\Service\AccessControlService;

/**
 * Per-request entitlement for agent requests (BQ-3).
 *
 * BudgetCheck has no license/seat concept (COMPANION-APP.md v2.0: the mobile
 * API is free) — the app access door IS the entitlement. A user removed
 * from the door allowlist loses agent access on the next call, without
 * waiting for token expiry. Any entitlement failure collapses to one
 * uniform 403 — no existence oracle.
 */
final class BudgetCheckAgentSeatGate implements AgentSeatGate
{
	public function __construct(
		private readonly AccessControlService $access,
	) {
	}

	public function assertEntitled(string $uid): void
	{
		try {
			if (!$this->access->canUseApp($uid)) {
				throw new AgentException(
					'agent_seat_revoked',
					'This agent token has no active entitlement.',
					403,
				);
			}
		} catch (AgentException $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw new AgentException(
				'agent_seat_revoked',
				'This agent token has no active entitlement.',
				403,
			);
		}
	}
}
