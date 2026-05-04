<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Exception;

/**
 * Base for BudgetCheck domain errors mapped by {@see \OCA\BudgetCheck\Controller\ApiController::safe()}.
 * Intentionally extends {@see \Exception} (not {@see \RuntimeException}) so these are never confused
 * with incidental runtime failures and stay audit-friendly.
 */
abstract class BudgetCheckException extends \Exception
{
}
