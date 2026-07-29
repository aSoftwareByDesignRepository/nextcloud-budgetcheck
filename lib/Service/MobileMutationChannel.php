<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

/**
 * CSRF / auth channel gate for mobile mutations.
 * Basic/Bearer app-password OR a non-empty requesttoken is required.
 * Cookie-only browsers without a token are rejected.
 */
final class MobileMutationChannel
{
	public static function isSafe(
		?string $authorizationHeader,
		?string $requestTokenHeader,
		?string $requestTokenParam,
	): bool {
		$auth = trim((string)$authorizationHeader);
		if ($auth !== '' && preg_match('/^(Basic|Bearer)\s+\S+/i', $auth) === 1) {
			return true;
		}
		$token = trim((string)($requestTokenHeader ?: $requestTokenParam ?: ''));
		return $token !== '';
	}
}
