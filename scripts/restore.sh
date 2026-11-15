#!/usr/bin/env bash
set -euo pipefail

# ==============================================================================
# Conexa — Production Complete Restore Script (Database & Storage Volume)
# (Strict Validation: Mandatory Checksums, Storage Integrity & Error Propagation)
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

SOURCE_PROD_DB="${DB_DATABASE:-conexa}"
DB_NAME="${TARGET_DB:-${DB_DATABASE:-conexa}}"
DB_USER="${DB_USER:-${DB_USERNAME:-conexa}}"

BACKUP_FILE="${1:-}"
CONFIRM_FLAG="${2:-}"

if [ -z "${BACKUP_FILE}" ]; then
  echo "Usage: $0 <backup-file.dump> [--confirm-restore]" >&2
  exit 1
fi

# State tracking for atomic coordinated restore and disaster rollback
TARGET_STORAGE_DIR="${TARGET_STORAGE_DIR:-}"
RESTORE_SUCCESS=0
DB_SWAP_STAGE="none"
STORAGE_SWAPPED=0
STORAGE_BACKUP_COMPLETE=0
DB_EXISTS=0
DB_ORIGINALLY_EXISTED=0
DB_STAGING=""
DB_ROLLBACK=""
STAGING_DIR=""
ROLLBACK_DIR=""
STAGING_CONTAINER_DIR=""
ROLLBACK_CONTAINER_DIR=""

emergency_cleanup_and_rollback() {
  local exit_code=$?
  local rollback_ok=1
  local target_exists=0
  local rollback_exists=0
  trap - EXIT INT TERM
  set +e
  if [ "${RESTORE_SUCCESS:-0}" -ne 1 ]; then
    echo "CRITICAL: Restore failed or aborted (code ${exit_code}) before final verification! Initiating coordinated rollback..." >&2

    # 1. Rollback storage if swapped
    if [ "${STORAGE_SWAPPED:-0}" -eq 1 ]; then
      echo "Rolling back storage files to previous snapshot..." >&2
      if [ "${SIMULATE_ROLLBACK_STORAGE_FAILURE:-0}" = "1" ]; then
        echo "CRITICAL: Simulated storage rollback failure; rollback artifacts retained." >&2
        rollback_ok=0
      elif [ -n "${TARGET_STORAGE_DIR}" ]; then
        if [ "${STORAGE_BACKUP_COMPLETE:-0}" -eq 1 ] && [ -d "${ROLLBACK_DIR}" ]; then
          if ! mkdir -p "${TARGET_STORAGE_DIR}" || ! find "${TARGET_STORAGE_DIR}" -mindepth 1 -delete; then
            echo "CRITICAL: Could not clear live storage during rollback; snapshot retained at '${ROLLBACK_DIR}'." >&2
            rollback_ok=0
          elif [ -n "$(ls -A "${ROLLBACK_DIR}" 2>/dev/null)" ] && ! cp -a "${ROLLBACK_DIR}"/. "${TARGET_STORAGE_DIR}/"; then
            echo "CRITICAL: Could not restore storage snapshot; snapshot retained at '${ROLLBACK_DIR}'." >&2
            rollback_ok=0
          fi
        else
          echo "CRITICAL: Storage was swapped but no completed rollback snapshot exists; staging/rollback artifacts retained." >&2
          rollback_ok=0
        fi
      else
        if [ "${STORAGE_BACKUP_COMPLETE:-0}" -eq 1 ]; then
          if ! docker compose exec -T backend sh -e -c "
            mkdir -p /var/www/html/storage/app/public &&
            find /var/www/html/storage/app/public -mindepth 1 -delete &&
            if [ -n \"\$(ls -A '${ROLLBACK_CONTAINER_DIR}' 2>/dev/null)\" ]; then
              cp -a '${ROLLBACK_CONTAINER_DIR}'/. /var/www/html/storage/app/public/
            fi
          " >/dev/null 2>&1; then
            echo "CRITICAL: Could not restore container storage; snapshot retained at '${ROLLBACK_CONTAINER_DIR}'." >&2
            rollback_ok=0
          fi
        else
          echo "CRITICAL: Storage was swapped but no completed container rollback snapshot exists; artifacts retained." >&2
          rollback_ok=0
        fi
      fi
    fi

    # 2. Recover from actual catalog state, including a signal between an ALTER
    #    DATABASE completing and the following shell state assignment.
    if [ "${SIMULATE_ROLLBACK_DB_FAILURE:-0}" = "1" ] && [ "${DB_SWAP_STAGE:-none}" != "none" ]; then
      echo "CRITICAL: Simulated database rollback failure; rollback database retained." >&2
      rollback_ok=0
    elif [ "${DB_SWAP_STAGE:-none}" != "none" ]; then
      rollback_exists=$(docker compose exec -T postgres psql -U "${DB_USER}" -d postgres -t -A -c "SELECT count(*) FROM pg_database WHERE datname='${DB_ROLLBACK}';" 2>/dev/null)
      target_exists=$(docker compose exec -T postgres psql -U "${DB_USER}" -d postgres -t -A -c "SELECT count(*) FROM pg_database WHERE datname='${DB_NAME}';" 2>/dev/null)
      if [ "${rollback_exists}" = "1" ]; then
        echo "Recovering database from retained rollback snapshot..." >&2
        if [ "${target_exists}" = "1" ]; then
          if ! docker compose exec -T postgres psql -U "${DB_USER}" -d postgres -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='${DB_NAME}' AND pid <> pg_backend_pid();" >/dev/null 2>&1 || ! docker compose exec -T postgres dropdb -U "${DB_USER}" "${DB_NAME}" >/dev/null 2>&1; then
            echo "CRITICAL: Could not remove promoted database; rollback database '${DB_ROLLBACK}' retained." >&2
            rollback_ok=0
          fi
        fi
        if [ "${rollback_ok}" -eq 1 ] && ! docker compose exec -T postgres psql -U "${DB_USER}" -d postgres -c "ALTER DATABASE \"${DB_ROLLBACK}\" RENAME TO \"${DB_NAME}\";" >/dev/null 2>&1; then
          echo "CRITICAL: Could not restore database snapshot '${DB_ROLLBACK}' to '${DB_NAME}'; snapshot retained." >&2
          rollback_ok=0
        fi
      elif [ "${DB_ORIGINALLY_EXISTED:-0}" -eq 0 ] && [ "${target_exists}" = "1" ]; then
        if ! docker compose exec -T postgres dropdb -U "${DB_USER}" "${DB_NAME}" >/dev/null 2>&1; then
          echo "CRITICAL: Could not remove partially promoted database '${DB_NAME}'." >&2
          rollback_ok=0
        fi
      fi
    fi

    if [ "${rollback_ok}" -eq 1 ] && [ -n "${DB_STAGING:-}" ]; then
      if ! docker compose exec -T postgres dropdb --if-exists -U "${DB_USER}" "${DB_STAGING}" >/dev/null 2>&1; then
        echo "CRITICAL: Could not remove database staging '${DB_STAGING}'." >&2
        rollback_ok=0
      fi
    fi

    # 3. Clean up staging & rollback storage directories
    if [ "${rollback_ok}" -eq 1 ]; then
      if [ -n "${TARGET_STORAGE_DIR}" ]; then
        [ -z "${STAGING_DIR}" ] || rm -rf "${STAGING_DIR}" || rollback_ok=0
        [ -z "${ROLLBACK_DIR}" ] || rm -rf "${ROLLBACK_DIR}" || rollback_ok=0
      else
        if [ -n "${STAGING_CONTAINER_DIR}" ] || [ -n "${ROLLBACK_CONTAINER_DIR}" ]; then
          docker compose exec -T backend rm -rf "${STAGING_CONTAINER_DIR}" "${ROLLBACK_CONTAINER_DIR}" >/dev/null 2>&1 || rollback_ok=0
        fi
      fi
    fi

    if [ "${rollback_ok}" -eq 1 ]; then
      echo "Coordinated rollback completed; previous database and storage state restored." >&2
    else
      echo "CRITICAL: Coordinated rollback was incomplete. Review retained staging/rollback artifacts and database catalog state before retrying." >&2
      exit 1
    fi
  fi
}
trap emergency_cleanup_and_rollback EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# ==============================================================================
# PHASE 1: STRICT PRE-FLIGHT VALIDATION (BEFORE ANY DESTRUCTIVE OPERATION)
# ==============================================================================

echo "=== Conexa Pre-Restore Strict Verification ==="

# 1. Database dump existence & non-empty
if [ ! -f "${BACKUP_FILE}" ]; then
  echo "ERROR: Backup file '${BACKUP_FILE}' not found. Restore aborted without touching data." >&2
  exit 1
fi

FILE_SIZE=$(wc -c < "${BACKUP_FILE}" | tr -d ' ')
if [ "${FILE_SIZE}" -le 0 ]; then
  echo "ERROR: Backup file '${BACKUP_FILE}' is empty. Restore aborted without touching data." >&2
  exit 1
fi

# 2. Mandatory DB Checksum Verification
CHECKSUM_FILE="${BACKUP_FILE}.sha256"
if [ ! -f "${CHECKSUM_FILE}" ]; then
  echo "ERROR: Mandatory DB checksum file '${CHECKSUM_FILE}' was not found. Restore aborted without touching data." >&2
  exit 1
fi

echo "1/4 Verifying database snapshot SHA-256 checksum..."
BACKUP_DIR="$(dirname "${BACKUP_FILE}")"
BASE_NAME="$(basename "${BACKUP_FILE}")"
CHECKSUM_BASE="$(basename "${CHECKSUM_FILE}")"

if command -v shasum >/dev/null 2>&1; then
  (cd "${BACKUP_DIR}" && shasum -a 256 -c "${CHECKSUM_BASE}") || {
    echo "ERROR: Database SHA-256 checksum verification failed! Restore aborted without touching data." >&2
    exit 1
  }
elif command -v sha256sum >/dev/null 2>&1; then
  (cd "${BACKUP_DIR}" && sha256sum -c "${CHECKSUM_BASE}") || {
    echo "ERROR: Database SHA-256 checksum verification failed! Restore aborted without touching data." >&2
    exit 1
  }
else
  echo "ERROR: Neither shasum nor sha256sum utility found. Restore aborted without touching data." >&2
  exit 1
fi
echo "Database checksum verified: OK"

# 3. Mandatory Companion Storage Archive Existence & Size
STORAGE_FILE="${BACKUP_FILE%.dump}.storage.tar.gz"
if [ ! -f "${STORAGE_FILE}" ]; then
  echo "ERROR: Mandatory storage archive '${STORAGE_FILE}' not found. Database and storage volume must be restored together. Restore aborted without touching data." >&2
  exit 1
fi

STORAGE_SIZE=$(wc -c < "${STORAGE_FILE}" | tr -d ' ')
if [ "${STORAGE_SIZE}" -le 0 ]; then
  echo "ERROR: Storage archive '${STORAGE_FILE}' is empty. Restore aborted without touching data." >&2
  exit 1
fi

# 4. Mandatory Storage Checksum Verification
STORAGE_CHECKSUM_FILE="${STORAGE_FILE}.sha256"
if [ ! -f "${STORAGE_CHECKSUM_FILE}" ]; then
  echo "ERROR: Mandatory storage checksum file '${STORAGE_CHECKSUM_FILE}' not found. Restore aborted without touching data." >&2
  exit 1
fi

echo "2/4 Verifying storage archive SHA-256 checksum..."
STORAGE_CHECKSUM_BASE="$(basename "${STORAGE_CHECKSUM_FILE}")"
if command -v shasum >/dev/null 2>&1; then
  (cd "${BACKUP_DIR}" && shasum -a 256 -c "${STORAGE_CHECKSUM_BASE}") || {
    echo "ERROR: Storage archive SHA-256 checksum verification failed! Restore aborted without touching data." >&2
    exit 1
  }
elif command -v sha256sum >/dev/null 2>&1; then
  (cd "${BACKUP_DIR}" && sha256sum -c "${STORAGE_CHECKSUM_BASE}") || {
    echo "ERROR: Storage archive SHA-256 checksum verification failed! Restore aborted without touching data." >&2
    exit 1
  }
fi
echo "Storage archive checksum verified: OK"

# 5. Mandatory Tar Archive Integrity Test (tar -tzf)
echo "3/4 Performing non-destructive integrity test on storage archive (tar -tzf)..."
if ! tar -tzf "${STORAGE_FILE}" >/dev/null 2>&1; then
  echo "ERROR: Storage archive '${STORAGE_FILE}' failed tar gzip integrity test! Archive is corrupted or truncated. Restore aborted without touching data." >&2
  exit 1
fi
echo "Storage archive integrity verified: OK"

# 6. Non-Destructive Database Snapshot Listing Verification (pg_restore --list)
echo "4/5 Verifying database snapshot structure via non-destructive 'pg_restore --list'..."
if ! docker compose exec -T postgres pg_restore -U "${DB_USER}" --list < "${BACKUP_FILE}" >/dev/null 2>&1; then
  echo "ERROR: Database snapshot '${BACKUP_FILE}' failed 'pg_restore --list' validation! Snapshot is not a valid pg_dump custom/tar archive. Restore aborted without touching target database or storage." >&2
  exit 1
fi
echo "Database snapshot format verified: OK"

# 7. Service Pre-flight Verification
echo "5/5 Verifying required Docker services are running..."
if ! docker compose ps --services --filter "status=running" | grep -q "postgres"; then
  echo "ERROR: PostgreSQL service is not running in Docker Compose. Restore aborted without touching data." >&2
  exit 1
fi

if [ -z "${TARGET_STORAGE_DIR}" ] && ! docker compose ps --services --filter "status=running" | grep -q "backend"; then
  echo "ERROR: Backend container is not running in Docker Compose. Storage restore cannot proceed. Restore aborted without touching data." >&2
  exit 1
fi

echo "All pre-flight validations PASSED. Ready for restore."
echo "-----------------------------------------------------"

# ==============================================================================
# PHASE 2: DESTRUCTIVE CONFIRMATION
# ==============================================================================

echo "====================================================================="
echo "WARNING: RESTORE IS A DESTRUCTIVE OPERATION!"
echo "Target Database: ${DB_NAME}"
echo "Source DB Dump:  ${BACKUP_FILE} (${FILE_SIZE} bytes)"
echo "Source Storage:  ${STORAGE_FILE} (${STORAGE_SIZE} bytes)"
echo "This operation will OVERWRITE existing data and storage attachments."
echo "====================================================================="

if [ "${DB_NAME}" = "${SOURCE_PROD_DB}" ]; then
  if [ "${CONFIRM_FLAG}" != "--confirm-restore" ]; then
    echo "To continue restoring PRODUCTION database '${DB_NAME}', type 'RESTORE' (in capital letters):"
    read -r USER_CONFIRMATION
    if [ "${USER_CONFIRMATION}" != "RESTORE" ]; then
      echo "Restore aborted by user. No changes were made."
      exit 1
    fi
  else
    echo "Production restore confirmation provided via --confirm-restore flag."
  fi
else
  echo "Restoring snapshot into isolated non-production target database '${DB_NAME}'."
fi

# ==============================================================================
# PHASE 3: STAGING STORAGE EXTRACTION (BEFORE ANY DATABASE OR STORAGE WRITE)
# ==============================================================================

echo "=== Phase 3: Staging Storage Archive Extraction ==="
if [ -n "${TARGET_STORAGE_DIR}" ]; then
  echo "Unpacking storage archive into isolated staging directory..."
  STAGING_DIR="${TARGET_STORAGE_DIR}_staging_$$"
  ROLLBACK_DIR="${TARGET_STORAGE_DIR}_rollback_$$"
  mkdir -p "${STAGING_DIR}"

  if [ "${SIMULATE_STORAGE_STAGING_FAILURE:-0}" = "1" ]; then
    echo "SIMULATED FAILURE: Storage staging extraction failed!" >&2
    rm -rf "${STAGING_DIR}"
    exit 1
  fi

  if ! tar -xzf "${STORAGE_FILE}" -C "${STAGING_DIR}"; then
    echo "ERROR: Failed to unpack storage archive into staging! Target database and storage untouched. Restore aborted." >&2
    rm -rf "${STAGING_DIR}"
    exit 1
  fi
  echo "Storage archive extracted into isolated staging directory: OK"
else
  echo "Unpacking storage archive into backend container staging directory..."
  STAGING_CONTAINER_DIR="/var/www/html/storage/app/public_staging_restore_$$"
  ROLLBACK_CONTAINER_DIR="/var/www/html/storage/app/public_rollback_restore_$$"

  if ! docker compose exec -T backend mkdir -p "${STAGING_CONTAINER_DIR}"; then
    echo "ERROR: Failed to create staging directory in backend container! Target database and storage untouched. Restore aborted." >&2
    exit 1
  fi

  if [ "${SIMULATE_STORAGE_STAGING_FAILURE:-0}" = "1" ]; then
    echo "SIMULATED FAILURE: Storage staging extraction failed!" >&2
    docker compose exec -T backend rm -rf "${STAGING_CONTAINER_DIR}" || true
    exit 1
  fi

  if ! docker compose exec -T backend tar -xzf - -C "${STAGING_CONTAINER_DIR}" < "${STORAGE_FILE}"; then
    echo "ERROR: Failed to unpack storage archive into container staging directory! Target database and storage untouched. Restore aborted." >&2
    docker compose exec -T backend rm -rf "${STAGING_CONTAINER_DIR}" || true
    exit 1
  fi
  echo "Storage archive extracted into container staging directory: OK"
fi

# ==============================================================================
# PHASE 4: STAGING DATABASE RESTORE & VALIDATION (TARGET DB UNTOUCHED)
# ==============================================================================

echo "=== Phase 4: Staging Database Restore & Validation ==="
DB_STAGING="${DB_NAME}_staging_$$"
DB_ROLLBACK="${DB_NAME}_rollback_$$"

echo "Creating staging database '${DB_STAGING}'..."
docker compose exec -T postgres createdb -U "${DB_USER}" "${DB_STAGING}"

echo "Restoring database from custom format snapshot into staging database '${DB_STAGING}'..."
set +e
if [ "${SIMULATE_DB_RESTORE_FAILURE:-0}" = "1" ]; then
  echo "SIMULATED FAILURE: pg_restore to staging database failed!" >&2
  PG_RESTORE_STATUS=2
  PG_RESTORE_OUTPUT="Simulated pg_restore failure"
else
  PG_RESTORE_OUTPUT=$(docker compose exec -T postgres pg_restore -U "${DB_USER}" -d "${DB_STAGING}" < "${BACKUP_FILE}" 2>&1)
  PG_RESTORE_STATUS=$?
fi
set -e

# In pg_restore: 0 = success, 1 = warnings (e.g. drop on non-existent object), >= 2 = fatal error
if [ ${PG_RESTORE_STATUS} -gt 1 ]; then
  echo "ERROR: pg_restore to staging database failed fatally with exit code ${PG_RESTORE_STATUS}!" >&2
  echo "${PG_RESTORE_OUTPUT}" >&2
  exit 1
elif [ ${PG_RESTORE_STATUS} -eq 1 ]; then
  echo "Notice: pg_restore completed with notices/clean warnings. Verifying database table integrity..."
fi

# Assert staging database has tables and is healthy
TABLE_COUNT=$(docker compose exec -T postgres psql -U "${DB_USER}" -d "${DB_STAGING}" -t -A -c "SELECT count(*) FROM information_schema.tables WHERE table_schema='public';")
if [ "${TABLE_COUNT}" -le 0 ]; then
  echo "ERROR: Staging database '${DB_STAGING}' has 0 tables after pg_restore! Restore failed." >&2
  exit 1
fi
echo "Staging database restored and verified: ${TABLE_COUNT} public tables present. Target DB '${DB_NAME}' remains 100% untouched."

# ==============================================================================
# PHASE 5: COORDINATED DATABASE & STORAGE SWAP WITH AUTOMATIC ROLLBACK
# ==============================================================================

echo "=== Phase 5: Coordinated Database & Storage Swap with Rollback Protection ==="

# Step 5.1: Backup existing storage to rollback directory BEFORE any swap
if [ -n "${TARGET_STORAGE_DIR}" ]; then
  if [ -d "${TARGET_STORAGE_DIR}" ] && [ -n "$(ls -A "${TARGET_STORAGE_DIR}" 2>/dev/null)" ]; then
    mkdir -p "${ROLLBACK_DIR}"
    if [ "${SIMULATE_STORAGE_BACKUP_FAILURE:-0}" = "1" ]; then
      echo "SIMULATED FAILURE: Backing up existing storage to rollback directory failed!" >&2
      exit 1
    fi
    if ! cp -a "${TARGET_STORAGE_DIR}"/. "${ROLLBACK_DIR}/"; then
      echo "ERROR: Failed to back up existing storage to rollback directory! Aborting without modifying DB or storage." >&2
      exit 1
    fi
  fi
  if [ ! -d "${ROLLBACK_DIR}" ]; then
    # A valid empty snapshot differs from a failed/incomplete backup.
    mkdir -p "${ROLLBACK_DIR}"
  fi
  STORAGE_BACKUP_COMPLETE=1
else
  # Back up container storage to rollback directory before swap
  if ! docker compose exec -T backend sh -e -c "
    mkdir -p /var/www/html/storage/app/public &&
    mkdir -p '${ROLLBACK_CONTAINER_DIR}' &&
    if [ -n \"\$(ls -A /var/www/html/storage/app/public 2>/dev/null)\" ]; then
      if [ \"${SIMULATE_STORAGE_BACKUP_FAILURE:-0}\" = \"1\" ]; then
        echo 'SIMULATED FAILURE: Backing up existing storage to rollback directory failed!' >&2
        exit 1
      fi
      if ! cp -a /var/www/html/storage/app/public/. '${ROLLBACK_CONTAINER_DIR}/'; then
        echo 'ERROR: Failed to back up existing storage to rollback directory! Aborting without modifying DB or storage.' >&2
        exit 1
      fi
    fi
  "; then
    echo "ERROR: Storage backup failed in backend container! Aborting without modifying DB or storage." >&2
    exit 1
  fi
  STORAGE_BACKUP_COMPLETE=1
fi

# Step 5.2: Database Swap (Target DB -> Rollback DB, Staging DB -> Target DB)
echo "Executing atomic database swap with rollback retention..."
DB_EXISTS=$(docker compose exec -T postgres psql -U "${DB_USER}" -d postgres -t -A -c "SELECT count(*) FROM pg_database WHERE datname='${DB_NAME}';")

if [ "${DB_EXISTS}" -gt 0 ]; then
  DB_ORIGINALLY_EXISTED=1
  # Terminate existing connections to target DB to permit rename
  docker compose exec -T postgres psql -U "${DB_USER}" -d postgres -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='${DB_NAME}' AND pid <> pg_backend_pid();" >/dev/null 2>&1 || true
  DB_SWAP_STAGE="primary_renamed"
  docker compose exec -T postgres psql -U "${DB_USER}" -d postgres -c "ALTER DATABASE \"${DB_NAME}\" RENAME TO \"${DB_ROLLBACK}\";"

  if [ "${SIMULATE_SECOND_RENAME_FAILURE:-0}" = "1" ]; then
    echo "SIMULATED FAILURE: Second ALTER DATABASE rename failed!" >&2
    exit 1
  fi

  docker compose exec -T postgres psql -U "${DB_USER}" -d postgres -c "ALTER DATABASE \"${DB_STAGING}\" RENAME TO \"${DB_NAME}\";"
  DB_SWAP_STAGE="swapped"
else
  DB_ORIGINALLY_EXISTED=0
  DB_SWAP_STAGE="swapped"
  docker compose exec -T postgres psql -U "${DB_USER}" -d postgres -c "ALTER DATABASE \"${DB_STAGING}\" RENAME TO \"${DB_NAME}\";"
fi

# Step 5.3: Storage Substitution from Staging (with Coordinated Rollback on Failure)
echo "Executing storage replacement from staging..."
if [ -n "${TARGET_STORAGE_DIR}" ]; then
  mkdir -p "${TARGET_STORAGE_DIR}"
  STORAGE_SWAPPED=1
  find "${TARGET_STORAGE_DIR}" -mindepth 1 -delete

  if [ "${SIMULATE_STORAGE_COPY_FAILURE:-0}" = "1" ]; then
    echo "SIMULATED FAILURE: Copying from staging failed!" >&2
    exit 1
  fi

  if ! cp -a "${STAGING_DIR}"/. "${TARGET_STORAGE_DIR}/"; then
    echo "ERROR: Failed to copy storage files to '${TARGET_STORAGE_DIR}'!" >&2
    exit 1
  fi
else
  if [ "${SIMULATE_STORAGE_COPY_FAILURE:-0}" = "1" ]; then
    STORAGE_SWAPPED=1
    echo "SIMULATED FAILURE: Copying from staging failed!" >&2
    exit 1
  fi

  STORAGE_SWAPPED=1
  if ! docker compose exec -T backend sh -e -c "
    find /var/www/html/storage/app/public -mindepth 1 -delete &&
    cp -a '${STAGING_CONTAINER_DIR}'/. /var/www/html/storage/app/public/
  "; then
    echo "ERROR: Storage copy failed in backend container!" >&2
    exit 1
  fi

  if ! docker compose exec -T backend chown -R www-data:www-data /var/www/html/storage/app/public; then
    echo "WARNING: Failed to set ownership on /var/www/html/storage/app/public." >&2
  fi
fi
echo "Database and storage swapped into place. Holding rollback copies pending health check..."

# ==============================================================================
# PHASE 6: POST-RESTORE VALIDATION (READ-ONLY GET /health/ready) & ROLLBACK RETENTION
# ==============================================================================

echo "=== Phase 6: Post-Restore Health Verification & Finalization ==="

if [ "${DB_NAME}" = "${SOURCE_PROD_DB}" ] || [ "${CHECK_MIGRATIONS:-0}" = "1" ] || [ "${SIMULATE_MIGRATE_FAILURE:-0}" = "1" ]; then
  echo "Running any subsequent migrations if required..."
  if [ "${SIMULATE_MIGRATE_FAILURE:-0}" = "1" ]; then
    echo "SIMULATED FAILURE: artisan migrate failed!" >&2
    exit 1
  fi
  docker compose exec -T backend php artisan migrate --force --no-interaction
fi

if [ "${DB_NAME}" = "${SOURCE_PROD_DB}" ] || [ "${CHECK_HEALTH_READY:-0}" = "1" ] || [ "${SIMULATE_HEALTH_CHECK_FAILURE:-0}" = "1" ]; then
  echo "Verifying application readiness via read-only GET /health/ready..."
  if [ "${SIMULATE_HEALTH_CHECK_FAILURE:-0}" = "1" ]; then
    echo "SIMULATED FAILURE: /health/ready check failed!" >&2
    exit 1
  fi

  HEALTH_URL="${HEALTH_CHECK_URL:-http://127.0.0.1:8000/health/ready}"
  if [ -n "${HEALTH_CHECK_URL:-}" ]; then
    HEALTH_RESPONSE=$(curl -sS --max-time 10 -w "\nHTTP_STATUS:%{http_code}" "${HEALTH_URL}" 2>&1 || true)
  elif [ "${DB_NAME}" = "${SOURCE_PROD_DB}" ]; then
    HEALTH_RESPONSE=$(docker compose exec -T backend curl -sS --max-time 10 -w "\nHTTP_STATUS:%{http_code}" "${HEALTH_URL}" 2>&1 || true)
  else
    echo "ERROR: Isolated restore health check requires HEALTH_CHECK_URL; refusing to query the normal backend." >&2
    exit 1
  fi
  HTTP_CODE=$(echo "${HEALTH_RESPONSE}" | grep "HTTP_STATUS:" | cut -d':' -f2 | tr -d ' \r\n')
  BODY=$(echo "${HEALTH_RESPONSE}" | grep -v "HTTP_STATUS:")

  if [ "${HTTP_CODE}" != "200" ]; then
    echo "ERROR: /health/ready returned HTTP ${HTTP_CODE} (expected 200)!" >&2
    echo "Response body: ${BODY}" >&2
    exit 1
  elif ! echo "${BODY}" | grep -Eq '"status"[[:space:]]*:[[:space:]]*"ready"'; then
    echo "ERROR: /health/ready response body status is not 'ready'!" >&2
    echo "Response body: ${BODY}" >&2
    exit 1
  elif ! echo "${BODY}" | grep -Eq '"database"[[:space:]]*:[[:space:]]*"ok"'; then
    echo "ERROR: /health/ready database check is not 'ok'!" >&2
    echo "Response body: ${BODY}" >&2
    exit 1
  else
    echo "Read-only GET /health/ready check PASSED: HTTP 200, status=ready, database=ok."
  fi
fi

# Step 6.2: Finalization — Health check passed, safe to purge rollback copies
RESTORE_SUCCESS=1
echo "Post-restore checks confirmed. Purging rollback database and temporary storage backups..."
if [ "${DB_EXISTS}" -gt 0 ]; then
  docker compose exec -T postgres dropdb --if-exists -U "${DB_USER}" "${DB_ROLLBACK}" >/dev/null 2>&1 || true
fi

if [ -n "${TARGET_STORAGE_DIR}" ]; then
  [ -z "${STAGING_DIR}" ] || rm -rf "${STAGING_DIR}" || echo "WARNING: Could not remove storage staging directory '${STAGING_DIR}'." >&2
  [ -z "${ROLLBACK_DIR}" ] || rm -rf "${ROLLBACK_DIR}" || echo "WARNING: Could not remove storage rollback directory '${ROLLBACK_DIR}'." >&2
else
  if [ -n "${STAGING_CONTAINER_DIR}" ] || [ -n "${ROLLBACK_CONTAINER_DIR}" ]; then
    docker compose exec -T backend rm -rf "${STAGING_CONTAINER_DIR}" "${ROLLBACK_CONTAINER_DIR}" >/dev/null 2>&1 || echo "WARNING: Could not remove temporary storage artifacts from backend container." >&2
  fi
fi

echo "====================================================================="
echo "Stack restore completed successfully with 100% data integrity!"
echo "====================================================================="
exit 0
