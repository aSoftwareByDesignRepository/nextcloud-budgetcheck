<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../scripts/de-formalize.php';

final class DeFormalizeTest extends TestCase
{
	public function testFormalizesCommonImperativesAndPronouns(): void
	{
		self::assertSame(
			'Sind Sie sicher?',
			budgetcheck_formalize_german('Bist du sicher?'),
		);
		self::assertSame(
			'Wählen Sie einen Arbeitsbereich, um Einnahmen und Ausgaben zu erfassen.',
			budgetcheck_formalize_german('Wähle einen Arbeitsbereich, um Einnahmen und Ausgaben zu erfassen.'),
		);
		self::assertSame(
			'Erstellen Sie Ihren ersten Arbeitsbereich',
			budgetcheck_formalize_german('Erstelle deinen ersten Arbeitsbereich'),
		);
	}

	public function testLeavesThirdPersonSieUntouched(): void
	{
		$thirdPerson = 'Für ungewöhnlich hohe oder einmalige Buchungen. Sie bleiben im Hauptbuch.';
		self::assertSame($thirdPerson, budgetcheck_formalize_german($thirdPerson));
	}

	public function testIsIdempotentOnAlreadyFormalStrings(): void
	{
		$formal = 'Sie haben keine Berechtigung, die App-Einstellungen zu öffnen.';
		self::assertSame($formal, budgetcheck_formalize_german($formal));
	}
}
