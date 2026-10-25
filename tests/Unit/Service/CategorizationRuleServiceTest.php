<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit\Service;

use OCA\BudgetCheck\Service\CategorizationRuleService;
use OCA\BudgetCheck\Service\PayeeService;
use PHPUnit\Framework\TestCase;

/**
 * The rule evaluator is pure (spec NN-03): same input → same category, first
 * match wins in (priority, id) order, literal matching is normalized, regex
 * is an explicit per-rule opt-in.
 */
final class CategorizationRuleServiceTest extends TestCase
{
	private function service(): CategorizationRuleService
	{
		return new CategorizationRuleService(
			$this->createMock(\OCP\IDBConnection::class),
			$this->createMock(\OCA\BudgetCheck\Service\AccessControlService::class),
			$this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class),
			$this->createMock(\OCA\BudgetCheck\Service\AuditLogService::class),
			$this->createMock(\OCA\BudgetCheck\Service\CategoryService::class),
			$this->createMock(PayeeService::class),
		);
	}

	private function rule(array $over = []): array
	{
		return array_merge([
			'id' => 1,
			'category_id' => 10,
			'match_field' => 'title',
			'match_type' => 'contains',
			'pattern' => 'rewe',
			'direction' => 'any',
			'priority' => 100,
			'use_regex' => false,
			'is_active' => true,
		], $over);
	}

	public function testContainsMatchesCaseAndWhitespaceInsensitive(): void
	{
		$s = $this->service();
		$hit = $s->evaluate([$this->rule()], ['title' => '  REWE  Markt  ']);
		self::assertSame(10, (int)$hit['category_id']);
	}

	public function testEqualsRequiresWholeNormalizedString(): void
	{
		$s = $this->service();
		self::assertNull($s->evaluate([$this->rule(['match_type' => 'equals'])], ['title' => 'rewe markt']));
		self::assertNotNull($s->evaluate([$this->rule(['match_type' => 'equals'])], ['title' => ' REWE ']));
	}

	public function testStartsWith(): void
	{
		$s = $this->service();
		self::assertNotNull($s->evaluate([$this->rule(['match_type' => 'starts_with'])], ['title' => 'rewe markt']));
		self::assertNull($s->evaluate([$this->rule(['match_type' => 'starts_with'])], ['title' => 'einkauf rewe']));
	}

	public function testFirstMatchInListOrderWins(): void
	{
		$s = $this->service();
		$rules = [
			$this->rule(['id' => 1, 'category_id' => 10, 'priority' => 50]),
			$this->rule(['id' => 2, 'category_id' => 20, 'priority' => 10]),
		];
		// Caller passes rules pre-sorted (priority, id); evaluator honours the order.
		$hit = $s->evaluate([$rules[1], $rules[0]], ['title' => 'rewe']);
		self::assertSame(20, (int)$hit['category_id']);
	}

	public function testInactiveRuleNeverMatches(): void
	{
		$s = $this->service();
		self::assertNull($s->evaluate([$this->rule(['is_active' => false])], ['title' => 'rewe']));
	}

	public function testDirectionGate(): void
	{
		$s = $this->service();
		$r = $this->rule(['direction' => 'expense']);
		self::assertNull($s->evaluate([$r], ['title' => 'rewe', 'direction' => 'income']));
		self::assertNotNull($s->evaluate([$r], ['title' => 'rewe', 'direction' => 'expense']));
		self::assertNotNull($s->evaluate([$this->rule()], ['title' => 'rewe', 'direction' => 'income']));
	}

	public function testMatchFieldSelectsSubject(): void
	{
		$s = $this->service();
		$payeeRule = $this->rule(['match_field' => 'payee']);
		self::assertNull($s->evaluate([$payeeRule], ['title' => 'rewe', 'payeeName' => 'aldi']));
		self::assertNotNull($s->evaluate([$payeeRule], ['title' => 'x', 'payeeName' => 'REWE GmbH']));
	}

	public function testEmptySubjectFieldNeverMatches(): void
	{
		$s = $this->service();
		self::assertNull($s->evaluate([$this->rule(['match_field' => 'notes'])], ['notes' => '']));
	}

	public function testEmptyPatternNeverMatches(): void
	{
		$s = $this->service();
		self::assertNull($s->evaluate([$this->rule(['pattern' => '   '])], ['title' => 'rewe']));
	}

	public function testLiteralPatternIsNotARegex(): void
	{
		$s = $this->service();
		// "a.b" literal must NOT match "axb" — proves patterns are not regex.
		$r = $this->rule(['pattern' => 'a.b', 'match_type' => 'contains']);
		self::assertNull($s->evaluate([$r], ['title' => 'axb']));
		self::assertNotNull($s->evaluate([$r], ['title' => 'a.b shop']));
	}

	public function testRegexOptInMatchesAndBadPatternIsSafe(): void
	{
		$s = $this->service();
		$r = $this->rule(['pattern' => 'rewe\s+\d+', 'use_regex' => true]);
		self::assertNotNull($s->evaluate([$r], ['title' => 'rewe 1234 store']));
		// A syntactically broken regex evaluates to no-match, never fatal.
		$bad = $this->rule(['pattern' => '([unclosed', 'use_regex' => true]);
		self::assertNull($s->evaluate([$bad], ['title' => 'anything']));
	}

	public function testUtf8NormalizationInMatching(): void
	{
		$s = $this->service();
		$r = $this->rule(['pattern' => 'aldi süd']);
		self::assertNotNull($s->evaluate([$r], ['title' => 'ALDI   SÜD Filiale']));
	}
}
