#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

PROFILE="${1:-all}"
OUTPUT_JSON="${2:-${ROOT_DIR}/storage/load_test_results.json}"

mkdir -p "${ROOT_DIR}/storage"

echo "=== Conexa Conversational Engine Load Testing ==="
echo "Target Profile: ${PROFILE}"
echo "Output Report:  ${OUTPUT_JSON}"
echo "-------------------------------------------------"

cd "${ROOT_DIR}/backend"

# If DB is not reachable on localhost:5432, safely fallback to local SQLite database
if [ "${DB_CONNECTION:-}" = "sqlite" ] || ! nc -z -w2 "${DB_HOST:-127.0.0.1}" "${DB_PORT:-5432}" 2>/dev/null; then
    export DB_CONNECTION=sqlite
    export DB_DATABASE="database/database.sqlite"
fi

php artisan flows:load-test --profile="${PROFILE}" --output-json="${OUTPUT_JSON}"

echo "================================================="
echo "Load test run completed successfully."
