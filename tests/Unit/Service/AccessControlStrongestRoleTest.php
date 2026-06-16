<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\AccessControlService;
use OCA\BudgetCheck\Service\MoneyService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Locks down the role-combination precedence used to merge a user's own
 * workspace role with the roles inherited from their groups. This is the
 * security-critical rule behind group-based access: the effective role must be
 * the strongest of all sources, and unknown/empty input must never grant a role.
 */
final class AccessControlStrongestRoleTest extends TestCase
{
	private AccessControlService $service;

	protected function setUp(): void
	{
		$this->service = new AccessControlService(
			$this->createMock(IDBConnection::class),
			$this->createMock(IConfig::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserSession::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(IUserManager::class),
			$this->createMock(MoneyService::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testEmptyListYieldsNoRole(): void
	{
		$this->assertNull($this->service->strongestRole([]));
	}

	public function testSingleRoleIsReturned(): void
	{
		$this->assertSame('viewer', $this->service->strongestRole(['viewer']));
		$this->assertSame('contributor', $this->service->strongestRole(['contributor']));
		$this->assertSame('manager', $this->service->strongestRole(['manager']));
	}

	public function testContributorBeatsViewerRegardlessOfOrder(): void
	{
		$this->assertSame('contributor', $this->service->strongestRole(['viewer', 'contributor']));
		$this->assertSame('contributor', $this->service->strongestRole(['contributor', 'viewer']));
	}

	public function testManagerIsStrongest(): void
	{
		$this->assertSame('manager', $this->service->strongestRole(['viewer', 'manager', 'contributor']));
	}

	public function testUnknownRolesAreIgnored(): void
	{
		$this->assertSame('viewer', $this->service->strongestRole(['bogus', 'viewer']));
	}

	public function testOnlyUnknownRolesYieldNoRole(): void
	{
		$this->assertNull($this->service->strongestRole(['bogus', '', 'owner']));
	}
}
