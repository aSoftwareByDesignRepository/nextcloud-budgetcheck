<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

/**
 * CSRF / auth channel gate for mobile mutations.
 *
 * Safe channels (enforced in {@see \OCA\BudgetCheck\Controller\MobileApiController}):
 *  - a cryptographically validated CSRF requesttoken ({@see IRequest::passesCSRFCheck()}), OR
 *  - Authorization: Basic credentials that {@see IUserManager::checkPassword()} as the
 *    already-bound session user (companion app password).
 *
 * Presence of `Authorization: Bearer …` / junk `Basic …` MUST NOT bypass CSRF —
 * that would let a same-site attacker ride the victim's browser cookie.
 * Cookie-only browsers without a *valid* token are rejected.
 */
final class MobileMutationChannel
{
	/**
	 * @param bool $csrfPassed Result of {@see IRequest::passesCSRFCheck()}
	 * @param bool $basicAuthValidated True only when Basic credentials checkPassword
	 *                                 as the current session UID
	 */
	public static function isSafe(
		bool $csrfPassed,
		bool $basicAuthValidated,
	): bool {
		return $csrfPassed || $basicAuthValidated;
	}
}
