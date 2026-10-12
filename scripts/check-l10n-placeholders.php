<?php

declare(strict_types=1);

/**
 * Ensures translated strings keep the same printf-style placeholders as their msgid.
 * Named placeholders like {project} are ignored (Nextcloud notifications / JS tPl).
 *
 * Exit 0 = OK, 1 = mismatch printed to STDERR.
 */

$base = __DIR__ . '/../l10n';
$localeFiles = ['en', 'de', 'fr', 'es', 'da', 'nl', 'it', 'pl', 'sv', 'nb', 'pt_BR', 'zh_CN'];

/**
 * Scan printf-style percent tokens in a msgid/msgstr.
 *
 * Literal percent signs keep their escaped form as the token: `%%` yields
 * '%%' and a bare `%` (literal, or the leading % of %n/%.0f-style tokens the
 * checker does not track individually) yields '%'. Escaped form must match
 * the msgid exactly: PHP-rendered msgids carry `%%` (vsprintf safety) and so
 * must their msgstr, while JS-rendered msgids carry a bare `%` and their
 * msgstr must too — `%%` in a JS-bound msgstr renders literally to users.
 *
 * @return list<string>
 */
function bcPrintfPlaceholders(string $s): array {
	$tokens = [];
	$len = strlen($s);
	for ($i = 0; $i < $len; $i++) {
		if ($s[$i] !== '%') {
			continue;
		}
		$next = $s[$i + 1] ?? '';
		if ($next === '%') {
			$tokens[] = '%%';
			$i++;
			continue;
		}
		if (preg_match('/\G%(?:\d+\$)?[sd]/', $s, $m, 0, $i)) {
			$tokens[] = $m[0];
			$i += strlen($m[0]) - 1;
			continue;
		}
		$tokens[] = '%';
	}

	return $tokens;
}

/**
 * @return list<string>
 */
function bcNamedPlaceholders(string $s): array {
	preg_match_all('/\{[a-zA-Z_][a-zA-Z0-9_]*\}/', $s, $m);

	return $m[0];
}

$catalogs = [];
foreach ($localeFiles as $lang) {
	$path = $base . '/' . $lang . '.json';
	if (!is_file($path)) {
		fwrite(STDERR, "Missing locale file: $path\n");
		exit(1);
	}
	$catalogs[$lang] = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

$enT = $catalogs['en']['translations'] ?? [];
$failed = false;

foreach ($enT as $key => $enVal) {
	$keyPrintf = bcPrintfPlaceholders($key);
	$keyNamed = bcNamedPlaceholders($key);

	foreach ($localeFiles as $lang) {
		$langT = $catalogs[$lang]['translations'] ?? [];
		if (!isset($langT[$key])) {
			continue;
		}
		$val = $langT[$key];

		if (is_array($val)) {
			// Plural-bundle entries (n() msgid `_sing_::_plur_`) hold one msgstr
			// per plural form. Every form may only use placeholders that exist
			// in the msgid, and the union of forms must cover all of them —
			// nplurals=1 locales legitimately collapse both sides into one
			// form. The count itself may be rendered either as %n (bare '%'
			// printf token) or as a named count param ({count}/{created});
			// either mechanism satisfies the other's coverage requirement.
			$countNames = ['{count}' => true, '{created}' => true];
			$coveredPrintf = [];
			$coveredNamed = [];
			foreach ($val as $form) {
				$form = (string) $form;
				$formPrintf = bcPrintfPlaceholders($form);
				$formNamed = bcNamedPlaceholders($form);
				if (array_diff($formPrintf, $keyPrintf) !== [] || array_diff($formNamed, $keyNamed) !== []) {
					$failed = true;
					fwrite(STDERR, "{$lang}.json plural-form placeholder mismatch for key: $key\n");
					fwrite(STDERR, '  form: ' . $form . "\n");
					fwrite(STDERR, '  expected placeholders from msgid: ' . implode(', ', array_merge($keyPrintf, $keyNamed)) . "\n");
					break;
				}
				$coveredPrintf = array_merge($coveredPrintf, $formPrintf);
				$coveredNamed = array_merge($coveredNamed, $formNamed);
			}
			$countCovered = in_array('%', $coveredPrintf, true)
				|| array_intersect($coveredNamed, array_keys($countNames)) !== [];
			foreach ($keyPrintf as $token) {
				$ok = $token === '%'
					? $countCovered
					: in_array($token, $coveredPrintf, true);
				if (!$ok) {
					$failed = true;
					fwrite(STDERR, "{$lang}.json plural-bundle coverage mismatch for key: $key\n");
					fwrite(STDERR, "  missing printf token: $token\n");
					break;
				}
			}
			foreach ($keyNamed as $token) {
				$ok = in_array($token, $coveredNamed, true)
					|| (isset($countNames[$token]) && in_array('%', $coveredPrintf, true));
				if (!$ok) {
					$failed = true;
					fwrite(STDERR, "{$lang}.json plural-bundle coverage mismatch for key: $key\n");
					fwrite(STDERR, "  missing named placeholder: $token\n");
					break;
				}
			}
			continue;
		}

		$val = (string) $val;

		$valPrintf = bcPrintfPlaceholders($val);
		if ($keyPrintf !== $valPrintf) {
			$failed = true;
			fwrite(STDERR, "{$lang}.json printf placeholder mismatch for key: $key\n");
			fwrite(STDERR, '  expected: ' . implode(', ', $keyPrintf) . "\n");
			fwrite(STDERR, '  got:      ' . implode(', ', $valPrintf) . "\n");
		}

		$valNamed = bcNamedPlaceholders($val);
		if ($keyNamed !== $valNamed) {
			$failed = true;
			fwrite(STDERR, "{$lang}.json named placeholder mismatch for key: $key\n");
			fwrite(STDERR, '  expected: ' . implode(', ', $keyNamed) . "\n");
			fwrite(STDERR, '  got:      ' . implode(', ', $valNamed) . "\n");
		}
	}
}

if ($failed) {
	fwrite(STDERR, "\nl10n placeholder check FAILED.\n");
	exit(1);
}

echo 'l10n placeholder check OK (' . implode('/', $localeFiles) . " printf and named placeholders match msgids).\n";
exit(0);
