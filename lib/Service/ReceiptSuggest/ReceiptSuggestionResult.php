<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service\ReceiptSuggest;

/**
 * Normalized suggestion returned to API clients after gates pass or abstain.
 *
 * @phpstan-type SuggestionArray array{
 *   status:string,
 *   quality:string,
 *   mode:?string,
 *   title:?string,
 *   merchant:?string,
 *   bookingDate:?string,
 *   currencyCode:?string,
 *   totalMinor:?int,
 *   direction:?string,
 *   lines:list<array{label:string,amountMinor:int,categoryId:int,confidence:float}>,
 *   warnings:list<string>,
 *   source:string,
 *   reasons:list<string>
 * }
 */
final class ReceiptSuggestionResult
{
	/**
	 * @param list<ReceiptSuggestionLine> $lines
	 * @param list<string> $warnings
	 * @param list<string> $reasons Machine-readable fail/abstain reasons (not shown raw to end users).
	 */
	public function __construct(
		public readonly string $status,
		public readonly string $quality,
		public readonly ?string $mode,
		public readonly ?string $title,
		public readonly ?string $merchant,
		public readonly ?string $bookingDate,
		public readonly ?string $currencyCode,
		public readonly ?int $totalMinor,
		public readonly ?string $direction,
		public readonly array $lines,
		public readonly array $warnings,
		public readonly string $source,
		public readonly array $reasons = [],
	) {
	}

	public function isReady(): bool
	{
		return $this->status === ReceiptSuggestConstants::STATUS_READY;
	}

	public static function failed(string $source, string ...$reasons): self
	{
		return new self(
			ReceiptSuggestConstants::STATUS_FAILED,
			ReceiptSuggestConstants::QUALITY_LOW,
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			[],
			[],
			$source,
			array_values($reasons),
		);
	}

	public static function lowQuality(string $source, string ...$reasons): self
	{
		return new self(
			ReceiptSuggestConstants::STATUS_LOW_QUALITY,
			ReceiptSuggestConstants::QUALITY_LOW,
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			[],
			[],
			$source,
			array_values($reasons),
		);
	}

	/** @return SuggestionArray */
	public function toArray(): array
	{
		return [
			'status' => $this->status,
			'quality' => $this->quality,
			'mode' => $this->mode,
			'title' => $this->title,
			'merchant' => $this->merchant,
			'bookingDate' => $this->bookingDate,
			'currencyCode' => $this->currencyCode,
			'totalMinor' => $this->totalMinor,
			'direction' => $this->direction,
			'lines' => array_map(
				static fn (ReceiptSuggestionLine $line): array => $line->toArray(),
				$this->lines,
			),
			'warnings' => $this->warnings,
			'source' => $this->source,
			'reasons' => $this->reasons,
		];
	}
}
