<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

/**
 * Money arithmetic, validation, and formatting for BudgetCheck.
 *
 * - All money is stored, transported, and computed in **minor units** (integers).
 *   Floating-point currency math is forbidden by §2.4 of the spec.
 * - VAT rates are basis points (1900 = 19.00%). The `bp` suffix on every rate
 *   field reminds the reader that the value is a basis point, not a percent.
 * - `convertTax()` produces (net, vat, gross) triples for either net-entry or
 *   gross-entry, deterministically rounding once and then deriving the partner
 *   so `net + vat = gross` always holds for the persisted row.
 * - `parseHumanAmount()` accepts the strings users actually type — German
 *   ("1.234,56"), English ("1,234.56"), and bare ("1234.5") — and rejects
 *   ambiguous or signed input. Sign comes from the explicit `direction` field.
 */
class MoneyService
{
	/**
	 * Largest amount we accept anywhere in the API (per row, as minor units).
	 *
	 * The cap exists for two reasons:
	 *  1. A bigint can store ~9.2 * 10^18, but JS `Number` only has 53 bits of
	 *     integer precision. We stay safely below 2^53 - 1 so dashboards that do
	 *     client-side aggregation never lose accuracy.
	 *  2. A €100 trillion line item is, with extremely high probability, a typo.
	 */
	public const MAX_AMOUNT_MINOR = 9_999_999_999_999; // 99.999.999.999,99 €
	public const MIN_AMOUNT_MINOR = 1; // amounts must be strictly positive
	public const MAX_VAT_RATE_BP = 5000; // 50% — covers every realistic VAT rate
	public const MIN_VAT_RATE_BP = 0;

	public function __construct(
		private ?CurrencyCatalog $currencyCatalog = null,
	) {
	}

	private function currencies(): CurrencyCatalog
	{
		return $this->currencyCatalog ?? new CurrencyCatalog();
	}

	/**
	 * Parse a free-form positive amount into minor units. Returns the integer
	 * value or throws on invalid input.
	 *
	 * Accepts:
	 *   "1234"        -> 123400
	 *   "1234.5"      -> 123450
	 *   "1234.56"     -> 123456
	 *   "1.234,56"    -> 123456
	 *   "1,234.56"    -> 123456
	 *   "1 234,56"    -> 123456 (NBSP and regular space tolerated)
	 *   " 12,3 "      -> 1230
	 *
	 * Rejects:
	 *   negative or signed strings (sign is in `direction`)
	 *   leading "€"/"$" symbols
	 *   non-numeric strings, multiple decimal points, scientific notation
	 *
	 * @throws \InvalidArgumentException on invalid input or out-of-range values.
	 */
	public function parseHumanAmount(mixed $value, int $decimals = 2): int
	{
		if (is_int($value)) {
			// Caller already supplied minor units — just validate the range.
			return $this->ensureInRange($value);
		}
		if (!is_string($value) && !is_float($value)) {
			throw new \InvalidArgumentException('Amount must be a string or number.');
		}
		$raw = trim((string)$value);
		if ($raw === '') {
			throw new \InvalidArgumentException('Amount is required.');
		}
		// Reject explicit signs; the caller specifies direction separately.
		if (str_starts_with($raw, '+') || str_starts_with($raw, '-')) {
			throw new \InvalidArgumentException('Amount must be positive. Use the direction field to express expense or income.');
		}
		// Strip thousands separators (regular space and non-breaking space).
		$cleaned = preg_replace('/[\s\x{00A0}]/u', '', $raw) ?? '';
		if ($cleaned === '') {
			throw new \InvalidArgumentException('Amount is required.');
		}
		// Detect decimal separator. If both `.` and `,` appear, the right-most one is the decimal.
		$lastDot = strrpos($cleaned, '.');
		$lastComma = strrpos($cleaned, ',');
		if ($lastDot !== false && $lastComma !== false) {
			$decimalChar = ($lastDot > $lastComma) ? '.' : ',';
			$thousandsChar = $decimalChar === '.' ? ',' : '.';
			$normalized = str_replace($thousandsChar, '', $cleaned);
			$normalized = str_replace($decimalChar, '.', $normalized);
		} elseif ($lastComma !== false) {
			// Only a comma present → it's the decimal separator (German style).
			$normalized = str_replace(',', '.', $cleaned);
		} else {
			$normalized = $cleaned; // only a dot or no separator at all
		}

		// Allow exactly one optional decimal block, no leading sign already filtered.
		if (!preg_match('/^[0-9]+(?:\.[0-9]+)?$/', $normalized)) {
			throw new \InvalidArgumentException('Amount is not a valid number.');
		}

		$parts = explode('.', $normalized, 2);
		$integerPart = $parts[0];
		$fractionPart = $parts[1] ?? '';
		if ($fractionPart !== '' && strlen($fractionPart) > $decimals + 6) {
			// Permissive but capped: tolerate extra trailing zeros while rejecting unbounded input.
			throw new \InvalidArgumentException('Amount has too many decimal digits.');
		}
		// Pad/truncate to the currency precision. Truncation is intentional —
		// callers typing "1.234" with a 2-decimal currency mean 123,40 cents,
		// and any extra digits past the precision should be cut, not rounded
		// (rounding belongs to the tax engine, not the parser).
		$fractionPart = str_pad(substr($fractionPart, 0, $decimals), $decimals, '0', STR_PAD_RIGHT);
		$digits = $integerPart . $fractionPart;
		$digits = ltrim($digits, '0');
		if ($digits === '') {
			$digits = '0';
		}
		if (!ctype_digit($digits)) {
			throw new \InvalidArgumentException('Amount is not a valid number.');
		}
		// PHP_INT_MAX safely holds our cap. Cast after we know we’re below the cap.
		if (strlen($digits) > 16) {
			throw new \InvalidArgumentException('Amount is too large.');
		}
		$minor = (int)$digits;
		return $this->ensureInRange($minor);
	}

	/** @throws \InvalidArgumentException */
	public function ensureInRange(int $minor): int
	{
		if ($minor < self::MIN_AMOUNT_MINOR) {
			throw new \InvalidArgumentException('Amount must be greater than zero.');
		}
		if ($minor > self::MAX_AMOUNT_MINOR) {
			throw new \InvalidArgumentException('Amount exceeds the maximum allowed value.');
		}
		return $minor;
	}

	public function decimalsFor(string $currencyCode): int
	{
		return $this->currencies()->decimalsFor($currencyCode);
	}

	public function isSupportedCurrency(string $currencyCode): bool
	{
		return $this->currencies()->isSupported($currencyCode);
	}

	/**
	 * @return list<string>
	 */
	public function supportedCurrencies(): array
	{
		return $this->currencies()->codes();
	}

	/**
	 * Machine-readable catalogue for UI selects (currency + minor digits).
	 *
	 * @return list<array{code:string, decimals:int}>
	 */
	public function supportedCurrencyOptions(): array
	{
		return $this->currencies()->options();
	}

	/**
	 * Format minor units as a numeric string suitable for the JSON `display` field.
	 * The locale-aware human formatting happens client-side via Intl.NumberFormat
	 * and server-side via {@see LocaleFormatService::formatMoney()}; this is the
	 * "machine-readable but already decimalised" representation.
	 */
	public function toDecimalString(int $minor, int $decimals = 2): string
	{
		$negative = $minor < 0;
		$abs = $negative ? -$minor : $minor;
		$digits = (string)$abs;
		if ($decimals === 0) {
			return $negative ? '-' . $digits : $digits;
		}
		if (strlen($digits) <= $decimals) {
			$digits = str_pad($digits, $decimals + 1, '0', STR_PAD_LEFT);
		}
		$intPart = substr($digits, 0, -$decimals);
		$fracPart = substr($digits, -$decimals);
		$out = $intPart . '.' . $fracPart;
		return $negative ? '-' . $out : $out;
	}

	/**
	 * Build the canonical money envelope used by every API response that exposes
	 * a money field. Keeping it consistent guarantees that frontend code can
	 * assume `{minor, currency, decimal, decimals}` everywhere.
	 *
	 * `decimals` is required for zero-decimal currencies (e.g. JPY): clients that
	 * fall back to 2 when the field is missing would scale minors by 100×.
	 *
	 * @return array{minor:int, currency:string, decimal:string, decimals:int}
	 */
	public function envelope(int $minor, string $currencyCode): array
	{
		$decimals = $this->decimalsFor($currencyCode);
		return [
			'minor' => $minor,
			'currency' => strtoupper($currencyCode),
			'decimal' => $this->toDecimalString($minor, $decimals),
			'decimals' => $decimals,
		];
	}

	/**
	 * Convert between net and gross deterministically and produce the third value.
	 *
	 * Inputs:
	 *  - `basis` = "simple": tax mode disabled; returns the original amount as-is
	 *    in all three fields, vat = 0. Caller must persist net/vat/gross as null
	 *    when the workspace is non-tax (see TransactionService).
	 *  - `basis` = "net": `amountMinor` is the net amount; vat = round(net * rate / 10000),
	 *    gross = net + vat.
	 *  - `basis` = "gross": `amountMinor` is the gross amount; net = round(gross * 10000 / (10000 + rate)),
	 *    vat = gross - net so the row always reconciles.
	 *
	 * Rounding: PHP_ROUND_HALF_EVEN (banker's rounding). We chose half-even because
	 * it does not bias totals for repeated rounding (relevant for monthly aggregates
	 * across many small line items). The choice is encoded once here so every
	 * caller — including imports and snapshots — gets the same arithmetic.
	 *
	 * @return array{net:int, vat:int, gross:int}
	 */
	public function convertTax(int $amountMinor, int $vatRateBp, string $basis): array
	{
		if ($amountMinor < 0) {
			throw new \InvalidArgumentException('Amount must be positive.');
		}
		if ($basis === 'simple') {
			return ['net' => $amountMinor, 'vat' => 0, 'gross' => $amountMinor];
		}
		if ($vatRateBp < self::MIN_VAT_RATE_BP || $vatRateBp > self::MAX_VAT_RATE_BP) {
			throw new \InvalidArgumentException('VAT rate is out of range.');
		}
		if ($basis === 'net') {
			$vat = (int)round(($amountMinor * $vatRateBp) / 10000, 0, PHP_ROUND_HALF_EVEN);
			$gross = $amountMinor + $vat;
			return ['net' => $amountMinor, 'vat' => $vat, 'gross' => $gross];
		}
		if ($basis === 'gross') {
			$net = (int)round(($amountMinor * 10000) / (10000 + $vatRateBp), 0, PHP_ROUND_HALF_EVEN);
			$vat = $amountMinor - $net;
			return ['net' => $net, 'vat' => $vat, 'gross' => $amountMinor];
		}
		throw new \InvalidArgumentException('Unknown tax basis. Use simple, net or gross.');
	}

	/**
	 * Produce a percent string for VAT rates expressed as basis points.
	 * Used for display strings and audit log entries.
	 */
	public function vatRatePercent(int $bp): string
	{
		$negative = $bp < 0;
		$abs = $negative ? -$bp : $bp;
		$intPart = intdiv($abs, 100);
		$decPart = $abs % 100;
		$str = $intPart . ($decPart === 0 ? '' : '.' . str_pad((string)$decPart, 2, '0', STR_PAD_LEFT));
		return ($negative ? '-' : '') . $str . '%';
	}
}
