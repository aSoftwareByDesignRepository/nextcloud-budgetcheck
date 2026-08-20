# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.1.14 - 2026-08-20

### Fixed

- **Rate limits for LDAP/AD UUID user ids (#17):** counters are stored in per-user preferences (`rate_limit:{action}`) instead of embedding the userId in an `oc_appconfig` key (VARCHAR(64)). Favoriting workspaces and other rate-limited actions work for UUID-style UIDs; exclusive locking also respects the 64-char `oc_file_locks` key limit.

## 1.1.13 - 2026-08-19

### Changed

- **l10n glossary alignment:** corrected wrong translations and standardised button labels across all shipped locales to match the cross-app glossary.
- **Help, Support & Us / Get the App:** WCAG 2.1 AA hardening and translated footer copy for all website footer locales.
- **CI:** collapsed GitHub Actions to a single PHP syntax-only smoke job; full test suites stay local.
- **Nextcloud:** `max-version` remains **34** (current stable **34.0.3**).

## 1.1.12 - 2026-08-13

### Added

- **Brazilian Portuguese (pt_BR):** full UI catalog (`l10n/pt_BR.json` / `pt_BR.js`).

### Changed

- **l10n tooling:** parity / regenerate / sync scripts recognise `pt_BR`.
- **Nextcloud:** `max-version` remains **34** (current stable **34.0.3**).

## 1.1.11 - 2026-08-11

### Changed

- **Sole-manager private workspaces:** converting standard → private no longer requires a second manager. Demoting/removing managers uses the normal last-manager rule (≥1). Groups are still forbidden on private workspaces.
- Confirmation and help copy warn that if the sole manager account is removed, the private workspace cannot be opened in BudgetCheck (host/DB recovery only).

## 1.1.10 - 2026-08-11

### Added

- **Private workspaces (ACL confidential mode):** managers can mark a workspace private so Nextcloud system admins and BudgetCheck app admins who are **not** members cannot see it in lists or open workspace-scoped APIs. Host/DB disclosure is shown before enabling private (not E2EE).
- **Create rules:** any user who can open the app may create a **private** workspace; **standard** workspace create remains app-admin-only.
- **Companion API 6:** mobile clients can create with `privacyMode` and update privacy via `PUT /api/mobile/v1/workspaces/{id}` under the same individual-manager / no-groups guards.
- Web and companion UX for privacy mode (settings fieldset, create modal, members group lock, Private badge).

### Changed

- Converting a **standard** workspace to **private** requires no Nextcloud group memberships; sole manager is allowed (orphan recovery = host/DB only). Private workspaces allow individuals only.
- Member demote/remove and privacy toggles serialize with workspace row locks to avoid last-manager races.
- Favorites / last-used and admin digest surfaces clip or opaque-count private workspaces (no name/id leak to non-members).

### Security

- Private workspaces skip admin role bypass and group-role inheritance; non-member admins get the same denial class as unknown workspace IDs.

## 1.1.7 - 2026-08-02

### Added

- **InvoiceCheck compose strip** on project workspaces: soft deep links to create an invoice and open receivables filtered by `workspaceId` when InvoiceCheck is enabled (settlement stays in BudgetCheck).

### Changed

- **Uninstall:** app removal still drops tables, config, and upgrade-backup snapshots; receipt attachment binaries in appdata are no longer deleted by the uninstall repair step.
- **l10n:** Locale catalog refresh across supported languages.

## [1.1.5] - 2026-07-31

### Added

- Multipage **Workspace settings** (`/settings/{section}`): Planning view, Workspace, Tax, Categories, Budget defaults, Booking statuses, Members, Recurring, and Help — catalog-driven routes, type/role visibility gates, sidebar sub-nav, in-page chip bar, and legacy `#anchor` forwarding with `workspaceId` preserved.

### Security / integrity

- Workspace settings dispatcher uses a literal slug→file map (no path concatenation from the request).
- Unauthorized or type-mismatched section URLs redirect to the type-aware default section (manager-only partials are never rendered to viewers).
- `data-bc-urls.settingsSections` lists only sections the current role/type may open (no advertising of manager-only paths).
- Members, Recurring, and Budget defaults ship soft denial cards if the controller gate ever regresses.
- Legacy hash redirects select targets only from server-rendered `urls.settingsSections` + a frozen allowlist.
- Non-manager savings-target radios now correctly carry `disabled`.

### Fixed

- Settings JS boots only the APIs needed for the active section (`data-bc-settings-section`).
- Savings-setup deep link prefers `/settings/categories` when section URLs are available.

### Tests

- Contract, template render, Node JS, mutation, and Playwright axe coverage for Workspace settings sections.

## [1.1.4] - 2026-07-31

### Added

- Multipage **App settings** (`/app-settings/{section}`): Access, App admins, Defaults, and Support us — catalog-driven routes, sidebar sub-nav, in-page chip bar, and legacy `#anchor` forwarding with `workspaceId` preserved.
- Merge-on-save for app policy section forms so incomplete section payloads cannot wipe other policy fields.

### Security / integrity

- App settings dispatcher uses a literal slug→file map (no path concatenation from the request).
- Legacy hash redirects select targets only from server-rendered `urls.appSettingsSections` + a frozen allowlist.

### Tests

- Contract, template render, Node JS, mutation, and Playwright axe coverage for every App settings section URL.

## [1.1.3] - 2026-07-31

### Added

- Mobile companion API v5: workspace-bound **attachment download/preview** endpoint (`GET /api/mobile/v1/workspaces/{workspaceId}/attachments/{attachmentId}/download`) streaming receipt bytes with `nosniff`, `no-store`, sanitized `Content-Disposition`, strict CSP for inline previews, and per-user rate limiting.
- Mobile companion API v4: monthly/yearly/period **glance summaries** (`scope=month|year|all` with new household span aggregation) and **app-admin workspace creation** mirroring the web permission model.
- Home category overview chips now carry a `direction` field and include income categories with activity, so the companion can split spending and income views.

### Security / integrity

- Attachment delivery for the companion is bound to the requested workspace (`resolveForDeliveryInWorkspace`): a crafted attachment id from another workspace, or from a deleted transaction, is rejected with an opaque access-denied error (IDOR closed).
- Year/scope summary parameters are strictly validated (four-digit year window, whitelisted scopes) with 422 validation errors.

### Fixed

- Mobile transaction list shape normalized; home category overview no longer drops income rows with activity.

### Tests

- New behavior/contract suites for attachment download (workspace mismatch, deleted transaction, rate limit, header hygiene) and glance summaries; mobile-companion mutation harness extended (attachment workspace-bind and download mutations all killed).

## [1.1.2] - 2026-07-30

### Security / integrity

- Mobile mutation channel now requires a **cryptographically valid** CSRF token (`IRequest::passesCSRFCheck()`); forged non-empty `requesttoken` strings are rejected. Basic/Bearer app-password clients are unaffected.
- New exclusive **workspace row lock** (`SELECT … FOR UPDATE`) serializes monthly close/reopen against ledger writers: snapshot summary and hash are computed under the lock, and budget-target and attachment writes re-check closed state under the same lock (TOCTOU closed).
- Budget upsert collapses historical duplicate `(workspace, month, category)` rows under the write lock (NULL `category_id` is not uniquely indexed on all engines).
- Attachment overwrite (receipt crop bake) snapshots prior bytes and restores the stored file when the DB metadata update fails — disk never stays ahead of durable metadata.
- Adding or removing attachments on a transaction of a **closed month** is rejected.

### Fixed

- Layout overflow hardening: cards, warnings list, and empty states wrap long titles instead of widening the app shell (`min-width: 0` / `max-width: 100%` grid constraints).

### Tests

- New snapshot-close serialization contract test; expanded update/delete CAS, mobile-channel, design-system CSS, and frontend-hardening suites; closeout and mobile-companion mutation harnesses cover the new gates.

## [1.1.1] - 2026-07-29

### Added

- In-app **Support & Us** admin surface with Partner CTAs.
- Public **billing facades** for InvoiceCheck (`BillingReadFacade` / `BillingWriteFacade`, including paid-state handshake).
- Mobile companion API surface (`MobileApiController`, capabilities, idempotent mutations).
- Playwright shell a11y smoke and epic-gate / closeout / billing mutation harnesses.

### Security / integrity

- Transaction **create/update/delete** optimistic locking (`version` required; CAS on update/delete).
- CSRF-required mutation coverage; opaque AccessDenied IDOR gates.

### Fixed

- Budgets navigation restored; warning recovery actions aligned with Dashboard.
- Money envelopes include `decimals` (zero-decimal currencies); header month follows selected month.
- Transaction dialog horizontal scrollbar; GET favorites remain read-only.
- Uninstall cleanup limited to upgrade-backup snapshots.

### Changed

- Nextcloud compatibility **32–34**; version sources aligned to **1.1.1**.
- Stabilized money formatting; dropped versioned asset filename suffixes.

## [1.0.43] - 2026-07-27

### Security / integrity

- Transaction **update** optimistic locking covered by behavioral PHPUnit twin of delete CAS (omit/null/empty/stale version + lost-race).
- Closeout mutations require **both** update and delete `version is required` messages plus update SQL CAS predicate.
- E2E global-setup **fails hard** when credentials are set but login fails (no false-green skip).

### UX / hygiene

- Removed dead archived-workspace card/CTA branches and orphan l10n keys (archive remains §26.0).
- `info.xml` requirements text aligned to Nextcloud **32–34**.

### Tests / CI

- `TransactionUpdateCasTest` added; shell smoke throws when still on login with `E2E_USER` set.

## [1.0.42] - 2026-07-27

### Security / integrity

- Transaction **update and delete require `version`** (422 if omitted) — no silent last-write-wins for API clients.
- Page chrome no longer persists pruned favorites on GET; only `PUT /workspace-favorites` writes.
- Removed dead “Show archived workspaces” teaser (archive is post-v1; no deactivate API).

### Accessibility / UX

- Period and yearly empty states use `aria-labelledby` like the dashboard empty card.
- Workspace overview copy no longer promises archived filtering.

### Tests / CI

- Favorites read-only contract covers `PageController::resolveWorkspace`.
- Epic-gate + closeout mutations assert version-required and no archived filter.
- GitHub CI runs closeout, epic-gate, and billing mutation scripts after PHPUnit.

## [1.0.41] - 2026-07-27

### Fixed

- **Budgets navigation restored** for household and project workspaces (was hardcoded off, leaving `/budgets` orphaned).
- **Warning recovery actions** on Monthly plan and Period overview now match Dashboard (§6.4 Open affordance).
- **GET favorites are read-only:** `listWorkspaces` / `getWorkspaceFavorites` no longer persist pruned IDs (writes stay on PUT `saveWorkspaceFavorites`).
- Version sources aligned (`appinfo/version`, `info.xml`, `package.json`) to **1.0.41**.

### Security / integrity

- Transaction **delete** optimistic locking (`version` CAS + `deleted_at IS NULL`); client sends `version` on delete.
- CSRF-required mutation attribute suite; opaque AccessDenied IDOR gates; frontend `requesttoken` on DELETE.

### Tests

- Savings target compute/mode unit suite; Snapshot project-type 422 gate; Budgets nav unit tests; GET favorites contract; WarningEngine recovery params.
- Mutation gauntlet `tests/Mutation/run-epic-gate-mutations.php` (+ existing closeout/billing mutations).
- Playwright shell a11y smoke (`npm run e2e:smoke:safe`).
- l10n variant catalogs resynced to base languages.

## [1.0.40] - 2026-07-24

### Added

- **Billing paid state:** `BillingStatus::PAID` with `BillingWriteFacade::markItemsPaid` (invoiced→paid) and `reopenFromPaid` (paid→open) for InvoiceCheck full-payment / credit-note handshake

## [1.0.39] - 2026-07-24

### Added

- `BillingReadFacade::listAccessibleWorkspaceIds` for sibling apps (InvoiceCheck list/view scope)

## [1.0.38] - 2026-07-24

### Added

- **BC-F0 billing facades for InvoiceCheck:** `Public\BillingReadFacade` / `BillingWriteFacade` with `listBillableItems`, `markItemsInvoiced`, `reopenItems`, `setItemBillable`
- Schema: `bc_transactions.is_billable` + `billing_status` (`open`/`invoiced`) — orthogonal to booking-status workflow and monthly close
- Optimistic `updated_at` / `version` checks; fail-closed `requireFullSuccess`; `trustedSiblingApp` for server-side callers only
- Mutation harness `tests/Mutation/run-billing-facade-mutations.php`

## [1.0.37] - 2026-07-24

### Fixed

- **Transaction dialog horizontal scrollbar:** the modal mounts on `document.body` (outside the app shell). The dialog now uses a dedicated body scrollport with `overflow-x: clip` (and `hidden` fallback), `scrollbar-gutter: stable`, and a `width:0; min-width:100%` form shrink so the receipt dropzone cannot expand past the dialog. Overlay padding replaces `100vw`-based max-width (which ignored padding and could be 1–2rem too wide). Focus rings on the dropzone stay inset so they do not inflate `scrollWidth`.

## [1.0.33] - 2026-07-24

### Fixed

- **Transaction dialog no longer grows a horizontal scrollbar:** the booking modal is mounted on `document.body` (outside the app shell), so long receipt-dropzone copy and `width: 100%` controls without a `min-width: 0` / border-box chain could expand past the dialog. Modal and gallery overlays now use border-box throughout; the receipt picker wraps its file-type line; dialogs clip horizontal overflow and only scroll vertically.

## [1.0.32] - 2026-07-24

### Fixed

- **Money envelopes include `decimals`:** API money envelopes now carry the currency's decimal places (including `0` for JPY). Clients and the yearly Excel export no longer assume 2 decimals and mis-scale amounts by 100×.
- **`parseHuman(…, 0)` no longer treats zero as missing:** budget and savings absolute amounts for zero-decimal currencies were stored 100× too large because `decimals || 2` coerced `0` to `2`.
- **Close / reopen / monthly overrides freeze the month:** confirming a close or reopen (or saving overrides) after changing the month picker can no longer apply the action to the newly selected month.
- **Budgets / savings mutation lock:** save and generate actions ignore double-clicks and only refresh when the month/load sequence is still current.
- **Import commit locks validate + file input** so a second validate cannot replace rows mid-commit.
- **Attachment gallery XML loads** clear the busy flag only for the latest request (same as image/PDF).
- **Settings `currencyChangeAllowed`** is passed into the template so the currency field locks after the first transaction instead of looking editable and failing on save.
- **Transactions scope strip** resets to the workspace active month for non-month filter ranges; ledger receipt patches update `items` (not a non-existent `transactions` field).
- **Dashboard ledger `aria-busy`** is cleared in `finally` even when a load returns early.

## [1.0.31] - 2026-07-24

### Fixed

- **Header month now follows the selected month:** the workspace scope strip (workspace name · type · month · currency) kept showing the current calendar month (e.g. "July") after switching the dashboard, monthly plan, budgets, or transactions view to another month (e.g. "August"). It now always shows the month the page is displaying, with an updated tooltip when it differs from the active calendar month.
- **Stale-response guards:** the dashboard, budgets, and yearly views now ignore out-of-date API responses when the month/year is switched quickly, so a slow response for the previous selection can no longer overwrite the newly selected period (the monthly view already had this guard).
- **Zero-decimal currencies:** amounts in currencies without decimal places (e.g. JPY) were divided by 100 in the client-side formatter; envelopes with `decimals: 0` now render correctly.
- **Frontend lint:** attachment gallery media loads go through the central API client (`Api.media`) instead of raw `fetch()`.

### Changed

- **Calmer summary tiles:** summary tiles on the dashboard, monthly plan, yearly overview, project period, and transactions (ledger KPI) views show plain locale-formatted amounts without the currency symbol — the workspace currency is already visible in the header scope strip. Screen readers still announce the currency via a visually hidden suffix; tables and detail lists keep the full currency format.
- **Removed forked `-v106` assets:** the transactions page previously loaded duplicated copies of the ledger script, workspace helper, and stylesheets (`transactions-v106.js`, `common/workspace-v106.js`, `transactions-v106.css`, `import-v106.css`) that had drifted from the canonical files — the transactions page was missing newer workspace helpers, and fixes applied to the canonical files never reached it. All pages now load the single canonical `js/transactions.js`, `js/common/workspace.js`, `css/transactions.css`, and `css/import.css`; the receipt-gallery features that only existed in the fork were merged back into the canonical ledger script. Nextcloud's `?v=` cache-busting makes the filename suffix unnecessary.

## [1.0.29] - 2026-07-21

### Fixed

- Monatsübersicht (`Kategorieverbrauch`): Der „Include one-off (special) transactions“-Schalter wird jetzt für die geplanten-vs.-tatsächlichen Kategorien korrekt angewendet, damit die Anzeige verständlich und konsistent ist.
- Audit-Härtung: Monatsabschluss-Snapshots (Close/Reopen) bleiben weiterhin kanonisch und unabhängig von der persönlichen Anzeige-Einstellung für „special“; dadurch bleibt der Berechnungs-Hash deterministisch.
- Robustheit: Wechseln der Anzeige-Einstellung lädt die Monatsdaten konsistent neu und ignoriert veraltete Antworten (vermeidet UI-Race-Conditions).

## [1.0.28] - 2026-07-15

### Changed

- **Receipt gallery toolbar:** grouped controls with tooltips, clearer hover/focus states, busy guards while saving, and a corrected rotate-clockwise icon.
- **Gallery translations:** attachment gallery strings are now translated across all supported locales (including regional variants).

### Fixed

- **Upgrade backup:** snapshot folder creation failures (permissions, quota) now surface as `UpgradeBackupException` instead of escaping the backup handler.

## [1.0.25] - 2026-07-15

### Added

- **Receipt gallery lightbox:** open receipts in a full-screen gallery with previous/next navigation, zoom, rotate, and crop for images. Saved edits are written back to the server; pending files update locally until you save the transaction.
- **PDF and EU e-invoice support:** PDF receipts preview inline in the gallery (including ZUGFeRD / Factur-X PDFs). Standalone XML e-invoices (XRechnung-style) can be uploaded, previewed as text, and downloaded.

### Fixed

- **Receipt counts after delete:** removing an attachment from the editor or gallery updates receipt badges in the ledger and dashboard immediately, without closing the modal or reloading the page.

## [1.0.24] - 2026-07-15

### Added

- **Specific-date schedules for recurring rules (#11):** a recurring rule can now repeat on an explicit list of irregular dates instead of a fixed interval. Pick "Specific dates" in the rule editor and add one row per date; each date may carry its own amount or fall back to the rule's default amount. Generate next / Generate full period, planned-entry matching, month close, and audit logging all work identically to interval rules. Editing the date list preserves generation progress (already-generated dates are not re-created); the schedule-alignment toggle snaps the next due date to the first scheduled date on or after the chosen day.

### Fixed

- **Closed-month write lock (#12):** the month-close lock now covers every write path on the server, not only deletes. Creating or editing real bookings in a closed month, moving a booking into a closed month, changing budget targets of a closed month, and generating planned entries for it are all rejected with clear, translated messages. Automatic planned-placeholder matching skips placeholders that sit in closed months so the snapshot evidence stays intact.
- **Planned placeholder cleanup (#12):** zeroing a budget target or deactivating a category now soft-deletes the matching planned placeholders instead of leaving orphaned rows; changing a target updates the placeholder amount. Duplicate placeholders from concurrent generate calls self-heal on the next sync.
- **Closed-month UI state (#12):** the Budgets page disables planned inputs, Save, and Generate with an explanatory note while the month is closed; the monthly overview disables “Edit monthly overrides” and guards the modal.
- **Transaction editor crash:** opening the new/edit transaction modal failed with a JavaScript error (`emptyGridMessage is not defined`) in the receipts section; the empty-state message is restored for both editable and read-only bookings.
- **Completed rules could not be edited:** once a rule had generated past its end date (and auto-deactivated), any edit — even a title change — was rejected with "nextDueDate must not be after endDate". The window check now only applies when the update actually moves the rule's dates.
- **Strict calendar validation for scheduled dates:** impossible days (e.g. 30 February) are rejected instead of silently rolling over into the next month.

## [1.0.23] - 2026-07-15

### Changed

- **Receipt attachments — one-step save:** pick photos or PDFs while filling in the new/edit transaction form; files queue locally with previews and upload automatically when you save. Removed the old “save first, then Done” post-save step. Partial upload failures keep the modal open so you can retry; cancel discards queued files safely.
- **Yearly overview layout (#14):** annual totals now use the same sectioned layout as the monthly plan (Cash flow, Expected plan, Savings, Budget) so planned income and expenses are easy to spot.

### Fixed

- **Ledger month header (#14):** the “Month: …” label now follows the active date filter instead of a stale `yearMonth` from the URL (e.g. August data no longer shows “July” after drilling down from analytics).
- **Danish planned-forecast copy (#14):** translated the “Expected (plan)” section and related ledger hints for `da` users.
- **Planned-forecast completeness (#14):** expense budget targets now appear in the Expected (plan) section (not only income targets and ledger placeholders); monthly activity counts separate actual bookings from planned placeholders; summary sections use labelled landmarks for screen readers.

## [1.0.22] - 2026-07-15

### Added

- **Transaction attachments:** upload receipt photos and PDFs directly on a booking (create/edit modal). Files are stored in app data, workspace-scoped, with MIME validation, size limits, and secure streaming. The ledger shows a receipt indicator; existing notes/links remain fully supported.
- **Planning forecast (#14):** monthly and yearly overviews now show a separate “Expected (plan)” section with income budget targets and ledger placeholder totals. Actual cash flow tiles stay real-booking-only; close snapshots unchanged.

### Fixed

- **Wrong language despite account setting (#15):** the app now ships catalogs for regional language variants (`en_GB` with British spellings, `de_DE` with formal German (Sie), and all `es_*` variants). Nextcloud resolves the interface language per app; without an exact match for e.g. “English (British English)” it silently fell back to the browser’s `Accept-Language` header, so a Danish browser profile rendered BudgetCheck in Danish while the rest of Nextcloud was in UK English. The account language now always wins. New `scripts/sync-l10n-variants.php` generates the variant catalogs; `scripts/de-formalize.php` produces the formal `de_DE` register from the informal `de` base.
- **Date/number formats follow the account locale (#15):** `LocaleFormatService` now prefers the explicit account locale (Personal settings → Locale) over the account language, matching how Files and Calendar format dates and numbers.
- **Ledger month deep links (#14):** opening the ledger with `yearMonth` in the URL now keeps that month in the address bar, shows “Month: …” in the header, and sets the range filter to Custom instead of “This month”.
- **Ledger KPI consistency:** analytics totals exclude planned placeholders; a dedicated KPI tile lists placeholder income and expenses.
- **Audit hardening (#14):** planned-section styling, monthly transaction tags, activity counts, popstate/clear-filter state fixes; production ledger script (`transactions-v106.js`) aligned with deep-link behaviour.

## [1.0.21] - 2026-07-11

### Fixed

- **Planned entries in summaries:** dashboard, monthly, yearly, and close snapshots now exclude `is_planned` placeholder rows from income/expense/budget actual totals (they remain visible on the ledger until matched).
- **Recurring generate:** batch generation runs in a DB transaction, skips duplicate dates idempotently, blocks closed months, and deactivates rules that have passed their end date.
- **Budget planned sync:** month sync is transactional; zero-target categories no longer throw a misleading 403 when no budget row exists.

### Changed

- Recurring rules table shows end date; modal adds active/inactive toggle and stronger end-date warnings (live region + save confirm).
- Yearly month cards show text badges for over-budget and closed states; recurring action buttons have unique accessible names.
- Modal focus lands on the first form field; dashboard warnings include severity text for screen readers.

## [1.0.20] - 2026-07-11

### Fixed

- **Yearly savings achieved (#10):** the annual “Savings achieved” total now sums actual savings transfers (matching the monthly “Saved this month” tile) instead of capping each month at the savings target. The achievement percentage still measures progress toward the yearly target.
- **Recurring rule end date (#11):** the rule editor warns when start and end date are the same (one booking only) and asks for confirmation before saving.

## [1.0.19] - 2026-06-19

### Added

- **Budget planned entries (#12):** category budget targets can generate planned ledger rows for a month. A real booking in the same category removes the placeholder automatically (any amount, same or neighbouring month). Optional workspace default and per-save toggle on the Budgets page (`BudgetPlannedService`).

### Changed

- Full translations (da, de, en, es, fr, it, nb, nl, pl, sv) updated for budget planned-entry UI.

## [1.0.18] - 2026-06-18

### Added

- **Shared transaction editor:** dashboard and ledger pages use a common inline editor for creating and editing bookings (`transaction-editor.js`).
- **Dashboard quick actions:** household dashboards show month-scoped shortcuts (new transaction, open ledger) with a compact recent-ledger preview.
- **Transaction title fallback:** blank titles default to the selected category name; unit tests cover resolution rules (`TransactionTitleTest`).

### Changed

- **Workspace settings for all members:** non-managers can open settings to view workspace details and personal planning-total preferences; managers retain full configuration access.
- **Transactions default window:** the ledger opens on the current month instead of all time.
- Full translations (da, de, en, es, fr, it, nb, nl, pl, sv) updated for new UI strings.

## [1.0.17] - 2026-06-18

### Added

- **Planning-total preferences:** special transactions can be excluded from everyday planning totals by default, with per-user, server-persisted overrides and a manager-set workspace default (`SummaryViewPreferencesService`).
- **Savings-transfer and planned-entry aggregation:** summaries now match planned entries against actual transactions and aggregate savings transfers (`PlannedTransactionMatchService`).
- **Dashboard transaction notes:** transaction notes are shown on the dashboard.

### Changed

- Special transactions are excluded from everyday planning totals by default; full translations (da, de, en, es, fr, it, nb, nl, pl, sv) updated accordingly.

### Fixed

- **Unbounded household transaction lists:** household transaction lists are now properly bounded.

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
