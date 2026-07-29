#!/usr/bin/env bash
set -euo pipefail

echo "[budgetcheck:e2e] Safe mode — no docker compose rebuild/restart."

if [[ ! -f "playwright.config.js" ]]; then
  echo "[budgetcheck:e2e] Run from apps/budgetcheck."
  exit 1
fi

if [[ -f "e2e/.env" ]]; then
  set -a
  # shellcheck disable=SC1091
  source "e2e/.env"
  set +a
  echo "[budgetcheck:e2e] Loaded e2e/.env"
fi

if [[ -z "${E2E_USER:-}" || -z "${E2E_PASSWORD:-${E2E_PASS:-}}" ]]; then
  echo "[budgetcheck:e2e] E2E_USER + E2E_PASSWORD not set — authenticated specs will skip."
fi

if [[ ! -d node_modules/@playwright/test ]]; then
  npm install --no-save @playwright/test@^1.56.1 @axe-core/playwright@^4.10.2
  npx playwright install chromium
fi

if command -v docker >/dev/null 2>&1; then
  if docker compose -f ../../compose.yaml ps nextcloud 2>/dev/null | grep -q 'Up' \
    || docker compose ps nextcloud 2>/dev/null | grep -q 'Up'; then
    COMPOSE_DIR="$(cd ../.. && pwd)"
    if (cd "$COMPOSE_DIR" && docker compose exec -T -u www-data nextcloud php occ status 2>/dev/null | grep -q 'needsDbUpgrade: true'); then
      echo "[budgetcheck:e2e] Running occ upgrade (needsDbUpgrade)..."
      (cd "$COMPOSE_DIR" && docker compose exec -T -u www-data nextcloud php occ upgrade)
      (cd "$COMPOSE_DIR" && docker compose exec -T -u www-data nextcloud php occ maintenance:mode --off || true)
    fi
  fi
fi

echo "[budgetcheck:e2e] Running Playwright smoke + a11y..."
rm -f .auth/storage-state.json
npx playwright test
exit $?
