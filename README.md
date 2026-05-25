# BudgetCheck (Nextcloud app)

BudgetCheck is a Nextcloud app for **workspace-scoped** finance: **household** (monthly budgets, savings, yearly view) or **bounded project** (period overview, optional spend cap). The server stores money in **minor units**, runs **monthly close** with immutable snapshots and verifiable hashes, and supports optional **tax-aware** net/gross entry.

## Requirements

- Nextcloud 32 or 33
- PHP 8.2–8.4
- MySQL or PostgreSQL

## Install from Git (development)

```bash
cd /path/to/nextcloud/apps/
git clone https://github.com/aSoftwareByDesignRepository/nextcloud-budgetcheck.git budgetcheck
cd budgetcheck
composer install --no-dev
```

Enable the app (Apps → BudgetCheck) or `php occ app:enable budgetcheck`.

## Uninstall

Removing the app from **Apps → Remove** runs the uninstall repair step: all `bc_*` tables, migration rows, and app config are dropped automatically (no foreign keys, no manual MySQL steps). Re-installing afterwards runs migrations from a clean state.

## Development

```bash
composer install
composer test
npm run lint
npm run l10n
```

Database table naming policy (release gate when present):

```bash
npm run db:naming-check
```

## Releasing

From this app directory with monorepo `occ` and Nextcloud signing certificates: `make release-signed`, then follow `ready2publish/APPSTORE-RELEASE.md` in the monorepo (preflight, checksums, App Store signature, GitHub release).

Default Git branch for store screenshot URLs is **`main`**. Images use `budgetcheck-screenshot-NN.png`; keep them aligned with `appinfo/info.xml` before each release.

## Security

Do not share production secrets or personal data in issues. Report sensitive findings privately to the maintainer (see `appinfo/info.xml`).

## License

AGPL-3.0-or-later — see [LICENSE](LICENSE).
