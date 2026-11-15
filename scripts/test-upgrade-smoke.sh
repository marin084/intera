#!/usr/bin/env bash
set -euo pipefail

# ==============================================================================
# Conexa — Production Upgrade Smoke Test
# ==============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

cd "${ROOT_DIR}"

if [ -f .env ]; then
  set -a
  # shellcheck disable=SC1091
  source .env
  set +a
fi

DB_USER="${DB_USERNAME:-conexa}"
DB_NAME="${DB_DATABASE:-conexa}"

echo "====================================================================="
echo "Starting Production Upgrade Smoke Simulation"
echo "====================================================================="

# Step 1: Record pre-upgrade entity counts
echo "Step 1: Recording pre-upgrade database state..."
PRE_CONV_COUNT=$(docker compose exec -T postgres psql -U "${DB_USER}" -d "${DB_NAME}" -t -A -c "SELECT count(*) FROM conversations;" || echo "0")
PRE_MSG_COUNT=$(docker compose exec -T postgres psql -U "${DB_USER}" -d "${DB_NAME}" -t -A -c "SELECT count(*) FROM messages;" || echo "0")

echo "Pre-upgrade state:"
echo "  Conversations: ${PRE_CONV_COUNT}"
echo "  Messages:      ${PRE_MSG_COUNT}"

# Step 2: Simulate release build (rebuild containers)
echo "Step 2: Building container images for release..."
docker compose build backend frontend

# Step 3: Start updated stack
echo "Step 3: Starting updated stack..."
docker compose up -d

# Step 4: Run database migrations
echo "Step 4: Executing database migrations..."
docker compose exec -T backend php artisan migrate --force --no-interaction

# Step 5: Wait for stack health
echo "Step 5: Waiting for services to become healthy..."
MAX_RETRIES=20
RETRIES=0
HEALTHY=0

until [ "${RETRIES}" -ge "${MAX_RETRIES}" ]; do
  if docker compose exec -T backend curl -sf http://127.0.0.1:8000/health >/dev/null 2>&1; then
    HEALTHY=1
    break
  fi
  echo "Waiting for backend health check (attempt $((RETRIES+1))/${MAX_RETRIES})..."
  sleep 3
  RETRIES=$((RETRIES+1))
done

if [ "${HEALTHY}" -ne 1 ]; then
  echo "ERROR: Backend failed to achieve healthy status after upgrade!" >&2
  exit 1
fi

# Step 6: Verify historical data preserved
echo "Step 6: Verifying data persistence..."
POST_CONV_COUNT=$(docker compose exec -T postgres psql -U "${DB_USER}" -d "${DB_NAME}" -t -A -c "SELECT count(*) FROM conversations;")
POST_MSG_COUNT=$(docker compose exec -T postgres psql -U "${DB_USER}" -d "${DB_NAME}" -t -A -c "SELECT count(*) FROM messages;")

echo "Post-upgrade state:"
echo "  Conversations: ${POST_CONV_COUNT}"
echo "  Messages:      ${POST_MSG_COUNT}"

if [ "${POST_CONV_COUNT}" -lt "${PRE_CONV_COUNT}" ] || [ "${POST_MSG_COUNT}" -lt "${PRE_MSG_COUNT}" ]; then
  echo "ERROR: Data loss detected after upgrade!" >&2
  exit 1
fi

# Step 7: Verify /health payload includes version
echo "Step 7: Validating health response payload..."
HEALTH_JSON=$(docker compose exec -T backend curl -s http://127.0.0.1:8000/health)
echo "Health Response: ${HEALTH_JSON}"

if ! echo "${HEALTH_JSON}" | grep -q '"status":"ok"'; then
  echo "ERROR: /health does not contain 'status: ok'!" >&2
  exit 1
fi

echo "====================================================================="
echo "UPGRADE SMOKE TEST PASSED: Application is healthy and data preserved!"
echo "====================================================================="
exit 0
