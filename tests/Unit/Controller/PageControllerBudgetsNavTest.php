<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Controller;

use OCA\BudgetCheck\Controller\PageController;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Budgets must appear in primary nav for any selected workspace (T3.1 / §6.2).
 */
final class PageControllerBudgetsNavTest extends TestCase
{
	public function testBudgetsNavShownWhenWorkspaceSelected(): void
	{
		$controller = $this->controllerWith(
			fn (string $text, array $parameters = []): string => $text,
			fn (string $route, array $params = []): string => '/apps/budgetcheck/' . str_replace('budgetcheck.page.', '', $route),
		);
		$method = new ReflectionMethod(PageController::class, 'buildNavigation');
		$method->setAccessible(true);

		$workspace = [
			'id' => 3,
			'type' => 'household',
			'role' => 'manager',
		];
		/** @var list<array{id:string,label:string}> $nav */
		$nav = $method->invoke($controller, 'dashboard', $workspace, false, true);
		$ids = array_column($nav, 'id');
		self::assertContains('budgets', $ids);
		self::assertContains('monthly', $ids);
		self::assertNotContains('period', $ids);
	}

	public function testBudgetsNavShownForProjectWithoutMonthly(): void
	{
		$controller = $this->controllerWith(
			fn (string $text, array $parameters = []): string => $text,
			fn (string $route, array $params = []): string => '/r/' . $route,
		);
		$method = new ReflectionMethod(PageController::class, 'buildNavigation');
		$method->setAccessible(true);

		$workspace = [
			'id' => 8,
			'type' => 'project',
			'role' => 'viewer',
		];
		$nav = $method->invoke($controller, 'budgets', $workspace, false, false);
		$ids = array_column($nav, 'id');
		self::assertContains('budgets', $ids);
		self::assertContains('period', $ids);
		self::assertNotContains('monthly', $ids);
		self::assertNotContains('yearly', $ids);
	}

	public function testBudgetsHiddenWithoutWorkspace(): void
	{
		$controller = $this->controllerWith(
			fn (string $text, array $parameters = []): string => $text,
			fn (string $route, array $params = []): string => '/r',
		);
		$method = new ReflectionMethod(PageController::class, 'buildNavigation');
		$method->setAccessible(true);
		$nav = $method->invoke($controller, 'dashboard', null, true, false);
		$ids = array_column($nav, 'id');
		self::assertNotContains('budgets', $ids);
		self::assertNotContains('transactions', $ids);
	}

	private function controllerWith(callable $t, callable $linkToRoute): PageController
	{
		$controller = (new \ReflectionClass(PageController::class))->newInstanceWithoutConstructor();
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback($t);
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRoute')->willReturnCallback($linkToRoute);
		$ref = new \ReflectionClass($controller);
		foreach (['l10n' => $l10n, 'urlGenerator' => $url] as $prop => $value) {
			$p = $ref->getProperty($prop);
			$p->setAccessible(true);
			$p->setValue($controller, $value);
		}
		return $controller;
	}
}
