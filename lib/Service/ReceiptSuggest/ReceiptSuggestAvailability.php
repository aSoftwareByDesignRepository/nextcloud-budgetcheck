<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service\ReceiptSuggest;

/**
 * Detects which receipt-suggest pipelines the instance can run for a user.
 * Pure: callers pass available Task Processing type IDs (from IManager).
 */
final class ReceiptSuggestAvailability
{
	/**
	 * @param list<string> $availableTaskTypeIds
	 * @return list<string> Mode identifiers: analyze-images, ocr+text
	 */
	public function detectModes(array $availableTaskTypeIds): array
	{
		$set = [];
		foreach ($availableTaskTypeIds as $id) {
			if (is_string($id) && $id !== '') {
				$set[$id] = true;
			}
		}

		$modes = [];
		if (isset($set[ReceiptSuggestConstants::TASK_ANALYZE_IMAGES])) {
			$modes[] = ReceiptSuggestConstants::SOURCE_ANALYZE_IMAGES;
		}
		if (
			isset($set[ReceiptSuggestConstants::TASK_OCR])
			&& isset($set[ReceiptSuggestConstants::TASK_TEXT2TEXT])
		) {
			$modes[] = ReceiptSuggestConstants::SOURCE_OCR_TEXT;
		}

		return $modes;
	}

	/**
	 * @param list<string> $availableTaskTypeIds
	 */
	public function isAvailable(array $availableTaskTypeIds): bool
	{
		return $this->detectModes($availableTaskTypeIds) !== [];
	}

	/**
	 * Preferred pipeline order when multiple modes exist.
	 *
	 * @param list<string> $modes
	 */
	public function preferredSource(array $modes): ?string
	{
		if (in_array(ReceiptSuggestConstants::SOURCE_ANALYZE_IMAGES, $modes, true)) {
			return ReceiptSuggestConstants::SOURCE_ANALYZE_IMAGES;
		}
		if (in_array(ReceiptSuggestConstants::SOURCE_OCR_TEXT, $modes, true)) {
			return ReceiptSuggestConstants::SOURCE_OCR_TEXT;
		}
		return null;
	}
}
