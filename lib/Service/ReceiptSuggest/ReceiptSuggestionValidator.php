<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service\ReceiptSuggest;

use OCA\BudgetCheck\Service\MoneyService;

/**
 * Structural + allow-list validation of a parsed suggestion object.
 *
 * Never invents category IDs. Money must be positive minor units.
 * Date / currency mismatches become warnings or cleared fields — not silent wrong books.
 */
final class ReceiptSuggestionValidator
{
	public function __construct(
		private readonly MoneyService $money = new MoneyService(),
	) {
	}

	/**
	 * @param array<string, mixed> $parsed
	 * @return array{ok:true,draft:array<string,mixed>}|array{ok:false,reasons:list<string>}
	 */
	public function validate(array $parsed, ReceiptSuggestContext $context): array
	{
		$reasons = [];
		$allowed = $context->allowedCategorySet();
		if ($allowed === []) {
			return ['ok' => false, 'reasons' => ['no_categories']];
		}

		$direction = $this->normalizeDirection($parsed['direction'] ?? null);
		if ($direction === null) {
			$direction = ReceiptSuggestConstants::DIRECTION_EXPENSE;
		}

		$title = $this->optionalString($parsed['title'] ?? null, ReceiptSuggestConstants::MAX_TITLE_LEN);
		$merchant = $this->optionalString($parsed['merchant'] ?? null, ReceiptSuggestConstants::MAX_TITLE_LEN);
		if ($title === null && $merchant !== null) {
			$title = $merchant;
		}

		$merchantConfidence = $this->optionalConfidence($parsed['merchantConfidence'] ?? $parsed['merchant_confidence'] ?? null);

		$currency = $this->normalizeCurrency($parsed['currencyCode'] ?? $parsed['currency'] ?? null);
		$warnings = [];
		if ($currency !== null && strtoupper($currency) !== strtoupper($context->workspaceCurrencyCode)) {
			return ['ok' => false, 'reasons' => ['currency_mismatch']];
		}
		if ($currency === null) {
			$currency = strtoupper($context->workspaceCurrencyCode);
		} else {
			$currency = strtoupper($currency);
		}

		$bookingDate = $this->normalizeBookingDate($parsed['bookingDate'] ?? $parsed['date'] ?? null, $context, $warnings);

		$linesRaw = $parsed['lines'] ?? null;
		if (!is_array($linesRaw) || $linesRaw === []) {
			return ['ok' => false, 'reasons' => ['no_lines']];
		}

		$lines = [];
		foreach ($linesRaw as $index => $line) {
			if (!is_array($line)) {
				$reasons[] = 'line_not_object:' . $index;
				continue;
			}
			$built = $this->buildLine($line, $allowed);
			if ($built === null) {
				$reasons[] = 'line_invalid:' . $index;
				continue;
			}
			$lines[] = $built;
		}

		if ($lines === []) {
			$reasons[] = 'no_valid_lines';
			return ['ok' => false, 'reasons' => $reasons];
		}

		$totalMinor = $this->resolveTotalMinor($parsed, $lines, $reasons);
		if ($totalMinor === null) {
			return ['ok' => false, 'reasons' => $reasons === [] ? ['bad_total'] : $reasons];
		}

		$sumLines = 0;
		foreach ($lines as $line) {
			$sumLines += $line['amountMinor'];
		}
		if ($sumLines !== $totalMinor) {
			// Prefer collapsing to a single dominant line when totals disagree.
			if (count($lines) === 1) {
				$totalMinor = $lines[0]['amountMinor'];
				$warnings[] = 'total_aligned_to_line';
			} else {
				return ['ok' => false, 'reasons' => ['total_line_sum_mismatch']];
			}
		}

		try {
			$this->money->ensureInRange($totalMinor);
			foreach ($lines as $line) {
				$this->money->ensureInRange($line['amountMinor']);
			}
		} catch (\InvalidArgumentException) {
			return ['ok' => false, 'reasons' => ['amount_out_of_range']];
		}

		return [
			'ok' => true,
			'draft' => [
				'title' => $title,
				'merchant' => $merchant,
				'merchantConfidence' => $merchantConfidence,
				'bookingDate' => $bookingDate,
				'currencyCode' => $currency,
				'totalMinor' => $totalMinor,
				'direction' => $direction,
				'lines' => $lines,
				'warnings' => $warnings,
			],
		];
	}

	/**
	 * @param array<int, true> $allowed
	 * @param array<string, mixed> $line
	 * @return array{label:string,amountMinor:int,categoryId:int,confidence:float}|null
	 */
	private function buildLine(array $line, array $allowed): ?array
	{
		$categoryId = $line['categoryId'] ?? $line['category_id'] ?? null;
		if (!is_int($categoryId) && !(is_string($categoryId) && ctype_digit($categoryId))) {
			return null;
		}
		$categoryId = (int)$categoryId;
		if ($categoryId <= 0 || !isset($allowed[$categoryId])) {
			return null;
		}

		$amount = $line['amountMinor'] ?? $line['amount_minor'] ?? null;
		if (!is_int($amount) && !(is_string($amount) && preg_match('/^-?\d+$/', $amount) === 1)) {
			// Accept decimal major only if explicitly "amount" float/string — convert via MoneyService? Prefer fail.
			return null;
		}
		$amountMinor = (int)$amount;
		if ($amountMinor < MoneyService::MIN_AMOUNT_MINOR) {
			return null;
		}

		$confidence = $this->optionalConfidence($line['confidence'] ?? null);
		if ($confidence === null) {
			return null;
		}

		$label = $this->optionalString($line['label'] ?? $line['title'] ?? null, ReceiptSuggestConstants::MAX_LABEL_LEN)
			?? 'Item';

		return [
			'label' => $label,
			'amountMinor' => $amountMinor,
			'categoryId' => $categoryId,
			'confidence' => $confidence,
		];
	}

	/**
	 * @param array<string, mixed> $parsed
	 * @param list<array{amountMinor:int}> $lines
	 * @param list<string> $reasons
	 */
	private function resolveTotalMinor(array $parsed, array $lines, array &$reasons): ?int
	{
		$raw = $parsed['totalMinor'] ?? $parsed['total_minor'] ?? null;
		if ($raw === null) {
			$sum = 0;
			foreach ($lines as $line) {
				$sum += $line['amountMinor'];
			}
			return $sum;
		}
		if (!is_int($raw) && !(is_string($raw) && preg_match('/^-?\d+$/', $raw) === 1)) {
			$reasons[] = 'total_not_integer';
			return null;
		}
		$total = (int)$raw;
		if ($total < MoneyService::MIN_AMOUNT_MINOR) {
			$reasons[] = 'total_non_positive';
			return null;
		}
		return $total;
	}

	private function normalizeDirection(mixed $value): ?string
	{
		if (!is_string($value)) {
			return null;
		}
		$v = strtolower(trim($value));
		if ($v === ReceiptSuggestConstants::DIRECTION_EXPENSE || $v === ReceiptSuggestConstants::DIRECTION_INCOME) {
			return $v;
		}
		return null;
	}

	private function normalizeCurrency(mixed $value): ?string
	{
		if (!is_string($value)) {
			return null;
		}
		$code = strtoupper(trim($value));
		if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
			return null;
		}
		return $code;
	}

	/**
	 * @param list<string> $warnings
	 */
	private function normalizeBookingDate(mixed $value, ReceiptSuggestContext $context, array &$warnings): ?string
	{
		if (!is_string($value)) {
			return null;
		}
		$raw = trim($value);
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m) !== 1) {
			$warnings[] = 'date_unparseable';
			return null;
		}
		$y = (int)$m[1];
		$mo = (int)$m[2];
		$d = (int)$m[3];
		if (!checkdate($mo, $d, $y)) {
			$warnings[] = 'date_invalid_calendar';
			return null;
		}

		$date = $context->today->setDate($y, $mo, $d)->setTime(0, 0, 0);
		$today = $context->today->setTime(0, 0, 0);
		$maxFuture = $today->modify('+' . ReceiptSuggestConstants::MAX_DATE_FUTURE_DAYS . ' days');
		$minPast = $today->modify('-' . ReceiptSuggestConstants::MAX_DATE_AGE_YEARS . ' years');

		if ($date > $maxFuture) {
			$warnings[] = 'date_too_future';
			return null;
		}
		if ($date < $minPast) {
			$warnings[] = 'date_too_old';
			return null;
		}

		return sprintf('%04d-%02d-%02d', $y, $mo, $d);
	}

	private function optionalString(mixed $value, int $maxLen): ?string
	{
		if (!is_string($value)) {
			return null;
		}
		$s = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
		if ($s === '') {
			return null;
		}
		if (mb_strlen($s) > $maxLen) {
			$s = mb_substr($s, 0, $maxLen);
		}
		return $s;
	}

	private function optionalConfidence(mixed $value): ?float
	{
		if (is_int($value) || is_float($value)) {
			$c = (float)$value;
		} elseif (is_string($value) && is_numeric($value)) {
			$c = (float)$value;
		} else {
			return null;
		}
		if ($c < 0.0 || $c > 1.0 || !is_finite($c)) {
			return null;
		}
		return $c;
	}
}
