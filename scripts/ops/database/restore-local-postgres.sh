#!/usr/bin/env bash
set -Eeuo pipefail

log() { printf '[%s] %s\n' "$(date -Is)" "$*"; }
die() { printf '[%s] ERROR: %s\n' "$(date -Is)" "$*" >&2; exit 1; }
require_var() { local name="$1"; [[ -n "${!name:-}" ]] || die "Missing required environment variable: ${name}"; }

LOCAL_DB_HOST="${LOCAL_DB_HOST:-127.0.0.1}"
LOCAL_DB_PORT="${LOCAL_DB_PORT:-5432}"
LOCAL_DB_NAME="${LOCAL_DB_NAME:-clubmanager_prod}"
LOCAL_DB_USER="${LOCAL_DB_USER:-clubmanager_app}"
PG_RESTORE_PREFERRED="/usr/lib/postgresql/17/bin/pg_restore"
PSQL_PREFERRED="/usr/lib/postgresql/17/bin/psql"
require_var LOCAL_DB_PASSWORD
require_var DUMP_PATH

[[ "${LOCAL_DB_HOST}" == "127.0.0.1" || "${LOCAL_DB_HOST}" == "localhost" ]] || die "Refusing to restore to non-local host: ${LOCAL_DB_HOST}"
[[ -f "${DUMP_PATH}" ]] || die "Dump file not found: ${DUMP_PATH}"

resolve_pg17_tool() {
    local preferred="$1" fallback="$2" tool version major
    if [[ -x "${preferred}" ]]; then
        tool="${preferred}"
    elif command -v "${fallback}" >/dev/null 2>&1; then
        tool="$(command -v "${fallback}")"
    else
        die "${fallback} is required"
    fi
    version="$("${tool}" --version)"
    major="$(printf '%s\n' "${version}" | grep -oE '[0-9]+(\.[0-9]+)?' | head -n1 | cut -d. -f1)"
    [[ "${major}" == '17' ]] || die "PostgreSQL 17 ${fallback} is required; found: ${version}"
    printf '%s' "${tool}"
}

PG_RESTORE="$(resolve_pg17_tool "${PG_RESTORE_PREFERRED}" pg_restore)"
PSQL="$(resolve_pg17_tool "${PSQL_PREFERRED}" psql)"

CHECKSUM_PATH="${CHECKSUM_PATH:-${DUMP_PATH}.sha256}"
if [[ -f "${CHECKSUM_PATH}" ]]; then
    log "Verifying SHA256 checksum before restore"
    (cd "$(dirname "${DUMP_PATH}")" && sha256sum -c "$(basename "${CHECKSUM_PATH}")") >/dev/null \
        || die "Checksum verification failed: ${DUMP_PATH}"
else
    log "Checksum file not present; continuing only because restore-local-postgres.sh also supports ad-hoc dumps."
fi

"${PG_RESTORE}" --list "${DUMP_PATH}" >/dev/null || die "pg_restore --list validation failed"

RESTORE_ARGS=(
    --no-owner
    --no-acl
    --role="${LOCAL_DB_USER}"
    --host="${LOCAL_DB_HOST}"
    --port="${LOCAL_DB_PORT}"
    --username="${LOCAL_DB_USER}"
    --dbname="${LOCAL_DB_NAME}"
)
if [[ "${DB1_ALLOW_CLEAN_RESTORE:-false}" == "true" ]]; then
    RESTORE_ARGS=(--clean --if-exists "${RESTORE_ARGS[@]}")
else
    log "Clean restore disabled. Set DB1_ALLOW_CLEAN_RESTORE=true only for a fresh local database."
fi

log "Restoring dump with PostgreSQL 17 into local database ${LOCAL_DB_NAME}"
PGPASSWORD="${LOCAL_DB_PASSWORD}" "${PG_RESTORE}" "${RESTORE_ARGS[@]}" "${DUMP_PATH}"

log "Running ANALYZE"
PGPASSWORD="${LOCAL_DB_PASSWORD}" "${PSQL}" -h "${LOCAL_DB_HOST}" -p "${LOCAL_DB_PORT}" -U "${LOCAL_DB_USER}" -d "${LOCAL_DB_NAME}" -v ON_ERROR_STOP=1 -c 'ANALYZE;' >/dev/null

log "Listing installed extensions"
PGPASSWORD="${LOCAL_DB_PASSWORD}" "${PSQL}" -h "${LOCAL_DB_HOST}" -p "${LOCAL_DB_PORT}" -U "${LOCAL_DB_USER}" -d "${LOCAL_DB_NAME}" -v ON_ERROR_STOP=1 -c 'select extname, extversion from pg_extension order by extname;'

log "Restore completed"
