#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Repair `_singular_::_plural_` catalog arrays to the arity the runtime can
 * actually select (defect class l10n_plural_array_nplurals_mismatch).
 *
 * `translatePlural` indexes `translation[pluralFunction(n)]` with no bounds
 * check; the nplurals authority is @nextcloud/l10n `getPlural` in the shipped
 * NC core bundle — the gettext `pluralForm` argument is ignored, so
 *   - pl (and other 3-form locales) need 3 elements; a 2-element array hands
 *     `undefined` to `_build` for counts >= 5 → TypeError mid-render,
 *   - tr/zh_* resolve to a single form, so every 2-element array leaves index
 *     0 — often a literal "1 …" singular — selected for EVERY count
 *     (dishonest counter),
 *   - every form reachable for n != 1 must carry `%n` itself; `{count}` /
 *     `{created}` named placeholders render only via the vars map and do not
 *     satisfy the per-form contract.
 *
 * The count-var name is derived per msgid: the named placeholder present in
 * the plural half of the `_::_` key but absent from the singular half
 * ({count} / {created}); all other named placeholders ({skipped}, {rules},
 * {error}, …) are left untouched.
 *
 * Polish 2-element arrays get a paucal (2–4) element spliced at index 1 from
 * the explicit map below — the translations mirror the already-audited
 * shapes shipped by sibling apps (ticketcheck/kanzleicheck/audiocheck/
 * inventorycheck/whistlecheck transfer-report keys).
 *
 * Run afterwards:
 *   php scripts/sync-l10n-variants.php
 *   php scripts/regenerate-l10n-js.php
 */

$base = __DIR__ . '/../l10n';

/** Runtime nplurals per shipped base catalog (@nextcloud/l10n getPlural). */
const NPLURALS = [
	'en' => 2, 'de' => 2, 'fr' => 2, 'es' => 2, 'da' => 2, 'nl' => 2,
	'it' => 2, 'sv' => 2, 'nb' => 2, 'pt' => 2, 'pt_BR' => 2,
	'pl' => 3,
	'tr' => 1, 'zh_CN' => 1, 'ru' => 3, 'zh_TW' => 1, 'ru' => 3,
];

/** Polish paucal (index-1) elements for keys whose arrays shipped 2 forms. */
const PL_PAUCAL = [
	'_(1 env-bound value emptied)_::_(%n env-bound values emptied)_' => '(%n opróżnione wartości związane z instancją)',
	'_(1 filtered)_::_(%n filtered)_' => '(%n odfiltrowane)',
	'_(1 secret redacted)_::_(%n secrets redacted)_' => '(%n ukryte sekrety)',
	'_(1 unresolved ref)_::_(%n unresolved refs)_' => '(%n nierozwiązane odwołania)',
	'_1 reference unresolved — see the groups below._::_%n references unresolved — see the groups below._' => 'Nierozwiązano %n odwołania — zobacz grupy poniżej.',
	'_1 row needs a value on this instance_::_%n rows need a value on this instance_' => '%n wiersze wymagają wartości na tej instancji',
	'_1 row skipped._::_%n rows skipped._' => 'Pominięto %n wiersze.',
	'_Warning: 1 row will be permanently deleted, then the file data is loaded._::_Warning: %n rows will be permanently deleted, then the file data is loaded._' => 'Ostrzeżenie: %n wiersze zostaną trwale usunięte, a następnie dane z pliku zostaną wczytane.',
];

/** @return list<string> named placeholders in $s ("{name}" → "name") */
function bcNamed(string $s): array {
	preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $s, $m);
	return $m[1];
}

/**
 * Named placeholders used as the count variable: present in the plural half
 * of the msgid but absent from the singular half ({count}, {created}).
 *
 * @return list<string>
 */
function bcCountVars(string $key): array {
	$parts = explode('_::_', $key, 2);
	if (count($parts) !== 2) {
		return [];
	}
	return array_values(array_diff(bcNamed($parts[1]), bcNamed($parts[0])));
}

/** Rewrite the count-var placeholders in $form to %n (first placeholder each). */
function bcRewriteCountVars(string $form, array $countVars): string {
	foreach ($countVars as $var) {
		$form = str_replace('{' . $var . '}', '%n', $form);
	}
	return $form;
}

$totalChanged = 0;

foreach (NPLURALS as $locale => $nplurals) {
	$file = $base . '/' . $locale . '.json';
	if (!is_file($file)) {
		fwrite(STDERR, "missing base catalog: $file\n");
		exit(1);
	}
	$data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
	$changed = 0;

	foreach ($data['translations'] as $key => $val) {
		if (!is_array($val) || !str_contains((string) $key, '_::_')) {
			continue;
		}
		$countVars = bcCountVars((string) $key);
		$forms = array_values(array_map('strval', $val));

		if ($nplurals === 1) {
			// Single form selected for EVERY count: must carry %n itself.
			$pick = null;
			foreach (array_reverse($forms) as $form) {
				if (str_contains($form, '%n')) {
					$pick = $form;
					break;
				}
			}
			if ($pick === null) {
				$pick = bcRewriteCountVars(end($forms), $countVars);
			}
			$fixed = [$pick];
		} else {
			$fixed = $forms;
			if (count($fixed) < $nplurals) {
				// pl under-fill: splice the audited paucal element at index 1.
				$paucal = PL_PAUCAL[$key] ?? null;
				if ($locale === 'pl' && $paucal !== null && count($fixed) === 2) {
					$fixed = [$fixed[0], $paucal, $fixed[1]];
				} else {
					fwrite(STDERR, "{$locale}.json plural msgid under-filled ("
						. count($fixed) . " < {$nplurals}) with no paucal map: {$key}\n");
					exit(1);
				}
			}
			if (count($fixed) !== $nplurals) {
				fwrite(STDERR, "{$locale}.json plural msgid arity "
					. count($fixed) . " != {$nplurals}: {$key}\n");
				exit(1);
			}
			for ($i = 1; $i < $nplurals; $i++) {
				if (!str_contains($fixed[$i], '%n')) {
					$fixed[$i] = bcRewriteCountVars($fixed[$i], $countVars);
				}
			}
		}

		// Post-fix assertion: every count-selected form must carry %n now —
		// fail loudly rather than ship a still-broken form.
		$selected = $nplurals === 1 ? [$fixed[0]] : array_slice($fixed, 1);
		foreach ($selected as $form) {
			if (!str_contains($form, '%n')) {
				fwrite(STDERR, "{$locale}.json plural form still lacks %n and "
					. "carries no countable placeholder: {$key} -> {$form}\n");
				exit(1);
			}
		}

		if ($fixed !== $forms) {
			$data['translations'][$key] = $fixed;
			$changed++;
		}
	}

	if ($changed > 0) {
		file_put_contents(
			$file,
			json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n"
		);
	}
	fwrite(STDOUT, "{$locale}: {$changed} plural msgid(s) repaired\n");
	$totalChanged += $changed;
}

fwrite(STDOUT, "total: {$totalChanged} plural msgid(s) repaired\n");
