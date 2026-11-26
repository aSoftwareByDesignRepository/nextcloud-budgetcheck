<?php
/** Synced from _shared/agent-auth — do not edit app-side. */

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Agent;

use OCA\BudgetCheck\Agent\AgentException;
use OCA\BudgetCheck\Agent\AgentScopeChecker;
use PHPUnit\Framework\TestCase;

/**
 * Scope vocabulary is exact-match only — the checks an attacker would
 * probe first (wildcards, prefix tricks, case games) must all fail closed.
 */
class AgentScopeCheckerTest extends TestCase
{
	public function testValidFormatsAccepted(): void
	{
		$this->assertTrue(AgentScopeChecker::isValidFormat('budgetcheck:time_entries:read'));
		$this->assertTrue(AgentScopeChecker::isValidFormat('budgetcheck:invoices:write'));
		$this->assertTrue(AgentScopeChecker::isValidFormat('a1_b2:c3_d4:write'));
	}

	public function testInvalidFormatsRejected(): void
	{
		$cases = [
			'',
			'budgetcheck:time_entries',                 // missing action
			'budgetcheck:time_entries:read:extra',      // too many segments
			'budgetcheck:Time_Entries:READ',            // case games
			'budgetcheck:*:read',                       // wildcard resource
			'budgetcheck:time_entries:*',               // wildcard action
			'*:*:*',
			'budgetcheck:*',                            // app-wide wildcard
			'budgetcheck :time_entries:read',           // embedded space
			"budgetcheck:time_entries:read\n",          // control chars
			'budgetcheck:time-entries:read',            // dash not allowed
			'budgetcheck::read',                        // empty resource
			':time_entries:read',                      // empty app
			'budgetcheck:time_entries:delete',          // v1 action set is read|write only
		];
		foreach ($cases as $scope) {
			$this->assertFalse(
				AgentScopeChecker::isValidFormat($scope),
				"scope should be invalid: " . var_export($scope, true)
			);
		}
	}

	public function testPrefixGrantIsNotSatisfaction(): void
	{
		// granted scope that is a string-prefix of the requirement must NOT
		// satisfy — exact match is the whole security model.
		$this->assertFalse(
			AgentScopeChecker::satisfied(
				['budgetcheck:time_ent'],
				'budgetcheck:time_entries:write',
			)
		);
		$this->assertFalse(
			AgentScopeChecker::satisfied(
				['budgetcheck:time_entries:write_extra'],
				'budgetcheck:time_entries:write',
			)
		);
	}

	public function testReadDoesNotSatisfyWrite(): void
	{
		$granted = ['budgetcheck:time_entries:read'];
		$this->assertFalse(
			AgentScopeChecker::satisfied($granted, 'budgetcheck:time_entries:write'),
			'read scope must never satisfy a write requirement'
		);
		$this->assertTrue(AgentScopeChecker::satisfied($granted, 'budgetcheck:time_entries:read'));
	}

	public function testPrefixMatchIsNotSatisfaction(): void
	{
		$granted = ['budgetcheck:time_entr:write', 'budgetcheck:time_entries_extended:write'];
		$this->assertFalse(
			AgentScopeChecker::satisfied($granted, 'budgetcheck:time_entries:write'),
			'prefix/partial matches must not satisfy'
		);
	}

	public function testValidateGrantRequestDeduplicatesAndValidates(): void
	{
		$declared = ['budgetcheck:a:read', 'budgetcheck:b:write'];
		$grants = AgentScopeChecker::validateGrantRequest(
			['budgetcheck:a:read', ' budgetcheck:a:read ', 'budgetcheck:b:write'],
			$declared,
		);
		$this->assertSame(['budgetcheck:a:read', 'budgetcheck:b:write'], $grants);
	}

	public function testValidateGrantRequestRejectsUndeclared(): void
	{
		$this->expectException(AgentException::class);
		AgentScopeChecker::validateGrantRequest(
			['budgetcheck:secret:read'],
			['budgetcheck:a:read'],
		);
	}

	public function testValidateGrantRequestRejectsWildcard(): void
	{
		try {
			AgentScopeChecker::validateGrantRequest(['budgetcheck:*:read'], ['budgetcheck:a:read']);
			$this->fail('wildcard grant must throw');
		} catch (AgentException $e) {
			$this->assertSame('validation', $e->getErrorCode());
		}
	}

	public function testValidateGrantRequestRejectsEmpty(): void
	{
		try {
			AgentScopeChecker::validateGrantRequest(['  '], ['budgetcheck:a:read']);
			$this->fail('empty grant list must throw');
		} catch (AgentException $e) {
			$this->assertSame('validation', $e->getErrorCode());
		}
	}

	public function testValidateGrantRequestRejectsNonString(): void
	{
		try {
			AgentScopeChecker::validateGrantRequest([['nested']], ['budgetcheck:a:read']);
			$this->fail('non-string scope must throw');
		} catch (AgentException $e) {
			$this->assertSame('validation', $e->getErrorCode());
		}
	}
}
