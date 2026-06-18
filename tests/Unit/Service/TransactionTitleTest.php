<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\MoneyService;
use OCA\BudgetCheck\Service\TransactionService;
use PHPUnit\Framework\TestCase;

final class TransactionTitleTest extends TestCase
{
	public function testResolveTitleUsesCategoryNameWhenBlank(): void
	{
		$svc = self::newService();
		$title = self::invokeResolveTitle($svc, '', ['name' => 'Groceries']);
		$this->assertSame('Groceries', $title);
	}

	public function testResolveTitleKeepsCustomValue(): void
	{
		$svc = self::newService();
		$title = self::invokeResolveTitle($svc, 'REWE checkout', ['name' => 'Groceries']);
		$this->assertSame('REWE checkout', $title);
	}

	public function testResolveTitleRejectsBlankWithoutCategoryName(): void
	{
		$svc = self::newService();
		$this->expectException(\InvalidArgumentException::class);
		self::invokeResolveTitle($svc, '', ['name' => '']);
	}

	private static function invokeResolveTitle(object $instance, string $title, array $category): string
	{
		$ref = new \ReflectionMethod(TransactionService::class, 'resolveTitle');
		$ref->setAccessible(true);
		/** @var string $out */
		$out = $ref->invoke($instance, $title, $category);
		return $out;
	}

	private static function newService(): TransactionService
	{
		$ref = new \ReflectionClass(TransactionService::class);
		/** @var TransactionService $svc */
		$svc = $ref->newInstanceWithoutConstructor();
		$moneyProp = new \ReflectionProperty(TransactionService::class, 'money');
		$moneyProp->setAccessible(true);
		$moneyProp->setValue($svc, new MoneyService());
		return $svc;
	}
}
