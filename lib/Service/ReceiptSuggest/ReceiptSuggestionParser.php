<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service\ReceiptSuggest;

/**
 * Extracts a strict suggestion object from untrusted model / OCR+LLM text.
 *
 * Fail-closed: prose, truncated JSON, or injection-shaped payloads yield null.
 */
final class ReceiptSuggestionParser
{
	/**
	 * @return array<string, mixed>|null Decoded object or null if unusable.
	 */
	public function parse(string $raw): ?array
	{
		$trimmed = trim($raw);
		if ($trimmed === '') {
			return null;
		}

		$candidate = $this->stripFences($trimmed);
		$json = $this->extractFirstObject($candidate);
		if ($json === null) {
			return null;
		}

		try {
			$decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}

		if (!is_array($decoded) || $this->isList($decoded)) {
			return null;
		}

		return $decoded;
	}

	private function stripFences(string $text): string
	{
		// ```json ... ``` or ``` ... ```
		if (preg_match('/^```(?:json)?\s*\n?(.*?)\n?```$/is', $text, $m) === 1) {
			return trim($m[1]);
		}
		if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/is', $text, $m) === 1) {
			return trim($m[1]);
		}
		return $text;
	}

	private function extractFirstObject(string $text): ?string
	{
		$start = strpos($text, '{');
		if ($start === false) {
			return null;
		}

		$depth = 0;
		$inString = false;
		$escape = false;
		$len = strlen($text);

		for ($i = $start; $i < $len; $i++) {
			$ch = $text[$i];
			if ($inString) {
				if ($escape) {
					$escape = false;
					continue;
				}
				if ($ch === '\\') {
					$escape = true;
					continue;
				}
				if ($ch === '"') {
					$inString = false;
				}
				continue;
			}
			if ($ch === '"') {
				$inString = true;
				continue;
			}
			if ($ch === '{') {
				$depth++;
				continue;
			}
			if ($ch === '}') {
				$depth--;
				if ($depth === 0) {
					return substr($text, $start, $i - $start + 1);
				}
			}
		}

		return null;
	}

	/** @param array<mixed> $value */
	private function isList(array $value): bool
	{
		if ($value === []) {
			return true;
		}
		return array_keys($value) === range(0, count($value) - 1);
	}
}
