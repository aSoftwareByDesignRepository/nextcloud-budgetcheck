<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Agent;

use OCA\BudgetCheck\Service\AccessControlService;

/**
 * App-door adapter: the agent surface honours the exact same access policy
 * as the web UI. canUseApp = the app access door; canAdminister = the
 * dedicated BudgetCheck app-admin role (isAppAdmin already covers NC server
 * admins) — matching who may reach the app-settings page that hosts the
 * "AI access (MCP)" section.
 */
final class BudgetCheckAgentAccessGate implements AgentAccessGate
{
	public function __construct(
		private readonly AccessControlService $access,
	) {
	}

	public function canUseApp(string $uid): bool
	{
		return $this->access->canUseApp($uid);
	}

	public function canAdminister(string $uid): bool
	{
		return $this->access->isAppAdmin($uid);
	}
}
