# Changelog

## [1.0.9] - 2026-05-22

- Fix App Store `info.xml` validation: screenshots before `donation`/`dependencies`, `settings` before `navigations`, drop unsupported `keywords`, cap at 10 screenshots (schema limit).

## [1.0.8] - 2026-05-22

- App Store packaging: bilingual `info.xml` (EN/DE), `<donation>`, `<navigations>`, modern `<dependencies>` with PHP bounds, ten screenshot slots on `main`.
- Screenshots: `budgetcheck-screenshot-01.png` … `10.png` aligned with `info.xml` and public repo `main`.
- Added `LICENSE`, release `Makefile`, hardened `.gitignore` (signing keys, build output).
