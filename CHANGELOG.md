# Changelog

## [1.0.10] - 2026-05-25

### Added

- **Searchable timezone and currency pickers** on workspace and app settings: `js/common/catalog-pickers.js` with keyboard navigation, live filtering, and accessible combobox/listbox semantics; shared partials `templates/common/bc-timezone-picker.php` and `bc-currency-picker.php`.
- **`CurrencyCatalog`:** single source for supported ISO codes (including RUB, UAH, KZT); **`TimezoneCatalog`:** full IANA list exposed via catalog API.
- **`OCA\BudgetCheck\Repair\UninstallDropTables`** and **`EnsureBudgetCheckSchema`** wired in `appinfo/info.xml` for complete uninstall cleanup and idempotent schema repair on upgrade.
- **Migration `Version1009`:** schema/repair hardening for production installs; **`BudgetCheckTableCatalog`** centralizes table names.
- **Tests:** `CurrencyCatalogTest`, `TimezoneCatalogTest`, `EnsureBudgetCheckSchemaTest`, `UninstallDropTablesTest`, `WorkspaceServiceUpdatePayloadTest`.

### Changed

- **Workspace settings:** server-side validation for timezone/currency; currency lock after the first transaction.
- **App settings / settings UI:** migrated to catalog pickers instead of plain text fields; styling in `css/app.css`.

## [1.0.9] - 2026-05-22

- Fix App Store `info.xml` validation: screenshots before `donation`/`dependencies`, `settings` before `navigations`, drop unsupported `keywords`, cap at 10 screenshots (schema limit).

## [1.0.8] - 2026-05-22

- App Store packaging: bilingual `info.xml` (EN/DE), `<donation>`, `<navigations>`, modern `<dependencies>` with PHP bounds, ten screenshot slots on `main`.
- Screenshots: `budgetcheck-screenshot-01.png` … `10.png` aligned with `info.xml` and public repo `main`.
- Added `LICENSE`, release `Makefile`, hardened `.gitignore` (signing keys, build output).
