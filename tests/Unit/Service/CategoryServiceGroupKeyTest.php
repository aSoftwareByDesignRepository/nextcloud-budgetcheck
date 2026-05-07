<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\AccessControlService;
use OCA\BudgetCheck\Service\AuditLogService;
use OCA\BudgetCheck\Service\CategoryService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class CategoryServiceGroupKeyTest extends TestCase
{
	private CategoryService $service;

	protected function setUp(): void
	{
		$this->service = new CategoryService(
			$this->createMock(IDBConnection::class),
			$this->createMock(AccessControlService::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(AuditLogService::class),
		);
	}

	public function testNormaliseGroupKeyAcceptsSpaces(): void
	{
		$method = new \ReflectionMethod(CategoryService::class, 'normaliseGroupKey');
		$method->setAccessible(true);
		$result = $method->invoke($this->service, 'Home Travel');
		$this->assertSame('Home Travel', $result);
	}

	public function testNormaliseGroupKeyRejectsSlash(): void
	{
		$method = new \ReflectionMethod(CategoryService::class, 'normaliseGroupKey');
		$method->setAccessible(true);
		$this->expectException(\InvalidArgumentException::class);
		$method->invoke($this->service, 'Home/Travel');
	}
}

