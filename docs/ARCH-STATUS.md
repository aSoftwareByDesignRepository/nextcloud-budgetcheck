# BudgetCheck — Architecture status (epic closure)

**App version:** 1.0.43  
**Date:** 2026-07-27  
**Planning SoT:** `planning/app-ideas/budgetcheck/README.md`

## Milestone status

| Milestone | Status | Notes |
|-----------|--------|-------|
| M0–M1 Scaffold, ACL, DB | Done | Migrations `bc_*`, AccessControl, AppAccessMiddleware |
| M2 Ledger + dashboard | Done | Workspace-scoped KPIs; type-aware APIs |
| M3 Budgets, savings, tax, warnings | Done | Budgets nav restored; shared warning recovery; tax unit tests |
| M4 Recurring, specials, close, yearly, period | Done | Snapshot hash + reopen; project period; type redirects |
| M5 Hardening | Done for release evidence | CSRF attribute suite, IDOR fail-closed contracts, e2e shell smoke, epic-gate mutations |

## Scope amendments (shipped beyond original §3.2 exclusions)

These features shipped with security review and tests; planning §0 documents them as approved expansions:

- Guided **CSV import** (`/import`) for contributors+
- Summary **workbook export** on yearly / period surfaces
- InvoiceCheck **billing facades** (server-only sibling trust)

## Waived / deferred (§26)

- Archived / read-only workspaces
- Budget templates, auto-categorization, carry-over (post-release roadmap)

## Weaknesses called out (accepted residual risk)

1. Full HTTP CSRF fail/pass against a live request cycle is covered by Nextcloud framework + attribute gate tests; not a separate browser CSRF suite.
2. GET favorites prune stale IDs only on the next PUT — intentional read-only GET.
3. Import/export enlarge attack surface; remain CSRF + role gated; keep in security regression when changing parsers.
