<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Exception;

/**
 * Thrown for client-correctable input; mapped to HTTP 400 with optional per-field messages (§7).
 */
class ValidationException extends \InvalidArgumentException
{
	/**
	 * @param array<string, string> $fields field name => human-readable message
	 */
	public function __construct(
		string $message,
		private array $fields = [],
	) {
		parent::__construct($message);
	}

	/**
	 * @return array<string, string>
	 */
	public function getFields(): array
	{
		return $this->fields;
	}
}
