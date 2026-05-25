<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

/**
 * Single source of truth for supported ISO 4217 workspace currencies.
 *
 * {@see MoneyService} delegates validation and minor-unit decimals here so API,
 * workspace persistence, and UI capabilities cannot drift apart.
 */
class CurrencyCatalog
{
	/**
	 * ISO 4217 codes BudgetCheck supports, mapped to minor-unit decimal places.
	 *
	 * @var array<string, int>
	 */
	private const DECIMALS = [
		'EUR' => 2,
		'USD' => 2,
		'GBP' => 2,
		'CHF' => 2,
		'PLN' => 2,
		'CZK' => 2,
		'SEK' => 2,
		'NOK' => 2,
		'DKK' => 2,
		'JPY' => 0,
		'AUD' => 2,
		'CAD' => 2,
		'NZD' => 2,
		'HUF' => 2,
		'RON' => 2,
		'BGN' => 2,
		'TRY' => 2,
		'RUB' => 2,
		'UAH' => 2,
		'KZT' => 2,
		'INR' => 2,
		'CNY' => 2,
		'BRL' => 2,
		'MXN' => 2,
		'ILS' => 2,
		'ZAR' => 2,
	];

	/** @var list<string> */
	private const PINNED = [
		'EUR',
		'USD',
		'GBP',
		'CHF',
		'PLN',
		'RUB',
		'UAH',
		'TRY',
		'KZT',
		'JPY',
	];

	/**
	 * UI grouping labels → codes. Every key in {@see DECIMALS} must appear exactly once.
	 *
	 * @var array<string, list<string>>
	 */
	private const GROUPS = [
		'Major' => ['EUR', 'USD', 'GBP', 'CHF'],
		'Europe' => ['PLN', 'CZK', 'SEK', 'NOK', 'DKK', 'HUF', 'RON', 'BGN', 'TRY', 'RUB', 'UAH'],
		'Americas' => ['CAD', 'MXN', 'BRL'],
		'Asia & Pacific' => ['JPY', 'CNY', 'INR', 'AUD', 'NZD', 'KZT', 'ILS'],
		'Africa' => ['ZAR'],
	];

	/** @var list<array{code:string, decimals:int}>|null */
	private ?array $optionsCache = null;

	/** @var list<array{label:string, items:list<array{code:string, decimals:int}>}>|null */
	private ?array $groupedCache = null;

	public function decimalsFor(string $currencyCode): int
	{
		$code = strtoupper(trim($currencyCode));
		return self::DECIMALS[$code] ?? 2;
	}

	public function isSupported(string $currencyCode): bool
	{
		return isset(self::DECIMALS[strtoupper(trim($currencyCode))]);
	}

	/**
	 * @return list<string>
	 */
	public function codes(): array
	{
		return array_keys(self::DECIMALS);
	}

	/**
	 * @return list<array{code:string, decimals:int}>
	 */
	public function options(): array
	{
		if ($this->optionsCache !== null) {
			return $this->optionsCache;
		}
		$out = [];
		foreach (self::DECIMALS as $code => $decimals) {
			$out[] = ['code' => $code, 'decimals' => $decimals];
		}
		usort($out, static fn (array $a, array $b): int => strcmp($a['code'], $b['code']));
		$this->optionsCache = $out;
		return $out;
	}

	/**
	 * @return list<string>
	 */
	public function pinned(): array
	{
		$out = [];
		foreach (self::PINNED as $code) {
			if ($this->isSupported($code)) {
				$out[] = $code;
			}
		}
		return $out;
	}

	/**
	 * @return list<array{label:string, items:list<array{code:string, decimals:int}>}>
	 */
	public function grouped(): array
	{
		if ($this->groupedCache !== null) {
			return $this->groupedCache;
		}
		$assigned = [];
		$out = [];
		foreach (self::GROUPS as $label => $codes) {
			$items = [];
			foreach ($codes as $code) {
				if (!isset(self::DECIMALS[$code])) {
					continue;
				}
				$assigned[$code] = true;
				$items[] = ['code' => $code, 'decimals' => self::DECIMALS[$code]];
			}
			if ($items !== []) {
				$out[] = ['label' => $label, 'items' => $items];
			}
		}
		$remaining = [];
		foreach (self::DECIMALS as $code => $decimals) {
			if (!isset($assigned[$code])) {
				$remaining[] = ['code' => $code, 'decimals' => $decimals];
			}
		}
		if ($remaining !== []) {
			usort($remaining, static fn (array $a, array $b): int => strcmp($a['code'], $b['code']));
			$out[] = ['label' => 'Other', 'items' => $remaining];
		}
		$this->groupedCache = $out;
		return $out;
	}

	/**
	 * @return array{pinned:list<string>, groups:list<array{label:string, items:list<array{code:string, decimals:int}>}>}
	 */
	public function forApi(): array
	{
		return [
			'pinned' => $this->pinned(),
			'groups' => $this->grouped(),
		];
	}

	public function normalizeOrThrow(string $currencyCode): string
	{
		$code = strtoupper(trim($currencyCode));
		if (!preg_match('/^[A-Z]{3}$/', $code)) {
			throw new \InvalidArgumentException('Currency must be a 3-letter ISO 4217 code.');
		}
		if (!$this->isSupported($code)) {
			throw new \InvalidArgumentException('Currency is not supported. Pick a value from the workspace currency list.');
		}
		return $code;
	}
}
