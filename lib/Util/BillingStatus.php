<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Util;

/**
 * Invoice settlement status for billable ledger rows (InvoiceCheck handshake).
 * Independent of booking_status_id project workflow labels.
 *
 * Lifecycle: open → invoiced → paid (and reverse via reopen / reopenFromPaid).
 */
final class BillingStatus
{
	public const OPEN = 'open';
	public const INVOICED = 'invoiced';
	public const PAID = 'paid';

	public static function isValid(string $status): bool
	{
		return $status === self::OPEN
			|| $status === self::INVOICED
			|| $status === self::PAID;
	}
}
