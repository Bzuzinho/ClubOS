#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/common.sh"

dr_require_root
dr_load_config
dr_require_pg17_tools
dr_require_command flock
dr_require_command gpg
dr_require_command rclone
dr_require_command tar
dr_require_command sha256sum

LOCK_FILE="${DR_RESTORE_LOCK_FILE:-/run/lock/clubos-dr-restore-test.lock}"
exec 9>"${LOCK_FILE}"
flock -n 9 || dr_die 'já existe um restore test em execução'

WORK_DIR="$(mktemp -d /tmp/clubos-dr-restore.XXXXXX)"
ENCRYPTED_PATH="${WORK_DIR}/archive.tar.gz.gpg"
TAR_PATH="${WORK_DIR}/archive.tar.gz"
EXTRACT_DIR="${WORK_DIR}/extract"
TEST_DB="clubos_dr_restore_$(date -u +%Y%m%d%H%M%S)_$$"
DB_PORT="$(dr_read_app_env DB_PORT)"
DB_PORT="${DB_PORT:-5433}"

cleanup() {
    sudo -u postgres "${DR_DROPDB}" -p "${DB_PORT}" --if-exists "${TEST_DB}" >/dev/null 2>&1 || true
    rm -rf -- "${WORK_DIR}" >/dev/null 2>&1 || true
}
trap cleanup EXIT
umask 077
mkdir -p "${EXTRACT_DIR}"

LATEST_OBJECT="$(
    rclone lsf "${DR_REMOTE_BASE}/daily" --recursive --files-only 2>/dev/null \
        | grep -E '\.tar\.gz\.gpg$' \
        | sort -r \
        | head -n1 \
        || true
)"
[[ -n "${LATEST_OBJECT}" ]] || dr_die 'nenhum arquivo diário encontrado no off-site'
REMOTE_OBJECT="${DR_REMOTE_BASE}/daily/${LATEST_OBJECT}"

dr_log "descarregar arquivo off-site para teste: ${LATEST_OBJECT}"
rclone copyto "${REMOTE_OBJECT}" "${ENCRYPTED_PATH}" --retries 3 --low-level-retries 10
[[ -s "${ENCRYPTED_PATH}" ]] || dr_die 'download off-site vazio'

dr_log 'decifrar arquivo off-site'
gpg \
    --batch \
    --yes \
    --pinentry-mode loopback \
    --passphrase-file "${DR_GPG_PASSPHRASE_FILE}" \
    --decrypt \
    --output "${TAR_PATH}" \
    "${ENCRYPTED_PATH}"
[[ -s "${TAR_PATH}" ]] || dr_die 'arquivo decifrado vazio'

tar -C "${EXTRACT_DIR}" -xzf "${TAR_PATH}"
dr_require_file "${EXTRACT_DIR}/manifest.txt"
dr_require_file "${EXTRACT_DIR}/database/database.dump"
dr_require_file "${EXTRACT_DIR}/database/database.dump.sha256"
dr_require_file "${EXTRACT_DIR}/application/.env"

(
    cd "${EXTRACT_DIR}/database"
    sha256sum -c database.dump.sha256
) >/dev/null
"${DR_PG_RESTORE}" --list "${EXTRACT_DIR}/database/database.dump" >/dev/null

# mktemp creates WORK_DIR as 0700 root. The postgres restore process only needs
# traverse permission on this parent directory to read its own 0600 dump copy.
chgrp postgres "${WORK_DIR}"
chmod 710 "${WORK_DIR}"
TEMP_DUMP="${WORK_DIR}/database-postgres.dump"
install -o postgres -g postgres -m 600 "${EXTRACT_DIR}/database/database.dump" "${TEMP_DUMP}"

sudo -u postgres "${DR_CREATEDB}" -p "${DB_PORT}" "${TEST_DB}"
STARTED="$(date +%s)"
sudo -u postgres "${DR_PG_RESTORE}" \
    -p "${DB_PORT}" \
    --no-owner \
    --no-acl \
    --dbname="${TEST_DB}" \
    "${TEMP_DUMP}"
FINISHED="$(date +%s)"

TABLE_COUNT="$(sudo -u postgres "${DR_PSQL}" -p "${DB_PORT}" -d "${TEST_DB}" -Atqc "select count(*) from information_schema.tables where table_schema='public';")"
MIGRATION_COUNT="$(sudo -u postgres "${DR_PSQL}" -p "${DB_PORT}" -d "${TEST_DB}" -Atqc "select count(*) from migrations;")"
[[ "${TABLE_COUNT}" =~ ^[0-9]+$ && "${TABLE_COUNT}" -gt 0 ]] || dr_die 'restauro sem tabelas públicas'
[[ "${MIGRATION_COUNT}" =~ ^[0-9]+$ && "${MIGRATION_COUNT}" -gt 0 ]] || dr_die 'restauro sem migrations'

STORAGE_FILE_COUNT="$(find "${EXTRACT_DIR}/application/storage-public" -type f 2>/dev/null | wc -l | tr -d ' ')"
RESTORE_SECONDS="$((FINISHED-STARTED))"
dr_state_write last-restore-test-success \
    "object=${LATEST_OBJECT}" \
    "restore_seconds=${RESTORE_SECONDS}" \
    "public_table_count=${TABLE_COUNT}" \
    "migration_count=${MIGRATION_COUNT}" \
    "storage_file_count=${STORAGE_FILE_COUNT}"

dr_log "restore test off-site OK: ${TABLE_COUNT} tabelas, ${MIGRATION_COUNT} migrations, ${RESTORE_SECONDS}s"
