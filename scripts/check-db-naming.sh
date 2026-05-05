#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MIGRATION_DIR="${APP_ROOT}/lib/Migration"

if [[ ! -d "${MIGRATION_DIR}" ]]; then
	echo "Migration directory not found: ${MIGRATION_DIR}" >&2
	exit 1
fi

MAX_IDENTIFIER_LEN=30
STRICT_PREFIX="bc_"
errors=0

while IFS= read -r file; do
	while IFS= read -r table; do
		[[ -z "${table}" ]] && continue
		if [[ "${table}" != ${STRICT_PREFIX}* ]]; then
			echo "ERROR ${file}: table '${table}' must use '${STRICT_PREFIX}' prefix." >&2
			errors=$((errors + 1))
		fi
		if (( ${#table} > MAX_IDENTIFIER_LEN )); then
			echo "ERROR ${file}: table '${table}' length ${#table} exceeds ${MAX_IDENTIFIER_LEN}." >&2
			errors=$((errors + 1))
		fi
	done < <(rg -o --replace '$1' "createTable\\('([a-zA-Z0-9_]+)'\\)" "${file}")

	while IFS= read -r ident; do
		[[ -z "${ident}" ]] && continue
		if [[ "${ident}" != ${STRICT_PREFIX}* ]]; then
			echo "ERROR ${file}: identifier '${ident}' must use '${STRICT_PREFIX}' prefix." >&2
			errors=$((errors + 1))
		fi
		if (( ${#ident} > MAX_IDENTIFIER_LEN )); then
			echo "ERROR ${file}: identifier '${ident}' length ${#ident} exceeds ${MAX_IDENTIFIER_LEN}." >&2
			errors=$((errors + 1))
		fi
	done < <(rg -o --replace '$1' "(?:setPrimaryKey|addIndex|addUniqueIndex|addForeignKeyConstraint)\\([^\\n]*'([a-zA-Z0-9_]+)'\\)" "${file}")
done < <(rg --files "${MIGRATION_DIR}" -g "Version*.php" | sort)

if (( errors > 0 )); then
	echo "DB naming check failed with ${errors} issue(s)." >&2
	exit 1
fi

echo "DB naming check passed for budgetcheck."
