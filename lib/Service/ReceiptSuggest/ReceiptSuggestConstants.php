<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service\ReceiptSuggest;

/**
 * Hard limits for receipt AI suggest. Tuned to abstain rather than wrong-book.
 *
 * @see planning/app-ideas/budgetcheck/RECEIPT-AI-SUGGEST.md
 */
final class ReceiptSuggestConstants
{
	public const TASK_ANALYZE_IMAGES = 'core:analyze-images';
	public const TASK_OCR = 'core:image2text:ocr';
	public const TASK_TEXT2TEXT = 'core:text2text';

	/** Minimum confidence for a single-line (whole-receipt) suggestion. */
	public const CONFIDENCE_SINGLE_MIN = 0.72;

	/** Minimum confidence per line when suggesting a split. */
	public const CONFIDENCE_SPLIT_LINE_MIN = 0.78;

	/** Minimum merchant/title confidence when present. */
	public const CONFIDENCE_MERCHANT_MIN = 0.65;

	/** Client poll TTL before falling back to manual entry. */
	public const CLIENT_POLL_TIMEOUT_SEC = 90;

	/** Booking dates older than this are cleared (user picks). */
	public const MAX_DATE_AGE_YEARS = 5;

	/** Booking dates more than this many days in the future are cleared. */
	public const MAX_DATE_FUTURE_DAYS = 1;

	/** Title / merchant / label length caps (DB-safe, UI-safe). */
	public const MAX_TITLE_LEN = 200;
	public const MAX_LABEL_LEN = 120;

	public const STATUS_READY = 'ready';
	public const STATUS_LOW_QUALITY = 'low_quality';
	public const STATUS_FAILED = 'failed';

	public const QUALITY_HIGH = 'high';
	public const QUALITY_MEDIUM = 'medium';
	public const QUALITY_LOW = 'low';

	public const MODE_SINGLE = 'single';
	public const MODE_SPLIT = 'split';

	public const DIRECTION_EXPENSE = 'expense';
	public const DIRECTION_INCOME = 'income';

	public const SOURCE_ANALYZE_IMAGES = 'analyze-images';
	public const SOURCE_OCR_TEXT = 'ocr+text';
}
