<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Exception;

use OCA\BudgetCheck\Exception\AccessDeniedException;
use OCA\BudgetCheck\Exception\BudgetCheckException;
use OCA\BudgetCheck\Exception\ConflictException;
use OCA\BudgetCheck\Exception\InternalErrorException;
use OCA\BudgetCheck\Exception\NotAuthenticatedException;
use OCA\BudgetCheck\Exception\RateLimitExceededException;
use OCA\BudgetCheck\Exception\WorkspaceTypeMismatchException;
use PHPUnit\Framework\TestCase;

class TypedExceptionsTest extends TestCase
{
	public function testHierarchy(): void
	{
		self::assertInstanceOf(BudgetCheckException::class, new AccessDeniedException());
		self::assertInstanceOf(BudgetCheckException::class, new NotAuthenticatedException());
		self::assertInstanceOf(BudgetCheckException::class, new ConflictException());
		self::assertInstanceOf(BudgetCheckException::class, new RateLimitExceededException());
		self::assertInstanceOf(BudgetCheckException::class, new InternalErrorException());
		self::assertInstanceOf(BudgetCheckException::class, new WorkspaceTypeMismatchException('household', 'project', 'op'));
	}

	public function testWorkspaceTypeMismatchPayload(): void
	{
		$e = new WorkspaceTypeMismatchException('household', 'project', 'monthly_close');
		self::assertSame('household', $e->getExpectedType());
		self::assertSame('project', $e->getActualType());
		self::assertSame('monthly_close', $e->getOperation());
	}
}
