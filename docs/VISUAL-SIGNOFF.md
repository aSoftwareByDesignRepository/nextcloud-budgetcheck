# BudgetCheck — Visual / UX sign-off

**Version:** 1.0.42  
**Standard:** WCAG 2.1 AA, responsive from 320px

## Checklist

| Surface | Pass criteria | Result |
|---------|---------------|--------|
| Shell | Skip link, main landmark, nav hints | Pass (e2e shell smoke) |
| Scope strip | Workspace name, type, month/period | Pass (templates) |
| Budgets nav | Visible for household + project | Pass (PageController + e2e when workspace active) |
| Warnings | Recovery Open link on dashboard, monthly, period | Pass (shared `renderWarningsList`) |
| Close month | Explainer before confirm | Pass (monthly template + JS) |
| Forms ≥720px | `bc-filter-grid` / `bc-form-grid` baseline | Pass (CSS contract; spot-check in UAT) |
| Light/Dark | NC tokens, no hard-coded purple themes | Pass (token-based CSS) |

Reviewer: automated gate + maintainer spot-check 2026-07-27.
