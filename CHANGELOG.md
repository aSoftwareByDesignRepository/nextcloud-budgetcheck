# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.16] - 2026-06-16

### Added

- **Import preferences API:** server-synced CSV import defaults (`ImportPreferencesService`) with unit tests.
- **Transaction import hardening:** UTF-8 and legacy encoding fallback, fingerprint-based duplicate skip, expanded reference column aliases, and higher rate limits for split imports.

### Changed

- **CSV import UI:** remove bank-type column from direction mapping; improved import page layout and validation feedback.

## [1.0.15] - 2026-06-16

### Fixed

- **App access permissions (#2):** users and groups on the directory allow-list can open BudgetCheck without a pre-existing workspace membership. Workspace data remains gated by per-workspace role checks.
- **Group workspace membership counting:** only honour group rows with assignable roles (`viewer`, `contributor`) when resolving effective access.

### Added

- **CSV transaction import:** guided import page with preview validation and atomic commit (`TransactionImportService`, `/import`, API preview/commit endpoints).
- **Unit tests** for `AccessControlService::canUseApp()` allow-list behaviour (`AccessControlAppAccessTest`).
- **Integration test** for `AppAccessMiddleware` deny/allow flows against live app config (`AppAccessGateIntegrationTest`).

### Changed

- Removed unused `DENIAL_NO_WORKSPACE` denial path; directory restriction is the sole app-entry gate.

## [1.0.14] - 2026-06-12

### Fixed

- **Data loss after Nextcloud upgrade:** `UninstallDropTables` preserves tables and settings on disable; full cleanup runs only on app removal.

## [1.0.13] - 2026-06-04

### Fixed

- **`EnsureBudgetCheckSchema`:** use the core `Connection` from the server container for `MigrationService`, so install/post-migration repair runs reliably on upgrade.

### Added

- **Integration tests** for repair steps (`UpgradeRepairIntegrationTest`) and PHPUnit bootstrap support when tests run against a Nextcloud tree (`NEXTCLOUD_ROOT` / monorepo `lib/base.php`).

### Changed

- Confirm Nextcloud **33** as `max-version` in `appinfo/info.xml` (aligned with latest stable server).

## [1.0.11] - 2026-06-01

### Added

- **`BudgetCheckApi.download()`** for Excel export routes (blob + `Content-Disposition`) so page scripts no longer call `fetch()` directly.

### Changed

- **Responsive layout (mobile-first):** `css/app.css` uses Nextcloud-aligned `min-width` breakpoints (480 / 640 / 768 / 1024px) for shell, headers, forms, tables, modals, and toasts; desktop (≥1024px) restores the prior layout.
- **Transactions styles:** `css/transactions-v106.css` now contains only `.bc-tx-*` rules (shared tokens and shell stay in `app.css`); mobile card ledger below 768px with horizontal-overflow fixes for `.bc-tx-table`.
- **Typography on mobile:** `--bc-fs-xs` is 0.875rem (14px) below 768px inside `.bc-app`.
- **Export UX:** yearly and project-period exports use the API client; new l10n strings for export success and failure toasts.

### Fixed

- **Table scroll cascade:** `.bc-table-scroll table { min-width: 540px }` no longer overrides the desktop `min-width: 0` rule after the 1024px media query.
- **Transactions on narrow viewports:** card rows no longer inherit the 540px table minimum width.

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
