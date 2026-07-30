<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service\ReceiptSuggest;

/**
 * Confidence / mode quality gate. Prefer abstain over wrong money.
 */
final class ReceiptSuggestionQualityGate
{
	/**
	 * @param array{
	 *   title:?string,
	 *   merchant:?string,
	 *   merchantConfidence:?float,
	 *   bookingDate:?string,
	 *   currencyCode:string,
	 *   totalMinor:int,
	 *   direction:string,
	 *   lines:list<array{label:string,amountMinor:int,categoryId:int,confidence:float}>,
	 *   warnings:list<string>
	 * } $draft
	 */
	public function evaluate(array $draft, string $source): ReceiptSuggestionResult
	{
		$lines = $draft['lines'];
		$count = count($lines);
		$warnings = $draft['warnings'];

		$merchantConfidence = $draft['merchantConfidence'];
		if ($merchantConfidence !== null && $merchantConfidence < ReceiptSuggestConstants::CONFIDENCE_MERCHANT_MIN) {
			return ReceiptSuggestionResult::lowQuality($source, 'merchant_confidence');
		}

		if ($count === 1) {
			$line = $lines[0];
			if ($line['confidence'] < ReceiptSuggestConstants::CONFIDENCE_SINGLE_MIN) {
				return ReceiptSuggestionResult::lowQuality($source, 'single_confidence');
			}
			$quality = $line['confidence'] >= 0.88
				? ReceiptSuggestConstants::QUALITY_HIGH
				: ReceiptSuggestConstants::QUALITY_MEDIUM;

			return $this->ready(
				ReceiptSuggestConstants::MODE_SINGLE,
				$quality,
				$draft,
				[new ReceiptSuggestionLine($line['label'], $line['amountMinor'], $line['categoryId'], $line['confidence'])],
				$warnings,
				$source,
			);
		}

		// Split path: every line must clear the higher bar, and sums already match.
		foreach ($lines as $i => $line) {
			if ($line['confidence'] < ReceiptSuggestConstants::CONFIDENCE_SPLIT_LINE_MIN) {
				// Collapse to best single line if that line alone would pass single gate.
				return $this->tryCollapseToBestSingle($draft, $source, 'split_line_confidence:' . $i);
			}
		}

		$lineObjects = [];
		$minConf = 1.0;
		foreach ($lines as $line) {
			$lineObjects[] = new ReceiptSuggestionLine(
				$line['label'],
				$line['amountMinor'],
				$line['categoryId'],
				$line['confidence'],
			);
			$minConf = min($minConf, $line['confidence']);
		}
		$quality = $minConf >= 0.90
			? ReceiptSuggestConstants::QUALITY_HIGH
			: ReceiptSuggestConstants::QUALITY_MEDIUM;

		return $this->ready(
			ReceiptSuggestConstants::MODE_SPLIT,
			$quality,
			$draft,
			$lineObjects,
			$warnings,
			$source,
		);
	}

	/**
	 * @param array{
	 *   title:?string,
	 *   merchant:?string,
	 *   merchantConfidence:?float,
	 *   bookingDate:?string,
	 *   currencyCode:string,
	 *   totalMinor:int,
	 *   direction:string,
	 *   lines:list<array{label:string,amountMinor:int,categoryId:int,confidence:float}>,
	 *   warnings:list<string>
	 * } $draft
	 */
	private function tryCollapseToBestSingle(array $draft, string $source, string $reason): ReceiptSuggestionResult
	{
		$best = null;
		foreach ($draft['lines'] as $line) {
			if ($best === null || $line['confidence'] > $best['confidence']) {
				$best = $line;
			}
		}
		if ($best === null || $best['confidence'] < ReceiptSuggestConstants::CONFIDENCE_SINGLE_MIN) {
			return ReceiptSuggestionResult::lowQuality($source, $reason, 'collapse_failed');
		}

		// Collapsing changes the booked amount to the dominant line — only safe if it equals total.
		if ($best['amountMinor'] !== $draft['totalMinor']) {
			// Use total with best category if the best line is not the full total — still one booking.
			$collapsed = [
				'label' => $best['label'],
				'amountMinor' => $draft['totalMinor'],
				'categoryId' => $best['categoryId'],
				'confidence' => $best['confidence'],
			];
			$warnings = $draft['warnings'];
			$warnings[] = 'split_collapsed_to_single';
			$quality = $collapsed['confidence'] >= 0.88
				? ReceiptSuggestConstants::QUALITY_HIGH
				: ReceiptSuggestConstants::QUALITY_MEDIUM;
			return $this->ready(
				ReceiptSuggestConstants::MODE_SINGLE,
				$quality,
				$draft,
				[new ReceiptSuggestionLine(
					$collapsed['label'],
					$collapsed['amountMinor'],
					$collapsed['categoryId'],
					$collapsed['confidence'],
				)],
				$warnings,
				$source,
				[$reason],
			);
		}

		$warnings = $draft['warnings'];
		$warnings[] = 'split_collapsed_to_single';
		$quality = $best['confidence'] >= 0.88
			? ReceiptSuggestConstants::QUALITY_HIGH
			: ReceiptSuggestConstants::QUALITY_MEDIUM;
		return $this->ready(
			ReceiptSuggestConstants::MODE_SINGLE,
			$quality,
			$draft,
			[new ReceiptSuggestionLine($best['label'], $best['amountMinor'], $best['categoryId'], $best['confidence'])],
			$warnings,
			$source,
			[$reason],
		);
	}

	/**
	 * @param list<ReceiptSuggestionLine> $lines
	 * @param list<string> $warnings
	 * @param list<string> $reasons
	 * @param array{
	 *   title:?string,
	 *   merchant:?string,
	 *   bookingDate:?string,
	 *   currencyCode:string,
	 *   totalMinor:int,
	 *   direction:string
	 * } $draft
	 */
	private function ready(
		string $mode,
		string $quality,
		array $draft,
		array $lines,
		array $warnings,
		string $source,
		array $reasons = [],
	): ReceiptSuggestionResult {
		return new ReceiptSuggestionResult(
			ReceiptSuggestConstants::STATUS_READY,
			$quality,
			$mode,
			$draft['title'],
			$draft['merchant'],
			$draft['bookingDate'],
			$draft['currencyCode'],
			$draft['totalMinor'],
			$draft['direction'],
			$lines,
			$warnings,
			$source,
			$reasons,
		);
	}
}
