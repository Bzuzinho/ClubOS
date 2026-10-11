#!/usr/bin/env bash
set -Eeuo pipefail

log() { printf '[pre-reset][%s] %s\n' "$(date -Is)" "$*"; }
die() { printf '[pre-reset][%s] ERROR: %s\n' "$(date -Is)" "$*" >&2; exit 1; }

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd -- "${SCRIPT_DIR}/../../.." && pwd)"
ENV_FILE="${ENV_FILE:-${APP_DIR}/.env}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/clubmanager/pre-reset-finance-20260928}"
PG_DUMP_PREFERRED="${PG_DUMP_PREFERRED:-/usr/lib/postgresql/17/bin/pg_dump}"
PG_RESTORE_PREFERRED="${PG_RESTORE_PREFERRED:-/usr/lib/postgresql/17/bin/pg_restore}"

read_env_value() {
    local key="$1"
    local line value
    line="$(grep -m 1 -E "^${key}=" "${ENV_FILE}" 2>/dev/null || true)"
    [[ -n "${line}" ]] || return 0
    value="${line#*=}"
    value="${value%$'\r'}"
    if [[ "${value}" == \"*\" && "${value}" == *\" ]]; then
        value="${value:1:${#value}-2}"
    elif [[ "${value}" == \'*\' && "${value}" == *\' ]]; then
        value="${value:1:${#value}-2}"
    fi
    printf '%s' "${value}"
}

resolve_pg17_tool() {
    local preferred="$1" fallback="$2" tool version major
    if [[ -x "${preferred}" ]]; then
        tool="${preferred}"
    elif command -v "${fallback}" >/dev/null 2>&1; then
        tool="$(command -v "${fallback}")"
    else
        die "PostgreSQL ${fallback} not found"
    fi
    version="$("${tool}" --version)"
    major="$(printf '%s\n' "${version}" | grep -oE '[0-9]+(\.[0-9]+)?' | head -n 1 | cut -d. -f1)"
    [[ "${major}" == "17" ]] || die "PostgreSQL 17 required; found: ${version}"
    printf '%s' "${tool}"
}

[[ -f "${ENV_FILE}" ]] || die "Environment file not found: ${ENV_FILE}"
command -v sha256sum >/dev/null 2>&1 || die "sha256sum is required"

PG_DUMP="$(resolve_pg17_tool "${PG_DUMP_PREFERRED}" pg_dump)"
PG_RESTORE="$(resolve_pg17_tool "${PG_RESTORE_PREFERRED}" pg_restore)"

DB_CONNECTION="$(read_env_value DB_CONNECTION)"
DB_HOST="$(read_env_value DB_HOST)"
DB_PORT="$(read_env_value DB_PORT)"
DB_DATABASE="$(read_env_value DB_DATABASE)"
DB_USERNAME="$(read_env_value DB_USERNAME)"
DB_PASSWORD="$(read_env_value DB_PASSWORD)"

[[ "${DB_CONNECTION}" == "pgsql" ]] || die "DB_CONNECTION must be pgsql"
[[ "${DB_HOST}" == "127.0.0.1" || "${DB_HOST}" == "localhost" ]] || die "DB_HOST must be local PostgreSQL"
[[ -n "${DB_DATABASE}" && -n "${DB_USERNAME}" && -n "${DB_PASSWORD}" ]] || die "Database credentials are incomplete"
DB_PORT="${DB_PORT:-5433}"

umask 077
mkdir -p "${BACKUP_DIR}"
chmod 700 "${BACKUP_DIR}"

TIMESTAMP="$(date -u +%Y%m%d-%H%M%S)"
DUMP_PATH="${BACKUP_DIR}/clubmanager-prod-pre-finance-reset-${TIMESTAMP}.dump"
CHECKSUM_PATH="${DUMP_PATH}.sha256"

cleanup() {
    if [[ "${BACKUP_COMPLETE:-false}" != "true" ]]; then
        rm -f -- "${DUMP_PATH}" "${CHECKSUM_PATH}"
    fi
}
trap cleanup EXIT

log "Creating fresh PostgreSQL 17 snapshot: ${DUMP_PATH}"
PGPASSWORD="${DB_PASSWORD}" "${PG_DUMP}" \
    --host="${DB_HOST}" \
    --port="${DB_PORT}" \
    --username="${DB_USERNAME}" \
    --dbname="${DB_DATABASE}" \
    --format=custom \
    --no-owner \
    --no-acl \
    --file="${DUMP_PATH}"
unset DB_PASSWORD

[[ -s "${DUMP_PATH}" ]] || die "Backup file is missing or empty"
sha256sum "${DUMP_PATH}" > "${CHECKSUM_PATH}"
chmod 600 "${DUMP_PATH}" "${CHECKSUM_PATH}"

(cd "${BACKUP_DIR}" && sha256sum -c "$(basename "${CHECKSUM_PATH}")") >/dev/null
"${PG_RESTORE}" --list "${DUMP_PATH}" >/dev/null || die "pg_restore catalogue validation failed"

BACKUP_COMPLETE=true
trap - EXIT

log "Snapshot validated successfully"
printf 'snapshot_path=%s\n' "${DUMP_PATH}"
printf 'snapshot_sha256=%s\n' "$(awk '{print $1}' "${CHECKSUM_PATH}")"
