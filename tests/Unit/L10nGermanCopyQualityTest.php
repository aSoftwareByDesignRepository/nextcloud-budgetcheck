<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Native-speaker German copy for household KPIs and alerts must not regress
 * to English leftovers or awkward calques ("Enger Monat", "Verfügbar nach Sparzielen").
 */
final class L10nGermanCopyQualityTest extends TestCase
{
	/** @return array<string, string|list<string>> */
	private function deTranslations(): array
	{
		$path = dirname(__DIR__, 2) . '/l10n/de.json';
		$data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($data['translations'] ?? null);
		return $data['translations'];
	}

	public function testAvailableAfterSavingsUsesNativeGerman(): void
	{
		$t = $this->deTranslations();
		self::assertSame('Nach Sparziel verfügbar', $t['Available after savings']);
		self::assertSame('Negatives Restbudget nach Sparziel', $t['Available after savings is negative']);
		self::assertSame(
			'Nach Abzug des Sparziels bist du diesen Monat im Minus.',
			$t['Available after savings is negative this month.']
		);
		self::assertSame('Knappes Budget', $t['Tight month']);
	}

	public function testBannedCalquesAbsent(): void
	{
		$t = $this->deTranslations();
		$parts = [];
		foreach ($t as $value) {
			if (is_string($value)) {
				$parts[] = $value;
			}
		}
		$blob = implode("\n", $parts);
		foreach ([
			'Verfügbar nach Sparzielen',
			'Verfügbar nach Sparen',
			'Enger Monat',
			'Knapper Monat',
			'Deckel-Hinweis',
			'Alltagsbudget-Saldo',
		] as $bad) {
			self::assertStringNotContainsString($bad, $blob, "Banned calque still present: {$bad}");
		}
	}

	public function testDeQualityScriptPasses(): void
	{
		$script = dirname(__DIR__, 2) . '/scripts/check-l10n-de-quality.php';
		self::assertFileExists($script);
		$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
		exec($cmd . ' 2>&1', $out, $code);
		self::assertSame(0, $code, implode("\n", $out));
	}
}
