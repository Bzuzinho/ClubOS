#!/usr/bin/env bash
set -euo pipefail

log() { printf '[%s] %s\n' "$(date -Is)" "$*"; }
die() { printf '[%s] ERROR: %s\n' "$(date -Is)" "$*" >&2; exit 1; }
require_var() { local name="$1"; [[ -n "${!name:-}" ]] || die "Missing required environment variable: ${name}"; }

LOCAL_DB_HOST="${LOCAL_DB_HOST:-127.0.0.1}"
LOCAL_DB_PORT="${LOCAL_DB_PORT:-5432}"
LOCAL_DB_NAME="${LOCAL_DB_NAME:-clubmanager_prod}"
LOCAL_DB_USER="${LOCAL_DB_USER:-clubmanager_app}"
require_var LOCAL_DB_PASSWORD
require_var DUMP_PATH

[[ "${LOCAL_DB_HOST}" == "127.0.0.1" || "${LOCAL_DB_HOST}" == "localhost" ]] || die "Refusing to restore to non-local host: ${LOCAL_DB_HOST}"
[[ -f "${DUMP_PATH}" ]] || die "Dump file not found: ${DUMP_PATH}"
command -v pg_restore >/dev/null 2>&1 || die "pg_restore is required"
command -v psql >/dev/null 2>&1 || die "psql is required"

RESTORE_ARGS=(--no-owner --no-acl --role="${LOCAL_DB_USER}" --dbname="postgresql://${LOCAL_DB_USER}@${LOCAL_DB_HOST}:${LOCAL_DB_PORT}/${LOCAL_DB_NAME}")
if [[ "${DB1_ALLOW_CLEAN_RESTORE:-false}" == "true" ]]; then
    RESTORE_ARGS=(--clean --if-exists "${RESTORE_ARGS[@]}")
else
    log "Clean restore disabled. Set DB1_ALLOW_CLEAN_RESTORE=true only for a fresh local database."
fi

log "Restoring dump into local PostgreSQL database ${LOCAL_DB_NAME}"
PGPASSWORD="${LOCAL_DB_PASSWORD}" pg_restore "${RESTORE_ARGS[@]}" "${DUMP_PATH}"

log "Running ANALYZE"
PGPASSWORD="${LOCAL_DB_PASSWORD}" psql -h "${LOCAL_DB_HOST}" -p "${LOCAL_DB_PORT}" -U "${LOCAL_DB_USER}" -d "${LOCAL_DB_NAME}" -v ON_ERROR_STOP=1 -c 'ANALYZE;' >/dev/null

log "Listing installed extensions"
PGPASSWORD="${LOCAL_DB_PASSWORD}" psql -h "${LOCAL_DB_HOST}" -p "${LOCAL_DB_PORT}" -U "${LOCAL_DB_USER}" -d "${LOCAL_DB_NAME}" -v ON_ERROR_STOP=1 -c 'select extname, extversion from pg_extension order by extname;'

log "Restore completed"
