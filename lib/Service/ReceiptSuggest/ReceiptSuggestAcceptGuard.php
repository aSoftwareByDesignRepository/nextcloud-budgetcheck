<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service\ReceiptSuggest;

/**
 * Re-validates a client-accepted suggestion at write time (categories may have changed).
 * Does not create transactions — callers use TransactionService after this passes.
 */
final class ReceiptSuggestAcceptGuard
{
	/**
	 * @param array{
	 *   mode?:string,
	 *   title?:?string,
	 *   bookingDate?:?string,
	 *   currencyCode?:?string,
	 *   totalMinor?:int,
	 *   direction?:string,
	 *   lines?:list<array{label?:string,amountMinor?:int,categoryId?:int,confidence?:float}>
	 * } $accepted
	 * @return array{ok:true}|array{ok:false,reasons:list<string>}
	 */
	public function assertAcceptable(array $accepted, ReceiptSuggestContext $context): array
	{
		$pipeline = new ReceiptSuggestionPipeline();
		// Re-run through the same gates so accept cannot bypass quality rules.
		$raw = json_encode($accepted, JSON_THROW_ON_ERROR);
		$result = $pipeline->process($raw, $context);
		if (!$result->isReady()) {
			return ['ok' => false, 'reasons' => $result->reasons !== [] ? $result->reasons : ['not_ready']];
		}

		$mode = $accepted['mode'] ?? null;
		if (is_string($mode) && $mode !== $result->mode) {
			// Client claimed split but gates collapsed — still OK if ready single.
			if ($mode === ReceiptSuggestConstants::MODE_SPLIT && $result->mode === ReceiptSuggestConstants::MODE_SINGLE) {
				return ['ok' => true];
			}
			return ['ok' => false, 'reasons' => ['mode_mismatch']];
		}

		return ['ok' => true];
	}
}
