<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service\ReceiptSuggest;

/**
 * Pure orchestration: raw model text → parse → validate → quality gate.
 * Task Processing I/O stays outside this class (P1 adapter).
 */
final class ReceiptSuggestionPipeline
{
	public function __construct(
		private readonly ReceiptSuggestionParser $parser = new ReceiptSuggestionParser(),
		private readonly ReceiptSuggestionValidator $validator = new ReceiptSuggestionValidator(),
		private readonly ReceiptSuggestionQualityGate $qualityGate = new ReceiptSuggestionQualityGate(),
	) {
	}

	public function process(string $rawModelOutput, ReceiptSuggestContext $context): ReceiptSuggestionResult
	{
		$parsed = $this->parser->parse($rawModelOutput);
		if ($parsed === null) {
			return ReceiptSuggestionResult::failed($context->source, 'parse_failed');
		}

		$validated = $this->validator->validate($parsed, $context);
		if ($validated['ok'] !== true) {
			/** @var list<string> $reasons */
			$reasons = $validated['reasons'];
			return ReceiptSuggestionResult::lowQuality($context->source, ...$reasons);
		}

		/** @var array<string, mixed> $draft */
		$draft = $validated['draft'];
		return $this->qualityGate->evaluate($draft, $context->source);
	}
}
