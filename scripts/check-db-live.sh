#!/usr/bin/env bash
#
# Live database audit for BudgetCheck.
#
# Verifies the running schema after migrations have applied: every
# budgetcheck table is `bc_`-prefixed, no over-long legacy identifier
# survives, and every column / index name stays within the 30-character
# portability ceiling.
#
# Usage:
#   apps/budgetcheck/scripts/check-db-live.sh                       # auto-detect docker env
#   DB_CONTAINER=mariadb DB_NAME=nextcloud DB_USER=root \
#   DB_PASSWORD=nextcloud_root_password TABLE_PREFIX=oc_ \
#       apps/budgetcheck/scripts/check-db-live.sh
set -euo pipefail

DB_CONTAINER="${DB_CONTAINER:-mariadb}"
DB_NAME="${DB_NAME:-nextcloud}"
DB_USER="${DB_USER:-root}"
DB_PASSWORD="${DB_PASSWORD:-nextcloud_root_password}"
TABLE_PREFIX="${TABLE_PREFIX:-oc_}"
APP_PREFIX="bc_"
MAX_IDENTIFIER_LEN=30

run_sql() {
	docker compose exec -T "${DB_CONTAINER}" \
		mysql -u"${DB_USER}" -p"${DB_PASSWORD}" -N -B "${DB_NAME}" -e "$1"
}

errors=0

# Legacy long column name from before Version1005. Should be gone.
legacy_col=$(run_sql "
SELECT TABLE_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA='${DB_NAME}'
  AND TABLE_NAME LIKE '${TABLE_PREFIX}${APP_PREFIX}%'
  AND COLUMN_NAME='auto_copy_budgets_from_previous_month'")
if [[ -n "${legacy_col}" ]]; then
	echo "ERROR legacy column 'auto_copy_budgets_from_previous_month' still present on:" >&2
	echo "${legacy_col}" >&2
	errors=$((errors + 1))
fi

# All bc_ tables must be within the limit.
table_violations=$(run_sql "
SELECT TABLE_NAME, LENGTH(TABLE_NAME)
FROM information_schema.TABLES
WHERE TABLE_SCHEMA='${DB_NAME}'
  AND TABLE_NAME LIKE '${TABLE_PREFIX}${APP_PREFIX}%'
  AND LENGTH(TABLE_NAME) > ${MAX_IDENTIFIER_LEN}")
if [[ -n "${table_violations}" ]]; then
	echo "ERROR budgetcheck tables exceed ${MAX_IDENTIFIER_LEN} chars:" >&2
	echo "${table_violations}" >&2
	errors=$((errors + 1))
fi

# All indexes on bc_ tables must be bc_-prefixed (or PRIMARY) and within limit.
index_violations=$(run_sql "
SELECT TABLE_NAME, INDEX_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA='${DB_NAME}'
  AND TABLE_NAME LIKE '${TABLE_PREFIX}${APP_PREFIX}%'
  AND INDEX_NAME != 'PRIMARY'
  AND INDEX_NAME NOT LIKE '${APP_PREFIX}%'
GROUP BY TABLE_NAME, INDEX_NAME")
if [[ -n "${index_violations}" ]]; then
	echo "ERROR budgetcheck indexes that are not bc_-prefixed:" >&2
	echo "${index_violations}" >&2
	errors=$((errors + 1))
fi

index_too_long=$(run_sql "
SELECT TABLE_NAME, INDEX_NAME, LENGTH(INDEX_NAME)
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA='${DB_NAME}'
  AND TABLE_NAME LIKE '${TABLE_PREFIX}${APP_PREFIX}%'
  AND INDEX_NAME != 'PRIMARY'
  AND LENGTH(INDEX_NAME) > ${MAX_IDENTIFIER_LEN}
GROUP BY TABLE_NAME, INDEX_NAME")
if [[ -n "${index_too_long}" ]]; then
	echo "ERROR budgetcheck indexes exceed ${MAX_IDENTIFIER_LEN} chars:" >&2
	echo "${index_too_long}" >&2
	errors=$((errors + 1))
fi

# Columns must stay within the limit.
col_violations=$(run_sql "
SELECT TABLE_NAME, COLUMN_NAME, LENGTH(COLUMN_NAME)
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA='${DB_NAME}'
  AND TABLE_NAME LIKE '${TABLE_PREFIX}${APP_PREFIX}%'
  AND LENGTH(COLUMN_NAME) > ${MAX_IDENTIFIER_LEN}")
if [[ -n "${col_violations}" ]]; then
	echo "ERROR budgetcheck columns exceed ${MAX_IDENTIFIER_LEN} chars:" >&2
	echo "${col_violations}" >&2
	errors=$((errors + 1))
fi

if (( errors > 0 )); then
	echo "Live DB audit FAILED with ${errors} issue(s)." >&2
	exit 1
fi

echo "Live DB audit passed: every budgetcheck identifier is bc_-prefixed and within length limits."
