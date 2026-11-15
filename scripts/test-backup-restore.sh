#!/usr/bin/env bash
set -euo pipefail

# ==============================================================================
# Conexa — Automated Disaster Recovery & Backup/Restore Test Script
# (100% Isolated Verification: Source Database is NEVER Modified or Seeded)
# Verifies:
#  1. Normal backup and restore with full data integrity.
#  2. Pre-flight rejection when DB checksum is missing.
#  3. Pre-flight rejection when storage archive is missing.
#  4. Pre-flight rejection when storage tar is corrupted.
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
RANDOM_SUFFIX="$(date +%s)_$RANDOM"
ISOLATED_SRC_DB="conexa_dr_src_${RANDOM_SUFFIX}"
ISOLATED_TGT_DB="conexa_dr_tgt_${RANDOM_SUFFIX}"
TEST_BACKUP_DIR="${ROOT_DIR}/backups/dr_test_${RANDOM_SUFFIX}"
TEST_STORAGE_DIR="${TEST_BACKUP_DIR}/isolated_source_storage"
RESTORED_STORAGE_DIR="${TEST_BACKUP_DIR}/restored_storage"
EMPTY_STORAGE_DIR="${TEST_BACKUP_DIR}/empty_storage"
TEST_MARKER_CONTENT="conexa_dr_marker_${RANDOM_SUFFIX}"
HEALTH_FIXTURE_OUTPUT="${TEST_BACKUP_DIR}/health_fixture.port"
HEALTH_FIXTURE_PID=""

mkdir -p "${TEST_BACKUP_DIR}" "${TEST_STORAGE_DIR}" "${RESTORED_STORAGE_DIR}"

echo "====================================================================="
echo "Starting Automated Isolated Backup & Restore Verification"
echo "Production Source DB:   ${DB_DATABASE:-conexa} (PROTECTED - WILL NOT BE TOUCHED)"
echo "Isolated Scratch DB 1:  ${ISOLATED_SRC_DB}"
echo "Isolated Scratch DB 2:  ${ISOLATED_TGT_DB}"
echo "Test Backup Directory:  ${TEST_BACKUP_DIR}"
echo "====================================================================="

cleanup() {
  echo "Cleaning up isolated scratch databases and test files..."
  if [ -n "${HEALTH_FIXTURE_PID}" ]; then
    kill "${HEALTH_FIXTURE_PID}" >/dev/null 2>&1 || true
    wait "${HEALTH_FIXTURE_PID}" >/dev/null 2>&1 || true
  fi
  docker compose exec -T postgres dropdb --if-exists -U "${DB_USER}" "${ISOLATED_SRC_DB}" >/dev/null 2>&1 || true
  docker compose exec -T postgres dropdb --if-exists -U "${DB_USER}" "${ISOLATED_TGT_DB}" >/dev/null 2>&1 || true
  rm -rf "${TEST_BACKUP_DIR}"
}
trap cleanup EXIT

# Local, loopback-only HTTP fixture: health-check assertions never contact the
# regular app container or its configured DB (including fbotmaker).
python3 "${SCRIPT_DIR}/test-health-ready-server.py" >"${HEALTH_FIXTURE_OUTPUT}" 2>/dev/null &
HEALTH_FIXTURE_PID=$!
for _ in $(seq 1 50); do
  [ -s "${HEALTH_FIXTURE_OUTPUT}" ] && break
  sleep 0.1
done
if [ ! -s "${HEALTH_FIXTURE_OUTPUT}" ]; then
  echo "FAIL: isolated health-check fixture did not start." >&2
  exit 1
fi
HEALTH_FIXTURE_PORT="$(head -n 1 "${HEALTH_FIXTURE_OUTPUT}")"
HEALTH_FIXTURE_URL="http://127.0.0.1:${HEALTH_FIXTURE_PORT}"

# Step 1: Create isolated scratch source DB
echo "Step 1: Creating isolated scratch database '${ISOLATED_SRC_DB}'..."
docker compose exec -T postgres createdb -U "${DB_USER}" "${ISOLATED_SRC_DB}"

# Seed sample schema and records ONLY into isolated scratch database
echo "Seeding test schema and records ONLY into '${ISOLATED_SRC_DB}'..."
docker compose exec -T postgres psql -U "${DB_USER}" -d "${ISOLATED_SRC_DB}" -c "
  CREATE TABLE dr_audit_test (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    payload JSONB,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  );
  INSERT INTO dr_audit_test (name, payload) VALUES
    ('Record Alpha', '{\"status\": \"active\", \"code\": 101}'),
    ('Record Beta', '{\"status\": \"pending\", \"code\": 102}'),
    ('Record Gamma', '{\"status\": \"completed\", \"code\": 103}');
" >/dev/null

# Create test storage file strictly in isolated host directory
echo "Creating test files strictly in isolated directory (not in production storage)..."
echo "${TEST_MARKER_CONTENT}" > "${TEST_STORAGE_DIR}/dr_marker.txt"
echo "Sample media binary content" > "${TEST_STORAGE_DIR}/sample_attachment.bin"

# Count baseline entities in scratch source DB
SRC_COUNT=$(docker compose exec -T postgres psql -U "${DB_USER}" -d "${ISOLATED_SRC_DB}" -t -A -c "SELECT count(*) FROM dr_audit_test;")
echo "Baseline row count in scratch DB '${ISOLATED_SRC_DB}': ${SRC_COUNT}"

# Step 2: Generate backup dump for isolated scratch database
echo "Step 2: Generating backup dump for isolated scratch database..."
BACKUP_FILE="${TEST_BACKUP_DIR}/conexa_${ISOLATED_SRC_DB}.dump"
CHECKSUM_FILE="${BACKUP_FILE}.sha256"
STORAGE_FILE="${TEST_BACKUP_DIR}/conexa_${ISOLATED_SRC_DB}.storage.tar.gz"
STORAGE_CHECKSUM_FILE="${STORAGE_FILE}.sha256"

# Dump database
docker compose exec -T postgres pg_dump -U "${DB_USER}" -Fc "${ISOLATED_SRC_DB}" > "${BACKUP_FILE}"
chmod 600 "${BACKUP_FILE}"
(cd "${TEST_BACKUP_DIR}" && shasum -a 256 "$(basename "${BACKUP_FILE}")" > "$(basename "${CHECKSUM_FILE}")")

# Archive isolated storage directory
(cd "${TEST_STORAGE_DIR}" && tar -czf "${STORAGE_FILE}" .)
chmod 600 "${STORAGE_FILE}"
(cd "${TEST_BACKUP_DIR}" && shasum -a 256 "$(basename "${STORAGE_FILE}")" > "$(basename "${STORAGE_CHECKSUM_FILE}")")

echo "Backup dump created: $(wc -c < "${BACKUP_FILE}" | tr -d ' ') bytes"
echo "Storage archive created: $(wc -c < "${STORAGE_FILE}" | tr -d ' ') bytes"

# Step 3: Test pre-flight failure guards (Security & Integrity Verification)
echo "Step 3: Testing pre-flight security guards..."

# 3a. Test refusal when DB checksum file is missing
echo "  [Test 3a] Verifying restore refusal when DB checksum file is missing..."
mv "${CHECKSUM_FILE}" "${CHECKSUM_FILE}.bak"
if TARGET_DB="${ISOLATED_TGT_DB}" "${SCRIPT_DIR}/restore.sh" "${BACKUP_FILE}" --confirm-restore >/dev/null 2>&1; then
  echo "FAIL: restore.sh should have aborted when DB checksum was missing!" >&2
  exit 1
fi
mv "${CHECKSUM_FILE}.bak" "${CHECKSUM_FILE}"
echo "  [Test 3a] Refusal on missing DB checksum: PASSED"

# 3b. Test refusal when companion storage file is missing
echo "  [Test 3b] Verifying restore refusal when storage archive is missing..."
mv "${STORAGE_FILE}" "${STORAGE_FILE}.bak"
if TARGET_DB="${ISOLATED_TGT_DB}" "${SCRIPT_DIR}/restore.sh" "${BACKUP_FILE}" --confirm-restore >/dev/null 2>&1; then
  echo "FAIL: restore.sh should have aborted when storage archive was missing!" >&2
  exit 1
fi
mv "${STORAGE_FILE}.bak" "${STORAGE_FILE}"
echo "  [Test 3b] Refusal on missing storage archive: PASSED"

# 3c. Test refusal when storage tar is corrupted
echo "  [Test 3c] Verifying restore refusal when storage archive fails tar integrity test..."
echo "CORRUPTED_TAR_DATA" > "${TEST_BACKUP_DIR}/corrupted.storage.tar.gz"
(cd "${TEST_BACKUP_DIR}" && shasum -a 256 "corrupted.storage.tar.gz" > "${STORAGE_CHECKSUM_FILE}")
cp "${STORAGE_FILE}" "${STORAGE_FILE}.real"
cp "${TEST_BACKUP_DIR}/corrupted.storage.tar.gz" "${STORAGE_FILE}"
if TARGET_DB="${ISOLATED_TGT_DB}" "${SCRIPT_DIR}/restore.sh" "${BACKUP_FILE}" --confirm-restore >/dev/null 2>&1; then
  echo "FAIL: restore.sh should have aborted when storage tar was corrupted!" >&2
  exit 1
fi
mv "${STORAGE_FILE}.real" "${STORAGE_FILE}"
(cd "${TEST_BACKUP_DIR}" && shasum -a 256 "$(basename "${STORAGE_FILE}")" > "$(basename "${STORAGE_CHECKSUM_FILE}")")
echo "  [Test 3c] Refusal on corrupted storage tar: PASSED"

# Step 4: Create isolated scratch target database & storage with pre-existing canary data
echo "Step 4: Creating isolated target database '${ISOLATED_TGT_DB}' with canary data..."
docker compose exec -T postgres createdb -U "${DB_USER}" "${ISOLATED_TGT_DB}"
docker compose exec -T postgres psql -U "${DB_USER}" -d "${ISOLATED_TGT_DB}" -c "
  CREATE TABLE canary_pre_restore (id INT PRIMARY KEY, marker VARCHAR(50));
  INSERT INTO canary_pre_restore VALUES (1, 'canary_db_intact');
" >/dev/null

mkdir -p "${RESTORED_STORAGE_DIR}"
echo "canary_storage_intact" > "${RESTORED_STORAGE_DIR}/canary_storage.txt"

# 3d. Test refusal on corrupt DB dump format via non-destructive pg_restore --list (BEFORE any DB/storage write)
echo "  [Test 3d] Verifying refusal when DB dump format is corrupt via pg_restore --list..."
CORRUPT_DUMP="${TEST_BACKUP_DIR}/corrupted_format.dump"
CORRUPT_DUMP_CHECKSUM="${CORRUPT_DUMP}.sha256"
CORRUPT_STORAGE="${TEST_BACKUP_DIR}/corrupted_format.storage.tar.gz"
CORRUPT_STORAGE_CHECKSUM="${CORRUPT_STORAGE}.sha256"
echo "NOT_A_VALID_POSTGRES_DUMP_FORMAT" > "${CORRUPT_DUMP}"
(cd "${TEST_BACKUP_DIR}" && shasum -a 256 "$(basename "${CORRUPT_DUMP}")" > "$(basename "${CORRUPT_DUMP_CHECKSUM}")")
cp "${STORAGE_FILE}" "${CORRUPT_STORAGE}"
(cd "${TEST_BACKUP_DIR}" && shasum -a 256 "$(basename "${CORRUPT_STORAGE}")" > "$(basename "${CORRUPT_STORAGE_CHECKSUM}")")

if TARGET_DB="${ISOLATED_TGT_DB}" TARGET_STORAGE_DIR="${RESTORED_STORAGE_DIR}" "${SCRIPT_DIR}/restore.sh" "${CORRUPT_DUMP}" --confirm-restore >/dev/null 2>&1; then
  echo "FAIL: restore.sh should have aborted on pg_restore --list failure!" >&2
  exit 1
fi

# Verify canary DB table and canary storage were 100% untouched
CANARY_DB_CHECK=$(docker compose exec -T postgres psql -U "${DB_USER}" -d "${ISOLATED_TGT_DB}" -t -A -c "SELECT marker FROM canary_pre_restore WHERE id=1;" 2>/dev/null || true)
if [ "${CANARY_DB_CHECK}" != "canary_db_intact" ]; then
  echo "FAIL: Target DB was modified when pg_restore --list failed! Previous DB was not preserved." >&2
  exit 1
fi

if [ ! -f "${RESTORED_STORAGE_DIR}/canary_storage.txt" ] || [ "$(cat "${RESTORED_STORAGE_DIR}/canary_storage.txt")" != "canary_storage_intact" ]; then
  echo "FAIL: Target storage was modified when pg_restore --list failed! Previous storage was not preserved." >&2
  exit 1
fi
rm -f "${CORRUPT_DUMP}" "${CORRUPT_DUMP_CHECKSUM}" "${CORRUPT_STORAGE}" "${CORRUPT_STORAGE_CHECKSUM}"
echo "  [Test 3d] Corrupt dump rejected by pg_restore --list; prior DB and storage preserved intact: PASSED"

# 3e. Test refusal when storage staging unpack fails BEFORE any DB write
echo "  [Test 3e] Verifying that failure during storage staging extraction aborts BEFORE any DB write..."
if SIMULATE_STORAGE_STAGING_FAILURE=1 TARGET_DB="${ISOLATED_TGT_DB}" TARGET_STORAGE_DIR="${RESTORED_STORAGE_DIR}" "${SCRIPT_DIR}/restore.sh" "${BACKUP_FILE}" --confirm-restore >/dev/null 2>&1; then
  echo "FAIL: restore.sh should have aborted when storage staging extraction failed!" >&2
  exit 1
fi

CANARY_DB_CHECK2=$(docker compose exec -T postgres psql -U "${DB_USER}" -d "${ISOLATED_TGT_DB}" -t -A -c "SELECT marker FROM canary_pre_restore WHERE id=1;" 2>/dev/null || true)
if [ "${CANARY_DB_CHECK2}" != "canary_db_intact" ]; then
  echo "FAIL: Target DB was modified or wiped when storage staging failed! DB write occurred before staging was verified." >&2
  exit 1
fi

if [ ! -f "${RESTORED_STORAGE_DIR}/canary_storage.txt" ] || [ "$(cat "${RESTORED_STORAGE_DIR}/canary_storage.txt")" != "canary_storage_intact" ]; then
  echo "FAIL: Target storage was modified when storage staging failed! Previous storage was not preserved." >&2
  exit 1
fi
echo "  [Test 3e] Staging unpack failure aborted before DB write; prior DB and storage preserved intact: PASSED"

# 3f. Test refusal when pg_restore to staging DB fails (Target DB and storage remain 100% untouched)
echo "  [Test 3f] Verifying that failure during pg_restore preserves target DB and storage 100% intact..."
if SIMULATE_DB_RESTORE_FAILURE=1 TARGET_DB="${ISOLATED_TGT_DB}" TARGET_STORAGE_DIR="${RESTORED_STORAGE_DIR}" "${SCRIPT_DIR}/restore.sh" "${BACKUP_FILE}" --confirm-restore >/dev/null 2>&1; then
  echo "FAIL: restore.sh should have aborted when pg_restore to staging DB failed!" >&2
  exit 1
fi

CANARY_DB_CHECK3=$(docker compose exec -T postgres psql -U "${DB_USER}" -d "${ISOLATED_TGT_DB}" -t -A -c "SELECT marker FROM canary_pre_restore WHERE id=1;" 2>/dev/null || true)
if [ "${CANARY_DB_CHECK3}" != "canary_db_intact" ]; then
  echo "FAIL: Target DB was modified or wiped when pg_restore failed! Target DB was not protected by staging." >&2
  exit 1
fi

if [ ! -f "${RESTORED_STORAGE_DIR}/canary_storage.txt" ] || [ "$(cat "${RESTORED_STORAGE_DIR}/canary_storage.txt")" != "canary_storage_intact" ]; then
  echo "FAIL: Target storage was modified when pg_restore failed! Previous storage was not preserved." >&2
  exit 1
fi
echo "  [Test 3f] pg_restore failure aborted without modifying target DB or storage: PASSED"

# 3g. Test refusal when storage backup to rollback directory fails BEFORE swap or deletion
echo "  [Test 3g] Verifying that storage backup failure aborts BEFORE any DB swap or storage delete..."
if SIMULATE_STORAGE_BACKUP_FAILURE=1 TARGET_DB="${ISOLATED_TGT_DB}" TARGET_STORAGE_DIR="${RESTORED_STORAGE_DIR}" "${SCRIPT_DIR}/restore.sh" "${BACKUP_FILE}" --confirm-restore >/dev/null 2>&1; then
  echo "FAIL: restore.sh should have aborted when storage backup failed!" >&2
  exit 1
fi

CANARY_DB_CHECK4=$(docker compose exec -T postgres psql -U "${DB_USER}" -d "${ISOLATED_TGT_DB}" -t -A -c "SELECT marker FROM canary_pre_restore WHERE id=1;" 2>/dev/null || true)
if [ "${CANARY_DB_CHECK4}" != "canary_db_intact" ]; then
  echo "FAIL: Target DB was modified when storage backup failed! DB swap occurred prematurely." >&2
  exit 1
fi

if [ ! -f "${RESTORED_STORAGE_DIR}/canary_storage.txt" ] || [ "$(cat "${RESTORED_STORAGE_DIR}/canary_storage.txt")" != "canary_storage_intact" ]; then
  echo "FAIL: Target storage was deleted when storage backup failed! find -delete ran before successful backup." >&2
  exit 1
fi
echo "  [Test 3g] Storage backup failure aborted before DB swap or delete; prior DB and storage preserved intact: PASSED"

# 3h. Test coordinated rollback when second ALTER DATABASE rename fails (EXIT trap recovers DB_ROLLBACK)
echo "  [Test 3h] Verifying EXIT trap recovers DB when second ALTER DATABASE rename fails..."
if SIMULATE_SECOND_RENAME_FAILURE=1 TARGET_DB="${ISOLATED_TGT_DB}" TARGET_STORAGE_DIR="${RESTORED_STORAGE_DIR}" "${SCRIPT_DIR}/restore.sh" "${BACKUP_FILE}" --confirm-restore >/dev/null 2>&1; then
  echo "FAIL: restore.sh should have aborted when second rename failed!" >&2
  exit 1
fi

CANARY_DB_CHECK5=$(docker compose exec -T postgres psql -U "${DB_USER}" -d "${ISOLATED_TGT_DB}" -t -A -c "SELECT marker FROM canary_pre_restore WHERE id=1;" 2>/dev/null || true)
if [ "${CANARY_DB_CHECK5}" != "canary_db_intact" ]; then
  echo "FAIL: Target DB was left missing or corrupted when second rename failed! EXIT trap did not revert DB_ROLLBACK." >&2
  exit 1
fi

if [ ! -f "${RESTORED_STORAGE_DIR}/canary_storage.txt" ] || [ "$(cat "${RESTORED_STORAGE_DIR}/canary_storage.txt")" != "canary_storage_intact" ]; then
  echo "FAIL: Target storage was modified when second rename failed!" >&2
  exit 1
fi
echo "  [Test 3h] Second rename failure caught by EXIT trap; prior DB and storage preserved intact: PASSED"

# 3i. Test coordinated rollback when storage copy from staging fails AFTER DB swap
echo "  [Test 3i] Verifying coordinated DB & storage rollback when storage copy from staging fails..."
if SIMULATE_STORAGE_COPY_FAILURE=1 TARGET_DB="${ISOLATED_TGT_DB}" TARGET_STORAGE_DIR="${RESTORED_STORAGE_DIR}" "${SCRIPT_DIR}/restore.sh" "${BACKUP_FILE}" --confirm-restore >/dev/null 2>&1; then
  echo "FAIL: restore.sh should have aborted when storage copy failed!" >&2
  exit 1
fi

CANARY_DB_CHECK6=$(docker compose exec -T postgres psql -U "${DB_USER}" -d "${ISOLATED_TGT_DB}" -t -A -c "SELECT marker FROM canary_pre_restore WHERE id=1;" 2>/dev/null || true)
if [ "${CANARY_DB_CHECK6}" != "canary_db_intact" ]; then
  echo "FAIL: Target DB was not rolled back when storage copy failed! DB was left in new state while storage failed." >&2
  exit 1
fi

if [ ! -f "${RESTORED_STORAGE_DIR}/canary_storage.txt" ] || [ "$(cat "${RESTORED_STORAGE_DIR}/canary_storage.txt")" != "canary_storage_intact" ]; then
  echo "FAIL: Target storage was not rolled back when storage copy failed! Storage rollback failed." >&2
  exit 1
fi
echo "  [Test 3i] Storage copy failure triggered coordinated DB & storage rollback; both preserved intact: PASSED"

# 3j. Test coordinated rollback when artisan migrate fails before health check (EXIT trap rollback)
echo "  [Test 3j] Verifying coordinated DB & storage rollback when artisan migrate fails..."
if SIMULATE_MIGRATE_FAILURE=1 CHECK_MIGRATIONS=1 TARGET_DB="${ISOLATED_TGT_DB}" TARGET_STORAGE_DIR="${RESTORED_STORAGE_DIR}" "${SCRIPT_DIR}/restore.sh" "${BACKUP_FILE}" --confirm-restore >/dev/null 2>&1; then
  echo "FAIL: restore.sh should have aborted when migrate failed!" >&2
  exit 1
fi

CANARY_DB_CHECK7=$(docker compose exec -T postgres psql -U "${DB_USER}" -d "${ISOLATED_TGT_DB}" -t -A -c "SELECT marker FROM canary_pre_restore WHERE id=1;" 2>/dev/null || true)
if [ "${CANARY_DB_CHECK7}" != "canary_db_intact" ]; then
  echo "FAIL: Target DB was not rolled back when migrate failed! DB was left half-migrated." >&2
  exit 1
fi

if [ ! -f "${RESTORED_STORAGE_DIR}/canary_storage.txt" ] || [ "$(cat "${RESTORED_STORAGE_DIR}/canary_storage.txt")" != "canary_storage_intact" ]; then
  echo "FAIL: Target storage was not rolled back when migrate failed!" >&2
  exit 1
fi
echo "  [Test 3j] Migrate failure caught by EXIT trap; coordinated DB & storage rollback preserved both intact: PASSED"

# 3k. Test real HTTP unhealthy response (not a simulated restore flag).
echo "  [Test 3k] Verifying coordinated rollback when isolated GET /health/ready returns HTTP 503..."
if CHECK_HEALTH_READY=1 HEALTH_CHECK_URL="${HEALTH_FIXTURE_URL}/unhealthy" TARGET_DB="${ISOLATED_TGT_DB}" TARGET_STORAGE_DIR="${RESTORED_STORAGE_DIR}" "${SCRIPT_DIR}/restore.sh" "${BACKUP_FILE}" --confirm-restore >/dev/null 2>&1; then
  echo "FAIL: restore.sh should have aborted when post-restore health check failed!" >&2
  exit 1
fi

CANARY_DB_CHECK8=$(docker compose exec -T postgres psql -U "${DB_USER}" -d "${ISOLATED_TGT_DB}" -t -A -c "SELECT marker FROM canary_pre_restore WHERE id=1;" 2>/dev/null || true)
if [ "${CANARY_DB_CHECK8}" != "canary_db_intact" ]; then
  echo "FAIL: Target DB was not rolled back when post-restore health check failed!" >&2
  exit 1
fi

if [ ! -f "${RESTORED_STORAGE_DIR}/canary_storage.txt" ] || [ "$(cat "${RESTORED_STORAGE_DIR}/canary_storage.txt")" != "canary_storage_intact" ]; then
  echo "FAIL: Target storage was not rolled back when post-restore health check failed!" >&2
  exit 1
fi
echo "  [Test 3k] HTTP 503 triggered coordinated DB & storage rollback; both preserved intact: PASSED"

# 3l. HTTP 200 with a non-ready body must also roll back.
echo "  [Test 3l] Verifying that HTTP 200 with database=down is rejected..."
if CHECK_HEALTH_READY=1 HEALTH_CHECK_URL="${HEALTH_FIXTURE_URL}/bad-body" TARGET_DB="${ISOLATED_TGT_DB}" TARGET_STORAGE_DIR="${RESTORED_STORAGE_DIR}" "${SCRIPT_DIR}/restore.sh" "${BACKUP_FILE}" --confirm-restore >/dev/null 2>&1; then
  echo "FAIL: restore.sh accepted a health response whose body was not ready!" >&2
  exit 1
fi
CANARY_DB_CHECK9=$(docker compose exec -T postgres psql -U "${DB_USER}" -d "${ISOLATED_TGT_DB}" -t -A -c "SELECT marker FROM canary_pre_restore WHERE id=1;" 2>/dev/null || true)
if [ "${CANARY_DB_CHECK9}" != "canary_db_intact" ] || [ ! -f "${RESTORED_STORAGE_DIR}/canary_storage.txt" ]; then
  echo "FAIL: Invalid health body did not preserve the prior DB and storage state." >&2
  exit 1
fi
echo "  [Test 3l] Non-ready HTTP 200 body rejected; rollback preserved DB and storage: PASSED"

# 3m. An originally empty storage directory must roll back to empty too.
mkdir -p "${EMPTY_STORAGE_DIR}"
echo "  [Test 3m] Verifying rollback to an originally empty storage directory..."
if SIMULATE_STORAGE_COPY_FAILURE=1 TARGET_DB="${ISOLATED_TGT_DB}" TARGET_STORAGE_DIR="${EMPTY_STORAGE_DIR}" "${SCRIPT_DIR}/restore.sh" "${BACKUP_FILE}" --confirm-restore >/dev/null 2>&1; then
  echo "FAIL: simulated storage copy failure unexpectedly succeeded for empty prior storage." >&2
  exit 1
fi
if [ -n "$(find "${EMPTY_STORAGE_DIR}" -mindepth 1 -maxdepth 1 -print -quit)" ]; then
  echo "FAIL: rollback did not restore the empty pre-restore storage state." >&2
  exit 1
fi
echo "  [Test 3m] Empty storage state restored after failure: PASSED"

# Add obsolete file to test clean replacement during valid restore
echo "obsolete_garbage_content" > "${RESTORED_STORAGE_DIR}/obsolete_pre_existing_file.txt"

# Step 5: Execute full valid restore.sh targeted at '${ISOLATED_TGT_DB}' with read-only GET /health/ready check
echo "Step 5: Executing full valid restore.sh targeted at '${ISOLATED_TGT_DB}' (validating read-only GET /health/ready)..."
CHECK_HEALTH_READY=1 HEALTH_CHECK_URL="${HEALTH_FIXTURE_URL}/health/ready" TARGET_DB="${ISOLATED_TGT_DB}" TARGET_STORAGE_DIR="${RESTORED_STORAGE_DIR}" "${SCRIPT_DIR}/restore.sh" "${BACKUP_FILE}" --confirm-restore

# Step 6: Verify restored entity count in target database
echo "Step 6: Verifying restored database entities..."
REST_COUNT=$(docker compose exec -T postgres psql -U "${DB_USER}" -d "${ISOLATED_TGT_DB}" -t -A -c "SELECT count(*) FROM dr_audit_test;")
echo "Restored row count in target DB '${ISOLATED_TGT_DB}': ${REST_COUNT}"

if [ "${SRC_COUNT}" != "${REST_COUNT}" ]; then
  echo "FAIL: Row count mismatch between source and restored databases!" >&2
  exit 1
fi

# Step 7: Verify restored storage archive contents
echo "Step 7: Verifying restored storage contents and clean replacement..."
if [ ! -f "${RESTORED_STORAGE_DIR}/dr_marker.txt" ]; then
  echo "FAIL: Restored marker file not found!" >&2
  exit 1
fi

if [ -f "${RESTORED_STORAGE_DIR}/obsolete_pre_existing_file.txt" ]; then
  echo "FAIL: Obsolete file from prior state was preserved during restore! Storage replacement did not clean obsolete files." >&2
  exit 1
fi
echo "  [Test 7] Obsolete files cleaned during storage replacement: PASSED"

RESTORED_CONTENT=$(cat "${RESTORED_STORAGE_DIR}/dr_marker.txt")
if [ "${RESTORED_CONTENT}" != "${TEST_MARKER_CONTENT}" ]; then
  echo "FAIL: Storage file marker content mismatch!" >&2
  exit 1
fi

# Step 8: Verify storage failure modes (clean rollback and backup failure abort before delete)
echo "Step 8: Verifying failure modes with coordinated DB & storage preservation..."

# 8a: Failure during copy triggers rollback to prior storage AND prior DB
echo "  [Test 8a] Verifying that failure during copy from staging triggers rollback of both DB and storage..."
echo "vital_storage_pre_restore_state" > "${RESTORED_STORAGE_DIR}/vital_prior_state.txt"

if SIMULATE_STORAGE_COPY_FAILURE=1 TARGET_DB="${ISOLATED_TGT_DB}" TARGET_STORAGE_DIR="${RESTORED_STORAGE_DIR}" "${SCRIPT_DIR}/restore.sh" "${BACKUP_FILE}" --confirm-restore >/dev/null 2>&1; then
  echo "FAIL: restore.sh should have returned non-zero error code on simulated storage failure!" >&2
  exit 1
fi

if [ ! -f "${RESTORED_STORAGE_DIR}/vital_prior_state.txt" ]; then
  echo "FAIL: Prior storage was not restored intact upon copy failure! Rollback failed." >&2
  exit 1
fi

PRIOR_CHECK=$(cat "${RESTORED_STORAGE_DIR}/vital_prior_state.txt")
if [ "${PRIOR_CHECK}" != "vital_storage_pre_restore_state" ]; then
  echo "FAIL: Prior storage content was corrupted during rollback!" >&2
  exit 1
fi

# Verify target DB was also cleanly rolled back to its prior state (3 rows)
STEP8A_DB_COUNT=$(docker compose exec -T postgres psql -U "${DB_USER}" -d "${ISOLATED_TGT_DB}" -t -A -c "SELECT count(*) FROM dr_audit_test;" 2>/dev/null || true)
if [ "${STEP8A_DB_COUNT}" != "3" ]; then
  echo "FAIL: Target DB was not preserved intact during storage copy rollback! Row count was ${STEP8A_DB_COUNT}, expected 3." >&2
  exit 1
fi
echo "  [Test 8a] Copy failure triggers clean rollback; both DB and storage preserved intact: PASSED"

# 8b: Failure during backup to rollback directory aborts BEFORE deleting any files or swapping DB
echo "  [Test 8b] Verifying that failure backing up to rollback directory aborts BEFORE deleting files or swapping DB..."
echo "critical_file_before_backup_failure" > "${RESTORED_STORAGE_DIR}/critical_file.txt"

if SIMULATE_STORAGE_BACKUP_FAILURE=1 TARGET_DB="${ISOLATED_TGT_DB}" TARGET_STORAGE_DIR="${RESTORED_STORAGE_DIR}" "${SCRIPT_DIR}/restore.sh" "${BACKUP_FILE}" --confirm-restore >/dev/null 2>&1; then
  echo "FAIL: restore.sh should have returned non-zero error code when backup to rollback failed!" >&2
  exit 1
fi

if [ ! -f "${RESTORED_STORAGE_DIR}/critical_file.txt" ]; then
  echo "FAIL: Critical file was deleted even though backup to rollback failed! find -delete ran before successful backup." >&2
  exit 1
fi

CRITICAL_CHECK=$(cat "${RESTORED_STORAGE_DIR}/critical_file.txt")
if [ "${CRITICAL_CHECK}" != "critical_file_before_backup_failure" ]; then
  echo "FAIL: Critical file content was modified!" >&2
  exit 1
fi

# Verify target DB was also not modified or swapped
STEP8B_DB_COUNT=$(docker compose exec -T postgres psql -U "${DB_USER}" -d "${ISOLATED_TGT_DB}" -t -A -c "SELECT count(*) FROM dr_audit_test;" 2>/dev/null || true)
if [ "${STEP8B_DB_COUNT}" != "3" ]; then
  echo "FAIL: Target DB was modified when storage backup failed! Row count was ${STEP8B_DB_COUNT}, expected 3." >&2
  exit 1
fi
echo "  [Test 8b] Backup failure aborts before delete/swap; both DB and storage preserved intact: PASSED"

# 8c: A failed DB rollback must report incomplete recovery and retain artifacts.
echo "  [Test 8c] Verifying failed rollback reports the problem and retains recovery artifacts..."
ROLLBACK_FAILURE_LOG="${TEST_BACKUP_DIR}/rollback_failure.log"
if SIMULATE_STORAGE_COPY_FAILURE=1 SIMULATE_ROLLBACK_DB_FAILURE=1 TARGET_DB="${ISOLATED_TGT_DB}" TARGET_STORAGE_DIR="${RESTORED_STORAGE_DIR}" "${SCRIPT_DIR}/restore.sh" "${BACKUP_FILE}" --confirm-restore >"${ROLLBACK_FAILURE_LOG}" 2>&1; then
  echo "FAIL: simulated DB rollback failure unexpectedly returned success." >&2
  exit 1
fi
if ! grep -q 'Coordinated rollback was incomplete' "${ROLLBACK_FAILURE_LOG}"; then
  echo "FAIL: restore did not clearly report the incomplete rollback." >&2
  exit 1
fi
RETAINED_ROLLBACK_DB="$(docker compose exec -T postgres psql -U "${DB_USER}" -d postgres -t -A -c "SELECT datname FROM pg_database WHERE datname LIKE '${ISOLATED_TGT_DB}_rollback_%' ORDER BY datname LIMIT 1;")"
RETAINED_ROLLBACK_STORAGE="$(find "${TEST_BACKUP_DIR}" -maxdepth 1 -type d -name 'restored_storage_rollback_*' -print -quit)"
if [ -z "${RETAINED_ROLLBACK_DB}" ] || [ -z "${RETAINED_ROLLBACK_STORAGE}" ]; then
  echo "FAIL: rollback failure did not retain the database and storage recovery artifacts." >&2
  exit 1
fi
echo "  [Test 8c] Incomplete rollback reported; recovery DB and storage snapshot retained: PASSED"

# This test intentionally verified artifact retention; clean only its unique scratch artifacts.
docker compose exec -T postgres psql -U "${DB_USER}" -d postgres -c "DROP DATABASE IF EXISTS \"${RETAINED_ROLLBACK_DB}\";" >/dev/null
RETAINED_STAGING_DBS="$(docker compose exec -T postgres psql -U "${DB_USER}" -d postgres -t -A -c "SELECT datname FROM pg_database WHERE datname LIKE '${ISOLATED_TGT_DB}_staging_%';")"
while IFS= read -r staging_db; do
  [ -z "${staging_db}" ] || docker compose exec -T postgres psql -U "${DB_USER}" -d postgres -c "DROP DATABASE IF EXISTS \"${staging_db}\";" >/dev/null
done <<< "${RETAINED_STAGING_DBS}"
rm -rf "${RETAINED_ROLLBACK_STORAGE}" "${RESTORED_STORAGE_DIR}_staging_"*

echo "====================================================================="
echo "ALL DR VERIFICATIONS PASSED: Mandatory checksums, storage integrity,"
echo "error propagation, clean replacement, and rollback on failure verified 100%."
echo "Production database '${DB_DATABASE:-conexa}' was NEVER touched or modified."
echo "====================================================================="
exit 0
