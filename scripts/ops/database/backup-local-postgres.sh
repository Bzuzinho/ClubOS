#!/usr/bin/env bash
set -euo pipefail

log() { printf '[%s] %s\n' "$(date -Is)" "$*"; }
die() { printf '[%s] ERROR: %s\n' "$(date -Is)" "$*" >&2; exit 1; }
require_var() { local name="$1"; [[ -n "${!name:-}" ]] || die "Missing required environment variable: ${name}"; }

LOCAL_DB_HOST="${LOCAL_DB_HOST:-127.0.0.1}"
LOCAL_DB_PORT="${LOCAL_DB_PORT:-5432}"
LOCAL_DB_NAME="${LOCAL_DB_NAME:-clubmanager_prod}"
LOCAL_DB_USER="${LOCAL_DB_USER:-clubmanager_app}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/clubmanager/local}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
require_var LOCAL_DB_PASSWORD

[[ "${LOCAL_DB_HOST}" == "127.0.0.1" || "${LOCAL_DB_HOST}" == "localhost" ]] || die "Refusing to backup non-local host: ${LOCAL_DB_HOST}"
command -v pg_dump >/dev/null 2>&1 || die "pg_dump is required"
command -v sha256sum >/dev/null 2>&1 || die "sha256sum is required"

TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
DUMP_PATH="${BACKUP_DIR}/local-prod-${TIMESTAMP}.dump"

umask 077
mkdir -p "${BACKUP_DIR}"

log "Creating local PostgreSQL backup at ${DUMP_PATH}"
PGPASSWORD="${LOCAL_DB_PASSWORD}" pg_dump -h "${LOCAL_DB_HOST}" -p "${LOCAL_DB_PORT}" -U "${LOCAL_DB_USER}" -d "${LOCAL_DB_NAME}" -Fc --no-owner --no-acl -f "${DUMP_PATH}"

log "Writing checksum"
sha256sum "${DUMP_PATH}" > "${DUMP_PATH}.sha256"
chmod 600 "${DUMP_PATH}" "${DUMP_PATH}.sha256"

log "Applying retention policy: ${RETENTION_DAYS} days"
find "${BACKUP_DIR}" -type f \( -name 'local-prod-*.dump' -o -name 'local-prod-*.dump.sha256' \) -mtime +"${RETENTION_DAYS}" -print -delete

log "Local backup completed"
printf 'DUMP_PATH=%s\nCHECKSUM_PATH=%s\n' "${DUMP_PATH}" "${DUMP_PATH}.sha256"
