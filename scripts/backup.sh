#!/usr/bin/env bash
set -euo pipefail

# ==============================================================================
# Conexa — Production Complete Backup Script (Database & Storage Volume)
# ==============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

cd "${ROOT_DIR}"

if [ -f .env ]; then
  # Load env vars safely without printing secrets
  set -a
  # shellcheck disable=SC1091
  source .env
  set +a
fi

BACKUP_DIR="${BACKUP_DIR:-./backups}"
mkdir -p "${BACKUP_DIR}"

TIMESTAMP="$(date -u +"%Y%m%d_%H%M%SZ")"
APP_VER="${APP_VERSION:-1.0.0}"
DB_NAME="${DB_NAME:-${DB_DATABASE:-conexa}}"
DB_USER="${DB_USER:-${DB_USERNAME:-conexa}}"

BACKUP_FILE="${BACKUP_DIR}/conexa_${DB_NAME}_${TIMESTAMP}.dump"
CHECKSUM_FILE="${BACKUP_FILE}.sha256"
STORAGE_FILE="${BACKUP_DIR}/conexa_${DB_NAME}_${TIMESTAMP}.storage.tar.gz"
STORAGE_CHECKSUM_FILE="${STORAGE_FILE}.sha256"
META_FILE="${BACKUP_FILE}.meta"

echo "=== Conexa Complete Stack Backup ==="
echo "Timestamp:        ${TIMESTAMP}"
echo "Database:         ${DB_NAME}"
echo "App Version:      ${APP_VER}"
echo "Database Target:  ${BACKUP_FILE}"
echo "Storage Target:   ${STORAGE_FILE}"
echo "------------------------------------"

# 1. Pre-flight verification: PostgreSQL service must be running
if ! docker compose ps --services --filter "status=running" | grep -q "postgres"; then
  echo "ERROR: PostgreSQL service is not running in Docker Compose. Backup aborted." >&2
  exit 1
fi

# 2. Pre-flight verification: Backend service must be running for storage volume access
if ! docker compose ps --services --filter "status=running" | grep -q "backend"; then
  echo "ERROR: Backend container is not running in Docker Compose. Storage backup cannot proceed without backend service." >&2
  exit 1
fi

# Verify storage directory exists inside container
if ! docker compose exec -T backend test -d /var/www/html/storage/app/public; then
  echo "ERROR: Storage directory /var/www/html/storage/app/public does not exist in backend container." >&2
  exit 1
fi

# 3. Database backup via pg_dump (custom format)
echo "Creating database snapshot using pg_dump (custom format)..."
if ! docker compose exec -T postgres pg_dump -U "${DB_USER}" -Fc "${DB_NAME}" > "${BACKUP_FILE}"; then
  echo "ERROR: pg_dump command failed." >&2
  rm -f "${BACKUP_FILE}"
  exit 1
fi

FILE_SIZE=$(wc -c < "${BACKUP_FILE}" | tr -d ' ')
if [ "${FILE_SIZE}" -le 0 ]; then
  echo "ERROR: Database backup file is empty." >&2
  rm -f "${BACKUP_FILE}"
  exit 1
fi
chmod 600 "${BACKUP_FILE}"

# SHA-256 for DB dump
if command -v shasum >/dev/null 2>&1; then
  (cd "${BACKUP_DIR}" && shasum -a 256 "$(basename "${BACKUP_FILE}")" > "$(basename "${CHECKSUM_FILE}")")
elif command -v sha256sum >/dev/null 2>&1; then
  (cd "${BACKUP_DIR}" && sha256sum "$(basename "${BACKUP_FILE}")" > "$(basename "${CHECKSUM_FILE}")")
else
  echo "ERROR: Neither shasum nor sha256sum utility found." >&2
  rm -f "${BACKUP_FILE}"
  exit 1
fi
chmod 600 "${CHECKSUM_FILE}"
CHECKSUM="$(awk '{print $1}' "${CHECKSUM_FILE}")"

# 4. Storage volume backup (storage_data: attachments, media, audio, images)
echo "Archiving storage volume (storage_data: /var/www/html/storage/app/public)..."
if ! docker compose exec -T backend tar -czf - -C /var/www/html/storage/app/public . > "${STORAGE_FILE}"; then
  echo "ERROR: Failed to archive storage_data from backend container." >&2
  rm -f "${BACKUP_FILE}" "${CHECKSUM_FILE}" "${STORAGE_FILE}"
  exit 1
fi

STORAGE_SIZE=$(wc -c < "${STORAGE_FILE}" | tr -d ' ')
if [ "${STORAGE_SIZE}" -le 0 ]; then
  echo "ERROR: Storage archive file is empty. Never create empty tar archives." >&2
  rm -f "${BACKUP_FILE}" "${CHECKSUM_FILE}" "${STORAGE_FILE}"
  exit 1
fi

# Test storage archive integrity immediately
if ! tar -tzf "${STORAGE_FILE}" >/dev/null 2>&1; then
  echo "ERROR: Storage archive '${STORAGE_FILE}' failed integrity test (tar -tzf). Archive is corrupted." >&2
  rm -f "${BACKUP_FILE}" "${CHECKSUM_FILE}" "${STORAGE_FILE}"
  exit 1
fi
chmod 600 "${STORAGE_FILE}"

# SHA-256 for storage archive
if command -v shasum >/dev/null 2>&1; then
  (cd "${BACKUP_DIR}" && shasum -a 256 "$(basename "${STORAGE_FILE}")" > "$(basename "${STORAGE_CHECKSUM_FILE}")")
elif command -v sha256sum >/dev/null 2>&1; then
  (cd "${BACKUP_DIR}" && sha256sum "$(basename "${STORAGE_FILE}")" > "$(basename "${STORAGE_CHECKSUM_FILE}")")
else
  echo "ERROR: Neither shasum nor sha256sum utility found for storage archive." >&2
  rm -f "${BACKUP_FILE}" "${CHECKSUM_FILE}" "${STORAGE_FILE}" "${STORAGE_CHECKSUM_FILE}"
  exit 1
fi
chmod 600 "${STORAGE_CHECKSUM_FILE}"
STORAGE_CHECKSUM="$(awk '{print $1}' "${STORAGE_CHECKSUM_FILE}")"

# 5. Write complete metadata file
cat <<EOF > "${META_FILE}"
timestamp_utc: ${TIMESTAMP}
app_version: ${APP_VER}
database: ${DB_NAME}
db_file: $(basename "${BACKUP_FILE}")
db_size_bytes: ${FILE_SIZE}
db_sha256: ${CHECKSUM}
storage_file: $(basename "${STORAGE_FILE}")
storage_size_bytes: ${STORAGE_SIZE}
storage_sha256: ${STORAGE_CHECKSUM}
EOF
chmod 600 "${META_FILE}"

echo "Complete backup finished successfully!"
echo "Database Size:    ${FILE_SIZE} bytes (SHA: ${CHECKSUM})"
echo "Storage Size:     ${STORAGE_SIZE} bytes (SHA: ${STORAGE_CHECKSUM})"
echo "Saved to:         ${BACKUP_FILE}"
echo "Storage saved to: ${STORAGE_FILE}"
echo "Metadata:         ${META_FILE}"
echo "===================================="
exit 0
