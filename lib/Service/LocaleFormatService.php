<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

use IntlDateFormatter;
use NumberFormatter;
use OCA\BudgetCheck\AppInfo\Application;
use OCP\IConfig;
use OCP\IDateTimeFormatter;
use OCP\IDateTimeZone;
use OCP\IUserSession;
use OCP\L10N\IFactory;

/**
 * Locale-aware date and money formatting helpers.
 *
 * BudgetCheck formats user-visible dates and money with the effective Nextcloud
 * account locale (PHP here; `Intl` / `IDateTimeFormatter` in JS via `clientHints`).
 * Native `<input type="date|month">` widgets: the app sets BCP 47 `lang` on
 * `#app-content`, on modal dialogs, and on each control (see `dates.js`) so UAs
 * align picker chrome with the account where the HTML/CSS stack allows; some
 * engines still blend OS locale—copy in the UI states that honestly.
 */
class LocaleFormatService
{
	private const DEFAULT_LOCALE = 'de';
	private const DEFAULT_TIMEZONE = 'Europe/Berlin';

	private ?string $cachedLocale = null;

	public function __construct(
		private IFactory $l10nFactory,
		private IDateTimeFormatter $dateTimeFormatter,
		private IUserSession $userSession,
		private IDateTimeZone $dateTimeZone,
		private IConfig $config,
	) {
	}

	public function locale(): string
	{
		if ($this->cachedLocale !== null) {
			return $this->cachedLocale;
		}
		$user = $this->userSession->getUser();
		$lang = '';
		if ($user !== null) {
			$lang = (string)$this->l10nFactory->getUserLanguage($user);
		}
		if ($lang === '') {
			$lang = (string)$this->config->getAppValue(Application::APP_ID, 'default_locale', '');
		}
		if ($lang === '') {
			try {
				$lang = (string)$this->l10nFactory->findLanguage(Application::APP_ID);
			} catch (\Throwable) {
				$lang = self::DEFAULT_LOCALE;
			}
		}
		if ($lang === '') {
			$lang = self::DEFAULT_LOCALE;
		}
		$this->cachedLocale = $lang;
		return $lang;
	}

	public function timezone(): \DateTimeZone
	{
		try {
			$tz = $this->dateTimeZone->getTimeZone();
			return $tz instanceof \DateTimeZone ? $tz : new \DateTimeZone(self::DEFAULT_TIMEZONE);
		} catch (\Throwable) {
			return new \DateTimeZone(self::DEFAULT_TIMEZONE);
		}
	}

	/** @return array{locale:string, htmlLang:string, timezone:string} */
	public function clientHints(): array
	{
		$locale = $this->locale();
		return [
			'locale' => $locale,
			'htmlLang' => self::canonicalHtmlLangFromLocaleString($locale),
			'timezone' => $this->timezone()->getName(),
		];
	}

	/**
	 * @internal Exported for unit tests
	 */
	public static function canonicalHtmlLangFromLocaleString(string $raw): string
	{
		$tag = str_replace('_', '-', trim($raw));
		if ($tag === '') {
			return 'de-DE';
		}
		$parts = explode('-', $tag);
		$n = count($parts);
		if ($n >= 2 && strlen($parts[1]) === 2 && ctype_alpha($parts[1])) {
			return strtolower($parts[0]) . '-' . strtoupper($parts[1]);
		}
		if ($n >= 3 && strlen($parts[2]) === 2 && ctype_alpha($parts[2])) {
			return $tag;
		}
		$base = strtolower($parts[0]);
		return match ($base) {
			'de' => 'de-DE',
			'fr' => 'fr-FR',
			'it' => 'it-IT',
			'es' => 'es-ES',
			'nl' => 'nl-NL',
			'pl' => 'pl-PL',
			'pt' => 'pt-PT',
			'sv' => 'sv-SE',
			'da' => 'da-DK',
			'fi' => 'fi-FI',
			'cs' => 'cs-CZ',
			'sk' => 'sk-SK',
			'hu' => 'hu-HU',
			'ro' => 'ro-RO',
			'tr' => 'tr-TR',
			'ru' => 'ru-RU',
			'uk' => 'uk-UA',
			'el' => 'el-GR',
			'en' => 'en-GB',
			'ja' => 'ja-JP',
			'ko' => 'ko-KR',
			'zh' => 'zh-CN',
			'nb', 'nn' => 'nb-NO',
			default => $tag,
		};
	}

	/**
	 * Short pattern hint for calendar-day text fields (must match parsing in `js/common/dates.js`).
	 */
	public function calendarDayPatternHint(): string
	{
		$lang = strtolower(str_replace('-', '_', $this->locale()));
		if (str_starts_with($lang, 'de')) {
			return 'dd.mm.yyyy';
		}
		if ($lang === 'en_us' || str_starts_with($lang, 'en_us')) {
			return 'mm/dd/yyyy';
		}
		if (str_starts_with($lang, 'en')) {
			return 'dd/mm/yyyy';
		}
		return 'yyyy-mm-dd';
	}

	public function formatDate(string $isoDate, string $width = 'long'): string
	{
		try {
			$date = new \DateTimeImmutable($isoDate, $this->timezone());
		} catch (\Throwable) {
			return $isoDate;
		}
		return $this->intlDate($date, $width);
	}

	public function formatYearMonth(string $yearMonth): string
	{
		if (!preg_match('/^\d{4}-\d{2}$/', $yearMonth)) {
			return $yearMonth;
		}
		try {
			$date = new \DateTimeImmutable($yearMonth . '-01', $this->timezone());
		} catch (\Throwable) {
			return $yearMonth;
		}
		if (class_exists(IntlDateFormatter::class)) {
			$fmt = new IntlDateFormatter(
				$this->locale(),
				IntlDateFormatter::NONE,
				IntlDateFormatter::NONE,
				$this->timezone(),
				IntlDateFormatter::GREGORIAN,
				'LLLL yyyy'
			);
			$out = $fmt->format($date);
			if ($out !== false && $out !== '') {
				return (string)$out;
			}
		}
		return $date->format('F Y');
	}

	public function formatMoney(int $minor, string $currency, int $decimals = 2): string
	{
		$amount = $minor / (10 ** $decimals);
		if (class_exists(NumberFormatter::class)) {
			$fmt = new NumberFormatter($this->locale(), NumberFormatter::CURRENCY);
			$fmt->setAttribute(NumberFormatter::FRACTION_DIGITS, $decimals);
			$formatted = $fmt->formatCurrency($amount, $currency);
			if (is_string($formatted) && $formatted !== '') {
				return $formatted;
			}
		}
		return number_format($amount, $decimals, '.', ',') . ' ' . strtoupper($currency);
	}

	private function intlDate(\DateTimeImmutable $date, string $width): string
	{
		if (class_exists(IntlDateFormatter::class)) {
			$dateStyle = match ($width) {
				'short' => IntlDateFormatter::SHORT,
				'medium' => IntlDateFormatter::MEDIUM,
				'full' => IntlDateFormatter::FULL,
				default => IntlDateFormatter::LONG,
			};
			$fmt = new IntlDateFormatter(
				$this->locale(),
				$dateStyle,
				IntlDateFormatter::NONE,
				$this->timezone(),
				IntlDateFormatter::GREGORIAN
			);
			$out = $fmt->format($date);
			if ($out !== false && $out !== '') {
				return (string)$out;
			}
		}
		try {
			return $this->dateTimeFormatter->formatDate($date->getTimestamp(), $width);
		} catch (\Throwable) {
			return $date->format('Y-m-d');
		}
	}
}
