<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

/**
 * Curated list of IANA timezones grouped for the Settings/Workspace UI.
 *
 * The full PHP-supplied list runs to ~430 entries. That is overwhelming for the
 * primary use case (a household or small project picking the timezone they
 * actually live in). This catalog surfaces ~50 frequently used zones and lets
 * the API still validate against the full {@see \DateTimeZone::listIdentifiers()}
 * list when an integration sends an exotic identifier.
 */
class TimezoneCatalog
{
	/** @var array<string, list<string>> */
	private const GROUPS = [
		'Europe' => [
			'Europe/Berlin', 'Europe/Vienna', 'Europe/Zurich', 'Europe/London',
			'Europe/Paris', 'Europe/Madrid', 'Europe/Rome', 'Europe/Amsterdam',
			'Europe/Brussels', 'Europe/Copenhagen', 'Europe/Dublin', 'Europe/Helsinki',
			'Europe/Lisbon', 'Europe/Luxembourg', 'Europe/Oslo', 'Europe/Prague',
			'Europe/Stockholm', 'Europe/Warsaw', 'Europe/Athens', 'Europe/Bucharest',
			'Europe/Budapest', 'Europe/Sofia',
		],
		'Americas' => [
			'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles',
			'America/Toronto', 'America/Vancouver', 'America/Mexico_City', 'America/Sao_Paulo',
			'America/Argentina/Buenos_Aires', 'America/Bogota', 'America/Halifax', 'America/Anchorage',
		],
		'Asia / Pacific' => [
			'Asia/Tokyo', 'Asia/Shanghai', 'Asia/Hong_Kong', 'Asia/Singapore',
			'Asia/Seoul', 'Asia/Kolkata', 'Asia/Dubai', 'Asia/Jerusalem',
			'Asia/Bangkok', 'Asia/Manila', 'Asia/Riyadh',
			'Australia/Sydney', 'Australia/Melbourne', 'Australia/Perth',
			'Pacific/Auckland', 'Pacific/Honolulu',
		],
		'Africa' => [
			'Africa/Cairo', 'Africa/Johannesburg', 'Africa/Lagos', 'Africa/Nairobi',
			'Africa/Casablanca', 'Africa/Algiers',
		],
		'Universal' => [
			'UTC',
		],
	];

	/**
	 * Return groups in the shape the frontend expects: an ordered list of
	 * `{label, items}` records. JSON-encoded this becomes an array of objects
	 * which the JS modules iterate without ambiguity (a PHP associative array
	 * would JSON-encode to `{}` and break `Array.prototype.forEach`).
	 *
	 * @return list<array{label:string, items:list<string>}>
	 */
	public function grouped(): array
	{
		$out = [];
		foreach (self::GROUPS as $label => $items) {
			$out[] = ['label' => $label, 'items' => array_values($items)];
		}
		return $out;
	}

	/**
	 * @return array<string, list<string>>
	 */
	public function groupedMap(): array
	{
		return self::GROUPS;
	}

	/**
	 * @return list<string>
	 */
	public function flat(): array
	{
		$out = [];
		foreach (self::GROUPS as $items) {
			foreach ($items as $tz) {
				$out[] = $tz;
			}
		}
		return $out;
	}

	public function isValid(string $timezone): bool
	{
		return in_array(trim($timezone), \DateTimeZone::listIdentifiers(), true);
	}
}
