#!/usr/bin/env bash
set -euo pipefail

log() { printf '[%s] %s\n' "$(date -Is)" "$*"; }
die() { printf '[%s] ERROR: %s\n' "$(date -Is)" "$*" >&2; exit 1; }
require_var() { local name="$1"; [[ -n "${!name:-}" ]] || die "Missing required environment variable: ${name}"; }

require_var NEON_DATABASE_URL
BACKUP_DIR="${BACKUP_DIR:-/var/backups/clubmanager}"
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
DUMP_PATH="${BACKUP_DIR}/neon-prod-${TIMESTAMP}.dump"
SCHEMA_PATH="${BACKUP_DIR}/neon-prod-${TIMESTAMP}.schema.sql"

command -v pg_dump >/dev/null 2>&1 || die "pg_dump is required"
command -v sha256sum >/dev/null 2>&1 || die "sha256sum is required"

umask 077
mkdir -p "${BACKUP_DIR}"

log "Creating Neon production custom dump at ${DUMP_PATH}"
pg_dump "${NEON_DATABASE_URL}" -Fc --no-owner --no-acl -f "${DUMP_PATH}"

log "Creating Neon production schema-only dump at ${SCHEMA_PATH}"
pg_dump "${NEON_DATABASE_URL}" --schema-only --no-owner --no-acl -f "${SCHEMA_PATH}"

log "Writing checksums"
sha256sum "${DUMP_PATH}" "${SCHEMA_PATH}" > "${DUMP_PATH}.sha256"
chmod 600 "${DUMP_PATH}" "${SCHEMA_PATH}" "${DUMP_PATH}.sha256"

log "Backup completed"
printf 'DUMP_PATH=%s\nSCHEMA_PATH=%s\nCHECKSUM_PATH=%s\n' "${DUMP_PATH}" "${SCHEMA_PATH}" "${DUMP_PATH}.sha256"
