<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Exception;

/**
 * Household-only vs project-only operation mismatch (§12.3). Maps to HTTP 422.
 */
final class WorkspaceTypeMismatchException extends BudgetCheckException
{
	public function __construct(
		private string $expectedType,
		private string $actualType,
		private string $operation,
	) {
		parent::__construct('NOT_APPLICABLE_FOR_WORKSPACE_TYPE');
	}

	public function getExpectedType(): string
	{
		return $this->expectedType;
	}

	public function getActualType(): string
	{
		return $this->actualType;
	}

	public function getOperation(): string
	{
		return $this->operation;
	}
}
