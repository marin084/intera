#!/usr/bin/env bash
set -euo pipefail

# ==============================================================================
# Conexa — Production Concurrency Verification Script
# ==============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

cd "${ROOT_DIR}"

BASE_URL="${1:-http://localhost:8000}"
CONCURRENCY=20
TOTAL_REQUESTS=60

echo "=== Conexa Concurrency Verification ==="
echo "Target Base URL:   ${BASE_URL}"
echo "Total Requests:    ${TOTAL_REQUESTS}"
echo "Concurrency Level: ${CONCURRENCY}"
echo "------------------------------------------"

TEMP_DIR=$(mktemp -d)
trap 'rm -rf "${TEMP_DIR}"' EXIT

ENDPOINTS=(
  "/health"
  "/health/ready"
  "/api/health"
)

# Launch requests in parallel
REQUEST_INDEX=0
for i in $(seq 1 "${TOTAL_REQUESTS}"); do
  EP_IDX=$(( (i - 1) % ${#ENDPOINTS[@]} ))
  EP="${ENDPOINTS[$EP_IDX]}"
  
  (
    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}${EP}" || echo "000")
    echo "${HTTP_CODE}" > "${TEMP_DIR}/res_${i}.txt"
  ) &
  
  # Throttle concurrency pool
  if (( i % CONCURRENCY == 0 )); then
    wait
  fi
done
wait

# Aggregate results
TOTAL_SUCCESS=0
TOTAL_FAILURES=0
SERVER_ERRORS=0

for res_file in "${TEMP_DIR}"/res_*.txt; do
  CODE=$(cat "${res_file}")
  if [[ "${CODE}" =~ ^2[0-9]{2}$ ]]; then
    TOTAL_SUCCESS=$((TOTAL_SUCCESS + 1))
  elif [[ "${CODE}" =~ ^5[0-9]{2}$ ]]; then
    SERVER_ERRORS=$((SERVER_ERRORS + 1))
    TOTAL_FAILURES=$((TOTAL_FAILURES + 1))
  else
    TOTAL_FAILURES=$((TOTAL_FAILURES + 1))
  fi
done

echo "------------------------------------------"
echo "Results:"
echo "  concurrent requests: ${CONCURRENCY}"
echo "  total requests:      ${TOTAL_REQUESTS}"
echo "  successful (2xx):    ${TOTAL_SUCCESS}"
echo "  failures:            ${TOTAL_FAILURES}"
echo "  server errors:       ${SERVER_ERRORS}"
echo "=========================================="

if [ "${TOTAL_FAILURES}" -gt 0 ]; then
  echo "FAIL: Concurrency verification encountered errors." >&2
  exit 1
fi

echo "PASS: Multi-worker PHP-FPM handled all concurrent requests without error."
exit 0
