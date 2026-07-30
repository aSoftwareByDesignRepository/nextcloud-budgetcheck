<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

/**
 * CSRF / auth channel gate for mobile mutations.
 *
 * Safe channels:
 *  - Basic/Bearer Authorization (app-password / token clients), OR
 *  - a cryptographically validated CSRF requesttoken ({@see IRequest::passesCSRFCheck()}).
 *
 * Cookie-only browsers without a *valid* token are rejected. A non-empty
 * forged `requesttoken` string alone is never enough.
 */
final class MobileMutationChannel
{
	public static function isSafe(
		?string $authorizationHeader,
		bool $csrfPassed,
	): bool {
		$auth = trim((string)$authorizationHeader);
		if ($auth !== '' && preg_match('/^(Basic|Bearer)\s+\S+/i', $auth) === 1) {
			return true;
		}
		return $csrfPassed;
	}
}
