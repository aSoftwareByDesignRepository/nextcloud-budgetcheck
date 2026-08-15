<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the regional variant catalogs that fix issue #15.
 *
 * Nextcloud resolves the UI language per app; without an exact catalog for
 * e.g. `en_GB`, the server falls back to the browser Accept-Language header
 * and the app can render in a different language than the account setting.
 * Every core-selectable variant of a supported base language must therefore
 * ship a complete catalog (json for PHP, js for the frontend).
 */
final class L10nVariantCatalogTest extends TestCase
{
	private const VARIANTS = [
		'en_GB' => 'en',
		'de_DE' => 'de',
		'es_419' => 'es',
		'es_AR' => 'es',
		'es_CL' => 'es',
		'es_CO' => 'es',
		'es_CR' => 'es',
		'es_DO' => 'es',
		'es_EC' => 'es',
		'es_GT' => 'es',
		'es_HN' => 'es',
		'es_MX' => 'es',
		'es_NI' => 'es',
		'es_PA' => 'es',
		'es_PE' => 'es',
		'es_PR' => 'es',
		'es_PY' => 'es',
		'es_SV' => 'es',
		'es_UY' => 'es',
	];

	private function l10nDir(): string
	{
		return dirname(__DIR__, 2) . '/l10n';
	}

	/** @return array{translations: array<string, string|list<string>>, pluralForm: string} */
	private function loadCatalog(string $lang): array
	{
		$path = $this->l10nDir() . '/' . $lang . '.json';
		self::assertFileExists($path, "Missing catalog {$lang}.json");
		$data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($data['translations'] ?? null, "{$lang}.json has no translations map");
		self::assertIsString($data['pluralForm'] ?? null, "{$lang}.json has no pluralForm");
		return $data;
	}

	public function testEveryVariantShipsJsonAndJs(): void
	{
		foreach (array_keys(self::VARIANTS) as $variant) {
			self::assertFileExists($this->l10nDir() . '/' . $variant . '.json');
			self::assertFileExists($this->l10nDir() . '/' . $variant . '.js');
		}
	}

	public function testVariantCatalogsMatchTheirBase(): void
	{
		foreach (self::VARIANTS as $variant => $base) {
			$variantCatalog = $this->loadCatalog($variant);
			$baseCatalog = $this->loadCatalog($base);

			self::assertSame(
				array_keys($baseCatalog['translations']),
				array_keys($variantCatalog['translations']),
				"{$variant}.json msgid keys drifted from {$base}.json — run scripts/sync-l10n-variants.php"
			);
			self::assertSame(
				$baseCatalog['pluralForm'],
				$variantCatalog['pluralForm'],
				"{$variant}.json pluralForm drifted from {$base}.json"
			);

			foreach ($variantCatalog['translations'] as $key => $value) {
				self::assertNotSame('', is_array($value) ? implode('', $value) : $value, "Empty translation for '{$key}' in {$variant}.json");
			}

			// Regional variants with deliberate value transforms (not plain copies).
			$derivedVariants = ['en_GB', 'de_DE'];
			if (!in_array($variant, $derivedVariants, true)) {
				self::assertSame(
					$baseCatalog['translations'],
					$variantCatalog['translations'],
					"{$variant}.json values drifted from {$base}.json — run scripts/sync-l10n-variants.php"
				);
			}
		}
	}

	public function testEnGbUsesBritishSpellings(): void
	{
		$catalog = $this->loadCatalog('en_GB');
		$flat = '';
		foreach ($catalog['translations'] as $value) {
			$flat .= is_array($value) ? implode(' ', $value) : $value;
			$flat .= ' ';
		}
		foreach (['categorized', 'Categorized', 'authorized', 'recognize', 'favorite'] as $americanism) {
			self::assertStringNotContainsString(
				$americanism,
				$flat,
				"en_GB.json still contains the US spelling '{$americanism}' — run scripts/sync-l10n-variants.php"
			);
		}
		self::assertStringContainsString('uncategorised', $flat);
	}

	public function testDeDeUsesFormalRegister(): void
	{
		require_once dirname(__DIR__, 2) . '/scripts/de-formalize.php';

		$de = $this->loadCatalog('de');
		$deDe = $this->loadCatalog('de_DE');
		$informalPattern = '/\b(du|dich|dir|dein|deine|deinen|deinem|deiner|deins|bist|kannst|musst|hast|wirst|warst|darfst|brauchst|änderst)\b'
			. '|^(Wähle|Gib|Erstelle|Verwende|Aktiviere|Markiere|Füge|Lege|Erfasse|Hänge|Prüfe|Tippe|Klicke|Öffne|Nutze|Lade|Benenne|Probiere|Versuche|Trage)\s/u';

		foreach (['de' => $de, 'de_DE' => $deDe] as $lang => $catalog) {
			foreach ($catalog['translations'] as $key => $value) {
				$text = is_array($value) ? implode(' ', $value) : $value;
				self::assertSame(
					0,
					preg_match($informalPattern, $text),
					"{$lang}.json still uses informal du for '{$key}': {$text}"
				);
			}
		}
		self::assertSame(
			'Sind Sie sicher?',
			$de['translations']['Are you sure?'] ?? '',
		);
		self::assertSame(
			'Sind Sie sicher?',
			$deDe['translations']['Are you sure?'] ?? '',
		);
		// de is formal Sie; de_DE is derived via formalize (idempotent copy).
		foreach ($deDe['translations'] as $key => $value) {
			$base = $de['translations'][$key] ?? null;
			self::assertSame(
				budgetcheck_formalize_german(is_array($base) ? implode(' ', $base) : (string)$base),
				is_array($value) ? implode(' ', $value) : $value,
				"de_DE '{$key}' must equal formalize(de)"
			);
		}
	}

	public function testVariantJsRegistersSameKeyCountAsJson(): void
	{
		foreach (array_keys(self::VARIANTS) as $variant) {
			$catalog = $this->loadCatalog($variant);
			$js = (string)file_get_contents($this->l10nDir() . '/' . $variant . '.js');
			self::assertStringContainsString('OC.L10N.register(', $js);
			self::assertStringContainsString('"budgetcheck"', $js);
			// Every msgid must appear in the JS bundle so t()/n() resolve client-side.
			foreach (['Dashboard', 'Transactions'] as $probe) {
				self::assertStringContainsString(json_encode($probe), $js);
			}
			preg_match_all('/^\s+"(?:[^"\\\\]|\\\\.)*" : /m', $js, $entryLines);
			self::assertSame(
				count($catalog['translations']),
				count($entryLines[0]),
				"{$variant}.js entry count differs from {$variant}.json — run scripts/regenerate-l10n-js.php"
			);
		}
	}
}
