# BudgetCheck — Audit / UAT evidence

**Version:** 1.0.42  
**Environment:** Docker Compose service `nextcloud` (port 8081)

## Automated

```bash
cd nextcloud
docker compose exec -u www-data -T -w /var/www/html/custom_apps/budgetcheck nextcloud ./vendor/bin/phpunit -c phpunit.xml
docker compose exec -u www-data -T -w /var/www/html/custom_apps/budgetcheck nextcloud php tests/Mutation/run-epic-gate-mutations.php
cd apps/budgetcheck && npm run db:naming-check && npm test && npm run e2e:smoke:safe
```

## UAT matrix (§20) — critical paths

1. Household + project workspace isolation — covered by AccessControl + type redirects  
2. Tax on/off — unit TransactionTaxFields + SummaryTaxBasisAggregation  
3. Close / reopen — ClosedMonthWriteLock + Snapshot type gate + SnapshotCanonicalise  
4. Budgets discoverability — nav restored; budgets page route live  
5. Warning recovery — shared UI helper on dashboard/monthly/period  
6. CSRF mutations — ApiControllerCsrfAttributeTest  
7. Keyboard / 320px — e2e shell smoke  

Release gate §21: no open critical findings from this suite; DB naming gate required on each version bump.
