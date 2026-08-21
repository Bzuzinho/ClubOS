#!/usr/bin/env bash
set -Eeuo pipefail

log() { printf '[%s] %s\n' "$(date -Is)" "$*"; }
die() { printf '[%s] ERROR: %s\n' "$(date -Is)" "$*" >&2; exit 1; }

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd -- "${SCRIPT_DIR}/../../.." && pwd)"
ENV_FILE="${ENV_FILE:-${APP_DIR}/.env}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/clubmanager/postgres-local}"
LOCK_FILE="${LOCK_FILE:-/tmp/clubmanager-postgres-backup.lock}"
PG_DUMP_PREFERRED="/usr/lib/postgresql/17/bin/pg_dump"
PG_RESTORE_PREFERRED="/usr/lib/postgresql/17/bin/pg_restore"
BACKUPS_TO_KEEP=7

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
        die "PostgreSQL ${fallback} was not found"
    fi

    version="$("${tool}" --version)"
    major="$(printf '%s\n' "${version}" | grep -oE '[0-9]+(\.[0-9]+)?' | head -n 1 | cut -d. -f1)"
    [[ "${major}" == "17" ]] || die "PostgreSQL 17 ${fallback} is required; found: ${version}"
    printf '%s' "${tool}"
}

validate_dump() {
    local dump="$1" checksum="${dump}.sha256"

    [[ -s "${dump}" && -s "${checksum}" ]] || die "Backup or checksum is incomplete: ${dump}"
    (cd "${BACKUP_DIR}" && sha256sum -c "$(basename "${checksum}")") >/dev/null \
        || die "Checksum verification failed: ${dump}"
    "${PG_RESTORE}" --list "${dump}" >/dev/null \
        || die "pg_restore --list validation failed: ${dump}"
}

[[ -f "${ENV_FILE}" ]] || die "Production environment file not found: ${ENV_FILE}"
command -v flock >/dev/null 2>&1 || die "flock is required"
command -v sha256sum >/dev/null 2>&1 || die "sha256sum is required"

PG_DUMP="$(resolve_pg17_tool "${PG_DUMP_PREFERRED}" pg_dump)"
PG_RESTORE="$(resolve_pg17_tool "${PG_RESTORE_PREFERRED}" pg_restore)"

exec 9>"${LOCK_FILE}"
if ! flock -n 9; then
    log "Another local PostgreSQL backup is already running; nothing to do."
    exit 0
fi

DB_CONNECTION="$(read_env_value DB_CONNECTION)"
DB_HOST="$(read_env_value DB_HOST)"
DB_PORT="$(read_env_value DB_PORT)"
DB_DATABASE="$(read_env_value DB_DATABASE)"
DB_USERNAME="$(read_env_value DB_USERNAME)"
DB_PASSWORD="$(read_env_value DB_PASSWORD)"

[[ "${DB_CONNECTION}" == "pgsql" ]] || die "DB_CONNECTION must be pgsql"
[[ "${DB_HOST}" == "127.0.0.1" || "${DB_HOST}" == "localhost" ]] || die "DB_HOST must point to local PostgreSQL (127.0.0.1 or localhost)"
[[ -n "${DB_DATABASE}" ]] || die "DB_DATABASE is missing from ${ENV_FILE}"
[[ -n "${DB_USERNAME}" ]] || die "DB_USERNAME is missing from ${ENV_FILE}"
[[ -n "${DB_PASSWORD}" ]] || die "DB_PASSWORD is missing from ${ENV_FILE}"
DB_PORT="${DB_PORT:-5433}"

umask 077
mkdir -p "${BACKUP_DIR}"
[[ -d "${BACKUP_DIR}" && -w "${BACKUP_DIR}" ]] || die "Backup directory is not writable: ${BACKUP_DIR}"

TODAY_UTC="$(date -u +%Y%m%d)"
mapfile -t TODAY_BACKUPS < <(
    find "${BACKUP_DIR}" -maxdepth 1 -type f -name "clubmanager-prod-${TODAY_UTC}-*.dump" -print
)
if (( ${#TODAY_BACKUPS[@]} > 0 )); then
    [[ ${#TODAY_BACKUPS[@]} -eq 1 ]] || die "More than one backup already exists for ${TODAY_UTC}; manual review is required"
    validate_dump "${TODAY_BACKUPS[0]}"
    log "A valid daily backup already exists for ${TODAY_UTC}; checksum and pg_restore catalogue are OK."
    ls -lh -- "${TODAY_BACKUPS[0]}" "${TODAY_BACKUPS[0]}.sha256"
    exit 0
fi

TIMESTAMP="$(date -u +%Y%m%d-%H%M%S)"
DUMP_PATH="${BACKUP_DIR}/clubmanager-prod-${TIMESTAMP}.dump"
CHECKSUM_PATH="${DUMP_PATH}.sha256"

cleanup_incomplete_backup() {
    if [[ "${BACKUP_COMPLETE:-false}" != "true" ]]; then
        rm -f -- "${DUMP_PATH}" "${CHECKSUM_PATH}"
    fi
}
trap cleanup_incomplete_backup EXIT

log "Creating PostgreSQL 17 custom backup: ${DUMP_PATH}"
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

[[ -s "${DUMP_PATH}" ]] || die "Backup file is missing or empty: ${DUMP_PATH}"

log "Creating SHA256 checksum"
sha256sum "${DUMP_PATH}" > "${CHECKSUM_PATH}"
[[ -s "${CHECKSUM_PATH}" ]] || die "Checksum was not created: ${CHECKSUM_PATH}"
chmod 600 "${DUMP_PATH}" "${CHECKSUM_PATH}"

log "Validating checksum and PostgreSQL 17 restore catalogue"
validate_dump "${DUMP_PATH}"

mapfile -t BACKUPS < <(
    find "${BACKUP_DIR}" -maxdepth 1 -type f -name 'clubmanager-prod-*.dump' -printf '%T@ %p\n' \
        | sort -rn \
        | cut -d' ' -f2-
)

if (( ${#BACKUPS[@]} > BACKUPS_TO_KEEP )); then
    log "Removing backups beyond the ${BACKUPS_TO_KEEP} most recent"
    for OLD_DUMP in "${BACKUPS[@]:BACKUPS_TO_KEEP}"; do
        log "Removing ${OLD_DUMP}"
        rm -f -- "${OLD_DUMP}" "${OLD_DUMP}.sha256"
    done
fi

mapfile -t REMAINING_BACKUPS < <(
    find "${BACKUP_DIR}" -maxdepth 1 -type f -name 'clubmanager-prod-*.dump' -printf '%T@ %p\n' \
        | sort -rn \
        | cut -d' ' -f2-
)
(( ${#REMAINING_BACKUPS[@]} <= BACKUPS_TO_KEEP )) || die "Retention validation failed: more than ${BACKUPS_TO_KEEP} dumps remain"

BACKUP_COMPLETE=true
trap - EXIT
log "Backup completed and restore catalogue validated; ${#REMAINING_BACKUPS[@]} daily dump(s) retained"
ls -lh -- "${DUMP_PATH}" "${CHECKSUM_PATH}"
