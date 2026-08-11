<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Exception;

/**
 * Recoverable conflict (optimistic lock or domain invariant). Maps to HTTP 409.
 */
final class ConflictException extends BudgetCheckException
{
	public const CODE_VERSION_CONFLICT = 'version_conflict';
	public const CODE_WORKSPACE_HAS_GROUP_MEMBERS = 'workspace_has_group_members';
	public const CODE_PRIVATE_WORKSPACE_GROUPS_FORBIDDEN = 'private_workspace_groups_forbidden';
	public const CODE_PRIVATE_WORKSPACE_DUAL_MANAGER = 'private_workspace_dual_manager_required';

	public function __construct(
		private string $errorCode = self::CODE_VERSION_CONFLICT,
		string $message = 'This entry changed since you opened it. Reload and retry.',
	) {
		parent::__construct($message);
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}
}
