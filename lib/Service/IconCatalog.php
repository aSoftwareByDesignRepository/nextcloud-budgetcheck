<?php

declare(strict_types=1);

namespace OCA\BudgetCheck\Service;

/**
 * Inline SVG icon catalogue for BudgetCheck templates.
 *
 * Same conventions as DienstplanCheck: lucide-style 24x24 strokes that inherit
 * `currentColor` so they recolour with the surrounding text in any theme.
 *
 * Returned strings are safe to embed directly in templates because they contain
 * no user data and always include `aria-hidden="true"` and `focusable="false"`.
 */
final class IconCatalog
{
	private const ICONS = [
		// Navigation
		'layout-grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
		'wallet' => '<path d="M19 7H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2Z"/><path d="M3 9V7a2 2 0 0 1 2-2h12"/><path d="M16 14h4"/>',
		'arrow-left-right' => '<path d="M8 3 4 7l4 4"/><path d="M4 7h16"/><path d="m16 21 4-4-4-4"/><path d="M20 17H4"/>',
		'piggy-bank' => '<path d="M19 5c-1.5 0-2.8 1.4-3 2-3.5-1.5-7.5-1-9.5 1-1 .8-2 2.5-2 4 0 1.7 1 4 4 5l1 3h3v-2h2v2h3l1-3c1-.5 2-1.5 2-3h2v-4h-2c0-1.5-.5-2-1-3l1-2h-1.5Z"/><path d="M14 8h-2"/>',
		'target' => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
		'calendar-days' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01M16 18h.01"/>',
		'calendar-range' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="M8 14h8M8 18h5"/>',
		'calendar-clock' => '<path d="M21 7.5V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h7"/><path d="M16 2v4M8 2v4M3 10h18"/><circle cx="17" cy="17" r="5"/><path d="M17 14.5V17l1.5 1.5"/>',
		'settings' => '<path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 0 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 0 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 0 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 0 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 0 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3h0a1.7 1.7 0 0 0 1-1.5V3a2 2 0 0 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 0 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8v0a1.7 1.7 0 0 0 1.5 1H21a2 2 0 0 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/>',
		'shield-check' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/>',
		// Inline / actions
		'plus' => '<path d="M12 5v14M5 12h14"/>',
		'plus-circle' => '<circle cx="12" cy="12" r="10"/><path d="M12 8v8M8 12h8"/>',
		'check' => '<path d="m5 12 5 5L20 7"/>',
		'check-circle' => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
		'x' => '<path d="M18 6 6 18M6 6l12 12"/>',
		'edit' => '<path d="M11 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-5"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4Z"/>',
		'trash' => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
		'rotate' => '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/>',
		'arrow-right' => '<path d="M5 12h14M13 5l7 7-7 7"/>',
		'arrow-up' => '<path d="M12 19V5M5 12l7-7 7 7"/>',
		'arrow-down' => '<path d="M12 5v14M19 12l-7 7-7-7"/>',
		'star' => '<path d="m12 2 3.09 6.26L22 9.27l-5 4.87L18.18 22 12 18.27 5.82 22 7 14.14 2 9.27l6.91-1.01L12 2Z"/>',
		'lock' => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
		'unlock' => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0"/>',
		'alert-triangle' => '<path d="m12 3 10 17H2Z"/><path d="M12 9v4M12 17h.01"/>',
		'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
		'help' => '<circle cx="12" cy="12" r="10"/><path d="M9.5 9a2.5 2.5 0 1 1 4.5 1.5c-.7.6-1.5.9-1.5 1.5v.5"/><path d="M12 17h.01"/>',
		'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
		'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
		'user-plus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>',
		'home' => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V9.5"/>',
		'briefcase' => '<rect x="3" y="7" width="18" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/><path d="M3 13h18"/>',
		'list' => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
		'tag' => '<path d="M20.6 13.4 13 21l-9-9V4h8l8 8a2.8 2.8 0 0 1 .6 1.4Z"/><circle cx="7" cy="7" r="1"/>',
		'search' => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
		'menu' => '<path d="M3 6h18M3 12h18M3 18h18"/>',
		'send' => '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
		'sparkles' => '<path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6 7.7 7.7M16.3 16.3l2.1 2.1M5.6 18.4 7.7 16.3M16.3 7.7l2.1-2.1"/>',
		'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5"/><path d="M12 3v12"/>',
		'chevron-left' => '<path d="m15 18-6-6 6-6"/>',
		'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
		'zoom-in' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/><path d="M11 8v6"/><path d="M8 11h6"/>',
		'zoom-out' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/><path d="M8 11h6"/>',
		'rotate-ccw' => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>',
		'rotate-cw' => '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/>',
		'crop' => '<path d="M6 2v14a2 2 0 0 0 2 2h14"/><path d="M18 22V8a2 2 0 0 0-2-2H2"/>',
		'refresh-cw' => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/>',
		'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
	];

	public static function render(string $name, ?string $extraClass = null): string
	{
		$inner = self::ICONS[$name] ?? null;
		if ($inner === null) {
			return '';
		}
		$class = 'bc-icon' . ($extraClass !== null ? ' ' . htmlspecialchars($extraClass, ENT_QUOTES, 'UTF-8') : '');
		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="%s" aria-hidden="true" focusable="false">%s</svg>',
			$class,
			$inner
		);
	}
}
